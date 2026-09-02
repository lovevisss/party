<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MinuteVersion extends Model
{
    protected $fillable = ['meeting_minute_id', 'version_no', 'snapshot', 'due_at', 'is_overdue', 'archived_by', 'archived_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'due_at' => 'datetime', 'is_overdue' => 'boolean', 'archived_at' => 'datetime'];
    }
}
