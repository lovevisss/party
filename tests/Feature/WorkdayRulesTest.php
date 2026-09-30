<?php

use App\Enums\MeetingType;
use App\Enums\UserRole;
use App\Models\MeetingMinute;
use App\Models\MeetingScope;
use App\Models\MinuteFile;
use App\Models\MinuteParticipant;
use App\Models\Organization;
use App\Models\Person;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Models\WorkdayRule;
use App\Services\MinutesArchiveService;
use App\Services\WorkdayService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function calendarAdmin(): User
{
    $user = User::factory()->create();
    RoleAssignment::create(['user_id' => $user->id, 'role' => UserRole::SystemAdmin, 'scope_key' => 'global']);

    return $user;
}

function calendarSubmitter(): array
{
    $organization = Organization::create(['external_code' => 'CALENDAR-ORG', 'name' => '日历测试单位']);
    $person = Person::create(['organization_id' => $organization->id, 'external_id' => 'CALENDAR-1', 'employee_no' => 'CALENDAR-1', 'name' => '提交人', 'status' => 'active']);
    $user = User::factory()->create(['person_id' => $person->id]);
    $scope = MeetingScope::where('meeting_type', MeetingType::PartyBranch->value)->firstOrFail();
    $scope->organizations()->attach($organization->id);
    RoleAssignment::create(['user_id' => $user->id, 'role' => UserRole::MinuteSubmitter, 'meeting_type' => MeetingType::PartyBranch, 'meeting_scope_id' => $scope->id, 'scope_key' => 'meeting:party_branch:scope:'.$scope->id]);

    return [$user, $organization, $scope];
}

function calendarMinute(User $user, Organization $organization, MeetingScope $scope): MeetingMinute
{
    $minute = MeetingMinute::create([
        'organization_id' => $organization->id,
        'meeting_scope_id' => $scope->id,
        'meeting_type' => MeetingType::PartyBranch,
        'meeting_year' => 2026,
        'sequence_no' => 1,
        'title' => '工作日历测试纪要',
        'meeting_start_at' => '2026-09-02 09:00:00',
        'meeting_end_at' => '2026-09-02 10:00:00',
        'status' => 'draft',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    foreach (['chair', 'recorder', 'attendee'] as $role) {
        MinuteParticipant::create(['meeting_minute_id' => $minute->id, 'role_type' => $role, 'display_name' => $role, 'is_external' => true]);
    }
    MinuteFile::create(['meeting_minute_id' => $minute->id, 'original_name' => 'signed.pdf', 'object_key' => "minutes/{$minute->id}/signed.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $user->id]);

    return $minute;
}

test('calendar rules use inclusive ranges and single dates override range and annual rules', function () {
    $admin = calendarAdmin();
    $this->actingAs($admin)->post('/admin/workday-rules', ['kind' => 'once', 'start_date' => '2026-09-03', 'end_date' => '2026-09-04', 'is_workday' => false, 'name' => '集中休假'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/admin/workday-rules', ['kind' => 'annual', 'start_month_day' => '09-07', 'end_month_day' => '09-08', 'name' => '固定假期'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->get('/admin/workdays')->assertOk()->assertInertia(fn (Assert $page) => $page->has('rules', 2)->etc());
    expect(app(WorkdayService::class)->thirdWorkdayAfter(CarbonImmutable::parse('2026-09-02'))->toDateString())->toBe('2026-09-11');

    $this->actingAs($admin)->post('/admin/workdays', ['date' => '2026-09-04', 'is_workday' => true, 'name' => '补班'])->assertSessionHasNoErrors();
    expect(app(WorkdayService::class)->thirdWorkdayAfter(CarbonImmutable::parse('2026-09-02'))->toDateString())->toBe('2026-09-10');
    $this->actingAs($admin)->delete('/admin/workdays/2026-09-04')->assertRedirect();
    expect(app(WorkdayService::class)->thirdWorkdayAfter(CarbonImmutable::parse('2026-09-02'))->toDateString())->toBe('2026-09-11');
    $this->actingAs($admin)->post('/admin/workday-rules', ['kind' => 'once', 'start_date' => '2026-09-07', 'end_date' => '2026-09-07', 'is_workday' => true, 'name' => '年度假期补班'])->assertSessionHasNoErrors();
    expect(app(WorkdayService::class)->thirdWorkdayAfter(CarbonImmutable::parse('2026-09-02'))->toDateString())->toBe('2026-09-10');
    $this->actingAs($admin)->post('/admin/workdays', ['date' => '2026-09-07', 'is_workday' => false, 'name' => '临时休息'])->assertSessionHasNoErrors();
    expect(app(WorkdayService::class)->thirdWorkdayAfter(CarbonImmutable::parse('2026-09-02'))->toDateString())->toBe('2026-09-11');
});

test('annual holidays span the year boundary and February 29 only applies in leap years', function () {
    $admin = calendarAdmin();
    $this->actingAs($admin)->post('/admin/workday-rules', ['kind' => 'annual', 'start_month_day' => '12-31', 'end_month_day' => '01-02', 'name' => '跨年假期'])->assertSessionHasNoErrors();
    expect(app(WorkdayService::class)->thirdWorkdayAfter(CarbonImmutable::parse('2026-12-30'))->toDateString())->toBe('2027-01-06');

    $this->actingAs($admin)->post('/admin/workday-rules', ['kind' => 'annual', 'start_month_day' => '02-29', 'end_month_day' => '02-29', 'name' => '闰日'])->assertSessionHasNoErrors();
    expect(app(WorkdayService::class)->thirdWorkdayAfter(CarbonImmutable::parse('2028-02-28'))->toDateString())->toBe('2028-03-03')
        ->and(app(WorkdayService::class)->thirdWorkdayAfter(CarbonImmutable::parse('2027-02-28'))->toDateString())->toBe('2027-03-03');
});

test('overlapping rules are rejected while editing and deleting remain available', function () {
    $admin = calendarAdmin();
    $this->actingAs($admin)->post('/admin/workday-rules', ['kind' => 'once', 'start_date' => '2026-09-03', 'end_date' => '2026-09-04', 'is_workday' => false, 'name' => '原范围'])->assertSessionHasNoErrors();
    $once = WorkdayRule::where('kind', 'once')->firstOrFail();
    $this->actingAs($admin)->post('/admin/workday-rules', ['kind' => 'once', 'start_date' => '2026-09-04', 'end_date' => '2026-09-06', 'is_workday' => true, 'name' => '重叠范围'])->assertSessionHasErrors('start_date');
    $this->actingAs($admin)->put('/admin/workday-rules/'.$once->id, ['kind' => 'once', 'start_date' => '2026-09-05', 'end_date' => '2026-09-06', 'is_workday' => true, 'name' => '调整范围'])->assertSessionHasNoErrors();
    expect($once->fresh()->name)->toBe('调整范围');

    $this->actingAs($admin)->post('/admin/workday-rules', ['kind' => 'annual', 'start_month_day' => '12-31', 'end_month_day' => '01-02', 'name' => '跨年'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/admin/workday-rules', ['kind' => 'annual', 'start_month_day' => '01-01', 'end_month_day' => '01-03', 'name' => '重叠'])->assertSessionHasErrors('start_month_day');
    $annual = WorkdayRule::where('kind', 'annual')->firstOrFail();
    $this->actingAs($admin)->put('/admin/workday-rules/'.$annual->id, ['kind' => 'annual', 'start_month_day' => '12-30', 'end_month_day' => '01-01', 'name' => '修改跨年'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/admin/workday-rules', ['kind' => 'annual', 'start_month_day' => '02-30', 'end_month_day' => '03-01', 'name' => '无效日期'])->assertSessionHasErrors('start_month_day');
    $this->actingAs($admin)->delete('/admin/workday-rules/'.$once->id)->assertRedirect();
    expect(WorkdayRule::whereKey($once->id)->exists())->toBeFalse();
});

test('only system administrators can maintain calendar rules', function () {
    [$user] = calendarSubmitter();
    $rule = WorkdayRule::create(['kind' => 'annual', 'start_month_day' => '01-01', 'end_month_day' => '01-01', 'is_workday' => false, 'name' => '元旦']);
    $this->actingAs($user)->post('/admin/workday-rules', ['kind' => 'annual', 'start_month_day' => '03-01', 'end_month_day' => '03-01', 'name' => '测试'])->assertForbidden();
    $this->actingAs($user)->put('/admin/workday-rules/'.$rule->id, ['kind' => 'annual', 'start_month_day' => '03-01', 'end_month_day' => '03-01', 'name' => '测试'])->assertForbidden();
    $this->actingAs($user)->delete('/admin/workday-rules/'.$rule->id)->assertForbidden();
});

test('draft preview uses new rules while archived versions remain unchanged and resubmission keeps the first verdict', function () {
    $admin = calendarAdmin();
    [$user, $organization, $scope] = calendarSubmitter();
    $minute = calendarMinute($user, $organization, $scope);
    $this->actingAs($admin)->post('/admin/workday-rules', ['kind' => 'once', 'start_date' => '2026-09-03', 'end_date' => '2026-09-04', 'is_workday' => false, 'name' => '休假'])->assertSessionHasNoErrors();
    $this->actingAs($user)->getJson('/minutes/deadline?meeting_end_at=2026-09-02T10%3A00')->assertOk()->assertJsonPath('due_at', '2026-09-09T23:59:59+08:00');

    $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00', 'Asia/Shanghai'));
    app(MinutesArchiveService::class)->archive($minute, $user);
    expect($minute->fresh()->is_overdue)->toBeTrue()->and($minute->fresh()->due_at->toDateString())->toBe('2026-09-09');

    $this->actingAs($admin)->post('/admin/workdays', ['date' => '2026-09-04', 'is_workday' => true, 'name' => '补班'])->assertSessionHasNoErrors();
    expect($minute->fresh()->due_at->toDateString())->toBe('2026-09-09');
    app(MinutesArchiveService::class)->returnForCorrection($minute, $user, '需要修改后重新归档');
    MinuteFile::create(['meeting_minute_id' => $minute->id, 'original_name' => 'revised.pdf', 'object_key' => "minutes/{$minute->id}/revised.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('b', 64), 'uploaded_by' => $user->id]);
    $this->travelTo(CarbonImmutable::parse('2026-09-11 12:00', 'Asia/Shanghai'));
    app(MinutesArchiveService::class)->archive($minute, $user);
    $versions = $minute->fresh()->versions()->orderBy('version_no')->get();
    expect($versions)->toHaveCount(2)
        ->and($versions[0]->due_at->toDateString())->toBe('2026-09-09')
        ->and($versions[0]->is_overdue)->toBeTrue()
        ->and($versions[1]->due_at->toDateString())->toBe('2026-09-08')
        ->and($versions[1]->is_overdue)->toBeTrue()
        ->and($minute->fresh()->is_overdue)->toBeTrue();
    $this->actingAs($user)->get('/minutes/'.$minute->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('firstArchivedAt', fn ($time) => str_contains($time, '2026-09-10'))->etc());
});
