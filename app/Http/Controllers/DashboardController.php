<?php

namespace App\Http\Controllers;

use App\Enums\MeetingType;
use App\Enums\UserRole;
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
        if (! $user->isSystemAdmin()) {
            $managedTypes = $user->roleAssignments()->where('role', UserRole::MinuteManager->value)->toBase()->pluck('meeting_type')->filter()->all();
            $q->where(function ($access) use ($user, $managedTypes): void {
                $hasCondition = false;
                if ($managedTypes) {
                    $access->whereIn('meeting_type', $managedTypes);
                    $hasCondition = true;
                }
                foreach (MeetingType::cases() as $type) {
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

        return Inertia::render('Dashboard', ['stats' => ['total' => (clone $q)->count(), 'draft' => (clone $q)->where('status', 'draft')->count(), 'returned' => (clone $q)->where('status', 'returned')->count(), 'overdue' => (clone $q)->where('is_overdue', true)->count()], 'recent' => (clone $q)->latest('updated_at')->limit(6)->get()]);
    }
}
