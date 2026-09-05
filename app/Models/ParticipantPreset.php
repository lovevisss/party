<?php

namespace App\Models;

use App\Enums\MeetingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property MeetingType|null $meeting_type */
class ParticipantPreset extends Model
{
    protected $fillable = ['user_id', 'organization_id', 'meeting_type', 'meeting_scope_id', 'name'];

    protected function casts(): array
    {
        return ['meeting_type' => MeetingType::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<MeetingScope, $this> */
    public function meetingScope(): BelongsTo
    {
        return $this->belongsTo(MeetingScope::class);
    }

    /** @return HasMany<ParticipantPresetItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ParticipantPresetItem::class)->orderBy('sort_order');
    }
}
