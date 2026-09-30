<?php

use App\Enums\MeetingType;
use App\Enums\UserRole;
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
