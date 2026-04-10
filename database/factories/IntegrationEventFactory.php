<?php

namespace Database\Factories;

use App\Models\Integration\IntegrationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for integration events — useful for testing the signal pipeline.
 *
 * @extends Factory<IntegrationEvent>
 */
class IntegrationEventFactory extends Factory
{
    protected $model = IntegrationEvent::class;

    public function definition(): array
    {
        $eventTypes = [
            'device_offline',
            'door_forced',
            'sos_triggered',
            'tamper_detected',
            'panic_alarm',
            'duress_alarm',
            'communication_failure',
            'power_failure',
            'camera_offline',
            'motion_detected',
            'access_granted',
            'access_denied',
        ];

        $providers = ['gallagher', 'hikvision', 'unifi'];
        $provider = $this->faker->randomElement($providers);

        return [
            'tenant_id' => 1,
            'site_id' => null,
            'room_id' => null,
            'hardware_id' => null,
            'provider' => $provider,
            'source_app' => $provider,
            'source_event_id' => $this->faker->uuid(),
            'occurred_at' => now(),
            'received_at' => now(),
            'severity' => $this->faker->randomElement([
                IntegrationEvent::SEVERITY_INFO,
                IntegrationEvent::SEVERITY_WARN,
                IntegrationEvent::SEVERITY_CRITICAL,
            ]),
            'event_type' => $this->faker->randomElement($eventTypes),
            'tags' => [],
            'normalized_payload' => [
                'summary' => $this->faker->sentence(),
            ],
            'raw_payload' => [
                'test' => true,
                'generated_by' => 'factory',
            ],
        ];
    }

    /**
     * Create a critical severity event.
     */
    public function critical(): static
    {
        return $this->state(fn () => [
            'severity' => IntegrationEvent::SEVERITY_CRITICAL,
            'event_type' => $this->faker->randomElement([
                'sos_triggered', 'panic_alarm', 'duress_alarm',
            ]),
        ]);
    }

    /**
     * Create a warn severity event.
     */
    public function warn(): static
    {
        return $this->state(fn () => [
            'severity' => IntegrationEvent::SEVERITY_WARN,
            'event_type' => $this->faker->randomElement([
                'door_forced', 'tamper_detected', 'power_failure',
            ]),
        ]);
    }

    /**
     * Create an info severity event.
     */
    public function info(): static
    {
        return $this->state(fn () => [
            'severity' => IntegrationEvent::SEVERITY_INFO,
            'event_type' => $this->faker->randomElement([
                'motion_detected', 'access_granted',
            ]),
        ]);
    }

    /**
     * Create an event with a specific provider.
     */
    public function provider(string $provider): static
    {
        return $this->state(fn () => [
            'provider' => $provider,
            'source_app' => $provider,
        ]);
    }

    /**
     * Create an event with a specific event type.
     */
    public function eventType(string $type): static
    {
        return $this->state(fn () => [
            'event_type' => $type,
        ]);
    }

    /**
     * Create an event without a source_event_id (weak idempotency).
     */
    public function withoutSourceEventId(): static
    {
        return $this->state(fn () => [
            'source_event_id' => null,
        ]);
    }
}
