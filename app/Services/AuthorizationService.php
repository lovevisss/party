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

    public function grant(Person $person, UserRole $role, ?MeetingType $meetingType, ?int $grantedBy, ?User $existingUser = null): RoleAssignment
    {
        if ($person->status !== 'active') {
            throw ValidationException::withMessages(['person_id' => '停用人员不能新增授权。']);
        }

        if (in_array($role, [UserRole::SystemAdmin, UserRole::GlobalAdmin], true)) {
            $meetingType = null;
        }

        [$scopeId, $scopeKey] = $this->scope($person, $role, $meetingType);

        return DB::transaction(function () use ($person, $role, $meetingType, $scopeId, $scopeKey, $grantedBy, $existingUser): RoleAssignment {
            $user = $existingUser ?? User::updateOrCreate(
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

    public function replace(RoleAssignment $assignment, UserRole $role, ?MeetingType $meetingType, int $grantedBy): RoleAssignment
    {
        return DB::transaction(function () use ($assignment, $role, $meetingType, $grantedBy): RoleAssignment {
            $current = RoleAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if ($current->getRawOriginal('role') === $role->value) {
                throw ValidationException::withMessages(['role' => '请选择其他角色。']);
            }
            if ($current->getRawOriginal('role') === UserRole::SystemAdmin->value && RoleAssignment::where('role', UserRole::SystemAdmin->value)->count() <= 1) {
                throw ValidationException::withMessages(['role' => '不能调整最后一名系统管理员。']);
            }

            $person = $current->user()->firstOrFail()->person()->first();
            if (! $person || $person->status !== 'active') {
                throw ValidationException::withMessages(['person_id' => '停用或未同步人员不能调整授权。']);
            }

            if (in_array($role, [UserRole::SystemAdmin, UserRole::GlobalAdmin], true)) {
                $meetingType = null;
            }
            [, $scopeKey] = $this->scope($person, $role, $meetingType);
            if (RoleAssignment::where('user_id', $current->user_id)->where('role', $role->value)->where('scope_key', $scopeKey)->exists()) {
                throw ValidationException::withMessages(['role' => '该人员已拥有所选角色和会议类型的授权。']);
            }

            $replacement = $this->grant($person, $role, $meetingType, $grantedBy, $current->user);
            $this->revoke($current);

            return $replacement;
        });
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
        if (in_array($role, [UserRole::SystemAdmin, UserRole::GlobalAdmin], true)) {
            return [null, 'global'];
        }
        if (! $meetingType) {
            throw ValidationException::withMessages(['meeting_type' => '请选择会议类型。']);
        }
        $scope = $this->scopes->scopeForPerson($person, $meetingType);
        if (! $scope) {
            throw ValidationException::withMessages(['person_id' => '该人员所属单位未映射到所选会议类型，不能授予权限。']);
        }

        return [$scope->id, 'meeting:'.$meetingType->value.':scope:'.$scope->id];
    }
}
