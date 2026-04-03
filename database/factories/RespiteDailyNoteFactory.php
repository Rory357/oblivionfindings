<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\RespiteDailyNote;
use Illuminate\Database\Eloquent\Factories\Factory;

class RespiteDailyNoteFactory extends Factory
{
    protected $model = RespiteDailyNote::class;

    public function definition(): array
    {
        return [
            'stay_id' => 1,
            'client_id' => Client::factory(),
            'note_date' => fake()->date(),
            'content' => fake()->paragraph(),
        ];
    }
}
