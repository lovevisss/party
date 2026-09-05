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

    public function manages(MeetingType $type): bool
    {
        return $this->isSystemAdmin() || $this->hasRole(UserRole::MinuteManager->value, $type);
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

        return array_values($this->roleAssignments()->whereNotNull('meeting_type')->pluck('meeting_type')->unique()
            ->map(fn (string|MeetingType $type): MeetingType => $type instanceof MeetingType ? $type : MeetingType::from($type))->values()->all());
    }
}
