<?php

namespace App\Http\Controllers;

use App\Enums\MeetingType;
use App\Enums\MinuteStatus;
use App\Models\MeetingMinute;
use App\Models\MeetingScope;
use App\Models\ParticipantPreset;
use App\Services\AuditService;
use App\Services\MinuteFileService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MeetingMinuteController extends Controller
{
    public function redirectToType(Request $request): RedirectResponse
    {
        $type = $request->user()->accessibleMeetingTypes()[0] ?? null;
        abort_unless($type !== null, 403, '当前账号没有会议纪要访问权限。');

        return redirect()->route('minutes.type.index', $type->slug());
    }

    public function index(Request $request, string $meetingType): Response
    {
        Gate::authorize('viewAny', MeetingMinute::class);
        $type = $this->type($meetingType);
        $user = $request->user();
        abort_unless(in_array($type, $user->accessibleMeetingTypes(), true), 403);
        $query = MeetingMinute::query()->where('meeting_type', $type->value)->with(['participants', 'meetingScope'])->withCount('versions');
        $canViewAll = $user->hasGlobalMinuteAccess();
        $managedScopeIds = $user->managedScopeIds($type);
        $ownScopeIds = $user->meetingScopeIds($type);
        if ($canViewAll) {
            $query->where('status', MinuteStatus::Archived->value);
        } else {
            $query->where(function ($visible) use ($user, $managedScopeIds, $ownScopeIds): void {
                $visible->whereIn('meeting_scope_id', $managedScopeIds)
                    ->orWhere(fn ($own) => $own->where('created_by', $user->id)->whereIn('meeting_scope_id', $ownScopeIds));
            });
        }
        $request->validate(['overdue' => 'nullable|in:0,1']);
        $query->when($request->filled('meeting_scope_id'), fn ($q) => $q->where('meeting_scope_id', $request->integer('meeting_scope_id')))->when($request->filled('year'), fn ($q) => $q->where('meeting_year', $request->integer('year')))->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('overdue'), fn ($q) => $q->where('status', MinuteStatus::Archived->value)->where('is_overdue', $request->boolean('overdue')));
        $size = in_array($request->integer('per_page'), [20, 50, 100]) ? $request->integer('per_page') : 20;

        $minutes = $query->latest('meeting_start_at')
            ->orderByDesc('sequence_no')
            ->paginate($size)
            ->withQueryString()
            ->through(fn (MeetingMinute $minute): array => [
                ...$minute->toArray(),
                'can_edit' => Gate::forUser($user)->allows('update', $minute),
                'can_delete' => Gate::forUser($user)->allows('delete', $minute),
            ]);
        $scopes = MeetingScope::query()->where('meeting_type', $type->value)->where('is_active', true)
            ->when(! $canViewAll, fn ($scopeQuery) => $scopeQuery->whereIn('id', array_unique([...$managedScopeIds, ...$ownScopeIds])))
            ->orderBy('display_order')->get(['id', 'name']);

        return Inertia::render('minutes/Index', [
            'minutes' => $minutes,
            'organizations' => $scopes,
            'meetingType' => $this->typePayload($type),
            'filters' => $request->only(['meeting_scope_id', 'year', 'status', 'overdue', 'per_page']),
            'canCreate' => ! $canViewAll && count($user->meetingScopeIds($type)) > 0,
            'isSystemAdmin' => $canViewAll,
        ]);
    }

    public function create(Request $request, string $meetingType): Response
    {
        abort_if($request->user()->hasGlobalMinuteAccess(), 403);
        $type = $this->type($meetingType);
        $scopeIds = $request->user()->meetingScopeIds($type);
        abort_unless(count($scopeIds) > 0, 403);

        return Inertia::render('minutes/Form', [
            'minute' => null,
            'organizations' => MeetingScope::whereIn('id', $scopeIds)->orderBy('display_order')->get(['id', 'name']),
            'meetingType' => $this->typePayload($type),
            'participantPresets' => $this->participantPresets($request, $type, $scopeIds),
        ]);
    }

    public function store(Request $request, string $meetingType, AuditService $audit, MinuteFileService $files): RedirectResponse
    {
        abort_if($request->user()->hasGlobalMinuteAccess(), 403);
        $type = $this->type($meetingType);
        $data = $this->draftData($request);
        $request->validate(['attachment' => 'nullable|file|max:20480']);
        $attachment = $request->file('attachment');
        if ($attachment instanceof UploadedFile) {
            $files->validateUpload($attachment);
        }
        $scope = $request->integer('meeting_scope_id') ?: $request->integer('organization_id');
        abort_unless(in_array($scope, $request->user()->meetingScopeIds($type), true), 403);
        $sourceOrganization = $request->user()->person?->organization_id;
        abort_unless($sourceOrganization !== null, 422, '当前人员没有所属单位。');
        $minute = DB::transaction(function () use ($data, $scope, $sourceOrganization, $type, $request, $files, $attachment) {
            $minute = MeetingMinute::create([...$data, 'organization_id' => $sourceOrganization, 'meeting_scope_id' => $scope, 'meeting_type' => $type, 'status' => MinuteStatus::Draft, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $this->replaceParticipants($minute, $request->input('participants', []));
            if ($attachment instanceof UploadedFile) {
                $files->store($minute, $attachment, $request->user());
            }

            return $minute;
        });
        $audit->record('minutes.created', $minute);

        return redirect()->route('minutes.edit', $minute)->with('success', $attachment instanceof UploadedFile ? '会议纪要已上传，草稿已自动保存。' : '草稿已保存。');
    }

    public function show(Request $request, MeetingMinute $minute): Response
    {
        Gate::authorize('view', $minute);

        $minute->load(['participants', 'meetingScope']);
        if ($request->user()->hasGlobalMinuteAccess()) {
            $minute->load([
                'versions' => fn ($query) => $query->where('version_no', $minute->current_version),
                'files' => fn ($query) => $query->where('version_no', $minute->current_version),
            ]);
        } else {
            $minute->load(['versions', 'files', 'returns']);
        }

        $firstArchivedAt = $minute->current_version > 1 ? $minute->versions()->where('version_no', 1)->value('archived_at') : null;

        return Inertia::render('minutes/Show', [
            'minute' => $minute,
            'meetingType' => $this->typePayload($minute->meeting_type),
            'canDelete' => Gate::allows('delete', $minute),
            'canReturn' => Gate::allows('returnForCorrection', $minute),
            'firstArchivedAt' => $firstArchivedAt ? CarbonImmutable::parse($firstArchivedAt, config('app.timezone'))->toIso8601String() : null,
        ]);
    }

    public function edit(Request $request, MeetingMinute $minute): Response
    {
        Gate::authorize('update', $minute);

        return Inertia::render('minutes/Form', [
            'minute' => $minute->load(['participants', 'files']),
            'organizations' => MeetingScope::whereKey($minute->meeting_scope_id)->get(['id', 'name']),
            'meetingType' => $this->typePayload($minute->meeting_type),
            'participantPresets' => $this->participantPresets($request, $minute->meeting_type, [$minute->meeting_scope_id]),
        ]);
    }

    public function update(Request $request, MeetingMinute $minute, AuditService $audit): RedirectResponse
    {
        Gate::authorize('update', $minute);
        $data = $this->draftData($request);
        $lock = $request->validate(['lock_version' => 'required|integer|min:0'])['lock_version'];
        DB::transaction(function () use ($minute, $data, $lock, $request) {
            $affected = MeetingMinute::whereKey($minute->id)->where('lock_version', $lock)->whereIn('status', ['draft', 'returned'])->update([...$data, 'lock_version' => $lock + 1, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            if (! $affected) {
                throw ValidationException::withMessages(['lock_version' => '记录已被他人更新，请刷新页面后重试。']);
            }
            $this->replaceParticipants($minute, $request->input('participants', []));
        });
        $audit->record('minutes.updated', $minute);

        return back()->with('success', '修改已保存。');
    }

    public function destroy(MeetingMinute $minute, AuditService $audit): RedirectResponse
    {
        Gate::authorize('delete', $minute);
        $slug = $minute->meeting_type->slug();

        DB::transaction(function () use ($minute, $audit): void {
            $audit->record('minutes.deleted', $minute, [
                'title' => $minute->title,
                'meeting_type' => $minute->meeting_type->value,
                'meeting_scope_id' => $minute->meeting_scope_id,
                'meeting_year' => $minute->meeting_year,
                'sequence_no' => $minute->sequence_no,
                'current_version' => $minute->current_version,
            ]);
            $minute->forceFill(['active_number_key' => null])->save();
            $minute->delete();
        });

        return redirect()->route('minutes.type.index', $slug)->with('success', '会议纪要已删除。');
    }

    /** @return array<string, mixed> */
    private function draftData(Request $request): array
    {
        $data = $request->validate(
            ['meeting_year' => 'nullable|integer|min:2000|max:2100', 'sequence_no' => 'nullable|integer|min:1|max:999', 'title' => 'nullable|string|max:200', 'meeting_start_at' => 'nullable|date', 'meeting_end_at' => 'nullable|date|after:meeting_start_at', 'participants' => 'array', 'participants.*.person_id' => 'nullable|exists:people,id', 'participants.*.role_type' => ['required', Rule::in(['chair', 'recorder', 'attendee', 'absent', 'observer'])], 'participants.*.display_name' => 'required|string|max:100', 'participants.*.is_external' => 'boolean'],
            [
                'meeting_end_at.after' => '基本信息第 6 项“结束时间”必须晚于“开始时间”。',
                'participants.*.person_id.exists' => '人员情况第 :position 行：所选人员不存在或已停用。',
                'participants.*.role_type.required' => '人员情况第 :position 行：请选择人员角色。',
                'participants.*.role_type.in' => '人员情况第 :position 行：人员角色无效。',
                'participants.*.display_name.required' => '人员情况第 :position 行：人员姓名不能为空。',
            ],
            [
                'meeting_year' => '基本信息第 2 项“会议年度”',
                'sequence_no' => '基本信息第 3 项“会议序号”',
                'title' => '基本信息第 4 项“会议名称”',
                'meeting_start_at' => '基本信息第 5 项“开始时间”',
                'meeting_end_at' => '基本信息第 6 项“结束时间”',
                'participants' => '人员情况',
            ],
        );
        unset($data['participants']);
        if (! empty($data['meeting_start_at'])) {
            $data['meeting_year'] = (int) date('Y', strtotime($data['meeting_start_at']));
        }

        return $data;
    }

    /** @param list<array<string, mixed>> $participants */
    private function replaceParticipants(MeetingMinute $minute, array $participants): void
    {
        $minute->participants()->delete();
        foreach ($participants as $p) {
            $minute->participants()->create($p);
        }
    }

    /** @param list<int> $scopeIds */
    private function participantPresets(Request $request, MeetingType $type, array $scopeIds): mixed
    {
        return ParticipantPreset::query()
            ->where('user_id', $request->user()->id)
            ->where('meeting_type', $type->value)
            ->whereIn('meeting_scope_id', $scopeIds)
            ->with(['items.person:id,organization_id,status'])
            ->orderBy('name')
            ->get();
    }

    private function type(string $slug): MeetingType
    {
        return MeetingType::fromSlug($slug);
    }

    /** @return array{value:string,slug:string,label:string,scope_label:string} */
    private function typePayload(MeetingType $type): array
    {
        return ['value' => $type->value, 'slug' => $type->slug(), 'label' => $type->label(), 'scope_label' => $type->scopeLabel()];
    }
}
