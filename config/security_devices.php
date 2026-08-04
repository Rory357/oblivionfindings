<?php

$definition = static fn (
    string $label,
    string $domain,
    string $level,
    string $risk,
    bool $stepUp,
    bool $approval,
    bool $change,
    bool $breakGlass,
    int $expires,
    string $reconciliation,
    array $parameters = [],
    array $safeSummaryFields = [],
    array $allowedCurrentStates = ['active', 'degraded'],
    string $retryPolicy = 'new_attempt',
    bool $requiresFreshObservation = true,
    bool $requiresMfa = false,
): array => [
    'label' => $label,
    'domain' => $domain,
    'level' => $level,
    'risk' => $risk,
    'requires_step_up' => $stepUp,
    'requires_approval' => $approval,
    'requires_change' => $change,
    'allows_break_glass' => $breakGlass,
    'expires_after_seconds' => $expires,
    'reconciliation' => $reconciliation,
    'retry_policy' => $retryPolicy,
    'requires_fresh_observation' => $requiresFreshObservation,
    'requires_mfa' => $requiresMfa,
    'impact' => '',
    'expected_result' => '',
    'confirmation_mode' => match ($risk) {
        'critical' => 'type_device_name',
        'high' => 'acknowledge_impact',
        default => 'none',
    },
    'parameters' => $parameters,
    'safe_summary_fields' => $safeSummaryFields,
    'allowed_current_states' => $allowedCurrentStates,
];

$high = static fn (
    string $label,
    string $domain,
    string $reconciliation,
    array $parameters = [],
    array $safeSummaryFields = [],
    bool $allowsBreakGlass = false,
): array => $definition(
    $label,
    $domain,
    'control',
    'high',
    true,
    true,
    false,
    $allowsBreakGlass,
    300,
    $reconciliation,
    $parameters,
    $safeSummaryFields,
    ['active', 'degraded'],
    'reconcile_before_retry',
);

$critical = static fn (
    string $label,
    string $domain,
    string $reconciliation,
    array $parameters = [],
    array $safeSummaryFields = [],
    bool $allowsBreakGlass = true,
): array => array_replace(
    $high($label, $domain, $reconciliation, $parameters, $safeSummaryFields, $allowsBreakGlass),
    [
        'risk' => 'critical',
        'expires_after_seconds' => 120,
        'requires_mfa' => true,
        'confirmation_mode' => 'type_device_name',
    ],
);

$capabilities = [
    'diagnostics.ping' => array_replace(
        $definition('Ping device', 'network_it', 'operate', 'low', false, false, false, false, 300, 'diagnostic_result'),
        ['requires_fresh_observation' => false],
    ),
    'diagnostics.trace_route' => array_replace($definition('Trace route', 'network_it', 'operate', 'low', false, false, false, false, 300, 'diagnostic_result', [
        'max_hops' => ['type' => 'integer', 'min' => 1, 'max' => 30],
    ], ['max_hops']), ['requires_fresh_observation' => false]),
    'tracking.location_refresh' => array_replace(
        $definition('Refresh location', 'tracking', 'operate', 'medium', true, false, false, false, 180, 'fresh_location_observation'),
        ['requires_fresh_observation' => false],
    ),
    'configuration.refresh' => array_replace(
        $definition('Refresh configuration snapshot', 'tracking', 'manage', 'medium', true, false, false, false, 600, 'fresh_configuration_observation', [
            'section' => ['type' => 'string', 'enum' => ['all', 'BSI', 'SRI', 'CFG', 'PIN', 'DOG', 'TMA', 'NMD', 'PDS', 'GEO', 'BTS', 'WFI', 'BID', 'UPC', 'WLT', 'FVR']],
        ], ['section']),
        ['requires_fresh_observation' => false],
    ),
    'device.reboot' => array_replace($high('Restart device', 'network_it', 'device_online_after_restart'), ['requires_change' => true]),
    'device.remote_session' => array_replace($high('Start remote session', 'network_it', 'session_closed_and_device_reobserved', [
        'duration_minutes' => ['type' => 'integer', 'min' => 1, 'max' => 60],
    ], ['duration_minutes']), ['requires_mfa' => true]),
    'device.remote_shell' => array_replace($critical('Start governed remote shell', 'network_it', 'session_closed_and_device_reobserved', [
        'support_session_reference' => ['type' => 'string', 'max_length' => 120],
        'duration_minutes' => ['type' => 'integer', 'min' => 1, 'max' => 30],
    ], ['support_session_reference', 'duration_minutes'], false), ['requires_change' => true]),
    'device.wipe' => array_replace($critical('Wipe device', 'network_it', 'device_wipe_state', [
        'wipe_mode' => ['type' => 'string', 'enum' => ['managed_data', 'full_device']],
    ], ['wipe_mode'], false), ['requires_change' => true]),
    'configuration.apply' => array_replace($high('Apply configuration', 'network_it', 'configuration_hash_matches', [
        'configuration_profile_id' => ['type' => 'integer', 'min' => 1, 'source' => 'compatible_configuration_profiles'],
    ]), ['requires_change' => true]),
    'firmware.update' => array_replace($high('Update firmware', 'network_it', 'firmware_version_matches', [
        'target_version' => ['type' => 'string', 'max_length' => 80],
    ], ['target_version']), ['requires_change' => true]),
    'network.port.enable' => $definition('Enable network port', 'network_it', 'manage', 'medium', true, false, true, false, 300, 'interface_admin_state', [
        'interface' => ['type' => 'string', 'max_length' => 80],
    ], ['interface']),
    'network.port.disable' => array_replace($high('Disable network port', 'network_it', 'interface_admin_state', [
        'interface' => ['type' => 'string', 'max_length' => 80],
    ], ['interface']), ['requires_change' => true]),
    'network.firewall_policy.apply' => array_replace($critical('Apply firewall policy', 'network_it', 'configuration_hash_matches', [
        'policy_reference' => ['type' => 'string', 'max_length' => 120],
    ], ['policy_reference'], false), ['requires_change' => true]),
    'access.door.unlock_timed' => $high('Unlock door temporarily', 'security', 'door_locked_after_window', [
        'duration_seconds' => ['type' => 'integer', 'min' => 5, 'max' => 60],
    ], ['duration_seconds'], true),
    'access.door.lock' => $high('Lock door', 'security', 'door_lock_state', [], [], true),
    'access.door.lockdown' => $critical('Lock down door', 'security', 'door_lockdown_state'),
    'access.schedule.update' => array_replace($high('Update access schedule', 'security', 'access_schedule_hash_matches', [
        'schedule_reference' => ['type' => 'string', 'max_length' => 120],
    ], ['schedule_reference']), ['requires_change' => true]),
    'access.credential.revoke' => $high('Revoke access credential', 'security', 'credential_revoked', [
        'credential_reference' => ['type' => 'string', 'max_length' => 120],
    ], ['credential_reference'], true),
    'access.credential.grant' => $high('Grant access credential', 'security', 'credential_granted', [
        'credential_reference' => ['type' => 'string', 'max_length' => 120],
        'access_profile_reference' => ['type' => 'string', 'max_length' => 120],
        'expires_at' => ['type' => 'date_time'],
    ], ['credential_reference', 'access_profile_reference', 'expires_at']),
    'alarm.arm' => $high('Arm alarm', 'security', 'alarm_arm_state', [
        'area' => ['type' => 'string', 'max_length' => 80],
    ], ['area'], true),
    'alarm.disarm' => $critical('Disarm alarm', 'security', 'alarm_arm_state', [
        'area' => ['type' => 'string', 'max_length' => 80],
    ], ['area']),
    'alarm.bypass' => $critical('Bypass alarm zone', 'security', 'alarm_zone_state', [
        'zone' => ['type' => 'string', 'max_length' => 80],
        'duration_minutes' => ['type' => 'integer', 'min' => 1, 'max' => 120],
    ], ['zone', 'duration_minutes']),
    'alarm.reset' => $high('Reset alarm', 'security', 'alarm_fault_state', [], [], true),
    'alarm.emergency_mode.set' => $critical('Change alarm emergency mode', 'security', 'alarm_emergency_mode_state', [
        'area' => ['type' => 'string', 'max_length' => 80],
        'mode' => ['type' => 'string', 'enum' => ['enabled', 'disabled']],
    ], ['area', 'mode']),
    'camera.privacy.enable' => $high('Enable camera privacy mode', 'security', 'camera_privacy_state'),
    'camera.privacy.disable' => $critical('Disable camera privacy mode', 'security', 'camera_privacy_state', [], [], false),
    'camera.recording.update' => $high('Update camera recording', 'security', 'camera_recording_state', [
        'recording_mode' => ['type' => 'string', 'enum' => ['events', 'continuous']],
    ], ['recording_mode']),
    'camera.recording.stop' => array_replace($critical('Stop camera recording', 'security', 'camera_recording_state', [], [], false), ['requires_change' => true]),
    'camera.stream.share' => $critical('Share protected camera stream', 'security', 'governed_stream_access_expired', [
        'case_reference' => ['type' => 'string', 'max_length' => 120],
        'duration_minutes' => ['type' => 'integer', 'min' => 1, 'max' => 60],
    ], ['case_reference', 'duration_minutes'], false),
    'camera.evidence.export' => array_replace($high('Export camera evidence', 'security', 'governed_export_ready', [
        'from' => ['type' => 'date_time'],
        'to' => ['type' => 'date_time'],
        'case_reference' => ['type' => 'string', 'max_length' => 120],
    ], ['from', 'to', 'case_reference']), ['requires_mfa' => true]),
    'monitoring.suppression.create' => array_replace($high('Suppress monitoring', 'network_it', 'suppression_state', [
        'duration_minutes' => ['type' => 'integer', 'min' => 1, 'max' => 240],
        'scope' => ['type' => 'string', 'enum' => ['device', 'service']],
    ], ['duration_minutes', 'scope']), ['requires_change' => true]),
    'monitoring.suppression.site.create' => array_replace($critical('Suppress monitoring across Site', 'network_it', 'site_suppression_state', [
        'duration_minutes' => ['type' => 'integer', 'min' => 1, 'max' => 120],
    ], ['duration_minutes'], false), ['requires_change' => true]),
    'healthcare.calibration_override' => $critical('Override device calibration', 'healthcare', 'calibration_state', [
        'technical_profile_reference' => ['type' => 'string', 'max_length' => 120],
    ], ['technical_profile_reference'], false),
    'healthcare.data_flow_reroute' => array_replace($critical('Reroute device data flow', 'healthcare', 'technical_data_flow_state', [
        'destination_reference' => ['type' => 'string', 'max_length' => 120],
    ], ['destination_reference'], false), ['requires_change' => true]),
    'facilities.actuator.set_state' => $high('Change facilities device state', 'facilities', 'actuator_state', [
        'target_state' => ['type' => 'string', 'max_length' => 80],
    ], ['target_state']),
];

/*
 * Command authorisation is part of the provider-neutral capability contract.
 * A provider declaration cannot widen these application, workspace, Device
 * class, source-permission, or sensitivity boundaries.
 */
$allDeviceDomains = [
    'security',
    'tracking',
    'iot_healthcare',
    'it_infrastructure',
    'facilities',
];
$authorisation = [];
$authorise = static function (
    array $keys,
    array $deviceDomains,
    array $deviceCategories = [],
    array $requiredPermissions = [],
    string $sensitivity = 'standard',
) use (&$authorisation): void {
    foreach ($keys as $key) {
        $authorisation[$key] = [
            'device_domains' => $deviceDomains,
            'device_categories' => $deviceCategories,
            'required_permissions' => $requiredPermissions,
            'sensitivity' => $sensitivity,
        ];
    }
};

$authorise(['diagnostics.ping', 'diagnostics.trace_route'], $allDeviceDomains);
$authorise(
    ['tracking.location_refresh'],
    ['tracking'],
    requiredPermissions: ['assets.telemetry.view'],
    sensitivity: 'personal_location',
);
$authorise(
    ['configuration.refresh'],
    ['tracking'],
    requiredPermissions: ['securityDevices.integrations.view'],
);
$authorise(['device.reboot', 'configuration.apply', 'firmware.update'], $allDeviceDomains);
$authorise(
    ['device.remote_session'],
    ['it_infrastructure'],
    ['server', 'endpoint'],
    sensitivity: 'privileged_remote',
);
$authorise(
    ['device.remote_shell'],
    ['it_infrastructure'],
    ['server', 'storage', 'network', 'networking'],
    sensitivity: 'privileged_remote',
);
$authorise(
    ['device.wipe'],
    ['it_infrastructure'],
    ['endpoint'],
    sensitivity: 'destructive_endpoint',
);
$authorise(
    ['network.port.enable', 'network.port.disable', 'network.firewall_policy.apply'],
    ['it_infrastructure'],
    ['network', 'networking'],
);
$authorise(
    [
        'access.door.unlock_timed',
        'access.door.lock',
        'access.door.lockdown',
        'access.schedule.update',
        'access.credential.revoke',
        'access.credential.grant',
    ],
    ['security'],
    ['access_control'],
    sensitivity: 'security_control',
);
$authorise(
    ['alarm.arm', 'alarm.disarm', 'alarm.bypass', 'alarm.reset', 'alarm.emergency_mode.set'],
    ['security'],
    ['alarm', 'perimeter'],
    sensitivity: 'security_control',
);
$authorise(
    [
        'camera.privacy.enable',
        'camera.privacy.disable',
        'camera.recording.update',
        'camera.recording.stop',
        'camera.stream.share',
        'camera.evidence.export',
    ],
    ['security'],
    ['cctv'],
    ['securityDevices.cctv.media.view'],
    'cctv_media',
);
$authorise(
    ['monitoring.suppression.create'],
    $allDeviceDomains,
    sensitivity: 'availability_control',
);
$authorise(
    ['monitoring.suppression.site.create'],
    $allDeviceDomains,
    sensitivity: 'broad_availability',
);
$authorise(
    ['healthcare.calibration_override'],
    ['iot_healthcare'],
    requiredPermissions: ['securityDevices.maintenance.view'],
    sensitivity: 'healthcare_technical',
);
$authorise(
    ['healthcare.data_flow_reroute'],
    ['iot_healthcare'],
    requiredPermissions: ['securityDevices.integrations.view'],
    sensitivity: 'healthcare_technical',
);
$authorise(
    ['facilities.actuator.set_state'],
    ['facilities'],
    ['facility_access', 'mechanical'],
    ['securityDevices.maintenance.view'],
    'facilities_control',
);

$guidance = [
    'diagnostics.ping' => ['Checks reachability without changing the Device.', 'A bounded diagnostic result is recorded.'],
    'diagnostics.trace_route' => ['Checks the approved network path without changing the Device.', 'A bounded route result is recorded.'],
    'tracking.location_refresh' => ['Requests one current technical location observation.', 'A fresh governed location observation is recorded if the tracker responds.'],
    'configuration.refresh' => ['Requests a protected configuration snapshot without changing the Device.', 'A fresh protected configuration snapshot is recorded if the tracker responds.'],
    'device.reboot' => ['Temporarily interrupts this Device and any service that depends on it.', 'The Device returns online and is freshly observed after restart.'],
    'device.remote_session' => ['Allows time-limited remote control of this Device and may expose operational data.', 'The session closes within its limit and the Device is freshly re-observed.'],
    'device.remote_shell' => ['Opens a privileged, time-limited support shell; raw commands are never stored in Oblivion Findings.', 'The governed session closes and the Device is freshly re-observed.'],
    'device.wipe' => ['Irreversibly removes managed or all Device data according to the selected wipe mode.', 'The provider freshly confirms the requested wipe state.'],
    'configuration.apply' => ['Changes the Device configuration to an approved immutable profile.', 'A protected readback matches the approved profile hash.'],
    'firmware.update' => ['Changes Device software and may temporarily interrupt service.', 'A fresh observation reports the approved firmware version.'],
    'network.port.enable' => ['Restores traffic on the selected interface and may reconnect downstream equipment.', 'A fresh observation reports the interface enabled.'],
    'network.port.disable' => ['Stops traffic on the selected interface and may disconnect downstream equipment.', 'A fresh observation reports the interface disabled.'],
    'network.firewall_policy.apply' => ['Changes network security policy and may affect Site connectivity.', 'A fresh configuration hash matches the approved firewall policy.'],
    'access.door.unlock_timed' => ['Temporarily unlocks this exact door for the approved attendance window.', 'The provider confirms the door returns to locked after the bounded window.'],
    'access.door.lock' => ['Locks this exact door and may prevent physical entry or exit.', 'A fresh provider observation confirms the door is locked.'],
    'access.door.lockdown' => ['Places this exact door into lockdown and may materially affect life safety and access.', 'A fresh provider observation confirms locked and lockdown state.'],
    'access.schedule.update' => ['Changes when approved credentials may use this access point.', 'A fresh access-schedule hash matches the approved schedule.'],
    'access.credential.revoke' => ['Immediately removes the referenced credential access.', 'The provider freshly confirms the credential is revoked.'],
    'access.credential.grant' => ['Grants the referenced credential the approved access profile until its expiry.', 'The provider freshly confirms the bounded credential grant.'],
    'alarm.arm' => ['Arms the selected alarm area and may change Site operating procedures.', 'A fresh provider observation confirms the area is armed.'],
    'alarm.disarm' => ['Disarms the selected alarm area and removes active intrusion protection.', 'A fresh provider observation confirms the area is disarmed.'],
    'alarm.bypass' => ['Temporarily bypasses one alarm zone and reduces protection for the bounded period.', 'A fresh provider observation confirms the exact bounded bypass.'],
    'alarm.reset' => ['Resets the alarm fault state and may clear an active operational condition.', 'A fresh provider observation confirms the resulting alarm fault state.'],
    'alarm.emergency_mode.set' => ['Changes emergency alarm behaviour for the selected area.', 'A fresh provider observation confirms the requested emergency mode.'],
    'camera.privacy.enable' => ['Enables privacy mode and stops normal camera observation.', 'A fresh provider observation confirms privacy mode is enabled.'],
    'camera.privacy.disable' => ['Disables privacy mode and resumes camera observation of the protected area.', 'A fresh provider observation confirms privacy mode is disabled.'],
    'camera.recording.update' => ['Changes how this camera records and may alter storage or privacy impact.', 'A fresh provider observation confirms the approved recording mode.'],
    'camera.recording.stop' => ['Stops recording on this camera and creates a security evidence gap.', 'A fresh provider observation confirms recording is stopped.'],
    'camera.stream.share' => ['Creates short-lived protected access to a live camera stream for an approved case.', 'The access expires automatically and its use remains audited.'],
    'camera.evidence.export' => ['Creates a governed export for the approved time range and case.', 'A protected evidence reference is created without embedding media in routine pages.'],
    'monitoring.suppression.create' => ['Suppresses alerts for this Device or service while retaining underlying observations.', 'The bounded suppression is visible with its reason and expiry.'],
    'monitoring.suppression.site.create' => ['Suppresses alerts across this Site and can hide multiple service failures.', 'The bounded Site suppression is visible with its reason and expiry.'],
    'healthcare.calibration_override' => ['Overrides technical calibration and may affect safety-critical Device behaviour.', 'A fresh technical observation confirms the approved calibration state; no clinical reading is stored.'],
    'healthcare.data_flow_reroute' => ['Changes the technical destination of healthcare Device data.', 'A fresh technical observation confirms the approved data-flow route; no clinical payload is exposed.'],
    'facilities.actuator.set_state' => ['Changes the physical state of this facilities actuator.', 'A fresh provider observation confirms the requested actuator state.'],
];

foreach ($capabilities as $key => $capability) {
    if (! isset($authorisation[$key])) {
        throw new LogicException("Device capability {$key} is missing its authorisation boundary.");
    }

    [$impact, $expectedResult] = $guidance[$key];
    $capabilities[$key] = array_replace($capability, $authorisation[$key], [
        'impact' => $impact,
        'expected_result' => $expectedResult,
    ]);
}

return [
    'command_queue' => env('SECURITY_DEVICES_COMMAND_QUEUE', 'monitoring-commands'),
    'step_up_max_age_seconds' => 900,
    'break_glass_max_age_seconds' => 120,
    'break_glass_review_due_seconds' => 86400,
    'break_glass_reviewer_limit' => 100,
    'command_observation_stale_after_seconds' => 900,
    'reconciliation_grace_seconds' => 5,
    'command_capabilities' => $capabilities,
];
