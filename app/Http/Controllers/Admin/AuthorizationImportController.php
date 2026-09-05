<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MeetingType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ImportBatch;
use App\Models\MeetingScope;
use App\Models\Person;
use App\Models\RoleAssignment;
use App\Services\AuditService;
use App\Services\AuthorizationService;
use App\Services\AuthorizationTemplateService;
use App\Services\MeetingScopeService;
use App\Services\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuthorizationImportController extends Controller
{
    public function index(Request $request): Response
    {
        $query = RoleAssignment::with(['user.person.organization', 'meetingScope'])->latest();
        $query->when($request->filled('q'), function ($builder) use ($request): void {
            $term = $request->string('q')->toString();
            $builder->whereHas('user.person', fn ($person) => $person->where('name', 'like', "%$term%")->orWhere('employee_no', 'like', "%$term%"));
        });
        $query->when($request->filled('role'), fn ($builder) => $builder->where('role', $request->string('role')->toString()));
        $query->when($request->filled('meeting_type'), fn ($builder) => $builder->where('meeting_type', $request->string('meeting_type')->toString()));
        $query->when($request->filled('meeting_scope_id'), fn ($builder) => $builder->where('meeting_scope_id', $request->integer('meeting_scope_id')));

        return Inertia::render('admin/AuthorizationImport', [
            'assignments' => $query->paginate(20)->withQueryString(),
            'batches' => ImportBatch::where('type', 'authorization')->latest()->limit(10)->get(),
            'organizations' => MeetingScope::with('organizations:id')->where('is_active', true)->orderBy('meeting_type')->orderBy('display_order')->get(['id', 'meeting_type', 'name']),
            'meetingTypes' => collect(MeetingType::cases())->map(fn (MeetingType $type) => ['value' => $type->value, 'label' => $type->label()]),
            'filters' => $request->only(['q', 'role', 'meeting_type', 'meeting_scope_id']),
        ]);
    }

    public function template(AuthorizationTemplateService $templates): StreamedResponse
    {
        $contents = $templates->make();

        return response()->streamDownload(fn () => print $contents, '授权名单导入模板.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function preview(Request $request, SpreadsheetReader $reader): RedirectResponse
    {
        $request->validate(['file' => 'required|file|max:5120']);
        $rows = $reader->rows($request->file('file'));
        $map = array_flip(array_map('trim', array_shift($rows) ?: []));
        foreach (['工号/统一账号', '姓名', '启用状态'] as $header) {
            abort_unless(isset($map[$header]), 422, "缺少列：$header");
        }
        $newFormat = isset($map['会议类型']) && isset($map['权限角色']);
        abort_unless($newFormat || isset($map['系统角色']) || isset($map['岗位角色']), 422, '缺少“会议类型、权限角色”或兼容的旧版角色列。');

        $payload = [];
        $errors = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            if (! array_filter($row, fn ($value) => trim((string) $value) !== '')) {
                continue;
            }
            $employeeNo = $this->cell($row, $map, '工号/统一账号');
            $name = $this->cell($row, $map, '姓名');
            $typeLabel = $newFormat ? $this->cell($row, $map, '会议类型') : '党总支会议纪要';
            $roleLabel = $newFormat ? $this->cell($row, $map, '权限角色') : ($this->cell($row, $map, '系统角色') ?: '学院提交人');
            $enabledLabel = $this->cell($row, $map, '启用状态');
            $role = $this->roleFromLabel($roleLabel);
            $meetingType = $role === UserRole::SystemAdmin ? null : $this->typeFromLabel($typeLabel);
            $person = Person::with('organization')->where('employee_no', $employeeNo)->first();
            $lineErrors = [];

            if (isset($seen[$employeeNo.'|'.$typeLabel.'|'.$roleLabel])) {
                $lineErrors[] = '工号和角色重复';
            }
            $seen[$employeeNo.'|'.$typeLabel.'|'.$roleLabel] = true;
            if (! $person || $person->status !== 'active') {
                $lineErrors[] = '有效人员不存在';
            } elseif ($person->name !== $name) {
                $lineErrors[] = '姓名与人员库不一致';
            }
            if (! $role) {
                $lineErrors[] = '权限角色无效';
            } elseif ($role !== UserRole::SystemAdmin && ! $meetingType) {
                $lineErrors[] = '会议类型无效';
            }
            if (! in_array($enabledLabel, ['启用', '停用'], true)) {
                $lineErrors[] = '启用状态无效';
            }

            $scope = ($person && $meetingType && $role === UserRole::MinuteSubmitter) ? app(MeetingScopeService::class)->scopeForPerson($person, $meetingType) : null;
            if ($person && $meetingType && $role === UserRole::MinuteSubmitter && ! $scope) {
                $lineErrors[] = '人员所属单位不能映射到所选会议类型';
            }
            $payload[] = ['employee_no' => $employeeNo, 'name' => $name, 'meeting_type' => $meetingType?->value, 'meeting_scope' => $scope?->name, 'role' => $role?->value, 'enabled' => $enabledLabel === '启用'];
            if ($lineErrors) {
                $errors[] = ['line' => $index + 2, 'employee_no' => $employeeNo, 'messages' => $lineErrors];
            }
        }

        $batch = ImportBatch::create(['type' => 'authorization', 'status' => $errors ? 'invalid' : 'preview', 'payload' => $payload, 'errors' => $errors ?: null, 'created_by' => $request->user()->id]);

        return back()->with($errors ? 'error' : 'success', $errors ? '预览发现错误，请查看批次错误并修正。' : '预览通过，共 '.count($payload).' 条。')->with('batch_id', $batch->id);
    }

    public function commit(Request $request, ImportBatch $batch, AuthorizationService $authorizations, AuditService $audit): RedirectResponse
    {
        abort_unless($batch->type === 'authorization' && $batch->status === 'preview' && empty($batch->errors), 422);
        $items = $batch->getAttribute('payload');
        abort_unless(is_array($items), 422);
        DB::transaction(function () use ($items, $batch, $request, $authorizations): void {
            foreach ($items as $item) {
                abort_unless(is_array($item) && is_string($item['role'] ?? null), 422);
                $person = Person::where('employee_no', $item['employee_no'])->where('status', 'active')->firstOrFail();
                $role = UserRole::from($item['role']);
                $meetingType = isset($item['meeting_type']) && $item['meeting_type'] ? MeetingType::from($item['meeting_type']) : ($role === UserRole::SystemAdmin ? null : MeetingType::PartyBranch);
                if ($item['enabled']) {
                    $authorizations->grant($person, $role, $meetingType, $request->user()->id);
                } else {
                    $authorizations->revokeForPerson($person, $role, $meetingType);
                }
            }
            $batch->update(['status' => 'committed', 'committed_at' => now()]);
        });
        $audit->record('authorization.import_committed', $batch, ['rows' => count($items)]);

        return back()->with('success', '授权名单已生效。');
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, int>  $map
     */
    private function cell(array $row, array $map, string $header): string
    {
        return isset($map[$header]) ? trim((string) ($row[$map[$header]] ?? '')) : '';
    }

    private function roleFromLabel(string $label): ?UserRole
    {
        return match ($label) {
            '提交人', '会议提交人', '学院提交人', '组织员', '办公室主任' => UserRole::MinuteSubmitter,
            '会议管理员', '校级业务管理员' => UserRole::MinuteManager,
            '系统管理员' => UserRole::SystemAdmin,
            default => null,
        };
    }

    private function typeFromLabel(string $label): ?MeetingType
    {
        return match ($label) {
            '党总支会议纪要', '党总支' => MeetingType::PartyBranch,
            '党政联席会议纪要', '党政联席会议' => MeetingType::PartyGovernmentJoint,
            default => MeetingType::tryFrom($label),
        };
    }
}
