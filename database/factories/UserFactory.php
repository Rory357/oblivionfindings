<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserWitnessPin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * PIN-1: factory users can act as a medication second person, so each gets
     * this known 6-digit witness PIN (tests and local demo data only). Use
     * withoutWitnessPin() for the "no PIN set" path.
     */
    public const TEST_WITNESS_PIN = '482915';

    protected static ?string $witnessPinHash = null;

    protected static ?bool $witnessPinTableExists = null;

    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if (! (static::$witnessPinTableExists ??= Schema::hasTable('user_witness_pins'))) {
                return;
            }
            UserWitnessPin::query()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'pin_hash' => static::$witnessPinHash ??= Hash::make(self::TEST_WITNESS_PIN),
                    'set_at' => now(),
                ],
            );
        });
    }

    /** The person hasn't set a witness PIN, so they can't witness or co-sign yet. */
    public function withoutWitnessPin(): static
    {
        return $this->afterCreating(function (User $user): void {
            UserWitnessPin::query()->where('user_id', $user->id)->delete();
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'approved_at' => now(),
            'approved_by' => null,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => Str::random(10),
            'two_factor_recovery_codes' => Str::random(10),
            'two_factor_confirmed_at' => now(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model does not have two-factor authentication configured.
     */
    public function withoutTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }

    public function frontlineWorker(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
    }
}
