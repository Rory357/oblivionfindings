<?php

namespace Database\Factories;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StaffFactory extends Factory
{
    protected $model = Staff::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'employee_id' => 'EMP' . str_pad(fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'job_title' => fake()->randomElement([
                'Support Worker',
                'Senior Support Worker',
                'Care Coordinator',
                'Clinical Lead',
                'Registered Nurse',
                'Team Leader',
                'Operations Manager',
                'Administration Officer',
                'Finance Officer',
                'HR Coordinator',
            ]),
            'department' => fake()->randomElement([
                'Clinical',
                'Operations',
                'Administration',
                'Finance',
                'HR',
                'IT',
                'Compliance',
            ]),
            'hire_date' => fake()->dateTimeBetween('-10 years', '-1 month'),
            'work_phone' => fake()->optional(0.7)->phoneNumber(),
            'mobile_phone' => fake()->phoneNumber(),
            'status' => fake()->randomElement(['active', 'active', 'active', 'on_leave']),
            'emergency_contact_name' => fake()->optional(0.8)->name(),
            'emergency_contact_phone' => fake()->optional(0.8)->phoneNumber(),
            'emergency_contact_relationship' => fake()->optional(0.8)->randomElement(['Spouse', 'Parent', 'Sibling', 'Partner', 'Friend']),
            'notes' => fake()->optional(0.3)->sentence(),
        ];
    }

    /**
     * Indicate that the staff is active
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    /**
     * Indicate that the staff is on leave
     */
    public function onLeave(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'on_leave',
        ]);
    }

    /**
     * Indicate that the staff is terminated
     */
    public function terminated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'terminated',
            'termination_date' => fake()->dateTimeBetween('-1 year', '-1 day'),
        ]);
    }

    /**
     * Create with an existing user instead of creating a new one
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }
}
