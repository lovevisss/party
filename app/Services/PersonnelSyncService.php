<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Person;
use App\Models\SyncRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PersonnelSyncService
{
    public function sync(?int $triggeredBy = null): SyncRun
    {
        return Cache::lock('personnel-sync', 1800)->block(1, function () use ($triggeredBy) {
            $run = SyncRun::create(['status' => 'running', 'started_at' => now(), 'triggered_by' => $triggeredBy]);
            try {
                $rows = DB::connection('middata')->table('t_bd_zzqxryxx')->select(['xgh', 'xm', 'dwmc', 'dwbm', 'dzyx', 'yddh'])->get();
                $count = $rows->count();
                if ($count === 0) {
                    throw new RuntimeException('中间库返回零条人员记录，已终止同步。');
                }
                $duplicates = $rows->groupBy(fn ($r) => trim((string) $r->xgh))->filter(fn ($group, $key) => $key === '' || $group->count() > 1);
                if ($duplicates->isNotEmpty()) {
                    throw new RuntimeException('中间库存在空工号或重复工号，已终止同步。');
                }
                $previous = SyncRun::where('status', 'completed')->latest('id')->value('source_count');
                if ($previous && $count < $previous * 0.5) {
                    throw new RuntimeException("本次人员数 $count 低于上次 $previous 的 50%，已触发安全保护。");
                }
                $seenAt = now();
                $created = 0;
                $updated = 0;
                $deactivated = 0;
                DB::transaction(function () use ($rows, $seenAt, &$created, &$updated, &$deactivated) {
                    foreach ($rows as $row) {
                        $code = trim((string) $row->dwbm);
                        $name = trim((string) $row->dwmc);
                        if ($code === '' || $name === '') {
                            throw new RuntimeException('人员记录缺少单位编码或名称。');
                        }
                        $organization = Organization::updateOrCreate(['external_code' => $code], ['name' => $name, 'is_active' => true]);
                        $person = Person::firstOrNew(['employee_no' => trim((string) $row->xgh)]);
                        $person->exists ? $updated++ : $created++;
                        $person->fill(['organization_id' => $organization->id, 'external_id' => trim((string) $row->xgh), 'cas_account' => trim((string) $row->xgh), 'name' => trim((string) $row->xm), 'email' => $row->dzyx ?: null, 'mobile' => $row->yddh ?: null, 'status' => 'active', 'last_seen_at' => $seenAt])->save();
                    }
                    app(MeetingScopeService::class)->syncOrganizationMappings();
                    $deactivated = Person::where('status', 'active')->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $seenAt))->update(['status' => 'inactive']);
                    Organization::whereDoesntHave('people', fn ($q) => $q->where('status', 'active'))->update(['is_active' => false]);
                });
                $run->update(['status' => 'completed', 'source_count' => $count, 'created_count' => $created, 'updated_count' => $updated, 'deactivated_count' => $deactivated, 'finished_at' => now()]);
            } catch (Throwable $e) {
                $run->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
                throw $e;
            }

            return $run->fresh();
        });
    }
}
