<?php

namespace App\Services;

use App\Models\Workday;
use App\Models\WorkdayRule;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class WorkdayService
{
    public function thirdWorkdayAfter(CarbonInterface $date): CarbonInterface
    {
        $cursor = $date->copy()->startOfDay();
        $end = $cursor->copy()->addDays(400);
        $singleDays = Workday::whereBetween('date', [$cursor->toDateString(), $end->toDateString()])->get()->keyBy('date');
        $onceRules = WorkdayRule::where('kind', 'once')->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>', $cursor->toDateString())->get();
        $annualRules = WorkdayRule::where('kind', 'annual')->get();
        $found = 0;
        for ($i = 0; $i < 400; $i++) {
            $cursor = $cursor->addDay();
            $day = $cursor->toDateString();
            $monthDay = $cursor->format('m-d');
            $workday = $singleDays->get($day);
            $once = $onceRules->first(fn (WorkdayRule $rule): bool => $rule->appliesTo($day, $monthDay));
            $annual = $annualRules->first(fn (WorkdayRule $rule): bool => $rule->matchesMonthDay($monthDay));
            $isWorkday = $workday->is_workday ?? $once->is_workday ?? ($annual ? false : $cursor->isWeekday());
            if ($isWorkday && ++$found === 3) {
                return $cursor->endOfDay();
            }
        } throw ValidationException::withMessages(['workday' => '无法计算三个工作日后的截止时间。']);
    }
}
