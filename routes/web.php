<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\AuthorizationController;
use App\Http\Controllers\Admin\AuthorizationImportController;
use App\Http\Controllers\Admin\PersonnelSyncController;
use App\Http\Controllers\Admin\WorkdayController;
use App\Http\Controllers\Auth\CasController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MeetingMinuteController;
use App\Http\Controllers\MinuteDeadlineController;
use App\Http\Controllers\MinuteActionController;
use App\Http\Controllers\ParticipantPresetController;
use App\Http\Controllers\PersonSearchController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');
Route::get('/auth/cas/login', [CasController::class, 'login'])->name('cas.login');
Route::get('/auth/cas/callback', [CasController::class, 'callback'])->name('cas.callback');
Route::post('/auth/cas/back-channel-logout', [CasController::class, 'backChannelLogout'])->name('cas.back-channel-logout');
Route::get('/access-denied', [CasController::class, 'denied'])->name('access.denied');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/auth/logout', [CasController::class, 'logout'])->name('cas.logout');
    Route::get('/people/search', PersonSearchController::class)->name('people.search');
    Route::post('/participant-presets', [ParticipantPresetController::class, 'store'])->name('participant-presets.store');
    Route::delete('/participant-presets/{participantPreset}', [ParticipantPresetController::class, 'destroy'])->name('participant-presets.destroy');
    Route::get('/minutes', [MeetingMinuteController::class, 'redirectToType'])->name('minutes.index');
    Route::get('/minutes/deadline', MinuteDeadlineController::class)->name('minutes.deadline');
    Route::get('/minutes/{meetingType}', [MeetingMinuteController::class, 'index'])->whereIn('meetingType', ['party-branch', 'party-government-joint'])->name('minutes.type.index');
    Route::get('/minutes/{meetingType}/create', [MeetingMinuteController::class, 'create'])->whereIn('meetingType', ['party-branch', 'party-government-joint'])->name('minutes.create');
    Route::post('/minutes/{meetingType}', [MeetingMinuteController::class, 'store'])->whereIn('meetingType', ['party-branch', 'party-government-joint'])->name('minutes.store');
    Route::get('/minutes/{minute}/edit', [MeetingMinuteController::class, 'edit'])->whereUuid('minute')->name('minutes.edit');
    Route::put('/minutes/{minute}', [MeetingMinuteController::class, 'update'])->whereUuid('minute')->name('minutes.update');
    Route::get('/minutes/{minute}', [MeetingMinuteController::class, 'show'])->whereUuid('minute')->name('minutes.show');
    Route::post('/minutes/{minute}/attachment', [MinuteActionController::class, 'upload'])->whereUuid('minute')->name('minutes.attachment');
    Route::post('/minutes/{minute}/archive', [MinuteActionController::class, 'archive'])->whereUuid('minute')->name('minutes.archive');
    Route::post('/minutes/{minute}/return', [MinuteActionController::class, 'returnForCorrection'])->whereUuid('minute')->name('minutes.return');
    Route::get('/minutes/{minute}/files/{file}', [MinuteActionController::class, 'download'])->whereUuid('minute')->name('minutes.files.download');

    Route::prefix('admin')->name('admin.')->middleware('role:system_admin')->group(function () {
        Route::get('/personnel-sync', [PersonnelSyncController::class, 'index'])->name('personnel-sync.index');
        Route::post('/personnel-sync', [PersonnelSyncController::class, 'store'])->name('personnel-sync.store');
        Route::get('/authorization-import', [AuthorizationImportController::class, 'index'])->name('authorization-import.index');
        Route::get('/authorization-import/template', [AuthorizationImportController::class, 'template'])->name('authorization-import.template');
        Route::post('/authorization-import/preview', [AuthorizationImportController::class, 'preview'])->name('authorization-import.preview');
        Route::post('/authorization-import/{batch}/commit', [AuthorizationImportController::class, 'commit'])->name('authorization-import.commit');
        Route::post('/authorizations', [AuthorizationController::class, 'store'])->name('authorizations.store');
        Route::delete('/authorizations/{assignment}', [AuthorizationController::class, 'destroy'])->name('authorizations.destroy');
        Route::post('/workdays/import', [WorkdayController::class, 'import'])->name('workdays.import');
        Route::resource('workdays', WorkdayController::class)->only(['index', 'store', 'destroy']);
        Route::get('/audit-logs', AuditLogController::class)->name('audit-logs.index');
    });
});
