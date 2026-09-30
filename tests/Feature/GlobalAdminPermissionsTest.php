<?php

use App\Enums\MeetingType;
use App\Enums\UserRole;
use App\Models\ImportBatch;
use App\Models\MeetingMinute;
use App\Models\MeetingScope;
use App\Models\MinuteFile;
use App\Models\Organization;
use App\Models\Person;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function globalTestUser(UserRole $role): User
{
    $user = User::factory()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role' => $role, 'scope_key' => 'global']);

    return $user;
}

function globalTestMinute(User $owner, Organization $organization, MeetingType $type, string $status): MeetingMinute
{
    $scope = MeetingScope::where('meeting_type', $type->value)->firstOrFail();
    $scope->organizations()->syncWithoutDetaching([$organization->id]);

    return MeetingMinute::create([
        'organization_id' => $organization->id,
        'meeting_scope_id' => $scope->id,
        'meeting_type' => $type,
        'title' => $type->value.' '.$status,
        'status' => $status,
        'current_version' => $status === 'archived' ? 2 : 0,
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
}

test('system administrator can grant revoke and import global administrator without meeting scope', function () {
    $admin = globalTestUser(UserRole::SystemAdmin);
    $organization = Organization::create(['external_code' => 'GLOBAL-ROLE', 'name' => '测试单位']);
    $person = Person::create(['organization_id' => $organization->id, 'external_id' => 'GLOBAL-1', 'employee_no' => 'GLOBAL-1', 'name' => '全局甲', 'status' => 'active']);

    $this->actingAs($admin)->post('/admin/authorizations', ['person_id' => $person->id, 'role' => 'global_admin', 'meeting_type' => 'party_branch'])->assertSessionHasNoErrors();
    $assignment = RoleAssignment::where('role', 'global_admin')->firstOrFail();
    expect($assignment->meeting_type)->toBeNull()->and($assignment->meeting_scope_id)->toBeNull()->and($assignment->scope_key)->toBe('global');
    $this->actingAs($admin)->get('/admin/authorization-import?role=global_admin')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('assignments.total', 1)->etc());
    $this->actingAs($admin)->delete('/admin/authorizations/'.$assignment->id)->assertSessionHasNoErrors();
    expect(RoleAssignment::where('role', 'global_admin')->count())->toBe(0);

    $csv = "工号/统一账号,姓名,会议类型,权限角色,启用状态\nGLOBAL-1,全局甲,,全局管理员,启用\n";
    $this->actingAs($admin)->post('/admin/authorization-import/preview', ['file' => UploadedFile::fake()->createWithContent('global.csv', $csv)])->assertSessionHasNoErrors();
    $batch = ImportBatch::where('type', 'authorization')->latest()->firstOrFail();
    expect($batch->status)->toBe('preview')->and($batch->payload[0]['meeting_type'])->toBeNull()->and($batch->payload[0]['role'])->toBe('global_admin');
    $this->actingAs($admin)->post('/admin/authorization-import/'.$batch->id.'/commit')->assertSessionHasNoErrors();
    expect(RoleAssignment::where('role', 'global_admin')->count())->toBe(1);
});

test('global administrator sees archived minutes of both types and only current version files', function () {
    Storage::fake(config('filesystems.default'));
    $global = globalTestUser(UserRole::GlobalAdmin);
    $owner = User::factory()->create();
    $organization = Organization::create(['external_code' => 'GLOBAL-VIEW', 'name' => '测试单位']);
    $branch = globalTestMinute($owner, $organization, MeetingType::PartyBranch, 'archived');
    $joint = globalTestMinute($owner, $organization, MeetingType::PartyGovernmentJoint, 'archived');
    $draft = globalTestMinute($owner, $organization, MeetingType::PartyBranch, 'draft');
    $current = MinuteFile::create(['meeting_minute_id' => $branch->id, 'version_no' => 2, 'original_name' => 'current.pdf', 'object_key' => 'global/current.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 3, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $owner->id]);
    $older = MinuteFile::create(['meeting_minute_id' => $branch->id, 'version_no' => 1, 'original_name' => 'older.pdf', 'object_key' => 'global/older.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 3, 'sha256' => str_repeat('b', 64), 'uploaded_by' => $owner->id]);
    Storage::put('global/current.pdf', 'pdf');
    Storage::put('global/older.pdf', 'pdf');

    $this->actingAs($global)->get('/dashboard')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('stats.total', 2)->where('auth.meeting_types', fn ($types) => count($types) === 2)->etc());
    $this->actingAs($global)->get('/minutes/party-branch')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('minutes.total', 1)->where('canCreate', false)->etc());
    $this->actingAs($global)->get('/minutes/party-government-joint')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('minutes.total', 1)->etc());
    $this->actingAs($global)->get('/minutes/'.$branch->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('minute.files', 1)->where('minute.files.0.id', $current->id)->where('canDelete', false)->where('canReturn', false)->etc());
    $this->actingAs($global)->get('/minutes/'.$joint->id)->assertOk();
    $this->actingAs($global)->get('/minutes/'.$draft->id)->assertForbidden();
    $this->actingAs($global)->get('/minutes/'.$branch->id.'/files/'.$current->id)->assertOk();
    $this->actingAs($global)->get('/minutes/'.$branch->id.'/files/'.$older->id)->assertForbidden();
});

test('global administrator cannot access management or minute mutation endpoints even with a submitter role', function () {
    $global = globalTestUser(UserRole::GlobalAdmin);
    $organization = Organization::create(['external_code' => 'GLOBAL-DENY', 'name' => '测试单位']);
    $person = Person::create(['organization_id' => $organization->id, 'external_id' => 'GLOBAL-DENY-1', 'employee_no' => 'GLOBAL-DENY-1', 'name' => '全局乙', 'status' => 'active']);
    $global->update(['person_id' => $person->id]);
    $scope = MeetingScope::where('meeting_type', MeetingType::PartyBranch->value)->firstOrFail();
    $scope->organizations()->syncWithoutDetaching([$organization->id]);
    RoleAssignment::create(['user_id' => $global->id, 'role' => UserRole::MinuteSubmitter, 'meeting_type' => MeetingType::PartyBranch, 'meeting_scope_id' => $scope->id, 'scope_key' => 'meeting:party_branch:scope:'.$scope->id]);
    RoleAssignment::create(['user_id' => $global->id, 'role' => UserRole::MinuteManager, 'meeting_type' => MeetingType::PartyBranch, 'meeting_scope_id' => $scope->id, 'scope_key' => 'meeting:party_branch:scope:'.$scope->id]);
    $archived = globalTestMinute($global, $organization, MeetingType::PartyBranch, 'archived');
    $draft = globalTestMinute($global, $organization, MeetingType::PartyBranch, 'draft');

    foreach (['/admin/personnel-sync', '/admin/authorization-import', '/admin/workdays', '/admin/audit-logs', '/admin/role-permissions'] as $path) {
        $this->actingAs($global)->get($path)->assertForbidden();
    }
    $this->actingAs($global)->post('/admin/personnel-sync')->assertForbidden();
    $this->actingAs($global)->post('/admin/authorizations')->assertForbidden();
    $this->actingAs($global)->post('/admin/workdays')->assertForbidden();
    $this->actingAs($global)->get('/people/search?q=test')->assertForbidden();
    $this->actingAs($global)->get('/minutes/party-branch/create')->assertForbidden();
    $this->actingAs($global)->post('/minutes/party-branch')->assertForbidden();
    $this->actingAs($global)->get('/minutes/'.$draft->id.'/edit')->assertForbidden();
    $this->actingAs($global)->put('/minutes/'.$draft->id)->assertForbidden();
    $this->actingAs($global)->post('/minutes/'.$draft->id.'/archive')->assertForbidden();
    $this->actingAs($global)->post('/minutes/'.$archived->id.'/return')->assertForbidden();
    $this->actingAs($global)->delete('/minutes/'.$archived->id)->assertForbidden();
});

test('role permissions page is available only to system administrators and describes the current access', function () {
    $system = globalTestUser(UserRole::SystemAdmin);
    $global = globalTestUser(UserRole::GlobalAdmin);

    $this->actingAs($system)->get('/admin/role-permissions')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/RolePermissions')->has('roles', 4)
            ->where('roles.2.scope', '全部已归档终稿 · 当前版本')
            ->where('roles.2.draft', false)->where('roles.2.return', false)->where('roles.2.admin', false)
            ->where('roles.3.admin', true)->etc());
    $this->actingAs($global)->get('/admin/role-permissions')->assertForbidden();
});

test('system administrator remains authoritative when both global roles are assigned', function () {
    $system = globalTestUser(UserRole::SystemAdmin);
    RoleAssignment::create(['user_id' => $system->id, 'role' => UserRole::GlobalAdmin, 'scope_key' => 'global']);
    $organization = Organization::create(['external_code' => 'BOTH-ADMINS', 'name' => '测试单位']);
    $minute = globalTestMinute($system, $organization, MeetingType::PartyBranch, 'archived');

    $this->actingAs($system)->get('/admin/role-permissions')->assertOk();
    $this->actingAs($system)->get('/minutes/'.$minute->id)->assertInertia(fn (Assert $page) => $page->where('canDelete', true)->where('canReturn', true)->etc());
});
