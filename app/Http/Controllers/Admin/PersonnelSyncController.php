<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller; use App\Jobs\SyncPersonnelJob; use App\Models\SyncRun; use App\Services\AuditService; use Illuminate\Http\RedirectResponse; use Illuminate\Http\Request; use Inertia\Inertia; use Inertia\Response;
class PersonnelSyncController extends Controller { public function index(): Response{return Inertia::render('admin/PersonnelSync',['runs'=>SyncRun::latest()->paginate(20)]);} public function store(Request $request,AuditService $audit): RedirectResponse{SyncPersonnelJob::dispatch($request->user()->id);$audit->record('personnel.sync_queued');return back()->with('success','人员同步任务已进入队列。');} }
