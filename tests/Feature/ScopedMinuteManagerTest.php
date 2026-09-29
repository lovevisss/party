<?php

use App\Enums\MeetingType;
use App\Enums\UserRole;
use App\Models\ImportBatch;
use App\Models\MeetingMinute;
use App\Models\MinuteFile;
use App\Models\Organization;
use App\Models\Person;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\MeetingScopeService;
use App\Services\PersonnelSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function scopedPerson(Organization $organization, string $number): Person
{
    return Person::create(['organization_id' => $organization->id, 'external_id' => $number, 'employee_no' => $number, 'name' => $number, 'status' => 'active']);
}

function scopedMinute(Organization $organization, MeetingType $type, int $scopeId, User $creator): MeetingMinute
{
    return MeetingMinute::create([
        'organization_id' => $organization->id, 'meeting_scope_id' => $scopeId,
        'meeting_type' => $type, 'title' => '范围测试 '.$organization->name,
        'status' => 'archived', 'created_by' => $creator->id, 'updated_by' => $creator->id,
    ]);
}

test('managers of both meeting types can only manage their own mapped scope', function (MeetingType $type) {
    $first = Organization::create(['external_code' => '100301', 'name' => '金融与经贸学院']);
    $other = Organization::create(['external_code' => '100302', 'name' => '财税学院']);
    app(MeetingScopeService::class)->syncOrganizationMappings();
    $person = scopedPerson($first, 'M001');
    $assignment = app(AuthorizationService::class)->grant($person, UserRole::MinuteManager, $type, null);
    $manager = $assignment->user;
    $firstScope = app(MeetingScopeService::class)->scopeForPerson($person, $type);
    $otherScope = app(MeetingScopeService::class)->scopeForPerson(scopedPerson($other, 'M002'), $type);
    $creator = User::factory()->create();
    $ownMinute = scopedMinute($first, $type, $firstScope->id, $creator);
    $foreignMinute = scopedMinute($other, $type, $otherScope->id, $creator);

    expect($assignment->meeting_scope_id)->toBe($firstScope->id)
        ->and($assignment->scope_key)->toBe('meeting:'.$type->value.':scope:'.$firstScope->id);
    $this->actingAs($manager)->get('/dashboard')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('stats.total', 1)->etc());
    $this->actingAs($manager)->get('/minutes/'.$type->slug())->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('minutes.data', 1)->where('minutes.data.0.id', $ownMinute->id)->has('organizations', 1)->etc());
    $this->actingAs($manager)->get('/minutes/'.$ownMinute->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canReturn', true)->etc());
    $this->actingAs($manager)->get('/minutes/'.$foreignMinute->id)->assertForbidden();
    $this->actingAs($manager)->post('/minutes/'.$foreignMinute->id.'/return', ['reason' => '无权退回其他范围的纪要'])->assertForbidden();
    $this->actingAs($manager)->get('/people/search?meeting_type='.$type->value.'&meeting_scope_id='.$otherScope->id.'&q=M002')->assertForbidden();
    $this->actingAs($manager)->get('/people/search?meeting_type='.$type->value.'&meeting_scope_id='.$firstScope->id.'&mode=all&q=M002')->assertOk()->assertExactJson([]);
    $this->actingAs($manager)->get('/people/search?q=M002')->assertForbidden();

    Storage::fake(config('filesystems.default'));
    $file = MinuteFile::create(['meeting_minute_id' => $foreignMinute->id, 'original_name' => '纪要.pdf', 'object_key' => 'foreign.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 4, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $creator->id]);
    $this->actingAs($manager)->get('/minutes/'.$foreignMinute->id.'/files/'.$file->id)->assertForbidden();
    $this->actingAs($manager)->post('/minutes/'.$ownMinute->id.'/return', ['reason' => '本范围纪要需要补充签字'])->assertRedirect();

    $admin = User::factory()->create();
    RoleAssignment::create(['user_id' => $admin->id, 'role' => 'system_admin', 'scope_key' => 'global']);
    $this->actingAs($admin)->get('/minutes/'.$foreignMinute->id)->assertOk();
    $this->actingAs($admin)->post('/minutes/'.$foreignMinute->id.'/return', ['reason' => '管理员退回修改内容'])->assertRedirect();
})->with([MeetingType::PartyBranch, MeetingType::PartyGovernmentJoint]);

test('legacy global manager assignments never grant cross-scope access before migration', function () {
    $organization = Organization::create(['external_code' => '100301', 'name' => '金融与经贸学院']);
    app(MeetingScopeService::class)->syncOrganizationMappings();
    $person = scopedPerson($organization, 'LEGACY');
    $user = User::factory()->create(['person_id' => $person->id]);
    RoleAssignment::create(['user_id' => $user->id, 'role' => 'minute_manager', 'meeting_type' => 'party_branch', 'scope_key' => 'meeting:party_branch:global']);
    $scope = app(MeetingScopeService::class)->scopeForPerson($person, MeetingType::PartyBranch);
    $minute = scopedMinute($organization, MeetingType::PartyBranch, $scope->id, User::factory()->create());

    $this->actingAs($user)->get('/minutes/'.$minute->id)->assertForbidden();
    $this->actingAs($user)->get('/minutes/party-branch')->assertForbidden();
});

test('legacy manager migration narrows grants and disables unmapped grants idempotently', function () {
    $organization = Organization::create(['external_code' => '100301', 'name' => '金融与经贸学院']);
    $unknown = Organization::create(['external_code' => 'UNKNOWN', 'name' => '未映射单位']);
    app(MeetingScopeService::class)->syncOrganizationMappings();
    $person = scopedPerson($organization, 'OLD-M');
    $missing = scopedPerson($unknown, 'OLD-U');
    $user = User::factory()->create(['person_id' => $person->id]);
    $missingUser = User::factory()->create(['person_id' => $missing->id]);
    $global = RoleAssignment::create(['user_id' => $user->id, 'role' => 'minute_manager', 'meeting_type' => 'party_branch', 'scope_key' => 'meeting:party_branch:global']);
    $unmapped = RoleAssignment::create(['user_id' => $missingUser->id, 'role' => 'minute_manager', 'meeting_type' => 'party_branch', 'scope_key' => 'meeting:party_branch:global']);
    $jointGlobal = RoleAssignment::create(['user_id' => $user->id, 'role' => 'minute_manager', 'meeting_type' => 'party_government_joint', 'scope_key' => 'meeting:party_government_joint:global']);
    $scope = app(MeetingScopeService::class)->scopeForPerson($person, MeetingType::PartyBranch);
    $scoped = RoleAssignment::create(['user_id' => $user->id, 'role' => 'minute_manager', 'meeting_type' => 'party_branch', 'meeting_scope_id' => $scope->id, 'scope_key' => 'meeting:party_branch:scope:'.$scope->id]);

    $migration = require database_path('migrations/2026_09_29_000100_scope_minute_managers.php');
    $migration->up();
    $migration->up();

    expect(RoleAssignment::find($global->id))->toBeNull()
        ->and(RoleAssignment::find($unmapped->id))->toBeNull()
        ->and($scoped->fresh()->meeting_scope_id)->toBe($scope->id)
        ->and($user->managedScopeIds(MeetingType::PartyBranch))->toBe([$scope->id])
        ->and($jointGlobal->fresh()->meeting_scope_id)->toBe(app(MeetingScopeService::class)->scopeForPerson($person, MeetingType::PartyGovernmentJoint)->id);
});

test('authorization import preview includes the managers mapped scope', function () {
    $organization = Organization::create(['external_code' => '100301', 'name' => '金融与经贸学院']);
    app(MeetingScopeService::class)->syncOrganizationMappings();
    scopedPerson($organization, 'IMPORT-M');
    $admin = User::factory()->create();
    RoleAssignment::create(['user_id' => $admin->id, 'role' => 'system_admin', 'scope_key' => 'global']);
    $csv = "工号/统一账号,姓名,会议类型,权限角色,启用状态\nIMPORT-M,IMPORT-M,党总支会议纪要,会议管理员,启用\n";

    $this->actingAs($admin)->post('/admin/authorization-import/preview', [
        'file' => UploadedFile::fake()->createWithContent('managers.csv', $csv),
    ])->assertSessionHasNoErrors();

    expect(ImportBatch::firstOrFail()->payload[0]['meeting_scope'])->toBe('金融与经贸学院党总支');
});

test('unmapped employees cannot become meeting managers', function () {
    $organization = Organization::create(['external_code' => 'UNKNOWN', 'name' => '未映射单位']);
    $person = scopedPerson($organization, 'MISSING-M');
    expect(fn () => app(AuthorizationService::class)->grant($person, UserRole::MinuteManager, MeetingType::PartyBranch, null))
        ->toThrow(ValidationException::class);
});

test('personnel sync revokes manager access on transfer and deactivation', function () {
    $first = Organization::create(['external_code' => '100301', 'name' => '金融与经贸学院']);
    app(MeetingScopeService::class)->syncOrganizationMappings();
    $moving = scopedPerson($first, 'MOVE-M');
    $inactive = scopedPerson($first, 'STOP-M');
    foreach ([$moving, $inactive] as $person) {
        app(AuthorizationService::class)->grant($person, UserRole::MinuteManager, MeetingType::PartyBranch, null);
    }
    config(['database.connections.middata' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('middata');
    Schema::connection('middata')->create('t_bd_zzqxryxx', function (Blueprint $table): void {
        foreach (['xgh', 'xm', 'dwmc', 'dwbm', 'dzyx', 'yddh'] as $column) {
            $table->string($column)->nullable();
        }
    });
    DB::connection('middata')->table('t_bd_zzqxryxx')->insert([
        ['xgh' => 'MOVE-M', 'xm' => '调动人员', 'dwmc' => '财税学院', 'dwbm' => '100302'],
        ['xgh' => 'OTHER', 'xm' => '保留人员', 'dwmc' => '金融与经贸学院', 'dwbm' => '100301'],
    ]);

    app(PersonnelSyncService::class)->sync();

    expect(RoleAssignment::where('role', 'minute_manager')->count())->toBe(0)
        ->and($moving->fresh()->organization->external_code)->toBe('100302')
        ->and($inactive->fresh()->status)->toBe('inactive');
});
