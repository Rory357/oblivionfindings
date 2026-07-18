<?php

namespace Database\Factories;

use App\Models\ItProvisioningTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ItProvisioningTemplate> */
class ItProvisioningTemplateFactory extends Factory
{
    protected $model = ItProvisioningTemplate::class;

    public function definition(): array
    {
        $lifecycle = fake()->randomElement(ItProvisioningTemplate::LIFECYCLE_TYPES);

        return [
            'tenant_id' => 1,
            'name' => ucfirst($lifecycle).' '.fake()->unique()->words(2, true),
            'lifecycle_type' => $lifecycle,
            'selection_priority' => 0,
            'is_active' => true,
        ];
    }
}
