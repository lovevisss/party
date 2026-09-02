<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\Person;
use App\Models\RoleAssignment;
use App\Services\AuditService;
use App\Services\AuthorizationService;
use App\Services\AuthorizationTemplateService;
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
        $query = RoleAssignment::with(['user.person.organization', 'organization'])->latest();
        $query->when($request->filled('q'), function ($builder) use ($request): void {
            $term = $request->string('q')->toString();
            $builder->whereHas('user.person', fn ($person) => $person->where('name', 'like', "%$term%")->orWhere('employee_no', 'like', "%$term%"));
        });
        $query->when($request->filled('role'), fn ($builder) => $builder->where('role', $request->string('role')->toString()));
        $query->when($request->filled('organization_id'), fn ($builder) => $builder->where('organization_id', $request->integer('organization_id')));

        return Inertia::render('admin/AuthorizationImport', [
            'assignments' => $query->paginate(20)->withQueryString(),
            'batches' => ImportBatch::where('type', 'authorization')->latest()->limit(10)->get(),
            'organizations' => Organization::where('is_active', true)->orderBy('name')->get(['id', 'external_code', 'name']),
            'filters' => $request->only(['q', 'role', 'organization_id']),
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
        $newFormat = isset($map['系统角色']);
        abort_unless($newFormat || isset($map['岗位角色']), 422, '缺少“系统角色”或旧版“岗位角色”列。');

        $payload = [];
        $errors = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            if (! array_filter($row, fn ($value) => trim((string) $value) !== '')) {
                continue;
            }
            $employeeNo = $this->cell($row, $map, '工号/统一账号');
            $name = $this->cell($row, $map, '姓名');
            $organizationCode = $this->cell($row, $map, '学院代码');
            $position = $newFormat ? $this->cell($row, $map, '岗位标签') : $this->cell($row, $map, '岗位角色');
            $roleLabel = $newFormat ? $this->cell($row, $map, '系统角色') : '学院提交人';
            $enabledLabel = $this->cell($row, $map, '启用状态');
            $role = $this->roleFromLabel($roleLabel);
            $person = Person::with('organization')->where('employee_no', $employeeNo)->first();
            $lineErrors = [];

            if (isset($seen[$employeeNo.'|'.$roleLabel])) {
                $lineErrors[] = '工号和角色重复';
            }
            $seen[$employeeNo.'|'.$roleLabel] = true;
            if (! $person || $person->status !== 'active') {
                $lineErrors[] = '有效人员不存在';
            } elseif ($person->name !== $name) {
                $lineErrors[] = '姓名与人员库不一致';
            }
            if (! $role) {
                $lineErrors[] = '系统角色无效';
            } elseif ($role === UserRole::CollegeSubmitter) {
                if (! $person || $organizationCode === '' || $person->organization?->external_code !== $organizationCode) {
                    $lineErrors[] = '学院代码与人员库不一致';
                }
                if (! in_array($position, ['组织员', '办公室主任'], true)) {
                    $lineErrors[] = '学院提交人岗位标签无效';
                }
            } elseif ($organizationCode !== '' || $position !== '') {
                $lineErrors[] = '全校角色的学院代码和岗位标签必须留空';
            }
            if (! in_array($enabledLabel, ['启用', '停用'], true)) {
                $lineErrors[] = '启用状态无效';
            }

            $payload[] = ['employee_no' => $employeeNo, 'name' => $name, 'role' => $role?->value, 'position_label' => $position ?: null, 'enabled' => $enabledLabel === '启用'];
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
                if ($item['enabled']) {
                    $authorizations->grant($person, $role, $item['position_label'] ?? null, $request->user()->id);
                } else {
                    $authorizations->revokeForPerson($person, $role);
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
            '学院提交人' => UserRole::CollegeSubmitter,
            '校级业务管理员' => UserRole::SchoolManager,
            '系统管理员' => UserRole::SystemAdmin,
            default => null,
        };
    }
}
