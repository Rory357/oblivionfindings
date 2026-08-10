<?php

namespace Database\Factories;

use App\Models\ItMajorIncident;
use App\Models\ItMajorIncidentUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ItMajorIncidentUpdate> */
class ItMajorIncidentUpdateFactory extends Factory
{
    protected $model = ItMajorIncidentUpdate::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'major_incident_id' => ItMajorIncident::factory(),
            'update_kind' => 'stakeholder_update',
            'audience' => 'staff',
            'summary' => fake()->sentence(),
            'service_status' => 'investigating',
            'published_at' => now(),
        ];
    }
}
