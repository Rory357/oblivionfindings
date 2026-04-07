<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TimesheetFactory extends Factory
{
    protected $model = Timesheet::class;

    public function definition(): array
    {
        $workDate = fake()->dateTimeBetween('-1 month', 'now');
        $startTime = (clone $workDate)->setTime(9, 0);
        $endTime = (clone $workDate)->setTime(17, 0);
        $shift = Shift::factory();

        return [
            'shift_id' => $shift,
            'user_id' => fn (array $attributes) => Shift::query()->whereKey($attributes['shift_id'] ?? null)->value('user_id')
                ?? User::factory()->create()->id,
            'client_id' => fn (array $attributes) => Shift::query()->whereKey($attributes['shift_id'] ?? null)->value('client_id')
                ?? Client::factory()->create()->id,
            'shift_site_id' => fn (array $attributes) => Shift::query()->whereKey($attributes['shift_id'] ?? null)->value('site_id'),
            'shift_service_context_id' => fn (array $attributes) => Shift::query()->whereKey($attributes['shift_id'] ?? null)->value('service_context_id'),
            'work_date' => $workDate,
            'starts_at' => $startTime,
            'ends_at' => $endTime,
            'break_minutes' => fake()->randomElement([0, 30, 60]),
            'mileage_km' => 0,
            'sleepover' => false,
            'on_call' => false,
            'allowance_notes' => null,
            'public_holiday' => false,
            'notes' => fake()->optional()->paragraph(),
            'is_residential_billable' => false,
            'shift_site_name_snapshot' => fn (array $attributes) => optional(
                Shift::query()->with(['site:id,name', 'client.site:id,name'])->find($attributes['shift_id'] ?? null)
            )->site?->name
                ?? optional(optional(Shift::query()->with('client.site:id,name')->find($attributes['shift_id'] ?? null))->client)->site?->name,
            'shift_location_snapshot' => fn (array $attributes) => Shift::query()->whereKey($attributes['shift_id'] ?? null)->value('location'),
            'service_context_name_snapshot' => fn (array $attributes) => optional(
                optional(Shift::query()->with('serviceContext:id,name')->find($attributes['shift_id'] ?? null))->serviceContext
            )->name,
            'client_name_snapshot' => fn (array $attributes) => trim(
                (string) optional(Client::query()->find(
                    Shift::query()->whereKey($attributes['shift_id'] ?? null)->value('client_id')
                ))->first_name.' '.(string) optional(Client::query()->find(
                    Shift::query()->whereKey($attributes['shift_id'] ?? null)->value('client_id')
                ))->last_name
            ),
            'staff_name_snapshot' => fn (array $attributes) => User::query()->whereKey(
                Shift::query()->whereKey($attributes['shift_id'] ?? null)->value('user_id')
            )->value('name'),
            'shift_type_snapshot' => fn (array $attributes) => Shift::query()->whereKey($attributes['shift_id'] ?? null)->value('shift_type') ?: 'standard',
            'coverage_roles_snapshot' => [],
            'status' => 'draft',
            'submitted_at' => null,
            'approved_at' => null,
            'approved_by' => null,
            'created_by' => User::factory(),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'submitted_at' => now()->subDay(),
            'approved_at' => now(),
            'approved_by' => User::factory(),
        ]);
    }
}
