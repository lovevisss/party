<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\ParticipantPreset;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function auditAdmin(): User
{
    $user = User::factory()->create(['name' => '审计管理员']);
    RoleAssignment::create(['user_id' => $user->id, 'role' => 'system_admin', 'scope_key' => 'global']);

    return $user;
}

test('audit list presents Chinese actions and Beijing time while retaining original details', function () {
    $admin = auditAdmin();
    $organization = Organization::create(['external_code' => 'audit-test', 'name' => '机关单位']);
    $preset = ParticipantPreset::create(['user_id' => $admin->id, 'organization_id' => $organization->id, 'name' => '旧清单']);
    $log = AuditLog::create([
        'request_id' => '00000000-0000-4000-8000-000000000001',
        'user_id' => $admin->id,
        'event' => 'participant_preset.deleted',
        'subject_type' => ParticipantPreset::class,
        'subject_id' => $preset->id,
        'ip_address' => '10.0.0.1',
        'metadata' => ['name' => '旧清单'],
        'created_at' => '2026-09-28 23:22:09',
    ]);
    $preset->delete();

    $this->actingAs($admin)->get('/admin/audit-logs?category=participant_preset')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/AuditLogs')
            ->where('logs.total', 1)
            ->where('logs.data.0.id', $log->id)
            ->where('logs.data.0.date', '2026-09-28')
            ->where('logs.data.0.time', '23:22:09')
            ->where('logs.data.0.event_label', '删除常用人员清单')
            ->where('logs.data.0.actor', '审计管理员')
            ->where('logs.data.0.subject_name', '旧清单')
            ->where('logs.data.0.request_id', $log->request_id)
            ->where('logs.data.0.ip_address', '10.0.0.1')
            ->where('logs.data.0.metadata.name', '旧清单')
            ->etc());
});

test('unknown events and missing users remain explicit without losing raw information', function () {
    $admin = auditAdmin();
    AuditLog::create([
        'event' => 'future.custom_action',
        'subject_type' => User::class,
        'subject_id' => 9999,
        'metadata' => ['source' => 'legacy'],
        'created_at' => '2026-09-28 10:00:00',
    ]);

    $this->actingAs($admin)->get('/admin/audit-logs')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('logs.data.0.event_label', '其他操作')
        ->where('logs.data.0.event', 'future.custom_action')
        ->where('logs.data.0.subject_name', '用户已失效')
        ->where('logs.data.0.subject_id', '9999')
        ->etc());
});

test('category filtering keeps the filter on later pages', function () {
    $admin = auditAdmin();
    for ($index = 0; $index < 51; $index++) {
        AuditLog::create(['event' => 'auth.login', 'created_at' => now()]);
    }
    AuditLog::create(['event' => 'workday.imported', 'created_at' => now()]);

    $this->actingAs($admin)->get('/admin/audit-logs?category=auth&page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('logs.total', 51)
        ->has('logs.data', 1)
        ->where('logs.data.0.event_label', '登录系统')
        ->where('filters.category', 'auth')
        ->etc());
});
