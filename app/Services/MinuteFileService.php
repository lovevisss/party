<?php
namespace App\Services;
use App\Models\MeetingMinute; use App\Models\MinuteFile; use App\Models\User; use Illuminate\Http\UploadedFile; use Illuminate\Support\Facades\Storage; use Illuminate\Support\Str; use Illuminate\Validation\ValidationException;
class MinuteFileService
{
    public function store(MeetingMinute $minute,UploadedFile $file,User $user): MinuteFile
    {
        $ext=strtolower($file->getClientOriginalExtension());if(!in_array($ext,['doc','docx','pdf']))throw ValidationException::withMessages(['attachment'=>'仅支持 DOC、DOCX 或 PDF。']);if($file->getSize()>20*1024*1024)throw ValidationException::withMessages(['attachment'=>'附件不能超过 20 MB。']);
        $head=file_get_contents($file->getRealPath(),false,null,0,8);$valid=$ext==='pdf'?str_starts_with($head,'%PDF-'):($ext==='docx'?str_starts_with($head,"PK\x03\x04"):str_starts_with($head,"\xD0\xCF\x11\xE0"));if(!$valid)throw ValidationException::withMessages(['attachment'=>'文件内容与扩展名不一致。']);
        $disk=config('filesystems.default');$key='minutes/'.$minute->id.'/'.Str::uuid().'.'.$ext;Storage::disk($disk)->putFileAs(dirname($key),$file,basename($key),['visibility'=>'private']);
        return MinuteFile::create(['meeting_minute_id'=>$minute->id,'original_name'=>$file->getClientOriginalName(),'object_key'=>$key,'mime_type'=>$file->getMimeType()?:'application/octet-stream','size_bytes'=>$file->getSize(),'sha256'=>hash_file('sha256',$file->getRealPath()),'uploaded_by'=>$user->id]);
    }
}
