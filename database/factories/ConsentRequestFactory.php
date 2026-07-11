<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ConsentRequest;
use App\Models\ConsentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsentRequest>
 */
class ConsentRequestFactory extends Factory
{
    protected $model = ConsentRequest::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'consent_type_id' => ConsentType::factory(),
            'requested_by_user_id' => User::factory(),
            'recipient_user_id' => User::factory(),
            'recipient_relationship' => ConsentRequest::RELATION_NEXT_OF_KIN,
            'purpose' => 'Monitor location of personal tracker for safety after documented wandering incidents.',
            'least_restrictive_justification' => 'Alternatives (staffing increase, environmental mods) reviewed 2026-04-12; tracker is least restrictive.',
            'data_scope' => 'Immediate care team + on-call coordinator. Not shared with external parties.',
            'retention_period_days' => 180,
            'withdrawal_method_text' => 'You may withdraw this consent at any time by contacting the key worker or through the family portal.',
            'status' => ConsentRequest::STATUS_PENDING,
            'sent_at' => now(),
            'expires_at' => now()->addDays(14),
            'audit_trail' => [],
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => ConsentRequest::STATUS_PENDING]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => ConsentRequest::STATUS_APPROVED,
            'responded_at' => now(),
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn () => [
            'status' => ConsentRequest::STATUS_DECLINED,
            'responded_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => ConsentRequest::STATUS_EXPIRED,
            'expires_at' => now()->subDay(),
        ]);
    }

    public function forTracker(): static
    {
        return $this->state(fn () => [
            'purpose' => 'Monitor location of personal GPS tracker assigned for safety.',
            'retention_period_days' => 90,
        ]);
    }
}
