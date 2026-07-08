<?php

namespace Database\Seeders;

use App\Models\ItSlaPolicy;
use Illuminate\Database\Seeder;

/**
 * Materialises the §G default SLA targets for the default tenant so the
 * admin editor has rows to show/adjust. Purely optional — the engine falls
 * back to ItSlaPolicy::DEFAULTS in code when rows are absent (deploys skip
 * seeders on this project).
 */
class ItSlaPolicySeeder extends Seeder
{
    public function run(): void
    {
        foreach (ItSlaPolicy::DEFAULTS as $priority => [$firstResponse, $resolution]) {
            ItSlaPolicy::query()->updateOrCreate(
                ['tenant_id' => 1, 'priority' => $priority],
                [
                    'first_response_minutes' => $firstResponse,
                    'resolution_minutes' => $resolution,
                ],
            );
        }
    }
}
