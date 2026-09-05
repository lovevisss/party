<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MeetingType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\RoleAssignment;
use App\Services\AuditService;
use App\Services\AuthorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuthorizationController extends Controller
{
    public function store(Request $request, AuthorizationService $authorizations, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'person_id' => ['required', 'integer', 'exists:people,id'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'meeting_type' => ['nullable', Rule::enum(MeetingType::class)],
        ]);
        $person = Person::where('status', 'active')->whereKey((int) $data['person_id'])->firstOrFail();
        $type = isset($data['meeting_type']) ? MeetingType::from($data['meeting_type']) : null;
        $assignment = $authorizations->grant($person, UserRole::from($data['role']), $type, $request->user()->id);
        $audit->record('authorization.granted', $assignment, ['person_id' => $person->id, 'role' => $data['role'], 'scope_key' => $assignment->scope_key]);

        return back()->with('success', '人员授权已保存。');
    }

    public function destroy(RoleAssignment $assignment, AuthorizationService $authorizations, AuditService $audit): RedirectResponse
    {
        $metadata = ['user_id' => $assignment->user_id, 'role' => $assignment->getRawOriginal('role'), 'scope_key' => $assignment->scope_key];
        $authorizations->revoke($assignment);
        $audit->record('authorization.revoked', $assignment, $metadata);

        return back()->with('success', '授权已撤销。');
    }
}
