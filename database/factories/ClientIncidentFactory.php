<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientIncidentFactory extends Factory
{
    protected $model = ClientIncident::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'reported_by' => User::factory(),
            'type' => fake()->randomElement(['fall', 'medication_error', 'behavioural', 'injury', 'property_damage', 'other']),
            'severity' => 'low',
            'status' => 'draft',
            'title' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'occurred_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'location' => fake()->randomElement(['Living room', 'Kitchen', 'Bedroom', 'Bathroom', 'Garden', 'Community']),
            'reviewed_at' => null,
            'reviewed_by' => null,
            'portal_visible' => fake()->boolean(50),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'submitted',
        ]);
    }

    public function reviewed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'reviewed',
            'reviewed_at' => now(),
            'reviewed_by' => User::factory(),
        ]);
    }

    public function highSeverity(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => 'high',
        ]);
    }

    public function atSite(Site $site): static
    {
        return $this->state(fn () => [
            'site_id' => $site->id,
        ]);
    }

    public function linkedToHsEvent(HsEvent $event): static
    {
        return $this->state(fn () => [
            'hs_event_id' => $event->id,
        ]);
    }

    public function forJourney(Site $site, HsEvent $event): static
    {
        return $this->atSite($site)->linkedToHsEvent($event);
    }
}
