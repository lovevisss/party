<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    use HasUuids;
    protected $fillable = ['type','status','payload','errors','created_by','committed_at'];
    protected function casts(): array { return ['payload'=>'array','errors'=>'array','committed_at'=>'datetime']; }
}
