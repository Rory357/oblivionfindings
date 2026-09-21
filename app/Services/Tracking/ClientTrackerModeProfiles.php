<?php

namespace App\Services\Tracking;

use App\Domain\SecurityDevices\Management\Models\DeviceConfigurationProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Services\Queclink\QueclinkConfigurationProfileService;
use Illuminate\Validation\ValidationException;

/** Only published, immutable profiles may be selected from the client workspace. */
final class ClientTrackerModeProfiles
{
    public const MODES = [
        'standard' => ['label' => 'Standard', 'key' => 'queclink:resident-safety', 'seconds' => 30],
        'live' => ['label' => 'Live tracking', 'key' => 'queclink:client-live-tracking', 'seconds' => 10],
        'power_saving' => ['label' => 'Power saving', 'key' => 'queclink:client-power-saving', 'seconds' => 120],
    ];

    public function profiles(Device $device): array
    {
        // Do not infer protocol compatibility from the broad personal-tracker category.
        if ($device->provider !== 'queclink' || $device->domain !== 'tracking'
            || ! in_array(strtoupper(trim((string) $device->model)), ['GL30M', 'GL30MEU', 'GL30MEUR01'], true)) {
            return [];
        }
        $profiles = app(QueclinkConfigurationProfileService::class)->compatibleProfiles($device);
        $result = [];
        foreach (self::MODES as $mode => $definition) {
            $profile = $profiles->where('profile_key', $definition['key'])->sortByDesc('version')->first();
            $settings = $profile?->sectionPayloads()['tracking'] ?? [];
            if ($profile && (int) ($settings['continuous_send_interval_seconds'] ?? 0) === $definition['seconds']
                && array_keys($profile->sectionPayloads()) === ['tracking']
                && array_diff(array_keys($settings), ['mode_selection', 'continuous_send_interval_seconds', 'battery_low_percentage',
                    'function_button_mode', 'sos_report_mode', 'gnss_enable', 'agps_mode', 'wifi_report', 'led_on', 'charge_standby_mode']) === []
                && (int) ($settings['mode_selection'] ?? 1) === 1 && (int) ($settings['charge_standby_mode'] ?? -1) === 0
                && (int) ($settings['function_button_mode'] ?? 0) === 1
                && (int) ($settings['sos_report_mode'] ?? 0) === 1 && (int) ($settings['gnss_enable'] ?? 0) === 1) {
                $result[$mode] = $profile;
            }
        }

        return $result;
    }

    public function require(Device $device, int $id): DeviceConfigurationProfile
    {
        foreach ($this->profiles($device) as $profile) {
            if ((int) $profile->id === $id) {
                return $profile;
            }
        }
        throw ValidationException::withMessages(['mode' => 'This tracker mode is unavailable or its approved profile changed. Reload the modes before trying again.']);
    }
}
