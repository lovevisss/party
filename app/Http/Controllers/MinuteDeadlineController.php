<?php

namespace App\Http\Controllers;

use App\Services\WorkdayService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MinuteDeadlineController extends Controller
{
    public function __invoke(Request $request, WorkdayService $workdays): JsonResponse
    {
        $data = $request->validate(['meeting_end_at' => 'required|date_format:Y-m-d\TH:i']);
        $meetingEnd = CarbonImmutable::parse($data['meeting_end_at'], config('app.timezone'));

        return response()->json([
            'due_at' => $workdays->thirdWorkdayAfter($meetingEnd)->format('Y-m-d\TH:i:sP'),
        ]);
    }
}
