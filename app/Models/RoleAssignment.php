<?php

namespace App\Models;

use App\Enums\MeetingType;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property MeetingType|null $meeting_type */
class RoleAssignment extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'role', 'meeting_type', 'meeting_scope_id', 'organization_id', 'position_label', 'scope_key', 'granted_by'];

    protected function casts(): array
    {
        return ['role' => UserRole::class, 'meeting_type' => MeetingType::class];
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
}
