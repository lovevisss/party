<?php

namespace App\Services;

use App\Enums\MinuteStatus;
use App\Models\MeetingMinute;
use App\Models\MinuteVersion;
use App\Models\ReturnRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MinutesArchiveService
{
    public function __construct(private WorkdayService $workdays, private AuditService $audit) {}

    public function archive(MeetingMinute $minute, User $user): MeetingMinute
    {
        return DB::transaction(function () use ($minute, $user): MeetingMinute {
            $minute = MeetingMinute::lockForUpdate()->findOrFail($minute->id);
            if (! in_array($minute->getRawOriginal('status'), [MinuteStatus::Draft->value, MinuteStatus::Returned->value], true)) {
                throw ValidationException::withMessages(['status' => '仅草稿或已退回的纪要可以归档。']);
            }

            validator(
                $minute->toArray(),
                [
                    'meeting_year' => 'required|integer|min:2000|max:2100',
                    'sequence_no' => 'required|integer|min:1|max:999',
                    'title' => 'required|string|max:200',
                    'meeting_start_at' => 'required|date',
                    'meeting_end_at' => 'required|date|after:meeting_start_at',
                    'first_topic_content' => 'required|string|max:20000',
                ],
                [
                    'meeting_year.required' => '基本信息第 2 项“会议年度”未填写。',
                    'sequence_no.required' => '基本信息第 3 项“会议序号”未填写。',
                    'title.required' => '基本信息第 4 项“会议名称”未填写。',
                    'meeting_start_at.required' => '基本信息第 5 项“开始时间”未填写。',
                    'meeting_end_at.required' => '基本信息第 6 项“结束时间”未填写。',
                    'meeting_end_at.after' => '基本信息第 6 项“结束时间”必须晚于“开始时间”。',
                    'first_topic_content.required' => '第一议题中的“学习内容”未填写。',
                ],
                [
                    'meeting_year' => '基本信息第 2 项“会议年度”',
                    'sequence_no' => '基本信息第 3 项“会议序号”',
                    'title' => '基本信息第 4 项“会议名称”',
                    'meeting_start_at' => '基本信息第 5 项“开始时间”',
                    'meeting_end_at' => '基本信息第 6 项“结束时间”',
                    'first_topic_content' => '第一议题中的“学习内容”',
                ],
            )->validate();

            $participantLabels = ['chair' => '主持人', 'recorder' => '记录人', 'attendee' => '参会人员'];
            foreach ($participantLabels as $role => $label) {
                if (! $minute->participants()->where('role_type', $role)->exists()) {
                    throw ValidationException::withMessages(['participants' => "必须至少选择一名{$label}。"]);
                }
            }

            $file = $minute->files()->whereNull('version_no')->latest()->first();
            if (! $file) {
                throw ValidationException::withMessages(['attachment' => '归档前必须上传正式纪要附件。']);
            }

            $version = $minute->current_version + 1;
            $now = now();
            $meetingEnd = CarbonImmutable::parse((string) $minute->meeting_end_at);
            $due = $minute->due_at ?: $this->workdays->thirdWorkdayAfter($meetingEnd);
            $overdue = $minute->is_overdue ?? $now->greaterThan($due);
            $snapshot = [
                'minute' => $minute->only(['organization_id', 'meeting_scope_id', 'meeting_type', 'meeting_year', 'sequence_no', 'title', 'meeting_start_at', 'meeting_end_at', 'first_topic_content', 'remarks']),
                'participants' => $minute->participants()->get()->map->only(['person_id', 'role_type', 'display_name', 'is_external'])->all(),
            ];
            $versionModel = MinuteVersion::create([
                'meeting_minute_id' => $minute->id,
                'version_no' => $version,
                'snapshot' => $snapshot,
                'due_at' => $due,
                'is_overdue' => $overdue,
                'archived_by' => $user->id,
                'archived_at' => $now,
            ]);
            $file->update(['minute_version_id' => $versionModel->id, 'version_no' => $version]);
            $minute->update([
                'status' => MinuteStatus::Archived,
                'current_version' => $version,
                'due_at' => $due,
                'is_overdue' => $overdue,
                'archived_at' => $minute->archived_at ?: $now,
                'resubmitted_at' => $version > 1 ? $now : null,
                'lock_version' => $minute->lock_version + 1,
                'updated_by' => $user->id,
            ]);
            $this->audit->record($version > 1 ? 'minutes.resubmitted' : 'minutes.archived', $minute, ['version' => $version]);

            return $minute->fresh(['participants', 'files', 'versions']);
        });
    }

    public function returnForCorrection(MeetingMinute $minute, User $user, string $reason): MeetingMinute
    {
        return DB::transaction(function () use ($minute, $user, $reason): MeetingMinute {
            $minute = MeetingMinute::lockForUpdate()->findOrFail($minute->id);
            if ($minute->getRawOriginal('status') !== MinuteStatus::Archived->value) {
                throw ValidationException::withMessages(['status' => '仅已归档的纪要可以退回。']);
            }
            ReturnRecord::create([
                'meeting_minute_id' => $minute->id,
                'version_no' => $minute->current_version,
                'reason' => $reason,
                'returned_by' => $user->id,
                'returned_at' => now(),
            ]);
            $minute->update(['status' => MinuteStatus::Returned, 'lock_version' => $minute->lock_version + 1, 'updated_by' => $user->id]);
            $this->audit->record('minutes.returned', $minute, ['reason' => $reason, 'version' => $minute->current_version]);

            return $minute->fresh();
        });
    }
}
