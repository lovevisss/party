<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MinuteParticipant extends Model
{
    protected $fillable = ['meeting_minute_id','person_id','role_type','display_name','is_external'];
    protected function casts(): array { return ['is_external'=>'boolean']; }
}
