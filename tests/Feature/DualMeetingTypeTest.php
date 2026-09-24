<?php

use App\Enums\MeetingType;
use App\Enums\UserRole;
use App\Models\MeetingMinute;
use App\Models\MeetingScope;
use App\Models\Organization;
use App\Models\Person;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\MeetingScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('all 26 source organizations map to the correct party branch and college scopes', function () {
    $collegeNames = ['金融与经贸学院', '财税学院', '工商管理学院', '会计学院', '信息与人工智能学院', '法律与社会工作学院、马克思主义学院', '文化传播与设计学院', '外国语学院', '创业学院、继续教育学院', '体育部'];
    foreach ($collegeNames as $index => $name) {
        Organization::create(['external_code' => '1003'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), 'name' => $name, 'is_active' => true]);
    }
    foreach ([401, 402, 403, 404, 405, 406, 407, 408, 409, 410, 411, 412, 413, 414, 415, 417] as $code) {
        Organization::create(['external_code' => '100'.$code, 'name' => '行政部门'.$code, 'is_active' => true]);
    }

    app(MeetingScopeService::class)->syncOrganizationMappings();

    expect(MeetingScope::where('meeting_type', MeetingType::PartyBranch->value)->count())->toBe(10)
        ->and(MeetingScope::where('meeting_type', MeetingType::PartyGovernmentJoint->value)->count())->toBe(11)
        ->and(MeetingScope::where('name', '机关党总支')->firstOrFail()->organizations()->count())->toBe(16)
        ->and(MeetingScope::where('name', '联合党总支')->firstOrFail()->organizations()->pluck('external_code')->sort()->values()->all())->toBe(['100309', '100310']);
});

test('an organ employee can receive joint meeting submitter access without changing college scopes', function () {
    $organ = Organization::create(['external_code' => '100401', 'name' => '党委组织部', 'is_active' => true]);
    $college = Organization::create(['external_code' => '100309', 'name' => '创业学院、继续教育学院', 'is_active' => true]);
    app(MeetingScopeService::class)->syncOrganizationMappings();
    $person = Person::create(['organization_id' => $organ->id, 'external_id' => 'O001', 'employee_no' => 'O001', 'name' => '机关人员', 'status' => 'active']);

    $scope = app(MeetingScopeService::class)->scopeForPerson($person, MeetingType::PartyGovernmentJoint);
    expect($scope?->name)->toBe('机关党总支')
        ->and(MeetingScope::where('meeting_type', MeetingType::PartyGovernmentJoint->value)->where('name', '创业学院、继续教育学院')->firstOrFail()->organizations()->pluck('external_code')->all())->toBe(['100309']);

    $assignment = app(AuthorizationService::class)->grant($person, UserRole::MinuteSubmitter, MeetingType::PartyGovernmentJoint, null);
    expect($assignment->meeting_scope_id)->toBe($scope->id);
});

test('an existing party branch organ association is repaired for joint meetings', function () {
    $organization = Organization::create(['external_code' => 'OTHER-ORGAN', 'name' => '党委办公室、院长办公室', 'is_active' => true]);
    $branchOrgan = MeetingScope::where('meeting_type', MeetingType::PartyBranch->value)->where('code', 'organ')->firstOrFail();
    $jointOrgan = MeetingScope::where('meeting_type', MeetingType::PartyGovernmentJoint->value)->where('code', 'organ')->firstOrFail();
    $branchOrgan->organizations()->attach($organization->id);
    $person = Person::create(['organization_id' => $organization->id, 'external_id' => 'O002', 'employee_no' => 'O002', 'name' => '机关人员', 'status' => 'active']);

    expect(app(MeetingScopeService::class)->scopeForPerson($person, MeetingType::PartyBranch)?->id)->toBe($branchOrgan->id)
        ->and(app(MeetingScopeService::class)->scopeForPerson($person, MeetingType::PartyGovernmentJoint))->toBeNull();

    $migration = require database_path('migrations/2026_09_24_000800_sync_joint_organ_scope_organizations.php');
    $migration->up();
    $migration->up();

    expect($jointOrgan->organizations()->whereKey($organization->id)->count())->toBe(1)
        ->and(app(MeetingScopeService::class)->scopeForPerson($person, MeetingType::PartyGovernmentJoint)?->id)->toBe($jointOrgan->id);
    $assignment = app(AuthorizationService::class)->grant($person, UserRole::MinuteSubmitter, MeetingType::PartyGovernmentJoint, null);
    expect($assignment->meeting_scope_id)->toBe($jointOrgan->id);
});

test('personnel mapping sync inherits manually associated organ units without affecting college scopes', function () {
    $organization = Organization::create(['external_code' => 'OTHER-ORGAN', 'name' => '党委办公室、院长办公室', 'is_active' => true]);
    $college = Organization::create(['external_code' => '100309', 'name' => '创业学院、继续教育学院', 'is_active' => true]);
    $branchOrgan = MeetingScope::where('meeting_type', MeetingType::PartyBranch->value)->where('code', 'organ')->firstOrFail();
    $jointOrgan = MeetingScope::where('meeting_type', MeetingType::PartyGovernmentJoint->value)->where('code', 'organ')->firstOrFail();
    $branchOrgan->organizations()->attach($organization->id);

    app(MeetingScopeService::class)->syncOrganizationMappings();
    app(MeetingScopeService::class)->syncOrganizationMappings();

    expect($jointOrgan->organizations()->whereKey($organization->id)->count())->toBe(1)
        ->and($jointOrgan->organizations()->whereKey($college->id)->exists())->toBeFalse()
        ->and(MeetingScope::where('meeting_type', MeetingType::PartyGovernmentJoint->value)->where('code', 'college-100309')->firstOrFail()->organizations()->whereKey($college->id)->exists())->toBeTrue();
});

test('the same person can hold independent submitter roles for both meeting types', function () {
    $organization = Organization::create(['external_code' => '100301', 'name' => '金融与经贸学院', 'is_active' => true]);
    app(MeetingScopeService::class)->syncOrganizationMappings();
    $person = Person::create(['organization_id' => $organization->id, 'external_id' => 'T001', 'employee_no' => 'T001', 'name' => '测试人员', 'status' => 'active']);
    $admin = User::factory()->create(['is_active' => true]);
    $service = app(AuthorizationService::class);

    $branch = $service->grant($person, UserRole::MinuteSubmitter, MeetingType::PartyBranch, $admin->id);
    $joint = $service->grant($person, UserRole::MinuteSubmitter, MeetingType::PartyGovernmentJoint, $admin->id);

    expect($branch->meeting_scope_id)->not->toBe($joint->meeting_scope_id)
        ->and($branch->user->fresh()->meetingScopeIds(MeetingType::PartyBranch))->toBe([$branch->meeting_scope_id])
        ->and($branch->user->fresh()->meetingScopeIds(MeetingType::PartyGovernmentJoint))->toBe([$joint->meeting_scope_id]);
});

test('type manager only sees minutes belonging to their meeting type', function () {
    $organization = Organization::create(['external_code' => '100301', 'name' => '金融与经贸学院', 'is_active' => true]);
    app(MeetingScopeService::class)->syncOrganizationMappings();
    $person = Person::create(['organization_id' => $organization->id, 'external_id' => 'M001', 'employee_no' => 'M001', 'name' => '类型管理员', 'status' => 'active']);
    $managerAssignment = app(AuthorizationService::class)->grant($person, UserRole::MinuteManager, MeetingType::PartyBranch, null);
    $manager = $managerAssignment->user;
    $creator = User::factory()->create(['is_active' => true]);
    $branchScope = MeetingScope::where('meeting_type', MeetingType::PartyBranch->value)->whereHas('organizations', fn ($query) => $query->whereKey($organization->id))->firstOrFail();
    $jointScope = MeetingScope::where('meeting_type', MeetingType::PartyGovernmentJoint->value)->whereHas('organizations', fn ($query) => $query->whereKey($organization->id))->firstOrFail();
    $branchMinute = MeetingMinute::create(['organization_id' => $organization->id, 'meeting_scope_id' => $branchScope->id, 'meeting_type' => MeetingType::PartyBranch, 'status' => 'draft', 'created_by' => $creator->id, 'updated_by' => $creator->id]);
    $jointMinute = MeetingMinute::create(['organization_id' => $organization->id, 'meeting_scope_id' => $jointScope->id, 'meeting_type' => MeetingType::PartyGovernmentJoint, 'status' => 'draft', 'created_by' => $creator->id, 'updated_by' => $creator->id]);

    $this->actingAs($manager)->get("/minutes/{$branchMinute->id}")->assertOk();
    $this->actingAs($manager)->get("/minutes/{$jointMinute->id}")->assertForbidden();
    $this->actingAs($manager)->get('/minutes/party-government-joint')->assertForbidden();
});
