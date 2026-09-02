<?php

namespace App\Http\Controllers;

use App\Models\MeetingMinute;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $q = MeetingMinute::query();
        if (! $request->user()->hasRole('school_manager') && ! $request->user()->hasRole('system_admin')) {
            $q->where('created_by', $request->user()->id)
                ->whereIn('organization_id', $request->user()->organizationIds());
        }

        return Inertia::render('Dashboard', ['stats' => ['total' => (clone $q)->count(), 'draft' => (clone $q)->where('status', 'draft')->count(), 'returned' => (clone $q)->where('status', 'returned')->count(), 'overdue' => (clone $q)->where('is_overdue', true)->count()], 'recent' => (clone $q)->latest('updated_at')->limit(6)->get()]);
    }
}
