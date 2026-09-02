<?php

namespace App\Http\Controllers;

use App\Enums\MinuteStatus;
use App\Models\MeetingMinute;
use App\Models\Organization;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MeetingMinuteController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', MeetingMinute::class);
        $user = $request->user();
        $query = MeetingMinute::query()->with('participants')->withCount('versions');
        $canViewAll = $user->hasRole('school_manager') || $user->hasRole('system_admin');
        if (! $canViewAll) {
            $query->where('created_by', $user->id)
                ->whereIn('organization_id', $user->organizationIds());
        }
        $query->when($request->filled('organization_id'), fn ($q) => $q->where('organization_id', $request->integer('organization_id')))->when($request->filled('year'), fn ($q) => $q->where('meeting_year', $request->integer('year')))->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('overdue'), fn ($q) => $q->where('is_overdue', $request->boolean('overdue')));
        $size = in_array($request->integer('per_page'), [20, 50, 100]) ? $request->integer('per_page') : 20;

        $minutes = $query->latest('meeting_start_at')
            ->orderByDesc('sequence_no')
            ->paginate($size)
            ->withQueryString()
            ->through(fn (MeetingMinute $minute): array => [
                ...$minute->toArray(),
                'can_edit' => Gate::forUser($user)->allows('update', $minute),
            ]);
        $organizations = Organization::query()
            ->where('is_active', true)
            ->when(! $canViewAll, fn ($organizationQuery) => $organizationQuery->whereIn('id', $user->organizationIds()))
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('minutes/Index', [
            'minutes' => $minutes,
            'organizations' => $organizations,
            'filters' => $request->only(['organization_id', 'year', 'status', 'overdue', 'per_page']),
            'canCreate' => Gate::forUser($user)->allows('create', MeetingMinute::class),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', MeetingMinute::class);

        return Inertia::render('minutes/Form', ['minute' => null, 'organizations' => Organization::whereIn('id', $request->user()->organizationIds())->get(['id', 'name'])]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        Gate::authorize('create', MeetingMinute::class);
        $data = $this->draftData($request);
        $org = $request->integer('organization_id') ?: ($request->user()->organizationIds()[0] ?? null);
        abort_unless(in_array($org, $request->user()->organizationIds()), 403);
        $minute = DB::transaction(function () use ($data, $org, $request) {
            $minute = MeetingMinute::create([...$data, 'organization_id' => $org, 'meeting_type' => 'party_committee', 'status' => MinuteStatus::Draft, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $this->replaceParticipants($minute, $request->input('participants', []));

            return $minute;
        });
        $audit->record('minutes.created', $minute);

        return redirect()->route('minutes.edit', $minute)->with('success', '草稿已保存。');
    }

    public function show(MeetingMinute $minute): Response
    {
        Gate::authorize('view', $minute);

        return Inertia::render('minutes/Show', ['minute' => $minute->load(['participants', 'versions', 'files', 'returns'])]);
    }

    public function edit(Request $request, MeetingMinute $minute): Response
    {
        Gate::authorize('update', $minute);

        return Inertia::render('minutes/Form', ['minute' => $minute->load(['participants', 'files']), 'organizations' => Organization::whereIn('id', $request->user()->organizationIds())->get(['id', 'name'])]);
    }

    public function update(Request $request, MeetingMinute $minute, AuditService $audit): RedirectResponse
    {
        Gate::authorize('update', $minute);
        $data = $this->draftData($request);
        $lock = $request->validate(['lock_version' => 'required|integer|min:0'])['lock_version'];
        DB::transaction(function () use ($minute, $data, $lock, $request) {
            $affected = MeetingMinute::whereKey($minute->id)->where('lock_version', $lock)->whereIn('status', ['draft', 'returned'])->update([...$data, 'lock_version' => $lock + 1, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            if (! $affected) {
                abort(409, '记录已被他人更新，请刷新后重试。');
            }$this->replaceParticipants($minute, $request->input('participants', []));
        });
        $audit->record('minutes.updated', $minute);

        return back()->with('success', '修改已保存。');
    }

    /** @return array<string, mixed> */
    private function draftData(Request $request): array
    {
        $data = $request->validate(
            ['meeting_year' => 'nullable|integer|min:2000|max:2100', 'sequence_no' => 'nullable|integer|min:1|max:999', 'title' => 'nullable|string|max:200', 'meeting_start_at' => 'nullable|date', 'meeting_end_at' => 'nullable|date|after:meeting_start_at', 'first_topic_content' => 'nullable|string|max:20000', 'remarks' => 'nullable|string|max:1000', 'participants' => 'array', 'participants.*.person_id' => 'nullable|exists:people,id', 'participants.*.role_type' => ['required', Rule::in(['chair', 'recorder', 'attendee', 'absent', 'observer'])], 'participants.*.display_name' => 'required|string|max:100', 'participants.*.is_external' => 'boolean'],
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
                'first_topic_content' => '第一议题中的“学习内容”',
                'remarks' => '第一议题中的“备注”',
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
}
