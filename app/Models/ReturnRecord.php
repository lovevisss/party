<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnRecord extends Model
{
    protected $fillable = ['meeting_minute_id', 'version_no', 'reason', 'returned_by', 'returned_at'];

    protected function casts(): array
    {
        return ['returned_at' => 'datetime'];
    }
}
