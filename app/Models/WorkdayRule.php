<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkdayRule extends Model
{
    protected $fillable = ['kind', 'start_date', 'end_date', 'start_month_day', 'end_month_day', 'is_workday', 'name', 'updated_by'];

    protected function casts(): array
    {
        return ['is_workday' => 'boolean'];
    }

    public function appliesTo(string $date, string $monthDay): bool
    {
        if ($this->kind === 'once') {
            return $this->start_date <= $date && $date <= $this->end_date;
        }

        return $this->matchesMonthDay($monthDay);
    }

    public function matchesMonthDay(string $monthDay): bool
    {
        if ($this->kind !== 'annual') {
            return false;
        }

        return $this->start_month_day <= $this->end_month_day
            ? $this->start_month_day <= $monthDay && $monthDay <= $this->end_month_day
            : $monthDay >= $this->start_month_day || $monthDay <= $this->end_month_day;
    }
}
