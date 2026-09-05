<?php

namespace App\Models;

use App\Enums\MeetingType;
use App\Enums\MinuteStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property MeetingType $meeting_type */
class MeetingMinute extends Model
{
    use HasUuids;

    protected $fillable = ['organization_id', 'meeting_scope_id', 'meeting_type', 'meeting_year', 'sequence_no', 'title', 'meeting_start_at', 'meeting_end_at', 'first_topic_content', 'remarks', 'status', 'current_version', 'lock_version', 'due_at', 'is_overdue', 'archived_at', 'resubmitted_at', 'created_by', 'updated_by'];

    protected static function booted(): void
    {
        static::creating(function (MeetingMinute $minute): void {
            if (! $minute->meeting_scope_id && $minute->organization_id) {
                $minute->meeting_scope_id = MeetingScope::query()->where('meeting_type', $minute->meeting_type->value)
                    ->whereHas('organizations', fn ($query) => $query->whereKey($minute->organization_id))->value('id');
            }
        });
    }

    protected function casts(): array
    {
        return ['meeting_type' => MeetingType::class, 'status' => MinuteStatus::class, 'meeting_start_at' => 'datetime', 'meeting_end_at' => 'datetime', 'due_at' => 'datetime', 'is_overdue' => 'boolean', 'archived_at' => 'datetime', 'resubmitted_at' => 'datetime'];
    }

    /** @return BelongsTo<MeetingScope, $this> */
    public function meetingScope(): BelongsTo
    {
        return $this->belongsTo(MeetingScope::class);
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
