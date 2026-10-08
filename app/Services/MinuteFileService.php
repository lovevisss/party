<?php

namespace App\Services;

use App\Models\MeetingMinute;
use App\Models\MinuteFile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MinuteFileService
{
    public function validatedUpload(Request $request, bool $required = true): ?UploadedFile
    {
        $file = $request->file('attachment');
        if ($file instanceof UploadedFile && $file->getError() !== UPLOAD_ERR_OK) {
            Log::warning('会议纪要附件被 PHP 拒绝', [
                'user_id' => $request->user()?->id,
                'upload_error' => $file->getError(),
                'content_length' => $request->header('content-length'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
            ]);

            $message = in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? '服务器单文件上传限制低于附件大小，请联系管理员调整上传配置。'
                : '附件传输失败，请重新上传；如问题持续，请联系管理员。';
            throw ValidationException::withMessages(['attachment' => $message]);
        }

        $request->validate(['attachment' => ($required ? 'required' : 'nullable').'|file|max:20480']);

        return $file;
    }

    public function store(MeetingMinute $minute, UploadedFile $file, User $user): MinuteFile
    {
        $this->validateUpload($file);

        $extension = strtolower($file->getClientOriginalExtension());
        $disk = config('filesystems.default');
        $objectKey = 'minutes/'.$minute->id.'/'.Str::uuid().'.'.$extension;
        $stored = Storage::disk($disk)->putFileAs(dirname($objectKey), $file, basename($objectKey), ['visibility' => 'private']);
        if ($stored === false) {
            Log::error('会议纪要附件写入存储失败', ['minute_id' => $minute->id, 'disk' => $disk, 'size_bytes' => $file->getSize()]);
            throw new RuntimeException('会议纪要附件写入存储失败。');
        }

        return MinuteFile::create([
            'meeting_minute_id' => $minute->id,
            'original_name' => $file->getClientOriginalName(),
            'object_key' => $objectKey,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $user->id,
        ]);
    }

    public function validateUpload(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension !== 'pdf') {
            throw ValidationException::withMessages(['attachment' => '请上传主要领导签字的PDF扫描件。']);
        }
        if ($file->getSize() > 20 * 1024 * 1024) {
            throw ValidationException::withMessages(['attachment' => '附件不能超过 20 MB。']);
        }

        $header = file_get_contents($file->getRealPath(), false, null, 0, 8);
        if ($header === false) {
            throw ValidationException::withMessages(['attachment' => '无法读取上传文件。']);
        }
        $hasValidHeader = str_starts_with($header, '%PDF-');
        if (! $hasValidHeader) {
            throw ValidationException::withMessages(['attachment' => '文件内容与扩展名不一致。']);
        }

    }
}
