<?php

namespace Database\Factories;

use App\Models\RosterPeriod;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

class RosterPeriodFactory extends Factory
{
    protected $model = RosterPeriod::class;

    public function definition(): array
    {
        $weekStart = now('Pacific/Auckland')->startOfWeek();

        return [
            'organization_id' => 1,
            'site_id' => Site::factory(),
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekStart->copy()->addDays(7)->toDateString(),
            'version' => 1,
            'status' => RosterPeriod::STATUS_DRAFT,
            'shift_count' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => RosterPeriod::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }
}
