<?php

namespace App\Http\Controllers;

use App\Enums\MeetingType;
use App\Enums\MinuteStatus;
use App\Models\MeetingMinute;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $q = MeetingMinute::query()->with('meetingScope');
        if ($user->isSystemAdmin()) {
            $q->where('status', MinuteStatus::Archived->value);
        } else {
            $q->where(function ($access) use ($user): void {
                $hasCondition = false;
                foreach (MeetingType::cases() as $type) {
                    $managedScopeIds = $user->managedScopeIds($type);
                    if ($managedScopeIds) {
                        $method = $hasCondition ? 'orWhere' : 'where';
                        $access->{$method}(fn ($managed) => $managed->where('meeting_type', $type->value)->whereIn('meeting_scope_id', $managedScopeIds));
                        $hasCondition = true;
                    }
                    $scopeIds = $user->meetingScopeIds($type);
                    if ($scopeIds) {
                        $method = $hasCondition ? 'orWhere' : 'where';
                        $access->{$method}(fn ($own) => $own->where('meeting_type', $type->value)->where('created_by', $user->id)->whereIn('meeting_scope_id', $scopeIds));
                        $hasCondition = true;
                    }
                }
                if (! $hasCondition) {
                    $access->whereRaw('1 = 0');
                }
            });
        }

        $stats = $user->isSystemAdmin()
            ? ['total' => (clone $q)->count(), 'on_time' => (clone $q)->where('is_overdue', false)->count(), 'overdue' => (clone $q)->where('is_overdue', true)->count()]
            : ['total' => (clone $q)->count(), 'draft' => (clone $q)->where('status', 'draft')->count(), 'returned' => (clone $q)->where('status', 'returned')->count(), 'overdue' => (clone $q)->where('is_overdue', true)->count()];

        return Inertia::render('Dashboard', ['stats' => $stats, 'isSystemAdmin' => $user->isSystemAdmin(), 'recent' => (clone $q)->latest('updated_at')->limit(6)->get()]);
    }
}
