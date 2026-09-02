<?php

use App\Enums\MinuteStatus;
use App\Models\MeetingMinute;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

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
    foreach ([['2026-09-03', true], ['2026-09-04', false], ['2026-09-05', true], ['2026-09-06', false], ['2026-09-07', true]] as [$date, $isWorkday]) Workday::create(['date' => $date, 'is_workday' => $isWorkday]);
    $due = app(WorkdayService::class)->thirdWorkdayAfter(now()->setDate(2026, 9, 2));
    expect($due->toDateTimeString())->toBe('2026-09-07 23:59:59');
});

test('college submitter cannot view another organization minute', function () {
    $a = Organization::create(['external_code' => 'A', 'name' => '学院A']);
    $b = Organization::create(['external_code' => 'B', 'name' => '学院B']);
    $user = coreUser('college_submitter', $a);
    $minute = MeetingMinute::create(['organization_id'=>$b->id,'meeting_type'=>'party_committee','status'=>MinuteStatus::Draft,'created_by'=>$user->id,'updated_by'=>$user->id]);
    $this->actingAs($user)->get("/minutes/{$minute->id}")->assertForbidden();
});

test('archive creates immutable version and fixes due date', function () {
    $org=Organization::create(['external_code'=>'C','name'=>'学院C']);$user=coreUser('college_submitter',$org);
    foreach(range(3,7) as $day)Workday::create(['date'=>"2026-09-0$day",'is_workday'=>true]);
    $minute=MeetingMinute::create(['organization_id'=>$org->id,'meeting_type'=>'party_committee','meeting_year'=>2026,'sequence_no'=>1,'title'=>'学院C2026年第1次党委会','meeting_start_at'=>'2026-09-02 09:00:00','meeting_end_at'=>'2026-09-02 10:00:00','first_topic_content'=>'学习内容','status'=>MinuteStatus::Draft,'created_by'=>$user->id,'updated_by'=>$user->id]);
    foreach(['chair','recorder','attendee'] as $role)MinuteParticipant::create(['meeting_minute_id'=>$minute->id,'role_type'=>$role,'display_name'=>$role,'is_external'=>true]);
    MinuteFile::create(['meeting_minute_id'=>$minute->id,'original_name'=>'minutes.pdf','object_key'=>'test.pdf','mime_type'=>'application/pdf','size_bytes'=>10,'sha256'=>str_repeat('a',64),'uploaded_by'=>$user->id]);
    $result=app(MinutesArchiveService::class)->archive($minute,$user);
    expect($result->status)->toBe(MinuteStatus::Archived)->and($result->current_version)->toBe(1)->and($result->versions)->toHaveCount(1)->and($result->due_at->format('H:i:s'))->toBe('23:59:59');
});
