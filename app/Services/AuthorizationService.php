<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Person;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthorizationService
{
    public function grant(Person $person, UserRole $role, ?string $positionLabel, ?int $grantedBy): RoleAssignment
    {
        if ($person->status !== 'active') {
            throw ValidationException::withMessages(['person_id' => '停用人员不能新增授权。']);
        }

        [$organizationId, $positionLabel, $scopeKey] = $this->scope($person, $role, $positionLabel);

        return DB::transaction(function () use ($person, $role, $organizationId, $positionLabel, $scopeKey, $grantedBy): RoleAssignment {
            $user = User::updateOrCreate(
                ['cas_account' => $person->employee_no],
                ['person_id' => $person->id, 'name' => $person->name, 'email' => $person->email ?: $person->employee_no.'@invalid.local', 'password' => bcrypt(Str::random(64)), 'is_active' => true],
            );

            if ($role === UserRole::CollegeSubmitter) {
                RoleAssignment::where('user_id', $user->id)->where('role', $role->value)->where('scope_key', '!=', $scopeKey)->delete();
            }

            $assignment = RoleAssignment::withTrashed()->firstOrNew([
                'user_id' => $user->id,
                'role' => $role->value,
                'scope_key' => $scopeKey,
            ]);
            $wasTrashed = $assignment->trashed();
            $assignment->fill([
                'organization_id' => $organizationId,
                'position_label' => $positionLabel,
                'granted_by' => $grantedBy,
            ])->save();
            if ($wasTrashed) {
                $assignment->restore();
            }

            return $assignment->fresh(['user.person.organization', 'organization']);
        });
    }

    public function revoke(RoleAssignment $assignment): void
    {
        if ($assignment->getRawOriginal('role') === UserRole::SystemAdmin->value && RoleAssignment::where('role', UserRole::SystemAdmin->value)->count() <= 1) {
            throw ValidationException::withMessages(['role' => '不能撤销最后一名系统管理员。']);
        }

        $assignment->delete();
    }

    public function revokeForPerson(Person $person, UserRole $role): ?RoleAssignment
    {
        $user = User::where('person_id', $person->id)->first();
        if (! $user) {
            return null;
        }

        $scopeKey = $role === UserRole::CollegeSubmitter ? 'org:'.$person->organization_id : 'global';
        $assignment = RoleAssignment::where('user_id', $user->id)->where('role', $role->value)->where('scope_key', $scopeKey)->first();
        if ($assignment) {
            $this->revoke($assignment);
        }

        return $assignment;
    }

    /** @return array{int|null,string|null,string} */
    private function scope(Person $person, UserRole $role, ?string $positionLabel): array
    {
        if ($role !== UserRole::CollegeSubmitter) {
            return [null, null, 'global'];
        }
        if (! in_array($positionLabel, ['组织员', '办公室主任'], true)) {
            throw ValidationException::withMessages(['position_label' => '学院提交人必须选择组织员或办公室主任。']);
        }

        return [$person->organization_id, $positionLabel, 'org:'.$person->organization_id];
    }
}
