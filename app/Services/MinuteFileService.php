<?php

namespace App\Services;

use App\Models\MeetingMinute;
use App\Models\MinuteFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MinuteFileService
{
    public function store(MeetingMinute $minute, UploadedFile $file, User $user): MinuteFile
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['doc', 'docx', 'pdf'], true)) {
            throw ValidationException::withMessages(['attachment' => '仅支持 DOC、DOCX 或 PDF 文件。']);
        }
        if ($file->getSize() > 20 * 1024 * 1024) {
            throw ValidationException::withMessages(['attachment' => '附件不能超过 20 MB。']);
        }

        $header = file_get_contents($file->getRealPath(), false, null, 0, 8);
        if ($header === false) {
            throw ValidationException::withMessages(['attachment' => '无法读取上传文件。']);
        }
        $hasValidHeader = match ($extension) {
            'pdf' => str_starts_with($header, '%PDF-'),
            'docx' => str_starts_with($header, "PK\x03\x04"),
            'doc' => str_starts_with($header, "\xD0\xCF\x11\xE0"),
        };
        if (! $hasValidHeader) {
            throw ValidationException::withMessages(['attachment' => '文件内容与扩展名不一致。']);
        }

        $disk = config('filesystems.default');
        $objectKey = 'minutes/'.$minute->id.'/'.Str::uuid().'.'.$extension;
        Storage::disk($disk)->putFileAs(dirname($objectKey), $file, basename($objectKey), ['visibility' => 'private']);

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
}
