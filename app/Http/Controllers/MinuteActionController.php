<?php

namespace App\Http\Controllers;

use App\Enums\MinuteStatus;
use App\Models\MeetingMinute;
use App\Models\MinuteFile;
use App\Services\MinuteFileService;
use App\Services\MinutesArchiveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MinuteActionController extends Controller
{
    public function upload(Request $request, MeetingMinute $minute, MinuteFileService $files): RedirectResponse
    {
        Gate::authorize('update', $minute);
        $attachment = $files->validatedUpload($request);
        $files->store($minute, $attachment, $request->user());

        return back()->with('success', '会议纪要已上传。');
    }

    public function destroyFile(MeetingMinute $minute, MinuteFile $file): RedirectResponse
    {
        DB::transaction(function () use ($minute, $file): void {
            $minute = MeetingMinute::whereKey($minute->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $minute);
            abort_unless($minute->getRawOriginal('status') === MinuteStatus::Draft->value, 403);

            $file = $minute->files()->whereKey($file->id)
                ->whereNull('version_no')->whereNull('minute_version_id')
                ->lockForUpdate()->firstOrFail();
            $disk = Storage::disk(config('filesystems.default'));
            if ($disk->exists($file->object_key) && ! $disk->delete($file->object_key)) {
                throw ValidationException::withMessages(['attachment' => '附件删除失败，请稍后重试。']);
            }
            $file->delete();
        });

        return back()->with('success', '待归档附件已删除。');
    }

    public function archive(Request $request, MeetingMinute $minute, MinutesArchiveService $service): RedirectResponse
    {
        Gate::authorize('update', $minute);
        $service->archive($minute, $request->user());

        return redirect()->route('minutes.show', $minute)->with('success', '纪要已正式归档。');
    }

    public function returnForCorrection(Request $request, MeetingMinute $minute, MinutesArchiveService $service): RedirectResponse
    {
        Gate::authorize('returnForCorrection', $minute);
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);
        $service->returnForCorrection($minute, $request->user(), $data['reason']);

        if ($request->user()->isSystemAdmin()) {
            return redirect()->route('minutes.type.index', $minute->meeting_type->slug())->with('success', '纪要已退回修改。');
        }

        return back()->with('success', '纪要已退回修改。');
    }

    public function download(MeetingMinute $minute, MinuteFile $file): StreamedResponse
    {
        $this->authorizeFileAccess($minute, $file);

        return Storage::disk(config('filesystems.default'))->download($file->object_key, $file->original_name);
    }

    public function preview(MeetingMinute $minute, MinuteFile $file): StreamedResponse
    {
        $this->authorizeFileAccess($minute, $file);
        abort_unless($file->mime_type === 'application/pdf' && str_ends_with(strtolower($file->object_key), '.pdf'), 404);

        $disk = Storage::disk(config('filesystems.default'));
        abort_unless($disk->exists($file->object_key), 404);

        return $disk->response($file->object_key, $file->original_name, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    private function authorizeFileAccess(MeetingMinute $minute, MinuteFile $file): void
    {
        Gate::authorize('view', $minute);
        abort_unless($file->meeting_minute_id === $minute->id, 404);
        if (request()->user()->hasGlobalMinuteAccess()) {
            abort_unless($file->version_no === $minute->current_version, 403);
        }
    }
}
