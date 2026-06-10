<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrPayrollRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class HrPayrollRunFactory extends Factory
{
    protected $model = HrPayrollRun::class;

    public function definition(): array
    {
        $periodStart = Carbon::parse(fake()->dateTimeBetween('-2 months', '-2 weeks'))->startOfWeek();

        return [
            'tenant_id' => 1,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodStart->copy()->addDays(13)->toDateString(),
            'status' => 'draft',
            'export_format' => 'csv',
            'total_hours' => 0,
            'total_gross' => 0,
            'total_staff' => 0,
            'validation_errors' => [],
            'created_by' => User::factory(),
        ];
    }
}
