<?php

namespace App\Models;

use App\Enums\MeetingType;
use App\Enums\UserRole;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['person_id', 'name', 'email', 'password', 'cas_account', 'is_active'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return HasMany<RoleAssignment, $this> */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function hasRole(string $role, ?MeetingType $meetingType = null): bool
    {
        return $this->roleAssignments()->where('role', $role)
            ->when($meetingType, fn ($query) => $query->where('meeting_type', $meetingType->value))->exists();
    }

    public function isSystemAdmin(): bool
    {
        return $this->hasRole(UserRole::SystemAdmin->value);
    }

    public function manages(MeetingType $type, ?int $scopeId): bool
    {
        return $this->isSystemAdmin() || ($scopeId !== null && in_array($scopeId, $this->managedScopeIds($type), true));
    }

    /** @return list<int> */
    public function managedScopeIds(MeetingType $type): array
    {
        $person = $this->person()->first();
        if (! $person || $person->status !== 'active') {
            return [];
        }

        return array_values(DB::table('role_assignments as assignments')
            ->join('meeting_scopes as scopes', 'scopes.id', '=', 'assignments.meeting_scope_id')
            ->join('meeting_scope_organizations as memberships', 'memberships.meeting_scope_id', '=', 'scopes.id')
            ->where('assignments.user_id', $this->id)->whereNull('assignments.deleted_at')
            ->where('assignments.role', UserRole::MinuteManager->value)
            ->where('assignments.meeting_type', $type->value)->where('scopes.meeting_type', $type->value)
            ->where('scopes.is_active', true)->where('memberships.organization_id', $person->organization_id)
            ->pluck('assignments.meeting_scope_id')->map(fn ($id): int => (int) $id)->unique()->all());
    }

    /** @return list<int> */
    public function meetingScopeIds(MeetingType $type): array
    {
        return array_values($this->roleAssignments()->where('role', UserRole::MinuteSubmitter->value)
            ->where('meeting_type', $type->value)->pluck('meeting_scope_id')->filter()
            ->map(fn ($id): int => (int) $id)->unique()->all());
    }

    /** @return list<MeetingType> */
    public function accessibleMeetingTypes(): array
    {
        if ($this->isSystemAdmin()) {
            return MeetingType::cases();
        }

        return array_values(array_filter(MeetingType::cases(), fn (MeetingType $type): bool => $this->managedScopeIds($type) !== [] || $this->meetingScopeIds($type) !== []));
    }
}
