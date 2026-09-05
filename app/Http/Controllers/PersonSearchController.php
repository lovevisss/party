<?php

namespace App\Http\Controllers;

use App\Enums\MeetingType;
use App\Models\MeetingScope;
use App\Models\Person;
use App\Services\MeetingScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonSearchController extends Controller
{
    public function __invoke(Request $request, MeetingScopeService $scopes): JsonResponse
    {
        $term = trim($request->string('q')->toString());
        $q = Person::with('organization:id,name,external_code')->where('status', 'active')->when($term !== '', fn ($builder) => $builder->where(fn ($x) => $x->where('name', 'like', "%$term%")->orWhere('employee_no', 'like', "%$term%")));
        $type = MeetingType::tryFrom($request->string('meeting_type')->toString());
        $scope = $request->filled('meeting_scope_id') ? MeetingScope::find($request->integer('meeting_scope_id')) : null;
        if ($type && $scope) {
            abort_unless($scope->meeting_type === $type, 422);
            abort_unless($request->user()->manages($type) || in_array($scope->id, $request->user()->meetingScopeIds($type), true), 403);
            if ($request->string('mode')->toString() !== 'all') {
                $q->whereIn('organization_id', $scopes->organizationIds($scope));
            }
        }

        return response()->json($q->orderBy('name')->limit(30)->get(['id', 'organization_id', 'employee_no', 'name']));
    }
}
