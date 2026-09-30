<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WorkdayRule;
use App\Services\AuditService;
use App\Services\WorkdayRuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkdayRuleController extends Controller
{
    public function store(Request $request, WorkdayRuleService $rules, AuditService $audit): RedirectResponse
    {
        $rule = $rules->save(null, $this->validated($request), $request->user()->id);
        $audit->record('workday.rule_saved', $rule, ['name' => $rule->name]);

        return back()->with('success', '日历规则已保存。');
    }

    public function update(Request $request, WorkdayRule $workdayRule, WorkdayRuleService $rules, AuditService $audit): RedirectResponse
    {
        $rule = $rules->save($workdayRule, $this->validated($request), $request->user()->id);
        $audit->record('workday.rule_saved', $rule, ['name' => $rule->name]);

        return back()->with('success', '日历规则已更新。');
    }

    public function destroy(WorkdayRule $workdayRule, AuditService $audit): RedirectResponse
    {
        $audit->record('workday.rule_deleted', $workdayRule, ['name' => $workdayRule->name]);
        $workdayRule->delete();

        return back()->with('success', '日历规则已删除。');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', Rule::in(['once', 'annual'])],
            'start_date' => ['required_if:kind,once', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['required_if:kind,once', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'start_month_day' => ['required_if:kind,annual', 'nullable', 'regex:/^\d{2}-\d{2}$/'],
            'end_month_day' => ['required_if:kind,annual', 'nullable', 'regex:/^\d{2}-\d{2}$/'],
            'is_workday' => ['required_if:kind,once', 'nullable', 'boolean'],
            'name' => ['required', 'string', 'max:100'],
        ]);
    }
}
