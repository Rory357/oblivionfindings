<?php

namespace Database\Factories;

use App\Models\ItCatalogItem;
use App\Models\ItCatalogSubmission;
use App\Models\ItTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ItCatalogSubmission>
 */
class ItCatalogSubmissionFactory extends Factory
{
    protected $model = ItCatalogSubmission::class;

    public function definition(): array
    {
        return [
            'tenant_id' => 1,
            'catalog_item_id' => ItCatalogItem::factory(),
            'requester_user_id' => User::factory(),
            'schema_version' => 1,
            'schema_snapshot' => ['fields' => []],
            'submitted_values' => [],
            'idempotency_key' => (string) Str::uuid(),
            'result_type' => 'it_ticket',
            'result_id' => ItTicket::factory(),
            'submitted_at' => now(),
        ];
    }
}
