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
        $sourceType = HsEvent::class;
        $sourceId = fake()->unique()->numberBetween(1_000_000, 2_000_000_000);
        $eventCategory = HsEvent::CATEGORY_INCIDENT;

        return [
            'organization_id' => 1,
            'reference_number' => HsEvent::generateReferenceNumber(),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'event_category' => $eventCategory,
            'severity' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'status' => HsEvent::STATUS_OPEN,
            'occurred_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'reported_at' => now(),
            'worksafe_notifiable' => null,
            'worksafe_decided_at' => null,
            'worksafe_decided_by_user_id' => null,
            'worksafe_decision_reason' => null,
            'worksafe_decision_source' => null,
            'worksafe_status' => null,
            'investigation_required' => false,
            'idempotency_key' => HsEvent::buildIdempotencyKey($sourceType, $sourceId, $eventCategory),
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

    public function worksafeUndecided(): static
    {
        return $this->state(fn () => [
            'worksafe_notifiable' => null,
            'worksafe_decided_at' => null,
            'worksafe_decided_by_user_id' => null,
            'worksafe_decision_reason' => null,
            'worksafe_decision_source' => null,
            'worksafe_status' => null,
        ]);
    }

    public function worksafeNotNotifiable(User $actor): static
    {
        return $this->state(fn () => [
            'worksafe_notifiable' => false,
            'worksafe_decided_at' => now(),
            'worksafe_decided_by_user_id' => $actor->id,
            'worksafe_decision_reason' => 'Assessed as not meeting the WorkSafe notification threshold.',
            'worksafe_decision_source' => 'manual',
            'worksafe_status' => null,
        ]);
    }

    public function worksafeNotifiable(?User $actor = null): static
    {
        return $this->state(fn () => [
            'worksafe_notifiable' => true,
            'worksafe_decided_at' => now(),
            'worksafe_decided_by_user_id' => $actor?->id ?? User::factory(),
            'worksafe_decision_reason' => 'Assessed as meeting the WorkSafe notification threshold.',
            'worksafe_decision_source' => 'manual',
            'worksafe_status' => HsEvent::WORKSAFE_PENDING,
            'investigation_required' => true,
        ]);
    }

    public function forClientIncident(ClientIncident $incident): static
    {
        $category = $incident->type === 'near_miss'
            ? HsEvent::CATEGORY_NEAR_MISS
            : HsEvent::CATEGORY_INCIDENT;

        return $this->state(fn () => [
            'source_type' => ClientIncident::class,
            'source_id' => $incident->id,
            'event_category' => $category,
            'site_id' => $incident->site_id,
            'client_id' => $incident->client_id,
            'occurred_at' => $incident->occurred_at,
            'idempotency_key' => HsEvent::buildIdempotencyKey(
                ClientIncident::class,
                $incident->id,
                $category
            ),
        ]);
    }

    public function awaitingHandoverAcceptance(User $owner): static
    {
        return $this->state(fn () => [
            'handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE,
            'owner_user_id' => $owner->id,
            'accepted_by_user_id' => null,
            'accepted_at' => null,
        ]);
    }

    public function handoverAccepted(User $owner, User $acceptedBy): static
    {
        return $this->state(fn () => [
            'handover_status' => HsEvent::HANDOVER_ACCEPTED,
            'owner_user_id' => $owner->id,
            'accepted_by_user_id' => $acceptedBy->id,
            'accepted_at' => now(),
        ]);
    }
}
