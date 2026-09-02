<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Workday extends Model
{
    protected $primaryKey = 'date';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['date','is_workday','name','updated_by'];
    protected function casts(): array { return ['is_workday'=>'boolean']; }
}
