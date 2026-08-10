<?php

namespace Database\Seeders;

use App\Models\ItCatalogItem;
use Illuminate\Database\Seeder;

class ItServiceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['name' => 'Report an IT issue', 'slug' => 'report-it-issue', 'outcome_type' => 'service_request', 'category' => 'other'],
            ['name' => 'Report a security concern', 'slug' => 'report-security-concern', 'outcome_type' => 'security_request', 'category' => 'other', 'default_priority' => 'high'],
            ['name' => 'Request equipment', 'slug' => 'request-equipment', 'outcome_type' => 'provisioning', 'category' => 'hardware', 'provisioning_type' => 'equipment'],
        ];

        foreach ($items as $sort => $attributes) {
            ItCatalogItem::query()->updateOrCreate(
                ['tenant_id' => 1, 'slug' => $attributes['slug']],
                [
                    ...$attributes,
                    'description' => 'Use this guided request so IT receives the right information first time.',
                    'default_priority' => $attributes['default_priority'] ?? 'normal',
                    'is_published' => true,
                    'form_schema_version' => 1,
                    'form_schema' => ['fields' => [[
                        'key' => 'details',
                        'label' => 'Tell us what you need',
                        'type' => 'textarea',
                        'required' => true,
                        'max' => 5000,
                    ]]],
                    'search_terms' => [],
                    'sort_order' => $sort,
                ],
            );
        }
    }
}
