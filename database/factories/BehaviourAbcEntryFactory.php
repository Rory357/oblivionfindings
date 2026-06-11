<?php

namespace Database\Factories;

use App\Domain\Clinical\Enums\BehaviourFunction;
use App\Models\BehaviourAbcEntry;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BehaviourAbcEntry>
 */
class BehaviourAbcEntryFactory extends Factory
{
    protected $model = BehaviourAbcEntry::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'recorded_by' => User::factory(),
            'occurred_at' => fake()->dateTimeBetween('-30 days', 'now'),
            'setting' => fake()->randomElement([
                'Dining room at dinner',
                'Lounge during free time',
                'Bathroom routine',
                'Community outing',
                'Morning handover',
            ]),
            'others_present' => fake()->optional()->randomElement(['Support worker', 'Peers', 'Whānau visitor']),
            'antecedent' => fake()->sentence(),
            'behaviour' => fake()->sentence(),
            'behaviour_tags' => fake()->randomElements(
                ['verbal', 'physical', 'withdrawal', 'property', 'self-injury', 'pacing'],
                fake()->numberBetween(0, 2),
            ),
            'consequence' => fake()->sentence(),
            'behaviour_function' => fake()->randomElement(BehaviourFunction::cases())->value,
            'intensity' => fake()->randomElement(BehaviourAbcEntry::INTENSITIES),
            'duration_seconds' => fake()->optional()->numberBetween(30, 1800),
            'strategies_used' => fake()->optional()->sentence(),
            'harm_occurred' => false,
            'escalated' => false,
            'requires_followup' => false,
        ];
    }

    public function escalated(): static
    {
        return $this->state(fn () => [
            'intensity' => 'high',
            'escalated' => true,
            'requires_followup' => true,
        ]);
    }

    public function withHarm(): static
    {
        return $this->state(fn () => [
            'harm_occurred' => true,
            'harm_notes' => 'Minor injury — no first aid required.',
        ]);
    }

    public function function(BehaviourFunction $function): static
    {
        return $this->state(fn () => [
            'behaviour_function' => $function->value,
        ]);
    }
}
