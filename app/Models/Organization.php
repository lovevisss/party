<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $fillable = ['external_code', 'name', 'is_active'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function people(): HasMany { return $this->hasMany(Person::class); }
}
