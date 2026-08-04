<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Management\Models\DeviceConfigurationProfile;

final class CommandExpectedStateResolver
{
    /** @param array<string, mixed> $parameters @return array<string, scalar|null> */
    public function resolve(string $capability, array $parameters): array
    {
        return match ($capability) {
            'access.door.unlock_timed' => ['locked' => true],
            'access.door.lock' => ['locked' => true],
            'access.door.lockdown' => ['locked' => true, 'lockdown' => true],
            'access.schedule.update' => ['schedule_reference' => $parameters['schedule_reference'] ?? null],
            'access.credential.revoke' => ['credential_revoked' => true, 'credential_reference' => $parameters['credential_reference'] ?? null],
            'access.credential.grant' => ['credential_granted' => true, 'credential_reference' => $parameters['credential_reference'] ?? null, 'expires_at' => $parameters['expires_at'] ?? null],
            'alarm.arm' => ['armed' => true, 'area' => $parameters['area'] ?? null],
            'alarm.disarm' => ['armed' => false, 'area' => $parameters['area'] ?? null],
            'alarm.bypass' => ['zone_bypassed' => true, 'zone' => $parameters['zone'] ?? null, 'duration_minutes' => $parameters['duration_minutes'] ?? null],
            'alarm.reset' => ['fault_reset' => true],
            'alarm.emergency_mode.set' => ['emergency_mode' => $parameters['mode'] ?? null, 'area' => $parameters['area'] ?? null],
            'camera.privacy.enable' => ['privacy_mode' => true],
            'camera.privacy.disable' => ['privacy_mode' => false],
            'camera.recording.update' => ['recording_mode' => $parameters['recording_mode'] ?? null],
            'camera.recording.stop' => ['recording_mode' => 'off'],
            'camera.stream.share' => ['stream_access_expires' => true, 'case_reference' => $parameters['case_reference'] ?? null],
            'camera.evidence.export' => ['governed_export_ready' => true, 'case_reference' => $parameters['case_reference'] ?? null],
            'network.port.enable' => ['admin_state' => 'enabled', 'interface' => $parameters['interface'] ?? null],
            'network.port.disable' => ['admin_state' => 'disabled', 'interface' => $parameters['interface'] ?? null],
            'network.firewall_policy.apply' => ['policy_reference' => $parameters['policy_reference'] ?? null],
            'configuration.apply' => $this->configurationProfileState($parameters),
            'firmware.update' => ['firmware_version' => $parameters['target_version'] ?? null],
            'device.reboot' => ['availability' => 'online'],
            'device.remote_session', 'device.remote_shell' => ['session_closed' => true],
            'device.wipe' => ['wipe_state' => $parameters['wipe_mode'] ?? null],
            'monitoring.suppression.create' => ['suppression_active' => true, 'scope' => $parameters['scope'] ?? null, 'duration_minutes' => $parameters['duration_minutes'] ?? null],
            'monitoring.suppression.site.create' => ['site_suppression_active' => true, 'duration_minutes' => $parameters['duration_minutes'] ?? null],
            'healthcare.calibration_override' => ['technical_profile_reference' => $parameters['technical_profile_reference'] ?? null],
            'healthcare.data_flow_reroute' => ['destination_reference' => $parameters['destination_reference'] ?? null],
            default => ['action_completed' => true],
        };
    }

    /** @param array<string, mixed> $parameters @return array<string, scalar|null> */
    private function configurationProfileState(array $parameters): array
    {
        $profile = DeviceConfigurationProfile::query()->findOrFail(
            (int) ($parameters['configuration_profile_id'] ?? 0),
        );

        return [
            'configuration_profile_uuid' => $profile->uuid,
            'configuration_payload_hash' => $profile->payload_hash,
        ];
    }
}
