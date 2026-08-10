<?php

namespace Database\Factories;

use App\Models\ItEmailDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ItEmailDelivery> */
class ItEmailDeliveryFactory extends Factory
{
    protected $model = ItEmailDelivery::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'notification_uuid' => (string) Str::uuid(),
            'recipient_user_id' => User::factory(),
            'recipient_email' => $this->faker->unique()->safeEmail(),
            'notification_type' => 'ticket_replied',
            'subject' => 'IT ticket update',
            'status' => 'queued',
            'queued_at' => now(),
        ];
    }
}
