<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Models\MeetingMinute;
use App\Models\MeetingScope;
use App\Models\ParticipantPreset;
use App\Models\Person;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Models\Workday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AuditLogPresenter
{
    public const EVENTS = [
        'auth.login' => '登录系统',
        'auth.logout' => '退出系统',
        'auth.unmatched' => '未匹配到在职人员',
        'auth.back_channel_logout' => '统一认证通知退出',
        'authorization.granted' => '授予权限',
        'authorization.revoked' => '撤销权限',
        'authorization.import_committed' => '批量导入授权',
        'participant_preset.saved' => '保存常用人员清单',
        'participant_preset.deleted' => '删除常用人员清单',
        'minutes.created' => '创建会议纪要',
        'minutes.updated' => '修改会议纪要',
        'minutes.archived' => '归档会议纪要',
        'minutes.resubmitted' => '重新归档会议纪要',
        'minutes.returned' => '退回会议纪要',
        'personnel.sync_queued' => '发起人员同步',
        'workday.saved' => '保存工作日历',
        'workday.deleted' => '删除工作日历日期',
        'workday.imported' => '导入工作日历',
    ];

    public const CATEGORIES = [
        'auth' => '登录与认证',
        'authorization' => '权限管理',
        'participant_preset' => '常用人员清单',
        'minutes' => '会议纪要',
        'personnel' => '人员同步',
        'workday' => '工作日历',
    ];

    /** @param Collection<int, AuditLog> $logs
     * @return array<int, array<string, mixed>>
     */
    public function present(Collection $logs): array
    {
        $subjectIds = fn (string $type): array => $logs->where('subject_type', $type)->pluck('subject_id')->filter()->unique()->all();
        $assignmentRows = RoleAssignment::withTrashed()->whereIn('id', $subjectIds(RoleAssignment::class))->get();
        $assignments = $assignmentRows->keyBy('id')->all();
        $userIds = $logs->pluck('user_id')->merge($logs->where('subject_type', User::class)->pluck('subject_id'))
            ->merge($assignmentRows->pluck('user_id'))->filter()->unique()->all();
        $userRows = User::whereIn('id', $userIds)->get(['id', 'name', 'person_id']);
        $users = $userRows->keyBy('id')->all();
        $personIds = $logs->map(fn (AuditLog $log) => $log->getAttribute('metadata'))
            ->map(fn ($metadata) => is_array($metadata) ? ($metadata['person_id'] ?? null) : null)
            ->merge($userRows->pluck('person_id'))->filter()->unique()->all();
        $people = Person::whereIn('id', $personIds)->get(['id', 'name'])->keyBy('id')->all();
        $minutes = MeetingMinute::whereIn('id', $subjectIds(MeetingMinute::class))->get(['id', 'title'])->keyBy('id')->all();
        $presets = ParticipantPreset::whereIn('id', $subjectIds(ParticipantPreset::class))->get(['id', 'name'])->keyBy('id')->all();
        $scopes = MeetingScope::whereIn('id', $assignmentRows->pluck('meeting_scope_id')->filter()->unique())->get(['id', 'name'])->keyBy('id')->all();
        $workdays = Workday::whereIn('date', $subjectIds(Workday::class))->get(['date', 'name'])->keyBy('date')->all();

        return $logs->map(function (AuditLog $log) use ($users, $people, $minutes, $presets, $assignments, $scopes, $workdays): array {
            $id = (string) $log->subject_id;
            $rawMetadata = $log->getAttribute('metadata');
            $metadata = is_array($rawMetadata) ? $rawMetadata : [];
            $timestamp = CarbonImmutable::parse($log->created_at, config('app.timezone'))->timezone('Asia/Shanghai');
            $subject = match ($log->subject_type) {
                User::class => ['用户', $users[$id]->name ?? '用户已失效'],
                RoleAssignment::class => $this->assignment($id, $metadata, $assignments, $users, $people, $scopes),
                MeetingMinute::class => ['会议纪要', $minutes[$id]->title ?? '记录已删除'],
                ParticipantPreset::class => ['常用人员清单', $presets[$id]->name ?? ($metadata['name'] ?? '记录已删除')],
                ImportBatch::class => ['授权导入批次', isset($metadata['rows']) ? $metadata['rows'].' 条授权' : '批次记录'],
                Workday::class => ['工作日历', $workdays[$id]->name ?? ($id ?: '记录已删除')],
                null => $log->event === 'auth.unmatched'
                    ? ['未匹配账号', $metadata['account'] ?? '账号未记录']
                    : ['系统', '系统操作'],
                default => ['其他对象', '记录已删除'],
            };
            $missing = in_array($subject[1], ['用户已失效', '记录已删除'], true);

            return [
                'id' => $log->id,
                'date' => $timestamp->format('Y-m-d'),
                'time' => $timestamp->format('H:i:s'),
                'event' => $log->event,
                'event_label' => self::EVENTS[$log->event] ?? '其他操作',
                'actor' => $log->user_id
                    ? ($users[$log->user_id]->name ?? '用户已失效')
                    : ($log->subject_type === User::class
                        ? ($users[$id]->name ?? '用户已失效')
                        : match ($log->event) {
                            'auth.back_channel_logout' => '系统',
                            'auth.unmatched' => '未登录账号',
                            default => '操作人未记录',
                        }),
                'user_id' => $log->user_id,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'subject_label' => $subject[0],
                'subject_name' => $subject[1],
                'subject_missing' => $missing,
                'request_id' => $log->request_id,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'metadata' => $metadata,
            ];
        })->all();
    }

    /** @param array<string, mixed> $metadata
     * @param  array<int, RoleAssignment>  $assignments
     * @param  array<int, User>  $users
     * @param  array<int, Person>  $people
     * @param  array<int, MeetingScope>  $scopes
     * @return array{string, string}
     */
    private function assignment(string $id, array $metadata, array $assignments, array $users, array $people, array $scopes): array
    {
        $assignment = $assignments[$id] ?? null;
        $person = $people[$metadata['person_id'] ?? ''] ?? null;
        $userId = $assignment ? $assignment->user_id : ($metadata['user_id'] ?? null);
        $user = $users[$userId ?? ''] ?? null;
        $name = $person ? $person->name : ($user ? $user->name : '用户已失效');
        $role = $metadata['role'] ?? ($assignment ? $assignment->getRawOriginal('role') : null);
        $roleLabel = match ($role) {
            'minute_submitter' => '会议提交人',
            'minute_manager' => '纪要管理员',
            'system_admin' => '系统管理员',
            default => '权限',
        };
        $scopeId = $assignment ? $assignment->meeting_scope_id : null;
        $scope = $scopes[$scopeId ?? '']->name ?? null;

        return ['人员权限', trim($name.' · '.$roleLabel.($scope ? ' · '.$scope : ''))];
    }
}
