<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    protected $fillable = ['source', 'status', 'source_count', 'created_count', 'updated_count', 'deactivated_count', 'error_message', 'started_at', 'finished_at', 'triggered_by'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
