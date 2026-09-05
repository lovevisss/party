<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\MeetingMinute;
use App\Models\User;

class MeetingMinutePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->roleAssignments()->exists();
    }

    public function view(User $user, MeetingMinute $minute): bool
    {
        $type = $minute->meeting_type;

        return $user->manages($type)
            || ($minute->created_by === $user->id
                && in_array($minute->meeting_scope_id, $user->meetingScopeIds($type), true));
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::MinuteSubmitter->value);
    }

    public function update(User $user, MeetingMinute $minute): bool
    {
        return $user->hasRole(UserRole::MinuteSubmitter->value, $minute->meeting_type)
            && $minute->created_by === $user->id
            && in_array($minute->meeting_scope_id, $user->meetingScopeIds($minute->meeting_type), true)
            && in_array($minute->getRawOriginal('status'), ['draft', 'returned']);
    }

    public function returnForCorrection(User $user, MeetingMinute $minute): bool
    {
        return $user->manages($minute->meeting_type) && $minute->getRawOriginal('status') === 'archived';
    }
}
