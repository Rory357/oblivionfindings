<?php

namespace Database\Factories;

use App\Models\RosterSuggestionRun;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RosterSuggestionRunFactory extends Factory
{
    protected $model = RosterSuggestionRun::class;

    public function definition(): array
    {
        $weekStart = now('Pacific/Auckland')->startOfWeek();

        return [
            'organization_id' => 1,
            'site_id' => Site::factory(),
            'requested_by' => User::factory(),
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekStart->copy()->addDays(7)->toDateString(),
            'status' => RosterSuggestionRun::STATUS_COMPLETED,
            'strategy' => 'eligibility_scoring',
            'totals' => [],
            'expires_at' => now()->addDay(),
        ];
    }
}
