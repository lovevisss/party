<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\AuthorizationImportController;
use App\Http\Controllers\Admin\PersonnelSyncController;
use App\Http\Controllers\Admin\WorkdayController;
use App\Http\Controllers\Auth\CasController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MeetingMinuteController;
use App\Http\Controllers\MinuteActionController;
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
    Route::resource('minutes', MeetingMinuteController::class)->except(['destroy']);
    Route::post('/minutes/{minute}/attachment', [MinuteActionController::class, 'upload'])->name('minutes.attachment');
    Route::post('/minutes/{minute}/archive', [MinuteActionController::class, 'archive'])->name('minutes.archive');
    Route::post('/minutes/{minute}/return', [MinuteActionController::class, 'returnForCorrection'])->name('minutes.return');
    Route::get('/minutes/{minute}/files/{file}', [MinuteActionController::class, 'download'])->name('minutes.files.download');

    Route::prefix('admin')->name('admin.')->middleware('role:system_admin')->group(function () {
        Route::get('/personnel-sync', [PersonnelSyncController::class, 'index'])->name('personnel-sync.index');
        Route::post('/personnel-sync', [PersonnelSyncController::class, 'store'])->name('personnel-sync.store');
        Route::get('/authorization-import', [AuthorizationImportController::class, 'index'])->name('authorization-import.index');
        Route::post('/authorization-import/preview', [AuthorizationImportController::class, 'preview'])->name('authorization-import.preview');
        Route::post('/authorization-import/{batch}/commit', [AuthorizationImportController::class, 'commit'])->name('authorization-import.commit');
        Route::post('/workdays/import', [WorkdayController::class, 'import'])->name('workdays.import');
        Route::resource('workdays', WorkdayController::class)->only(['index', 'store', 'destroy']);
        Route::get('/audit-logs', AuditLogController::class)->name('audit-logs.index');
    });
});
