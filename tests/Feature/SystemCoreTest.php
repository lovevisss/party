<?php

use App\Enums\MinuteStatus;
use App\Models\MeetingMinute;
use App\Models\MinuteFile;
use App\Models\MinuteParticipant;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Models\Workday;
use App\Services\CasAuthenticationService;
use App\Services\MinutesArchiveService;
use App\Services\WorkdayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function coreUser(string $role, ?Organization $organization = null): User
{
    $user = User::factory()->create(['cas_account' => fake()->unique()->userName(), 'is_active' => true]);
    RoleAssignment::create(['user_id' => $user->id, 'role' => $role, 'organization_id' => $organization?->id]);

    return $user->fresh('roleAssignments');
}

test('CAS login return URL cannot become an open redirect', function () {
    config(['services.cas.service_url' => 'https://minutes.example.edu/auth/cas/callback']);
    $service = app(CasAuthenticationService::class);
    $service->loginUrl('https://evil.example/steal');
    expect(session('cas.return_url'))->toBe('/dashboard');
});

test('CAS service validation reads cas user without exposing ticket', function () {
    config(['services.cas.service_url' => 'https://minutes.example.edu/auth/cas/callback']);
    Http::fake(['*' => Http::response('<?xml version="1.0"?><cas:serviceResponse xmlns:cas="http://www.yale.edu/tp/cas"><cas:authenticationSuccess><cas:user>20260001</cas:user></cas:authenticationSuccess></cas:serviceResponse>')]);
    $identity = app(CasAuthenticationService::class)->validateTicket('ST-secret');
    expect($identity['account'])->toBe('20260001');
});

test('third workday calculation includes adjusted workdays', function () {
    foreach ([['2026-09-03', true], ['2026-09-04', false], ['2026-09-05', true], ['2026-09-06', false], ['2026-09-07', true]] as [$date, $isWorkday]) {
        Workday::create(['date' => $date, 'is_workday' => $isWorkday]);
    }
    $due = app(WorkdayService::class)->thirdWorkdayAfter(now()->setDate(2026, 9, 2));
    expect($due->toDateTimeString())->toBe('2026-09-07 23:59:59');
});

test('third workday defaults to Monday through Friday without calendar records', function () {
    $due = app(WorkdayService::class)->thirdWorkdayAfter(now()->setDate(2026, 9, 2));

    expect($due->toDateTimeString())->toBe('2026-09-07 23:59:59');
});

test('validation messages identify the exact minute section field and participant row', function () {
    $organization = Organization::create(['external_code' => 'VALIDATION', 'name' => '校验提示单位']);
    $user = coreUser('college_submitter', $organization);
    $minute = MeetingMinute::create([
        'organization_id' => $organization->id,
        'meeting_type' => 'party_committee',
        'status' => MinuteStatus::Draft,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $this->actingAs($user)->put("/minutes/{$minute->id}", [
        'lock_version' => 0,
        'participants' => [[
            'person_id' => null,
            'role_type' => 'chair',
            'display_name' => null,
            'is_external' => true,
        ]],
    ])->assertSessionHasErrors([
        'participants.0.display_name' => '人员情况第 1 行：人员姓名不能为空。',
    ]);

    try {
        app(MinutesArchiveService::class)->archive($minute, $user);
        $this->fail('Expected archive validation to fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['meeting_year'][0])->toBe('基本信息第 2 项“会议年度”未填写。');
    }
});

test('college submitter cannot view another organization minute', function () {
    $a = Organization::create(['external_code' => 'A', 'name' => '学院A']);
    $b = Organization::create(['external_code' => 'B', 'name' => '学院B']);
    $user = coreUser('college_submitter', $a);
    $minute = MeetingMinute::create(['organization_id' => $b->id, 'meeting_type' => 'party_committee', 'status' => MinuteStatus::Draft, 'created_by' => $user->id, 'updated_by' => $user->id]);
    $this->actingAs($user)->get("/minutes/{$minute->id}")->assertForbidden();
});

test('college submitter only sees and edits minutes created by themselves', function () {
    $organization = Organization::create(['external_code' => 'OWN', 'name' => '本单位']);
    $owner = coreUser('college_submitter', $organization);
    $colleague = coreUser('college_submitter', $organization);
    $ownMinute = MeetingMinute::create([
        'organization_id' => $organization->id,
        'meeting_type' => 'party_committee',
        'title' => '本人提交的纪要',
        'status' => MinuteStatus::Draft,
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
    $colleagueMinute = MeetingMinute::create([
        'organization_id' => $organization->id,
        'meeting_type' => 'party_committee',
        'title' => '同学院其他人员提交的纪要',
        'status' => MinuteStatus::Draft,
        'created_by' => $colleague->id,
        'updated_by' => $colleague->id,
    ]);

    $this->actingAs($owner)->get('/minutes')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('minutes/Index')
        ->has('minutes.data', 1)
        ->where('minutes.data.0.id', $ownMinute->id)
        ->where('minutes.data.0.can_edit', true)
        ->where('canCreate', true));
    $this->actingAs($owner)->get("/minutes/{$colleagueMinute->id}")->assertForbidden();
    $this->actingAs($owner)->get("/minutes/{$colleagueMinute->id}/edit")->assertForbidden();
});

test('system administrator sees every minute but does not edit submitter drafts', function () {
    $organizationA = Organization::create(['external_code' => 'ALL-A', 'name' => '单位A']);
    $organizationB = Organization::create(['external_code' => 'ALL-B', 'name' => '单位B']);
    $submitterA = coreUser('college_submitter', $organizationA);
    $submitterB = coreUser('college_submitter', $organizationB);
    $admin = coreUser('system_admin');

    $minuteA = MeetingMinute::create(['organization_id' => $organizationA->id, 'meeting_type' => 'party_committee', 'status' => MinuteStatus::Draft, 'created_by' => $submitterA->id, 'updated_by' => $submitterA->id]);
    $minuteB = MeetingMinute::create(['organization_id' => $organizationB->id, 'meeting_type' => 'party_committee', 'status' => MinuteStatus::Draft, 'created_by' => $submitterB->id, 'updated_by' => $submitterB->id]);

    $this->actingAs($admin)->get('/minutes')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('minutes/Index')
        ->has('minutes.data', 2)
        ->where('canCreate', false));
    $this->actingAs($admin)->get("/minutes/{$minuteA->id}")->assertOk();
    $this->actingAs($admin)->get("/minutes/{$minuteB->id}")->assertOk();
    $this->actingAs($admin)->get("/minutes/{$minuteA->id}/edit")->assertForbidden();
});

test('archive creates immutable version and fixes due date', function () {
    $org = Organization::create(['external_code' => 'C', 'name' => '学院C']);
    $user = coreUser('college_submitter', $org);
    foreach (range(3, 7) as $day) {
        Workday::create(['date' => "2026-09-0$day", 'is_workday' => true]);
    }
    $minute = MeetingMinute::create(['organization_id' => $org->id, 'meeting_type' => 'party_committee', 'meeting_year' => 2026, 'sequence_no' => 1, 'title' => '学院C2026年第1次党委会', 'meeting_start_at' => '2026-09-02 09:00:00', 'meeting_end_at' => '2026-09-02 10:00:00', 'first_topic_content' => '学习内容', 'status' => MinuteStatus::Draft, 'created_by' => $user->id, 'updated_by' => $user->id]);
    foreach (['chair', 'recorder', 'attendee'] as $role) {
        MinuteParticipant::create(['meeting_minute_id' => $minute->id, 'role_type' => $role, 'display_name' => $role, 'is_external' => true]);
    }
    MinuteFile::create(['meeting_minute_id' => $minute->id, 'original_name' => 'minutes.pdf', 'object_key' => 'test.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $user->id]);
    $result = app(MinutesArchiveService::class)->archive($minute, $user);
    expect($result->status)->toBe(MinuteStatus::Archived)->and($result->current_version)->toBe(1)->and($result->versions)->toHaveCount(1)->and($result->due_at->format('H:i:s'))->toBe('23:59:59');
});

test('uploaded attachment is visible while archive validation errors are returned', function () {
    $organization = Organization::create(['external_code' => 'FILES', 'name' => '附件测试单位']);
    $user = coreUser('college_submitter', $organization);
    $minute = MeetingMinute::create([
        'organization_id' => $organization->id,
        'meeting_type' => 'party_committee',
        'meeting_year' => 2026,
        'sequence_no' => 8,
        'title' => '附件展示测试',
        'meeting_start_at' => '2026-09-02 09:00:00',
        'meeting_end_at' => '2026-09-02 10:00:00',
        'first_topic_content' => '学习内容',
        'status' => MinuteStatus::Draft,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    MinuteParticipant::create(['meeting_minute_id' => $minute->id, 'role_type' => 'attendee', 'display_name' => '参会人员', 'is_external' => true]);
    MinuteFile::create([
        'meeting_minute_id' => $minute->id,
        'original_name' => '正式纪要.pdf',
        'object_key' => 'minutes/test.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'sha256' => str_repeat('a', 64),
        'uploaded_by' => $user->id,
    ]);

    $this->actingAs($user)->get("/minutes/{$minute->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('minutes/Form')
        ->has('minute.files', 1)
        ->where('minute.files.0.original_name', '正式纪要.pdf'));
    $this->actingAs($user)->post("/minutes/{$minute->id}/archive")
        ->assertSessionHasErrors('participants');
    expect($minute->fresh()->status)->toBe(MinuteStatus::Draft);
});

test('saving participant changes before archive makes the new roles available to archive', function () {
    $organization = Organization::create(['external_code' => 'SAVE-FIRST', 'name' => '保存后归档单位']);
    $user = coreUser('college_submitter', $organization);
    $minute = MeetingMinute::create([
        'organization_id' => $organization->id,
        'meeting_type' => 'party_committee',
        'meeting_year' => 2026,
        'sequence_no' => 9,
        'title' => '保存后归档测试',
        'meeting_start_at' => '2026-09-02 09:00:00',
        'meeting_end_at' => '2026-09-02 10:00:00',
        'first_topic_content' => '学习内容',
        'status' => MinuteStatus::Draft,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    MinuteFile::create(['meeting_minute_id' => $minute->id, 'original_name' => 'minutes.pdf', 'object_key' => 'test.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $user->id]);
    foreach (range(3, 5) as $day) {
        Workday::create(['date' => "2026-09-0{$day}", 'is_workday' => true]);
    }
    $participants = collect(['chair', 'recorder', 'attendee'])->map(fn (string $role): array => [
        'person_id' => null,
        'role_type' => $role,
        'display_name' => $role,
        'is_external' => true,
    ])->all();

    $this->actingAs($user)->put("/minutes/{$minute->id}", [
        'meeting_year' => 2026,
        'sequence_no' => 9,
        'title' => '保存后归档测试',
        'meeting_start_at' => '2026-09-02 09:00:00',
        'meeting_end_at' => '2026-09-02 10:00:00',
        'first_topic_content' => '学习内容',
        'lock_version' => 0,
        'participants' => $participants,
    ])->assertSessionHasNoErrors();
    $this->actingAs($user)->post("/minutes/{$minute->id}/archive")->assertRedirect("/minutes/{$minute->id}");

    expect($minute->fresh()->status)->toBe(MinuteStatus::Archived)
        ->and($minute->participants()->pluck('role_type')->all())->toContain('chair', 'recorder', 'attendee');
});
