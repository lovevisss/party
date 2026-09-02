<?php

use App\Enums\UserRole;
use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\Person;
use App\Models\RoleAssignment;
use App\Services\AuthorizationService;
use App\Services\AuthorizationTemplateService;
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
    $organization = Organization::create(['external_code' => 'COL001', 'name' => '第一学院']);
    $person = authorizationPerson($organization);
    $admin = coreUser('system_admin');

    $this->actingAs($admin)->post('/admin/authorizations', ['person_id' => $person->id, 'role' => 'college_submitter', 'position_label' => '组织员'])->assertRedirect();
    $this->actingAs($admin)->post('/admin/authorizations', ['person_id' => $person->id, 'role' => 'school_manager'])->assertRedirect();
    $this->actingAs($admin)->post('/admin/authorizations', ['person_id' => $person->id, 'role' => 'system_admin'])->assertRedirect();

    expect(RoleAssignment::whereHas('user', fn ($query) => $query->where('person_id', $person->id))->count())->toBe(3)
        ->and(RoleAssignment::where('role', 'college_submitter')->where('organization_id', $organization->id)->value('position_label'))->toBe('组织员')
        ->and(RoleAssignment::where('role', 'school_manager')->value('organization_id'))->toBeNull();
});

test('college role uses synchronized organization and validates position', function () {
    $organization = Organization::create(['external_code' => 'COL001', 'name' => '第一学院']);
    $person = authorizationPerson($organization);
    $admin = coreUser('system_admin');

    $this->actingAs($admin)->post('/admin/authorizations', ['person_id' => $person->id, 'role' => 'college_submitter', 'position_label' => '错误岗位'])->assertSessionHasErrors('position_label');
    expect(RoleAssignment::where('role', 'college_submitter')->count())->toBe(0);
});

test('grant is idempotent and restores a revoked assignment', function () {
    $organization = Organization::create(['external_code' => 'COL001', 'name' => '第一学院']);
    $person = authorizationPerson($organization);
    $admin = coreUser('system_admin');
    $service = app(AuthorizationService::class);

    $first = $service->grant($person, UserRole::CollegeSubmitter, '组织员', $admin->id);
    $service->revoke($first);
    $second = $service->grant($person, UserRole::CollegeSubmitter, '办公室主任', $admin->id);

    expect($second->id)->toBe($first->id)->and($second->position_label)->toBe('办公室主任')->and(RoleAssignment::withTrashed()->where('user_id', $second->user_id)->count())->toBe(1);
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
        ->and($strings)->toContain('工号/统一账号')->toContain('系统角色')->toContain('岗位标签')
        ->and($sheet)->toContain('dataValidations');
});

test('new and legacy CSV formats both create valid previews', function () {
    $organization = Organization::create(['external_code' => 'COL001', 'name' => '第一学院']);
    authorizationPerson($organization);
    $admin = coreUser('system_admin');

    $new = "工号/统一账号,姓名,学院代码,系统角色,岗位标签,启用状态\n20260001,张三,COL001,学院提交人,组织员,启用\n";
    $old = "工号/统一账号,姓名,学院代码,岗位角色,启用状态\n20260001,张三,COL001,办公室主任,启用\n";
    $this->actingAs($admin)->post('/admin/authorization-import/preview', ['file' => UploadedFile::fake()->createWithContent('new.csv', $new)])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/admin/authorization-import/preview', ['file' => UploadedFile::fake()->createWithContent('old.csv', $old)])->assertSessionHasNoErrors();

    expect(ImportBatch::where('status', 'preview')->count())->toBe(2)
        ->and(ImportBatch::latest('created_at')->first()->payload[0]['role'])->toBe('college_submitter');
});
