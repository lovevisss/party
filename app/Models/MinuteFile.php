<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MinuteFile extends Model
{
    use HasUuids;

    protected $fillable = ['meeting_minute_id', 'minute_version_id', 'version_no', 'original_name', 'object_key', 'mime_type', 'size_bytes', 'sha256', 'scan_status', 'uploaded_by'];
}
