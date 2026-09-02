<?php

namespace App\Console\Commands;

use App\Services\PersonnelSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncPersonnelNow extends Command
{
    protected $signature = 'minutes:sync-personnel';

    protected $description = 'Synchronize all personnel from the middata table';

    public function handle(PersonnelSyncService $service): int
    {
        try {
            $run = $service->sync();
            $this->info("同步完成：{$run->source_count} 条，新增 {$run->created_count}，更新 {$run->updated_count}，停用 {$run->deactivated_count}。");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
