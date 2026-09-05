<?php

namespace App\Http\Controllers;

use App\Enums\MeetingType;
use App\Models\MeetingMinute;
use App\Models\ParticipantPreset;
use App\Models\Person;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ParticipantPresetController extends Controller
{
    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        Gate::authorize('create', MeetingMinute::class);
        $data = $request->validate([
            'meeting_type' => ['required', Rule::enum(MeetingType::class)],
            'meeting_scope_id' => 'required|integer|exists:meeting_scopes,id',
            'name' => 'required|string|min:2|max:50',
            'participants' => 'required|array|min:1|max:200',
            'participants.*.person_id' => 'nullable|integer|exists:people,id',
            'participants.*.role_type' => ['required', Rule::in(['chair', 'recorder', 'attendee', 'absent', 'observer'])],
            'participants.*.display_name' => 'required|string|max:100',
            'participants.*.is_external' => 'required|boolean',
        ], [
            'participants.required' => '当前人员清单为空，无法保存。',
            'participants.min' => '当前人员清单为空，无法保存。',
            'participants.*.person_id.exists' => '人员清单第 :position 行：所选人员不存在。',
            'participants.*.role_type.required' => '人员清单第 :position 行：请选择人员角色。',
            'participants.*.display_name.required' => '人员清单第 :position 行：人员姓名不能为空。',
        ], [
            'meeting_scope_id' => '会议范围',
            'name' => '清单名称',
            'participants' => '当前人员清单',
        ]);

        $type = MeetingType::from($data['meeting_type']);
        $scopeId = (int) $data['meeting_scope_id'];
        abort_unless(in_array($scopeId, $request->user()->meetingScopeIds($type), true), 403);
        foreach ($data['participants'] as $index => $participant) {
            if ($participant['is_external']) {
                continue;
            }
            $person = isset($participant['person_id'])
                ? Person::whereKey($participant['person_id'])->where('status', 'active')->first()
                : null;
            if (! $person) {
                throw ValidationException::withMessages([
                    "participants.{$index}.person_id" => '人员清单第 '.($index + 1).' 行：人员已停用或不存在。',
                ]);
            }
        }

        $preset = DB::transaction(function () use ($request, $data, $type, $scopeId): ParticipantPreset {
            $preset = ParticipantPreset::updateOrCreate(
                ['user_id' => $request->user()->id, 'meeting_type' => $type->value, 'meeting_scope_id' => $scopeId, 'name' => trim($data['name'])],
                ['organization_id' => $request->user()->person?->organization_id],
            );
            $preset->items()->delete();
            foreach ($data['participants'] as $index => $participant) {
                $preset->items()->create([
                    'person_id' => $participant['is_external'] ? null : $participant['person_id'],
                    'role_type' => $participant['role_type'],
                    'display_name' => $participant['display_name'],
                    'is_external' => $participant['is_external'],
                    'sort_order' => $index,
                ]);
            }

            return $preset;
        });
        $audit->record('participant_preset.saved', $preset, ['name' => $preset->name, 'items' => count($data['participants'])]);

        return back()->with('success', '常用人员清单已保存。');
    }

    public function destroy(Request $request, ParticipantPreset $participantPreset, AuditService $audit): RedirectResponse
    {
        abort_unless($participantPreset->user_id === $request->user()->id, 403);
        abort_unless(in_array($participantPreset->meeting_scope_id, $request->user()->meetingScopeIds($participantPreset->meeting_type), true), 403);
        $audit->record('participant_preset.deleted', $participantPreset, ['name' => $participantPreset->name]);
        $participantPreset->delete();

        return back()->with('success', '常用人员清单已删除。');
    }
}
