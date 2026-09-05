<?php

namespace App\Models;

use App\Enums\MeetingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property MeetingType $meeting_type */
class MeetingScope extends Model
{
    protected $fillable = ['meeting_type', 'code', 'name', 'display_order', 'is_active'];

    protected function casts(): array
    {
        return ['meeting_type' => MeetingType::class, 'is_active' => 'boolean'];
    }

    /** @return BelongsToMany<Organization, $this> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'meeting_scope_organizations');
    }

    /** @return HasMany<MeetingMinute, $this> */
    public function minutes(): HasMany
    {
        return $this->hasMany(MeetingMinute::class);
    }
}
