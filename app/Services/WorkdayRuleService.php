<?php

namespace App\Services;

use App\Models\WorkdayRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkdayRuleService
{
    /** @param array<string, mixed> $data */
    public function save(?WorkdayRule $rule, array $data, int $updatedBy): WorkdayRule
    {
        return DB::transaction(function () use ($rule, $data, $updatedBy): WorkdayRule {
            $kind = $data['kind'];
            if ($kind === 'annual') {
                foreach (['start_month_day', 'end_month_day'] as $field) {
                    [$month, $day] = array_map('intval', explode('-', $data[$field]));
                    if (! checkdate($month, $day, 2000)) {
                        throw ValidationException::withMessages([$field => '请输入有效的月日。']);
                    }
                }
                $candidate = new WorkdayRule(['kind' => 'annual', 'start_month_day' => $data['start_month_day'], 'end_month_day' => $data['end_month_day']]);
                $existing = WorkdayRule::where('kind', 'annual')->when($rule, fn ($query) => $query->whereKeyNot($rule->id))->get();
                $day = CarbonImmutable::create(2000, 1, 1);
                for ($i = 0; $i < 366; $i++, $day = $day->addDay()) {
                    $monthDay = $day->format('m-d');
                    if ($candidate->matchesMonthDay($monthDay) && $existing->contains(fn (WorkdayRule $item): bool => $item->matchesMonthDay($monthDay))) {
                        throw ValidationException::withMessages(['start_month_day' => '年度假期与已有规则重叠。']);
                    }
                }
            } else {
                $overlap = WorkdayRule::where('kind', 'once')
                    ->where('start_date', '<=', $data['end_date'])
                    ->where('end_date', '>=', $data['start_date'])
                    ->when($rule, fn ($query) => $query->whereKeyNot($rule->id))->exists();
                if ($overlap) {
                    throw ValidationException::withMessages(['start_date' => '日期范围与已有规则重叠。']);
                }
            }

            $rule ??= new WorkdayRule;
            $rule->fill([
                'kind' => $kind,
                'start_date' => $kind === 'once' ? $data['start_date'] : null,
                'end_date' => $kind === 'once' ? $data['end_date'] : null,
                'start_month_day' => $kind === 'annual' ? $data['start_month_day'] : null,
                'end_month_day' => $kind === 'annual' ? $data['end_month_day'] : null,
                'is_workday' => $kind === 'once' ? $data['is_workday'] : false,
                'name' => $data['name'],
                'updated_by' => $updatedBy,
            ]);
            $rule->save();

            return $rule;
        });
    }
}
