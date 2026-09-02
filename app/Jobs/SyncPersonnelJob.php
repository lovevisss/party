<?php

namespace App\Jobs;

use App\Services\PersonnelSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncPersonnelJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 1800;

    public function __construct(public ?int $triggeredBy = null) {}

    public function handle(PersonnelSyncService $service): void
    {
        $service->sync($this->triggeredBy);
    }
}
