<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParticipantPresetItem extends Model
{
    protected $fillable = ['person_id', 'role_type', 'display_name', 'is_external', 'sort_order'];

    protected function casts(): array
    {
        return ['is_external' => 'boolean'];
    }

    /** @return BelongsTo<ParticipantPreset, $this> */
    public function preset(): BelongsTo
    {
        return $this->belongsTo(ParticipantPreset::class, 'participant_preset_id');
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
