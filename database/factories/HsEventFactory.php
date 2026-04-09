<?php

namespace Database\Factories;

use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HsEventFactory extends Factory
{
    protected $model = HsEvent::class;

    public function definition(): array
    {
        $source = ClientIncident::factory()->create();

        return [
            'reference_number' => HsEvent::generateReferenceNumber(),
            'source_type' => get_class($source),
            'source_id' => $source->getKey(),
            'event_category' => HsEvent::CATEGORY_INCIDENT,
            'severity' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'status' => HsEvent::STATUS_OPEN,
            'occurred_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'reported_at' => now(),
            'worksafe_notifiable' => false,
            'investigation_required' => false,
            'idempotency_key' => hash('sha256', fake()->uuid()),
            'created_by' => User::factory(),
        ];
    }

    public function high(): static
    {
        return $this->state(fn () => [
            'severity' => HsEvent::SEVERITY_HIGH,
            'investigation_required' => true,
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn () => [
            'severity' => HsEvent::SEVERITY_CRITICAL,
            'investigation_required' => true,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => HsEvent::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => User::factory(),
            'closure_summary' => fake()->sentence(),
        ]);
    }

    public function worksafeNotifiable(): static
    {
        return $this->state(fn () => [
            'worksafe_notifiable' => true,
            'worksafe_status' => HsEvent::WORKSAFE_PENDING,
            'investigation_required' => true,
        ]);
    }
}
