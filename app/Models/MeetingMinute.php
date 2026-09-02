<?php

namespace App\Models;

use App\Enums\MinuteStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetingMinute extends Model
{
    use HasUuids;

    protected $fillable = ['organization_id', 'meeting_type', 'meeting_year', 'sequence_no', 'title', 'meeting_start_at', 'meeting_end_at', 'first_topic_content', 'remarks', 'status', 'current_version', 'lock_version', 'due_at', 'is_overdue', 'archived_at', 'resubmitted_at', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['status' => MinuteStatus::class, 'meeting_start_at' => 'datetime', 'meeting_end_at' => 'datetime', 'due_at' => 'datetime', 'is_overdue' => 'boolean', 'archived_at' => 'datetime', 'resubmitted_at' => 'datetime'];
    }

    /** @return HasMany<MinuteParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(MinuteParticipant::class);
    }

    /** @return HasMany<MinuteVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(MinuteVersion::class);
    }

    /** @return HasMany<MinuteFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(MinuteFile::class);
    }

    /** @return HasMany<ReturnRecord, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(ReturnRecord::class);
    }
}
