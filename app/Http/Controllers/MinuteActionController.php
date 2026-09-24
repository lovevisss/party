<?php

namespace App\Http\Controllers;

use App\Models\MeetingMinute;
use App\Models\MinuteFile;
use App\Services\MinuteFileService;
use App\Services\MinutesArchiveService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MinuteActionController extends Controller
{
    public function upload(Request $request, MeetingMinute $minute, MinuteFileService $files): RedirectResponse
    {
        Gate::authorize('update', $minute);
        $request->validate(['attachment' => 'required|file|max:20480']);
        $files->store($minute, $request->file('attachment'), $request->user());

        return back()->with('success', '会议纪要已上传。');
    }

    public function archive(Request $request, MeetingMinute $minute, MinutesArchiveService $service): RedirectResponse
    {
        Gate::authorize('update', $minute);
        $data = $request->validate(
            ['archived_at' => 'required|date_format:Y-m-d\TH:i'],
            ['archived_at.required' => '请填写实际归档时间。', 'archived_at.date_format' => '归档时间格式无效。'],
        );
        $service->archive($minute, $request->user(), CarbonImmutable::parse($data['archived_at'], config('app.timezone')));

        return redirect()->route('minutes.show', $minute)->with('success', '纪要已正式归档。');
    }

    public function returnForCorrection(Request $request, MeetingMinute $minute, MinutesArchiveService $service): RedirectResponse
    {
        Gate::authorize('returnForCorrection', $minute);
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);
        $service->returnForCorrection($minute, $request->user(), $data['reason']);

        return back()->with('success', '纪要已退回修改。');
    }

    public function download(MeetingMinute $minute, MinuteFile $file): StreamedResponse
    {
        Gate::authorize('view', $minute);
        abort_unless($file->meeting_minute_id === $minute->id, 404);

        return Storage::disk(config('filesystems.default'))->download($file->object_key, $file->original_name);
    }
}
