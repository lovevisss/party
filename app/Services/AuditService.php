<?php
namespace App\Services;
use App\Models\AuditLog; use Illuminate\Database\Eloquent\Model; use Illuminate\Http\Request;
class AuditService { public function record(string $event, ?Model $subject=null, array $metadata=[], ?Request $request=null): void { $request??=request(); AuditLog::create(['request_id'=>$request?->attributes->get('request_id'),'user_id'=>$request?->user()?->id,'event'=>$event,'subject_type'=>$subject?->getMorphClass(),'subject_id'=>$subject?->getKey(),'ip_address'=>$request?->ip(),'user_agent'=>mb_substr((string)$request?->userAgent(),0,500),'metadata'=>$metadata,'created_at'=>now()]); } }
