<?php

use App\Enums\MeetingType;
use App\Enums\UserRole;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\Person;
use App\Models\RoleAssignment;
use App\Services\AuthorizationService;
use App\Services\AuthorizationTemplateService;
use App\Services\MeetingScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
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

test('system administrator can grant all roles from synchronized personnel', function () {
    $organization = Organization::create(['external_code' => '100301', 'name' => '金融与经贸学院']);
    app(MeetingScopeService::class)->syncOrganizationMappings();
    $person = authorizationPerson($organization);
    $admin = coreUser('system_admin');

    $this->actingAs($admin)->post('/admin/authorizations', ['person_id' => $person->id, 'role' => 'minute_submitter', 'meeting_type' => 'party_branch'])->assertRedirect();
    $this->actingAs($admin)->post('/admin/authorizations', ['person_id' => $person->id, 'role' => 'minute_manager', 'meeting_type' => 'party_government_joint'])->assertRedirect();
    $this->actingAs($admin)->post('/admin/authorizations', ['person_id' => $person->id, 'role' => 'system_admin'])->assertRedirect();

    expect(RoleAssignment::whereHas('user', fn ($query) => $query->where('person_id', $person->id))->count())->toBe(3)
        ->and(RoleAssignment::where('role', 'minute_submitter')->value('meeting_type'))->toBe(MeetingType::PartyBranch)
        ->and(RoleAssignment::where('role', 'minute_submitter')->value('meeting_scope_id'))->not->toBeNull()
        ->and(RoleAssignment::where('role', 'minute_manager')->value('meeting_scope_id'))->toBeNull();
});

test('submitter scope uses synchronized organization and rejects unmapped unit', function () {
    $organization = Organization::create(['external_code' => 'UNKNOWN', 'name' => '未映射单位']);
    $person = authorizationPerson($organization);
    $admin = coreUser('system_admin');

    $this->actingAs($admin)->post('/admin/authorizations', ['person_id' => $person->id, 'role' => 'minute_submitter', 'meeting_type' => 'party_branch'])->assertSessionHasErrors('person_id');
    expect(RoleAssignment::where('role', 'minute_submitter')->count())->toBe(0);
});

test('grant is idempotent and restores a revoked assignment', function () {
    $organization = Organization::create(['external_code' => '100301', 'name' => '金融与经贸学院']);
    app(MeetingScopeService::class)->syncOrganizationMappings();
    $person = authorizationPerson($organization);
    $admin = coreUser('system_admin');
    $service = app(AuthorizationService::class);

    $first = $service->grant($person, UserRole::MinuteSubmitter, MeetingType::PartyBranch, $admin->id);
    $service->revoke($first);
    $second = $service->grant($person, UserRole::MinuteSubmitter, MeetingType::PartyBranch, $admin->id);

    expect($second->id)->toBe($first->id)->and($second->position_label)->toBeNull()->and(RoleAssignment::withTrashed()->where('user_id', $second->user_id)->count())->toBe(1);
});

test('last system administrator cannot be revoked', function () {
    $admin = coreUser('system_admin');
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
        ->and($strings)->toContain('工号/统一账号')->toContain('会议类型')->toContain('权限角色')
        ->and($sheet)->toContain('dataValidations');
});

test('new and legacy CSV formats both create valid previews', function () {
    $organization = Organization::create(['external_code' => '100301', 'name' => '金融与经贸学院']);
    app(MeetingScopeService::class)->syncOrganizationMappings();
    authorizationPerson($organization);
    $admin = coreUser('system_admin');

    $new = "工号/统一账号,姓名,会议类型,权限角色,启用状态\n20260001,张三,党政联席会议纪要,会议提交人,启用\n";
    $old = "工号/统一账号,姓名,学院代码,岗位角色,启用状态\n20260001,张三,100301,办公室主任,启用\n";
    $this->actingAs($admin)->post('/admin/authorization-import/preview', ['file' => UploadedFile::fake()->createWithContent('new.csv', $new)])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/admin/authorization-import/preview', ['file' => UploadedFile::fake()->createWithContent('old.csv', $old)])->assertSessionHasNoErrors();

    expect(ImportBatch::where('status', 'preview')->count())->toBe(2)
        ->and(ImportBatch::latest('created_at')->first()->payload[0]['role'])->toBe('minute_submitter');
});
