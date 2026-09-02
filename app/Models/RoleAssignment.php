<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RoleAssignment extends Model
{
    use SoftDeletes;
    protected $fillable = ['user_id', 'role', 'organization_id', 'granted_by'];
    protected function casts(): array { return ['role' => UserRole::class]; }
}
