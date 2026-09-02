<?php
namespace App\Policies;
use App\Models\MeetingMinute; use App\Models\User;
class MeetingMinutePolicy
{
    public function viewAny(User $user): bool{return $user->roleAssignments()->exists();}
    public function view(User $user,MeetingMinute $minute): bool{return $user->hasRole('school_manager')||$user->hasRole('system_admin')||in_array($minute->organization_id,$user->organizationIds());}
    public function create(User $user): bool{return $user->hasRole('college_submitter')&&count($user->organizationIds())>0;}
    public function update(User $user,MeetingMinute $minute): bool{return in_array($minute->organization_id,$user->organizationIds())&&in_array($minute->status->value,['draft','returned']);}
    public function returnForCorrection(User $user,MeetingMinute $minute): bool{return $user->hasRole('school_manager')&&$minute->status->value==='archived';}
}
