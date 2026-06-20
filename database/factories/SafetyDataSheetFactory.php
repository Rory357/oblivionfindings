<?php

namespace Database\Factories;

use App\Models\HazardousSubstance;
use App\Models\SafetyDataSheet;
use Illuminate\Database\Eloquent\Factories\Factory;

class SafetyDataSheetFactory extends Factory
{
    protected $model = SafetyDataSheet::class;

    public function definition(): array
    {
        $issued = fake()->dateTimeBetween('-2 years', '-1 month');

        return [
            'hazardous_substance_id' => HazardousSubstance::factory(),
            'version' => fake()->randomElement(['1.0', '2.0', '2.1', '3.0']),
            'issue_date' => $issued,
            'review_date' => fake()->dateTimeBetween('+3 months', '+2 years'),
            'supplier_name' => fake()->company(),
            'supplier_contact' => fake()->optional()->phoneNumber(),
            'document_path' => 'health-safety/sds/example.pdf',
            'status' => 'current',
        ];
    }

    /** A current sheet due for review within the 30-day horizon. */
    public function expiring(): static
    {
        return $this->state(fn () => [
            'status' => 'current',
            'review_date' => now()->addDays(10),
        ]);
    }

    /** A current sheet whose review date has already passed. */
    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => 'current',
            'review_date' => now()->subDays(10),
        ]);
    }

    public function superseded(): static
    {
        return $this->state(fn () => ['status' => 'superseded']);
    }
}
