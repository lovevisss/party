<?php

namespace App\Http\Controllers;

use App\Models\Person;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());
        $q = Person::with('organization:id,name,external_code')->where('status', 'active')->when($term !== '', fn ($builder) => $builder->where(fn ($x) => $x->where('name', 'like', "%$term%")->orWhere('employee_no', 'like', "%$term%")));
        if (! $request->user()->hasRole('school_manager') && ! $request->user()->hasRole('system_admin')) {
            $q->whereIn('organization_id', $request->user()->organizationIds());
        }

        return response()->json($q->limit(20)->get(['id', 'organization_id', 'employee_no', 'name']));
    }
}
