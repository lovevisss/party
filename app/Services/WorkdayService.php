<?php

namespace App\Services;

use App\Models\Workday;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class WorkdayService
{
    public function thirdWorkdayAfter(CarbonInterface $date): CarbonInterface
    {
        $cursor = $date->copy()->startOfDay();
        $found = 0;
        for ($i = 0; $i < 40; $i++) {
            $cursor = $cursor->addDay();
            $workday = Workday::find($cursor->toDateString());
            $isWorkday = $workday ? $workday->is_workday : $cursor->isWeekday();
            if ($isWorkday && ++$found === 3) {
                return $cursor->endOfDay();
            }
        } throw ValidationException::withMessages(['workday' => '无法计算三个工作日后的截止时间。']);
    }
}
