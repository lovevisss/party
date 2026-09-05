<?php

use App\Models\Organization;
use App\Models\ParticipantPreset;
use App\Models\Person;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function presetSubmitter(Organization $organization): User
{
    $user = User::factory()->create(['cas_account' => fake()->unique()->userName(), 'is_active' => true]);
    RoleAssignment::create(['user_id' => $user->id, 'role' => 'college_submitter', 'organization_id' => $organization->id]);

    return $user;
}

test('submitter saves applies and replaces their participant preset with roles', function () {
    $organization = Organization::create(['external_code' => 'PRESET-A', 'name' => '模板单位']);
    $otherOrganization = Organization::create(['external_code' => 'PRESET-B', 'name' => '外单位']);
    $user = presetSubmitter($organization);
    $chair = Person::create(['organization_id' => $organization->id, 'external_id' => 'P-1', 'employee_no' => 'P-1', 'name' => '主持甲', 'status' => 'active']);
    $attendee = Person::create(['organization_id' => $otherOrganization->id, 'external_id' => 'P-2', 'employee_no' => 'P-2', 'name' => '参会乙', 'status' => 'active']);

    $this->actingAs($user)->post('/participant-presets', [
        'organization_id' => $organization->id,
        'name' => '党总支会议固定名单',
        'participants' => [
            ['person_id' => $chair->id, 'role_type' => 'chair', 'display_name' => $chair->name, 'is_external' => false],
            ['person_id' => $attendee->id, 'role_type' => 'attendee', 'display_name' => $attendee->name, 'is_external' => false],
        ],
    ])->assertSessionHasNoErrors();

    $preset = ParticipantPreset::firstOrFail();
    expect($preset->items()->count())->toBe(2)
        ->and($preset->items()->pluck('role_type')->all())->toBe(['chair', 'attendee']);
    $this->actingAs($user)->get('/minutes/create')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('minutes/Form')
        ->has('participantPresets', 1)
        ->where('participantPresets.0.name', '党总支会议固定名单')
        ->has('participantPresets.0.items', 2));

    $this->actingAs($user)->post('/participant-presets', [
        'organization_id' => $organization->id,
        'name' => '党总支会议固定名单',
        'participants' => [
            ['person_id' => $chair->id, 'role_type' => 'recorder', 'display_name' => $chair->name, 'is_external' => false],
        ],
    ])->assertSessionHasNoErrors();

    expect(ParticipantPreset::count())->toBe(1)
        ->and($preset->fresh()->items()->count())->toBe(1)
        ->and($preset->fresh()->items()->value('role_type'))->toBe('recorder');
});

test('participant presets are private and inactive people cannot be saved', function () {
    $organization = Organization::create(['external_code' => 'PRESET-PRIVATE', 'name' => '私有模板单位']);
    $owner = presetSubmitter($organization);
    $otherUser = presetSubmitter($organization);
    $inactivePerson = Person::create(['organization_id' => $organization->id, 'external_id' => 'P-OFF', 'employee_no' => 'P-OFF', 'name' => '停用人员', 'status' => 'inactive']);
    $preset = ParticipantPreset::create(['user_id' => $owner->id, 'organization_id' => $organization->id, 'name' => '个人名单']);

    $this->actingAs($otherUser)->get('/minutes/create')->assertOk()->assertInertia(fn (Assert $page) => $page->has('participantPresets', 0));
    $this->actingAs($otherUser)->delete("/participant-presets/{$preset->id}")->assertForbidden();
    $this->actingAs($owner)->post('/participant-presets', [
        'organization_id' => $organization->id,
        'name' => '含停用人员',
        'participants' => [[
            'person_id' => $inactivePerson->id,
            'role_type' => 'attendee',
            'display_name' => $inactivePerson->name,
            'is_external' => false,
        ]],
    ])->assertSessionHasErrors([
        'participants.0.person_id' => '人员清单第 1 行：人员已停用或不存在。',
    ]);
});
