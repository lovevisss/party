<?php

use App\Enums\MeetingType;
use App\Enums\MinuteStatus;
use App\Models\AuditLog;
use App\Models\MeetingMinute;
use App\Models\MeetingScope;
use App\Models\MinuteFile;
use App\Models\MinuteParticipant;
use App\Models\Organization;
use App\Models\Person;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Models\Workday;
use App\Services\CasAuthenticationService;
use App\Services\MinutesArchiveService;
use App\Services\WorkdayService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function coreUser(string $role, ?Organization $organization = null): User
{
    $user = User::factory()->create(['cas_account' => fake()->unique()->userName(), 'is_active' => true]);
    $scope = null;
    if ($organization && in_array($role, ['minute_submitter', 'minute_manager'], true)) {
        $scope = MeetingScope::where('meeting_type', 'party_branch')->firstOrFail();
        $scope->organizations()->syncWithoutDetaching([$organization->id]);
    }
    if ($role === 'minute_manager' && ! $organization) {
        $organization = Organization::create(['external_code' => 'MANAGER-'.$user->id, 'name' => '管理员测试单位']);
        $person = Person::create(['organization_id' => $organization->id, 'external_id' => 'M'.$user->id, 'employee_no' => 'M'.$user->id, 'name' => '测试管理员', 'status' => 'active']);
        $user->update(['person_id' => $person->id]);
        $scope = MeetingScope::where('meeting_type', 'party_branch')->firstOrFail();
        $scope->organizations()->syncWithoutDetaching([$organization->id]);
    }
    RoleAssignment::create([
        'user_id' => $user->id,
        'role' => $role,
        'meeting_type' => $role === 'system_admin' ? null : 'party_branch',
        'meeting_scope_id' => $scope?->id,
        'scope_key' => $role === 'system_admin' ? 'global' : 'meeting:party_branch:scope:'.$scope?->id,
    ]);

    return $user->fresh('roleAssignments');
}

function readyMinute(User $user, Organization $organization, int $sequence): MeetingMinute
{
    $minute = MeetingMinute::create([
        'organization_id' => $organization->id,
        'meeting_type' => 'party_branch',
        'meeting_year' => 2026,
        'sequence_no' => $sequence,
        'title' => "归档时间测试 {$sequence}",
        'meeting_start_at' => '2026-09-02 09:00:00',
        'meeting_end_at' => '2026-09-02 10:00:00',
        'first_topic_content' => '学习内容',
        'status' => MinuteStatus::Draft,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    foreach (['chair', 'recorder', 'attendee'] as $role) {
        MinuteParticipant::create(['meeting_minute_id' => $minute->id, 'role_type' => $role, 'display_name' => $role, 'is_external' => true]);
    }
    MinuteFile::create(['meeting_minute_id' => $minute->id, 'original_name' => 'signed.pdf', 'object_key' => "minutes/{$minute->id}/signed.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $user->id]);

    return $minute;
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

test('deadline preview uses the configured holiday and adjusted workday calendar', function () {
    $user = coreUser('system_admin');
    foreach ([['2026-09-03', true], ['2026-09-04', false], ['2026-09-05', true], ['2026-09-07', true]] as [$date, $isWorkday]) {
        Workday::create(['date' => $date, 'is_workday' => $isWorkday]);
    }

    $this->actingAs($user)->getJson('/minutes/deadline?meeting_end_at=2026-09-02T10%3A00')
        ->assertOk()->assertJsonPath('due_at', '2026-09-07T23:59:59+08:00');
    $this->actingAs($user)->getJson('/minutes/deadline?meeting_end_at=invalid')
        ->assertUnprocessable();
});

test('archive compares the submitted time with the inclusive deadline and filters only archived minutes', function () {
    $organization = Organization::create(['external_code' => 'TIME-RULES', 'name' => '归档时间测试单位']);
    $user = coreUser('minute_submitter', $organization);
    $onTime = readyMinute($user, $organization, 1);
    $late = readyMinute($user, $organization, 2);
    $returned = readyMinute($user, $organization, 3);
    $service = app(MinutesArchiveService::class);

    $service->archive($onTime, $user, CarbonImmutable::parse('2026-09-07 23:59:59', 'Asia/Shanghai'));
    $service->archive($late, $user, CarbonImmutable::parse('2026-09-08 00:00:00', 'Asia/Shanghai'));
    $service->archive($returned, $user, CarbonImmutable::parse('2026-09-08 00:00:00', 'Asia/Shanghai'));
    $service->returnForCorrection($returned, $user, '需要修改会议纪要内容');

    expect($onTime->fresh()->is_overdue)->toBeFalse()
        ->and($late->fresh()->is_overdue)->toBeTrue()
        ->and($onTime->fresh()->due_at->format('Y-m-d H:i:s'))->toBe('2026-09-07 23:59:59')
        ->and($onTime->fresh()->archived_at->format('Y-m-d H:i:s'))->toBe('2026-09-07 23:59:59');

    $this->actingAs($user)->get('/minutes/party-branch?overdue=0&year=2026&status=archived')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('minutes.data', 1)->where('minutes.data.0.id', $onTime->id));
    $this->actingAs($user)->get('/minutes/party-branch?overdue=1&year=2026')
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('minutes.data', 1)->where('minutes.data.0.id', $late->id));
});

test('archive uses server time and resubmission updates only the current version', function () {
    $organization = Organization::create(['external_code' => 'RESUBMIT-TIME', 'name' => '再次归档测试单位']);
    $user = coreUser('minute_submitter', $organization);
    $minute = readyMinute($user, $organization, 1);
    $url = "/minutes/{$minute->id}/archive";

    $this->travelTo(CarbonImmutable::parse('2026-09-02 09:59:00', 'Asia/Shanghai'));
    $this->actingAs($user)->post($url)->assertSessionHasErrors('archived_at');
    expect($minute->fresh()->status)->toBe(MinuteStatus::Draft);

    $this->travelTo(CarbonImmutable::parse('2026-09-07 12:00:00', 'Asia/Shanghai'));
    $this->actingAs($user)->post($url, ['archived_at' => '2026-09-08T12:00'])->assertRedirect("/minutes/{$minute->id}");
    app(MinutesArchiveService::class)->returnForCorrection($minute, $user, '需要修改后重新归档');
    $minute->update(['meeting_end_at' => '2026-09-03 10:00:00']);
    MinuteFile::create(['meeting_minute_id' => $minute->id, 'original_name' => 'revised.pdf', 'object_key' => "minutes/{$minute->id}/revised.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $user->id]);

    $this->travelTo(CarbonImmutable::parse('2026-09-09 12:00:00', 'Asia/Shanghai'));
    $this->actingAs($user)->post($url)->assertRedirect("/minutes/{$minute->id}");
    $versions = $minute->fresh()->versions()->orderBy('version_no')->get();
    expect($versions)->toHaveCount(2)
        ->and($versions[0]->archived_at->format('Y-m-d H:i'))->toBe('2026-09-07 12:00')
        ->and($versions[1]->archived_at->format('Y-m-d H:i'))->toBe('2026-09-09 12:00')
        ->and($minute->fresh()->archived_at->format('Y-m-d H:i'))->toBe('2026-09-09 12:00')
        ->and($minute->fresh()->due_at->format('Y-m-d'))->toBe('2026-09-08')
        ->and($minute->fresh()->is_overdue)->toBeTrue();
});

test('validation messages identify the exact minute section field and participant row', function () {
    $organization = Organization::create(['external_code' => 'VALIDATION', 'name' => '校验提示单位']);
    $user = coreUser('minute_submitter', $organization);
    $minute = MeetingMinute::create([
        'organization_id' => $organization->id,
        'meeting_type' => 'party_branch',
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
        app(MinutesArchiveService::class)->archive($minute, $user, CarbonImmutable::parse('2026-09-02 10:00:00'));
        $this->fail('Expected archive validation to fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['meeting_year'][0])->toBe('基本信息第 2 项“会议年度”未填写。');
    }
});

test('college submitter cannot view another organization minute', function () {
    $a = Organization::create(['external_code' => 'A', 'name' => '学院A']);
    $b = Organization::create(['external_code' => 'B', 'name' => '学院B']);
    $user = coreUser('minute_submitter', $a);
    $minute = MeetingMinute::create(['organization_id' => $b->id, 'meeting_type' => 'party_branch', 'status' => MinuteStatus::Draft, 'created_by' => $user->id, 'updated_by' => $user->id]);
    $this->actingAs($user)->get("/minutes/{$minute->id}")->assertForbidden();
});

test('college submitter only sees and edits minutes created by themselves', function () {
    $organization = Organization::create(['external_code' => 'OWN', 'name' => '本单位']);
    $owner = coreUser('minute_submitter', $organization);
    $colleague = coreUser('minute_submitter', $organization);
    $ownMinute = MeetingMinute::create([
        'organization_id' => $organization->id,
        'meeting_type' => 'party_branch',
        'title' => '本人提交的纪要',
        'status' => MinuteStatus::Draft,
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);
    $colleagueMinute = MeetingMinute::create([
        'organization_id' => $organization->id,
        'meeting_type' => 'party_branch',
        'title' => '同学院其他人员提交的纪要',
        'status' => MinuteStatus::Draft,
        'created_by' => $colleague->id,
        'updated_by' => $colleague->id,
    ]);

    $this->actingAs($owner)->get('/minutes/party-branch')->assertOk()->assertInertia(fn (Assert $page) => $page
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
    $submitterA = coreUser('minute_submitter', $organizationA);
    $submitterB = coreUser('minute_submitter', $organizationB);
    $admin = coreUser('system_admin');

    $minuteA = MeetingMinute::create(['organization_id' => $organizationA->id, 'meeting_type' => 'party_branch', 'status' => MinuteStatus::Draft, 'created_by' => $submitterA->id, 'updated_by' => $submitterA->id]);
    $minuteB = MeetingMinute::create(['organization_id' => $organizationB->id, 'meeting_type' => 'party_branch', 'status' => MinuteStatus::Draft, 'created_by' => $submitterB->id, 'updated_by' => $submitterB->id]);

    $this->actingAs($admin)->get('/minutes/party-branch')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('minutes/Index')
        ->has('minutes.data', 2)
        ->where('canCreate', false));
    $this->actingAs($admin)->get("/minutes/{$minuteA->id}")->assertOk();
    $this->actingAs($admin)->get("/minutes/{$minuteB->id}")->assertOk();
    $this->actingAs($admin)->get("/minutes/{$minuteA->id}/edit")->assertForbidden();
});

test('archive creates immutable version and fixes due date', function () {
    $org = Organization::create(['external_code' => 'C', 'name' => '学院C']);
    $user = coreUser('minute_submitter', $org);
    foreach (range(3, 7) as $day) {
        Workday::create(['date' => "2026-09-0$day", 'is_workday' => true]);
    }
    $minute = MeetingMinute::create(['organization_id' => $org->id, 'meeting_type' => 'party_branch', 'meeting_year' => 2026, 'sequence_no' => 1, 'title' => '学院C2026年第1次党总支会议', 'meeting_start_at' => '2026-09-02 09:00:00', 'meeting_end_at' => '2026-09-02 10:00:00', 'first_topic_content' => '学习内容', 'status' => MinuteStatus::Draft, 'created_by' => $user->id, 'updated_by' => $user->id]);
    foreach (['chair', 'recorder', 'attendee'] as $role) {
        MinuteParticipant::create(['meeting_minute_id' => $minute->id, 'role_type' => $role, 'display_name' => $role, 'is_external' => true]);
    }
    MinuteFile::create(['meeting_minute_id' => $minute->id, 'original_name' => 'minutes.pdf', 'object_key' => 'test.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $user->id]);
    $result = app(MinutesArchiveService::class)->archive($minute, $user, CarbonImmutable::parse('2026-09-07 23:59:00'));
    expect($result->status)->toBe(MinuteStatus::Archived)->and($result->current_version)->toBe(1)->and($result->versions)->toHaveCount(1)->and($result->due_at->format('H:i:s'))->toBe('23:59:59');
});

test('only a PDF meeting minute can be uploaded and used for archive', function () {
    Storage::fake(config('filesystems.default'));
    $organization = Organization::create(['external_code' => 'SIGNED-PDF', 'name' => '签字纪要测试单位']);
    $user = coreUser('minute_submitter', $organization);
    $minute = MeetingMinute::create([
        'organization_id' => $organization->id,
        'meeting_type' => 'party_branch',
        'meeting_year' => 2026,
        'sequence_no' => 1,
        'title' => '会议纪要PDF测试',
        'meeting_start_at' => '2026-09-02 09:00:00',
        'meeting_end_at' => '2026-09-02 10:00:00',
        'first_topic_content' => '第一议题学习内容',
        'status' => MinuteStatus::Draft,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    foreach (['chair', 'recorder', 'attendee'] as $role) {
        MinuteParticipant::create(['meeting_minute_id' => $minute->id, 'role_type' => $role, 'display_name' => $role, 'is_external' => true]);
    }

    $this->actingAs($user)->post("/minutes/{$minute->id}/attachment", [
        'attachment' => UploadedFile::fake()->createWithContent('draft.docx', "PK\x03\x04test"),
    ])->assertSessionHasErrors('attachment');

    MinuteFile::create([
        'meeting_minute_id' => $minute->id,
        'original_name' => 'legacy.docx',
        'object_key' => 'minutes/legacy.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'size_bytes' => 10,
        'sha256' => str_repeat('a', 64),
        'uploaded_by' => $user->id,
    ]);
    $this->actingAs($user)->post("/minutes/{$minute->id}/archive")
        ->assertSessionHasErrors(['attachment' => '归档前必须上传主要领导签字的PDF会议纪要。']);
    expect($minute->fresh()->status)->toBe(MinuteStatus::Draft);

    $this->actingAs($user)->post("/minutes/{$minute->id}/attachment", [
        'attachment' => UploadedFile::fake()->createWithContent('fake.pdf', 'not actually a PDF'),
    ])->assertSessionHasErrors('attachment');

    $this->actingAs($user)->post("/minutes/{$minute->id}/attachment", [
        'attachment' => UploadedFile::fake()->createWithContent('signed.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"),
    ])->assertSessionHasNoErrors();
    $this->actingAs($user)->post("/minutes/{$minute->id}/archive")
        ->assertRedirect("/minutes/{$minute->id}");

    expect($minute->fresh()->status)->toBe(MinuteStatus::Archived)
        ->and($minute->files()->where('version_no', 1)->value('original_name'))->toBe('signed.pdf');
});

test('a signed PDF can be uploaded from the new minute form without first saving a draft', function () {
    Storage::fake(config('filesystems.default'));
    $organization = Organization::create(['external_code' => 'DIRECT-UPLOAD', 'name' => '直接上传测试单位']);
    $person = Person::create(['organization_id' => $organization->id, 'external_id' => 'DIRECT-1', 'employee_no' => 'DIRECT-1', 'name' => '提交人', 'status' => 'active']);
    $user = coreUser('minute_submitter', $organization);
    $user->update(['person_id' => $person->id]);
    $scope = $user->meetingScopeIds(MeetingType::PartyBranch)[0];

    $this->actingAs($user)->post('/minutes/party-branch', [
        'meeting_scope_id' => $scope,
        'attachment' => UploadedFile::fake()->createWithContent('invalid.pdf', 'not a PDF'),
    ])->assertSessionHasErrors('attachment');
    expect(MeetingMinute::count())->toBe(0);

    $this->actingAs($user)->post('/minutes/party-branch', [
        'meeting_scope_id' => $scope,
        'title' => '填表过程中上传',
        'first_topic_content' => '已填写的学习内容',
        'remarks' => '不应再保存的备注',
        'participants' => [['role_type' => 'chair', 'display_name' => '主持甲', 'is_external' => true]],
        'attachment' => UploadedFile::fake()->createWithContent('signed.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"),
    ])->assertSessionHasNoErrors();

    $minute = MeetingMinute::sole();
    expect($minute->status)->toBe(MinuteStatus::Draft)
        ->and($minute->title)->toBe('填表过程中上传')
        ->and($minute->first_topic_content)->toBe('已填写的学习内容')
        ->and($minute->remarks)->toBeNull()
        ->and($minute->participants()->value('display_name'))->toBe('主持甲')
        ->and($minute->files()->whereNull('version_no')->value('original_name'))->toBe('signed.pdf');
    Storage::disk(config('filesystems.default'))->assertExists($minute->files()->sole()->object_key);
});

test('only chair recorder and attendee are required among participant roles', function () {
    $organization = Organization::create(['external_code' => 'REQUIRED-ROLES', 'name' => '必选角色测试单位']);
    $user = coreUser('minute_submitter', $organization);
    foreach (['chair', 'recorder', 'attendee'] as $index => $role) {
        $incomplete = readyMinute($user, $organization, 17 + $index);
        $incomplete->participants()->where('role_type', $role)->delete();
        $this->actingAs($user)->post("/minutes/{$incomplete->id}/archive")
            ->assertSessionHasErrors('participants');
        expect($incomplete->fresh()->status)->toBe(MinuteStatus::Draft);
    }

    $minute = readyMinute($user, $organization, 20);
    $this->actingAs($user)->post("/minutes/{$minute->id}/archive")
        ->assertRedirect("/minutes/{$minute->id}");
    expect($minute->fresh()->status)->toBe(MinuteStatus::Archived);
});

test('uploaded attachment is visible while archive validation errors are returned', function () {
    $organization = Organization::create(['external_code' => 'FILES', 'name' => '附件测试单位']);
    $user = coreUser('minute_submitter', $organization);
    $minute = MeetingMinute::create([
        'organization_id' => $organization->id,
        'meeting_type' => 'party_branch',
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

test('only a system administrator can delete an existing minute while preserving its history', function () {
    Storage::fake(config('filesystems.default'));
    $organization = Organization::create(['external_code' => 'DELETE-MINUTE', 'name' => '删除测试单位']);
    $submitter = coreUser('minute_submitter', $organization);
    $manager = coreUser('minute_manager');
    $admin = coreUser('system_admin');
    $minute = readyMinute($submitter, $organization, 31);
    Storage::disk(config('filesystems.default'))->put($minute->files()->firstOrFail()->object_key, 'signed PDF');
    app(MinutesArchiveService::class)->archive($minute, $submitter, CarbonImmutable::parse('2026-09-07 12:00:00', 'Asia/Shanghai'));
    $file = $minute->files()->firstOrFail();

    $this->actingAs($submitter)->delete("/minutes/{$minute->id}")->assertForbidden();
    $this->actingAs($manager)->delete("/minutes/{$minute->id}")->assertForbidden();
    $this->actingAs($manager)->get("/minutes/{$minute->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canDelete', false)->etc());
    $this->actingAs($admin)->get('/minutes/party-branch')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('minutes.data.0.can_delete', true)->etc());
    $this->actingAs($admin)->get("/minutes/{$minute->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canDelete', true)->etc());
    $this->actingAs($admin)->delete("/minutes/{$minute->id}")->assertRedirect('/minutes/party-branch');

    expect(MeetingMinute::whereKey($minute->id)->exists())->toBeFalse()
        ->and(MeetingMinute::withTrashed()->whereKey($minute->id)->firstOrFail()->active_number_key)->toBeNull()
        ->and($minute->versions()->count())->toBe(1)
        ->and($minute->files()->count())->toBe(1)
        ->and(AuditLog::where('event', 'minutes.deleted')->where('subject_id', $minute->id)->firstOrFail()->metadata['title'])->toBe($minute->title);
    Storage::disk(config('filesystems.default'))->assertExists($file->object_key);
    $this->actingAs($admin)->get("/minutes/{$minute->id}")->assertNotFound();
    $this->actingAs($admin)->get("/minutes/{$minute->id}/files/{$file->id}")->assertNotFound();
    $this->actingAs($admin)->get('/minutes/party-branch')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('minutes.data', 0));

    $replacement = readyMinute($submitter, $organization, 31);
    expect($replacement->id)->not->toBe($minute->id);
});

test('system administrator can delete a draft from the joint meeting list', function () {
    $organization = Organization::create(['external_code' => 'DELETE-JOINT', 'name' => '联席删除测试单位']);
    $admin = coreUser('system_admin');
    $minute = MeetingMinute::create([
        'organization_id' => $organization->id,
        'meeting_type' => MeetingType::PartyGovernmentJoint,
        'status' => MinuteStatus::Draft,
        'title' => '待删除草稿',
        'created_by' => $admin->id,
        'updated_by' => $admin->id,
    ]);

    $this->actingAs($admin)->delete("/minutes/{$minute->id}")
        ->assertRedirect('/minutes/party-government-joint');
    expect(MeetingMinute::whereKey($minute->id)->exists())->toBeFalse()
        ->and(MeetingMinute::withTrashed()->whereKey($minute->id)->exists())->toBeTrue();
});

test('saving participant changes before archive makes the new roles available to archive', function () {
    $organization = Organization::create(['external_code' => 'SAVE-FIRST', 'name' => '保存后归档单位']);
    $user = coreUser('minute_submitter', $organization);
    $minute = MeetingMinute::create([
        'organization_id' => $organization->id,
        'meeting_type' => 'party_branch',
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
