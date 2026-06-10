<?php

namespace Database\Factories\Hr;

use App\Domain\Hr\Models\HrTimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class HrTimeEntryFactory extends Factory
{
    protected $model = HrTimeEntry::class;

    public function definition(): array
    {
        $entryDate = Carbon::parse(fake()->dateTimeBetween('-2 weeks', 'now'))->startOfDay();
        $clockIn = $entryDate->copy()->setTime(9, 0);
        $clockOut = $clockIn->copy()->addHours(8);

        return [
            'tenant_id' => 1,
            'user_id' => User::factory(),
            'entry_date' => $entryDate->toDateString(),
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
            'break_minutes' => 30,
            'total_hours' => 7.5,
            'entry_type' => 'manual',
            'status' => 'approved',
            'notes' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
