<?php
namespace App\Services;
use App\Enums\MinuteStatus; use App\Models\MeetingMinute; use App\Models\MinuteVersion; use App\Models\ReturnRecord; use App\Models\User; use Illuminate\Support\Facades\DB; use Illuminate\Validation\ValidationException;
class MinutesArchiveService
{
    public function __construct(private WorkdayService $workdays,private AuditService $audit){}
    public function archive(MeetingMinute $minute,User $user): MeetingMinute
    {
        return DB::transaction(function()use($minute,$user){
            $minute=MeetingMinute::lockForUpdate()->findOrFail($minute->id);
            if(!in_array($minute->status,[MinuteStatus::Draft,MinuteStatus::Returned],true))throw ValidationException::withMessages(['status'=>'仅草稿或已退回记录可以归档。']);
            validator($minute->toArray(),['meeting_year'=>'required|integer|min:2000|max:2100','sequence_no'=>'required|integer|min:1|max:999','title'=>'required|string|max:200','meeting_start_at'=>'required|date','meeting_end_at'=>'required|date|after:meeting_start_at','first_topic_content'=>'required|string|max:20000'])->validate();
            foreach(['chair','recorder','attendee']as$role)if(!$minute->participants()->where('role_type',$role)->exists())throw ValidationException::withMessages(['participants'=>"必须至少选择一名{$role}人员。"]); 
            $file=$minute->files()->whereNull('version_no')->latest()->first();if(!$file)throw ValidationException::withMessages(['attachment'=>'归档前必须上传正式纪要附件。']);
            $version=$minute->current_version+1;$now=now();$due=$minute->due_at?:$this->workdays->thirdWorkdayAfter($minute->meeting_end_at);$overdue=$minute->is_overdue??$now->greaterThan($due);
            $snapshot=['minute'=>$minute->only(['organization_id','meeting_type','meeting_year','sequence_no','title','meeting_start_at','meeting_end_at','first_topic_content','remarks']),'participants'=>$minute->participants()->get()->map->only(['person_id','role_type','display_name','is_external'])->all()];
            $versionModel=MinuteVersion::create(['meeting_minute_id'=>$minute->id,'version_no'=>$version,'snapshot'=>$snapshot,'due_at'=>$due,'is_overdue'=>$overdue,'archived_by'=>$user->id,'archived_at'=>$now]);
            $file->update(['minute_version_id'=>$versionModel->id,'version_no'=>$version]);
            $minute->update(['status'=>MinuteStatus::Archived,'current_version'=>$version,'due_at'=>$due,'is_overdue'=>$overdue,'archived_at'=>$minute->archived_at?:$now,'resubmitted_at'=>$version>1?$now:null,'lock_version'=>$minute->lock_version+1,'updated_by'=>$user->id]);
            $this->audit->record($version>1?'minutes.resubmitted':'minutes.archived',$minute,['version'=>$version]);return $minute->fresh(['participants','files','versions']);
        });
    }
    public function returnForCorrection(MeetingMinute $minute,User $user,string $reason): MeetingMinute
    {
        return DB::transaction(function()use($minute,$user,$reason){$minute=MeetingMinute::lockForUpdate()->findOrFail($minute->id);if($minute->status!==MinuteStatus::Archived)throw ValidationException::withMessages(['status'=>'仅已归档记录可以退回。']);ReturnRecord::create(['meeting_minute_id'=>$minute->id,'version_no'=>$minute->current_version,'reason'=>$reason,'returned_by'=>$user->id,'returned_at'=>now()]);$minute->update(['status'=>MinuteStatus::Returned,'lock_version'=>$minute->lock_version+1,'updated_by'=>$user->id]);$this->audit->record('minutes.returned',$minute,['reason'=>$reason,'version'=>$minute->current_version]);return $minute->fresh();});
    }
}
