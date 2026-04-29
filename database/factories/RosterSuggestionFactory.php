<?php

namespace Database\Factories;

use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RosterSuggestionFactory extends Factory
{
    protected $model = RosterSuggestion::class;

    public function definition(): array
    {
        return [
            'roster_suggestion_run_id' => RosterSuggestionRun::factory(),
            'shift_id' => Shift::factory()->unassigned(),
            'candidate_user_id' => User::factory(),
            'rank' => 1,
            'score' => fake()->numberBetween(10, 100),
            'reasons' => [],
            'eligibility_snapshot' => [],
            'status' => RosterSuggestion::STATUS_SUGGESTED,
        ];
    }
}
