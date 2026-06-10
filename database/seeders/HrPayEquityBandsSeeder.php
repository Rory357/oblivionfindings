<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrSalaryBand;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Default salary bands shaped on the Care and Support Workers (Pay Equity)
 * Settlement qualification levels (NZQA L0 → L4). Rates are editable
 * defaults — update them to the current funded rates when MSD/Te Whatu Ora
 * publish adjustments; the band structure (levels by qualification) is the
 * durable part.
 */
class HrPayEquityBandsSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = (int) (HrEmployeeProfile::query()->orderBy('id')->value('tenant_id') ?? 1);
        $createdBy = User::query()->orderBy('id')->value('id');

        $bands = [
            ['Pay Equity L0 — No qualification (<3 yrs)', 28.25, 29.10],
            ['Pay Equity L2 — Level 2 qual or 3+ yrs', 30.00, 30.90],
            ['Pay Equity L3 — Level 3 qual or 8+ yrs', 31.30, 32.25],
            ['Pay Equity L4 — Level 4 qual or 12+ yrs', 33.50, 34.50],
        ];

        foreach ($bands as [$name, $minHourly, $maxHourly]) {
            HrSalaryBand::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'band_name' => $name,
                ],
                [
                    'position_role' => 'support_worker',
                    'min_hourly' => number_format($minHourly, 2, '.', ''),
                    'max_hourly' => number_format($maxHourly, 2, '.', ''),
                    'min_salary' => number_format($minHourly * 2080, 2, '.', ''),
                    'mid_salary' => number_format((($minHourly + $maxHourly) / 2) * 2080, 2, '.', ''),
                    'max_salary' => number_format($maxHourly * 2080, 2, '.', ''),
                    'currency' => 'NZD',
                    'effective_from' => '2025-07-01',
                    'created_by' => $createdBy,
                ]
            );
        }

        $this->command?->info('Seeded '.count($bands).' NZ pay-equity salary bands.');
    }
}
