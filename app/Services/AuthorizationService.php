<?php

namespace App\Services;

use App\Enums\MeetingType;
use App\Enums\UserRole;
use App\Models\Person;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthorizationService
{
    public function __construct(private readonly MeetingScopeService $scopes) {}

    public function grant(Person $person, UserRole $role, ?MeetingType $meetingType, ?int $grantedBy): RoleAssignment
    {
        if ($person->status !== 'active') {
            throw ValidationException::withMessages(['person_id' => '停用人员不能新增授权。']);
        }

        [$scopeId, $scopeKey] = $this->scope($person, $role, $meetingType);

        return DB::transaction(function () use ($person, $role, $meetingType, $scopeId, $scopeKey, $grantedBy): RoleAssignment {
            $user = User::updateOrCreate(
                ['cas_account' => $person->employee_no],
                ['person_id' => $person->id, 'name' => $person->name, 'email' => $person->email ?: $person->employee_no.'@invalid.local', 'password' => bcrypt(Str::random(64)), 'is_active' => true],
            );

            $assignment = RoleAssignment::withTrashed()->firstOrNew([
                'user_id' => $user->id,
                'role' => $role->value,
                'scope_key' => $scopeKey,
            ]);
            $wasTrashed = $assignment->trashed();
            $assignment->fill([
                'organization_id' => null,
                'meeting_type' => $meetingType?->value,
                'meeting_scope_id' => $scopeId,
                'position_label' => null,
                'granted_by' => $grantedBy,
            ])->save();
            if ($wasTrashed) {
                $assignment->restore();
            }

            return $assignment->fresh(['user.person.organization', 'meetingScope']);
        });
    }

    public function revoke(RoleAssignment $assignment): void
    {
        if ($assignment->getRawOriginal('role') === UserRole::SystemAdmin->value && RoleAssignment::where('role', UserRole::SystemAdmin->value)->count() <= 1) {
            throw ValidationException::withMessages(['role' => '不能撤销最后一名系统管理员。']);
        }

        $assignment->delete();
    }

    public function revokeForPerson(Person $person, UserRole $role, ?MeetingType $meetingType): ?RoleAssignment
    {
        $user = User::where('person_id', $person->id)->first();
        if (! $user) {
            return null;
        }

        [, $scopeKey] = $this->scope($person, $role, $meetingType);
        $assignment = RoleAssignment::where('user_id', $user->id)->where('role', $role->value)->where('scope_key', $scopeKey)->first();
        if ($assignment) {
            $this->revoke($assignment);
        }

        return $assignment;
    }

    /** @return array{int|null,string} */
    private function scope(Person $person, UserRole $role, ?MeetingType $meetingType): array
    {
        if ($role === UserRole::SystemAdmin) {
            return [null, 'global'];
        }
        if (! $meetingType) {
            throw ValidationException::withMessages(['meeting_type' => '请选择会议类型。']);
        }
        if ($role === UserRole::MinuteManager) {
            return [null, 'meeting:'.$meetingType->value.':global'];
        }

        $scope = $this->scopes->scopeForPerson($person, $meetingType);
        if (! $scope) {
            throw ValidationException::withMessages(['person_id' => '该人员所属单位未映射到所选会议类型，不能授予提交权限。']);
        }

        return [$scope->id, 'meeting:'.$meetingType->value.':scope:'.$scope->id];
    }
}
