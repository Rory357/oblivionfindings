<?php

namespace Database\Seeders;

use App\Models\Queclink\QueclinkPreset;
use App\Services\Queclink\QueclinkConfigurationProfileService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ships the built-in Queclink configuration presets. Idempotent — safe to run
 * on every deploy.
 *
 * Only GL30MEU (personal tracker) presets are seeded today because the
 * over-the-air write path currently builds GL30 commands. A vehicle-tracker
 * preset is intentionally omitted rather than shipped as a non-applicable stub;
 * add it here once GV500CG section writes exist.
 */
class QueclinkPresetSeeder extends Seeder
{
    public function run(QueclinkConfigurationProfileService $profiles): void
    {
        foreach ($this->systemPresets() as $preset) {
            DB::transaction(function () use ($preset, $profiles): void {
                $wrapper = QueclinkPreset::query()
                    ->where('slug', $preset['slug'])
                    ->where('is_system', true)
                    ->lockForUpdate()
                    ->first();
                $payload = $profiles->normaliseSections($preset['payload']);
                $current = $wrapper?->configurationProfile()->first();
                if ($current === null || ! hash_equals($current->payload_hash, $current::hashPayload($payload))) {
                    $next = $profiles->createProfile(
                        profileKey: QueclinkConfigurationProfileService::profileKey($preset['slug']),
                        name: $preset['name'],
                        description: $preset['description'],
                        targetCategory: 'personal_tracker',
                        sections: $payload,
                        isSystem: true,
                        createdByUserId: null,
                    );
                    $current?->retire();
                    $current = $next;
                }

                if ($wrapper === null) {
                    QueclinkPreset::query()->create([
                        'device_configuration_profile_id' => $current->id,
                        'tenant_id' => null,
                        'name' => $preset['name'],
                        'slug' => $preset['slug'],
                        'description' => $preset['description'],
                        'target_category' => 'personal_tracker',
                        'payload' => [],
                        'is_system' => true,
                        'created_by_user_id' => null,
                    ]);

                    return;
                }

                $wrapper->forceFill([
                    'device_configuration_profile_id' => $current->id,
                    'tenant_id' => null,
                    'name' => $preset['name'],
                    'description' => $preset['description'],
                    'target_category' => 'personal_tracker',
                    'payload' => [],
                    'created_by_user_id' => null,
                ])->save();
            });
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function systemPresets(): array
    {
        return [
            [
                'slug' => 'resident-safety',
                'name' => 'Resident safety',
                'description' => 'GL30MEU pendant on a resident: 30-second tracking, '
                    .'panic button + SOS reporting on, 20% low-battery warning, GNSS + AGPS + Wi-Fi fallback. '
                    .'Matches the one-click Resident safety profile.',
                // Mirrors CommandBuilder::gl30ResidentSafetyProfile() exactly so an
                // applied preset queues a byte-identical GTCFG.
                'payload' => [
                    'tracking' => [
                        'continuous_send_interval_seconds' => 30,
                        'battery_low_percentage' => 20,
                        'function_button_mode' => 1,
                        'sos_report_mode' => 1,
                        'gnss_enable' => 1,
                        'agps_mode' => 1,
                        'wifi_report' => 2,
                        'led_on' => 1,
                        'charge_standby_mode' => 0,
                    ],
                ],
            ],
            [
                'slug' => 'lone-worker',
                'name' => 'Lone worker',
                'description' => 'Pendant on a staff member working alone: 60-second tracking to '
                    .'preserve battery across a full shift, panic button + SOS on, 25% low-battery warning.',
                'payload' => [
                    'tracking' => [
                        'continuous_send_interval_seconds' => 60,
                        'battery_low_percentage' => 25,
                        'function_button_mode' => 1,
                        'sos_report_mode' => 1,
                        'gnss_enable' => 1,
                        'agps_mode' => 1,
                        'wifi_report' => 2,
                        'led_on' => 1,
                        'charge_standby_mode' => 0,
                    ],
                ],
            ],
        ];
    }
}
