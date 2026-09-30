<?php

use App\Enums\MeetingType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Models\MeetingScope;
use App\Models\Organization;
use App\Models\Person;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\AuthorizationTemplateService;
use App\Services\MeetingScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use ZipArchive;

uses(RefreshDatabase::class);

function authorizationPerson(Organization $organization, string $employeeNo = '20260001'): Person
{
    return Person::create([
        'organization_id' => $organization->id,
        'external_id' => $employeeNo,
        'employee_no' => $employeeNo,
        'cas_account' => $employeeNo,
        'name' => '张三',
        'status' => 'active',
    ]);
}

function authorizationAdmin(): User
{
    $admin = User::factory()->create();
    RoleAssignment::create(['user_id' => $admin->id, 'role' => UserRole::SystemAdmin, 'scope_key' => 'global']);

    return $admin;
}

test('system administrator can grant all roles from synchronized personnel', function () {
    $organization = Organization::create(['external_code' => '100301', 'name' => '金融与经贸学院']);
    app(MeetingScopeService::class)->syncOrganizationMappings();
    $person = authorizationPerson($organization);
    $admin = authorizationAdmin();

    $this->actingAs($admin)->post('/admin/authorizations', ['person_id' => $person->id, 'role' => 'minute_submitter', 'meeting_type' => 'party_branch'])->assertRedirect();
    $this->actingAs($admin)->post('/admin/authorizations', ['person_id' => $person->id, 'role' => 'minute_manager', 'meeting_type' => 'party_government_joint'])->assertRedirect();
    $this->actingAs($admin)->post('/admin/authorizations', ['person_id' => $person->id, 'role' => 'system_admin'])->assertRedirect();

    expect(RoleAssignment::whereHas('user', fn ($query) => $query->where('person_id', $person->id))->count())->toBe(3)
        ->and(RoleAssignment::where('role', 'minute_submitter')->value('meeting_type'))->toBe(MeetingType::PartyBranch)
        ->and(RoleAssignment::where('role', 'minute_submitter')->value('meeting_scope_id'))->not->toBeNull()
        ->and(RoleAssignment::where('role', 'minute_manager')->value('meeting_scope_id'))->not->toBeNull();
});

test('submitter scope uses synchronized organization and rejects unmapped unit', function () {
    $organization = Organization::create(['external_code' => 'UNKNOWN', 'name' => '未映射单位']);
    $person = authorizationPerson($organization);
    $admin = authorizationAdmin();

    $this->actingAs($admin)->post('/admin/authorizations', ['person_id' => $person->id, 'role' => 'minute_submitter', 'meeting_type' => 'party_branch'])->assertSessionHasErrors('person_id');
    expect(RoleAssignment::where('role', 'minute_submitter')->count())->toBe(0);
});

test('authorization filters require a matching meeting type before applying a scope', function () {
    $organization = Organization::create(['external_code' => '100401', 'name' => '党委办公室']);
    app(MeetingScopeService::class)->syncOrganizationMappings();
    $admin = authorizationAdmin();
    $branchScope = MeetingScope::where('meeting_type', MeetingType::PartyBranch->value)->where('name', '机关党总支')->firstOrFail();
    $jointScope = MeetingScope::where('meeting_type', MeetingType::PartyGovernmentJoint->value)->where('name', '机关党总支')->firstOrFail();
    for ($number = 1; $number <= 21; $number++) {
        $person = authorizationPerson($organization, 'FILTER-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT));
        app(AuthorizationService::class)->grant($person, UserRole::MinuteManager, MeetingType::PartyBranch, $admin->id);
    }
    app(AuthorizationService::class)->grant($person, UserRole::MinuteManager, MeetingType::PartyGovernmentJoint, $admin->id);

    $this->actingAs($admin)->get('/admin/authorization-import?meeting_scope_id='.$branchScope->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('assignments.total', 23)->missing('filters.meeting_scope_id')->etc());
    $this->actingAs($admin)->get('/admin/authorization-import?meeting_type=party_branch&meeting_scope_id='.$jointScope->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('assignments.total', 21)
            ->missing('filters.meeting_scope_id')
            ->where('assignments.next_page_url', fn ($url) => str_contains($url, 'meeting_type=party_branch') && ! str_contains($url, 'meeting_scope_id='))->etc());
    $this->actingAs($admin)->get('/admin/authorization-import?meeting_type=party_branch&meeting_scope_id='.$branchScope->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('assignments.total', 21)
            ->where('filters.meeting_scope_id', (string) $branchScope->id)
            ->where('assignments.next_page_url', fn ($url) => str_contains($url, 'meeting_scope_id='.$branchScope->id))->etc());
    $this->actingAs($admin)->get('/admin/authorization-import?meeting_type=party_branch&meeting_scope_id='.$branchScope->id.'&page=2')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('assignments.data', 1)
            ->where('filters.meeting_scope_id', (string) $branchScope->id)->etc());
    $this->actingAs($admin)->get('/admin/authorization-import?meeting_type=party_government_joint&meeting_scope_id='.$jointScope->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('assignments.total', 1)
            ->where('filters.meeting_scope_id', (string) $jointScope->id)->etc());
});

test('grant is idempotent and restores a revoked assignment', function () {
    $organization = Organization::create(['external_code' => '100301', 'name' => '金融与经贸学院']);
    app(MeetingScopeService::class)->syncOrganizationMappings();
    $person = authorizationPerson($organization);
    $admin = authorizationAdmin();
    $service = app(AuthorizationService::class);

    $first = $service->grant($person, UserRole::MinuteSubmitter, MeetingType::PartyBranch, $admin->id);
    $service->revoke($first);
    $second = $service->grant($person, UserRole::MinuteSubmitter, MeetingType::PartyBranch, $admin->id);

    expect($second->id)->toBe($first->id)->and($second->position_label)->toBeNull()->and(RoleAssignment::withTrashed()->where('user_id', $second->user_id)->count())->toBe(1);
});

test('last system administrator cannot be revoked', function () {
    $admin = authorizationAdmin();
    $assignment = $admin->roleAssignments()->firstOrFail();

    expect(fn () => app(AuthorizationService::class)->revoke($assignment))->toThrow(ValidationException::class);
});

test('adjusting a role replaces only the selected assignment and records the change', function () {
    $organization = Organization::create(['external_code' => '100301', 'name' => '金融与经贸学院']);
    app(MeetingScopeService::class)->syncOrganizationMappings();
    $person = authorizationPerson($organization);
    $admin = authorizationAdmin();
    $service = app(AuthorizationService::class);
    $source = $service->grant($person, UserRole::MinuteSubmitter, MeetingType::PartyBranch, $admin->id);
    $other = $service->grant($person, UserRole::MinuteSubmitter, MeetingType::PartyGovernmentJoint, $admin->id);
    $source->user->update(['cas_account' => 'legacy-account']);

    $this->actingAs($admin)->patch('/admin/authorizations/'.$source->id, ['role' => 'minute_manager', 'meeting_type' => 'party_branch'])->assertSessionHasNoErrors();

    expect(RoleAssignment::whereKey($source->id)->exists())->toBeFalse()
        ->and($other->fresh())->not->toBeNull()
        ->and(RoleAssignment::where('user_id', $source->user_id)->where('role', 'minute_manager')->where('meeting_type', MeetingType::PartyBranch->value)->value('meeting_scope_id'))->not->toBeNull()
        ->and(RoleAssignment::where('role', 'minute_manager')->value('user_id'))->toBe($source->user_id)
        ->and(AuditLog::where('event', 'authorization.adjusted')->count())->toBe(1);
});

test('adjusting between global and meeting roles clears or resolves meeting scope', function () {
    $organization = Organization::create(['external_code' => '100301', 'name' => '金融与经贸学院']);
    app(MeetingScopeService::class)->syncOrganizationMappings();
    $person = authorizationPerson($organization);
    $admin = authorizationAdmin();
    $global = app(AuthorizationService::class)->grant($person, UserRole::GlobalAdmin, null, $admin->id);

    $this->actingAs($admin)->patch('/admin/authorizations/'.$global->id, ['role' => 'minute_manager', 'meeting_type' => 'party_government_joint'])->assertSessionHasNoErrors();
    $manager = RoleAssignment::where('user_id', $global->user_id)->where('role', 'minute_manager')->firstOrFail();
    expect($manager->meeting_type)->toBe(MeetingType::PartyGovernmentJoint)->and($manager->meeting_scope_id)->not->toBeNull();

    $this->actingAs($admin)->patch('/admin/authorizations/'.$manager->id, ['role' => 'global_admin', 'meeting_type' => 'party_branch'])->assertSessionHasNoErrors();
    $replacement = RoleAssignment::where('user_id', $global->user_id)->where('role', 'global_admin')->firstOrFail();
    expect($replacement->meeting_type)->toBeNull()->and($replacement->meeting_scope_id)->toBeNull()->and($replacement->scope_key)->toBe('global');
});

test('adjustment rejects an existing target and keeps both assignments', function () {
    $organization = Organization::create(['external_code' => '100301', 'name' => '金融与经贸学院']);
    app(MeetingScopeService::class)->syncOrganizationMappings();
    $person = authorizationPerson($organization);
    $admin = authorizationAdmin();
    $service = app(AuthorizationService::class);
    $source = $service->grant($person, UserRole::MinuteSubmitter, MeetingType::PartyBranch, $admin->id);
    $target = $service->grant($person, UserRole::MinuteManager, MeetingType::PartyBranch, $admin->id);

    $this->actingAs($admin)->patch('/admin/authorizations/'.$source->id, ['role' => 'minute_manager', 'meeting_type' => 'party_branch'])->assertSessionHasErrors('role');
    expect($source->fresh())->not->toBeNull()->and($target->fresh())->not->toBeNull()->and(AuditLog::where('event', 'authorization.adjusted')->count())->toBe(0);
});

test('adjustment rejects inactive or unmapped people without changing the original assignment', function () {
    $organization = Organization::create(['external_code' => 'UNKNOWN', 'name' => '未映射单位']);
    $person = authorizationPerson($organization);
    $admin = authorizationAdmin();
    $source = app(AuthorizationService::class)->grant($person, UserRole::GlobalAdmin, null, $admin->id);

    $this->actingAs($admin)->patch('/admin/authorizations/'.$source->id, ['role' => 'minute_manager', 'meeting_type' => 'party_branch'])->assertSessionHasErrors('person_id');
    expect($source->fresh())->not->toBeNull();

    $person->update(['status' => 'inactive']);
    $this->actingAs($admin)->patch('/admin/authorizations/'.$source->id, ['role' => 'system_admin'])->assertSessionHasErrors('person_id');
    expect($source->fresh())->not->toBeNull()->and(RoleAssignment::where('role', 'system_admin')->count())->toBe(1);
});

test('the last system administrator cannot be adjusted away and revoke remains available for other roles', function () {
    $admin = authorizationAdmin();
    $adminAssignment = $admin->roleAssignments()->firstOrFail();
    $organization = Organization::create(['external_code' => '100301', 'name' => '金融与经贸学院']);
    $person = authorizationPerson($organization);

    $this->actingAs($admin)->patch('/admin/authorizations/'.$adminAssignment->id, ['role' => 'global_admin'])->assertSessionHasErrors('role');
    expect($adminAssignment->fresh())->not->toBeNull()->and(RoleAssignment::where('role', 'global_admin')->count())->toBe(0);

    $global = app(AuthorizationService::class)->grant($person, UserRole::GlobalAdmin, null, $admin->id);
    $this->actingAs($admin)->delete('/admin/authorizations/'.$global->id)->assertSessionHasNoErrors();
    expect(RoleAssignment::whereKey($global->id)->exists())->toBeFalse();
});

test('only system administrators may adjust roles', function () {
    $admin = authorizationAdmin();
    $global = User::factory()->create();
    RoleAssignment::create(['user_id' => $global->id, 'role' => UserRole::GlobalAdmin, 'scope_key' => 'global']);

    $this->actingAs($global)->patch('/admin/authorizations/'.$admin->roleAssignments()->firstOrFail()->id, ['role' => 'global_admin'])->assertForbidden();
});

test('downloaded xlsx template contains two sheets and required headers', function () {
    $bytes = app(AuthorizationTemplateService::class)->make();
    $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $workbook = $zip->getFromName('xl/workbook.xml');
    $strings = $zip->getFromName('xl/sharedStrings.xml');
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($path);

    expect($workbook)->toContain('授权名单')->toContain('填写说明')
        ->and($strings)->toContain('工号/统一账号')->toContain('会议类型')->toContain('权限角色')->toContain('全局管理员')
        ->and($sheet)->toContain('dataValidations')->toContain('全局管理员');
});

test('new and legacy CSV formats both create valid previews', function () {
    $organization = Organization::create(['external_code' => '100301', 'name' => '金融与经贸学院']);
    app(MeetingScopeService::class)->syncOrganizationMappings();
    authorizationPerson($organization);
    $admin = authorizationAdmin();

    $new = "工号/统一账号,姓名,会议类型,权限角色,启用状态\n20260001,张三,党政联席会议纪要,会议提交人,启用\n";
    $old = "工号/统一账号,姓名,学院代码,岗位角色,启用状态\n20260001,张三,100301,办公室主任,启用\n";
    $this->actingAs($admin)->post('/admin/authorization-import/preview', ['file' => UploadedFile::fake()->createWithContent('new.csv', $new)])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/admin/authorization-import/preview', ['file' => UploadedFile::fake()->createWithContent('old.csv', $old)])->assertSessionHasNoErrors();

    expect(ImportBatch::where('status', 'preview')->count())->toBe(2)
        ->and(ImportBatch::latest('created_at')->first()->payload[0]['role'])->toBe('minute_submitter');
});
