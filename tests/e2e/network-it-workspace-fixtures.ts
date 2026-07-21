import { runLaravelPhp } from './helpers';

export function seedNetworkItWorkspaceReadinessFixtures() {
    type Fixture = {
        siteName: string;
        gatewayName: string;
        switchName: string;
        printerName: string;
        interfaceName: string;
        availabilityName: string;
        serviceName: string;
        ticketTitle: string;
        topologyPort: string;
        rawSentinel: string;
    };

    const output = runLaravelPhp(`
$admin = \\App\\Models\\User::query()->where('email', 'admin@demo.test')->firstOrFail();
$tenantId = (int) ($admin->organization_id ?? 1);
$site = \\App\\Models\\Site::query()
    ->where('tenant_id', $tenantId)
    ->where('archived', false)
    ->orderBy('id')
    ->firstOrFail();

$rawSentinel = 'PW-NETWORK-RAW-PROVIDER-SECRET-MUST-NOT-RENDER';
$upsertDevice = function (string $uid, string $name, string $subcategory, array $attributes = []) use ($tenantId, $admin, $site) {
    $device = \\App\\Domain\\SecurityDevices\\Models\\Device::withTrashed()
        ->where('tenant_id', $tenantId)
        ->where('device_uid', $uid)
        ->first();
    if (! $device) {
        $device = new \\App\\Domain\\SecurityDevices\\Models\\Device([
            'tenant_id' => $tenantId,
            'device_uid' => $uid,
        ]);
    } elseif ($device->trashed()) {
        $device->restore();
    }

    $device->forceFill(array_merge([
        'name' => $name,
        'domain' => 'it_infrastructure',
        'category' => 'network',
        'subcategory' => $subcategory,
        'manufacturer' => 'Oblivion Native',
        'model' => 'Playwright managed device',
        'serial_number' => $uid.'-SERIAL',
        'mac_address' => '02:00:00:00:'.substr(md5($uid), 0, 2).':'.substr(md5($uid), 2, 2),
        'ip_address' => '10.77.0.'.(10 + (crc32($uid) % 100)),
        'provider' => 'oblivion_native',
        'status' => 'active',
        'health_status' => 'healthy',
        'last_seen_at' => now(),
        'created_by_user_id' => $admin->id,
    ], $attributes))->save();

    \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::query()
        ->where('device_id', $device->id)
        ->delete();
    \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::create([
        'device_id' => $device->id,
        'assignable_type' => \\App\\Domain\\SecurityDevices\\Models\\DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
    ]);

    return $device;
};

$gatewayName = 'Playwright Kauri SD-WAN gateway';
$switchName = 'Playwright Kauri core switch';
$printerName = 'Playwright Kauri basic printer';
$gateway = $upsertDevice('PW-NET-GATEWAY', $gatewayName, 'edge_router', [
    'firmware_version' => '1.4.0',
    'meta' => [
        'observed' => [
            'configuration_hash' => 'pw-observed-hash',
            'configuration_at' => now()->subHour()->toISOString(),
            'firmware_at' => now()->subHours(2)->toISOString(),
        ],
        'desired' => [
            'configuration_hash' => 'pw-desired-hash',
            'firmware_version' => '1.5.0',
        ],
        'provider_secret' => $rawSentinel,
    ],
    'config' => ['private_provider_envelope' => $rawSentinel],
]);
$switch = $upsertDevice('PW-NET-SWITCH', $switchName, 'managed_switch');
$printer = $upsertDevice('PW-NET-PRINTER', $printerName, 'network_printer', [
    'firmware_version' => null,
    'meta' => null,
    'config' => null,
]);

\\App\\Domain\\SecurityDevices\\Models\\DeviceRelationship::query()->updateOrCreate(
    ['parent_device_id' => $gateway->id, 'child_device_id' => $switch->id],
    [
        'relationship_type' => 'uplinks_to',
        'port' => 'PW-WAN1',
        'notes' => $rawSentinel,
    ],
);

$profile = \\App\\Domain\\Monitoring\\Models\\MonitoringProfile::query()->updateOrCreate(
    ['name' => 'Playwright native network profile'],
    [
        'description' => 'Browser acceptance evidence for native Network & IT.',
        'interval_seconds' => 60,
        'failure_confirmations' => 3,
        'recovery_confirmations' => 2,
        'stale_after_seconds' => 300,
        'is_active' => true,
    ],
);

$upsertMonitor = function ($device, string $name, string $kind, string $state, array $attributes = []) use ($profile) {
    $monitor = \\App\\Domain\\Monitoring\\Models\\Monitor::query()->updateOrCreate(
        ['device_id' => $device->id, 'name' => $name],
        array_merge([
            'profile_id' => $profile->id,
            'collector_id' => null,
            'kind' => $kind,
            'target' => $device->ip_address,
            'config' => [],
            'current_state' => $state,
            'pending_state' => null,
            'pending_count' => 0,
            'affects_availability' => true,
            'is_enabled' => true,
            'last_observation_at' => now(),
            'last_state_changed_at' => now(),
        ], $attributes),
    );
    \\App\\Domain\\Monitoring\\Models\\MonitorObservation::query()
        ->where('monitor_id', $monitor->id)
        ->where('source_key', 'playwright-current')
        ->delete();

    return $monitor;
};

$availabilityName = 'Playwright WAN availability';
$serviceName = 'Playwright client portal HTTPS';
$interfaceName = 'Playwright WAN 1';
$availability = $upsertMonitor($gateway, $availabilityName, 'icmp', 'healthy');
$service = $upsertMonitor($gateway, $serviceName, 'http', 'failed', [
    'config' => ['authorization' => $rawSentinel],
]);
$interface = $upsertMonitor($switch, $interfaceName, 'snmp_interface', 'degraded', [
    'config' => ['community' => $rawSentinel],
]);

$observe = function ($monitor, string $state, array $metrics) use ($site) {
    return \\App\\Domain\\Monitoring\\Models\\MonitorObservation::query()->create([
        'monitor_id' => $monitor->id,
        'device_id' => $monitor->device_id,
        'site_id' => $site->id,
        'collector_id' => $monitor->collector_id,
        'source_key' => 'playwright-current',
        'state' => $state,
        'latency_ms' => 12,
        'metrics' => $metrics,
        'observed_at' => now(),
        'ingested_at' => now(),
    ]);
};
$observe($availability, 'healthy', ['packet_loss_pct' => 0]);
$observe($service, 'failed', ['status_code' => 503, 'private_payload' => $rawSentinel]);
$observe($interface, 'degraded', [
    'interface_name' => $interfaceName,
    'if_index' => 7,
    'admin_status' => 'up',
    'operational_status' => 'up',
    'speed_bps' => 1000000000,
    'in_bps' => 850000000,
    'out_bps' => 620000000,
    'in_utilization_pct' => 85,
    'out_utilization_pct' => 62,
    'errors' => 12,
    'discards' => 3,
    'private_payload' => $rawSentinel,
]);

$ticketTitle = 'Playwright investigate Kauri WAN capacity';
$ticket = \\App\\Models\\ItTicket::query()
    ->where('tenant_id', $tenantId)
    ->where('title', $ticketTitle)
    ->first();
if (! $ticket) {
    $ticket = new \\App\\Models\\ItTicket();
}
$ticket->forceFill([
    'tenant_id' => $tenantId,
    'title' => $ticketTitle,
    'description' => 'Linked browser evidence for the Network & IT workspace.',
    'requester_user_id' => $admin->id,
    'category' => 'network',
    'source' => 'system',
    'work_type' => 'incident',
    'priority' => 'high',
    'impact' => 'site',
    'urgency' => 'high',
    'status' => 'open',
])->save();
\\App\\Models\\ItTicketLink::query()->updateOrCreate(
    [
        'tenant_id' => $tenantId,
        'ticket_id' => $ticket->id,
        'relationship' => 'affected_device',
        'linkable_type' => \\App\\Domain\\SecurityDevices\\Models\\Device::class,
        'linkable_id' => $switch->id,
    ],
    ['created_by_user_id' => $admin->id],
);

echo json_encode([
    'siteName' => $site->name,
    'gatewayName' => $gatewayName,
    'switchName' => $switchName,
    'printerName' => $printerName,
    'interfaceName' => $interfaceName,
    'availabilityName' => $availabilityName,
    'serviceName' => $serviceName,
    'ticketTitle' => $ticketTitle,
    'topologyPort' => 'PW-WAN1',
    'rawSentinel' => $rawSentinel,
]);
`);

    const jsonStart = output.lastIndexOf('{"siteName"');
    if (jsonStart === -1) throw new Error(output.trim());

    return JSON.parse(output.slice(jsonStart)) as Fixture;
}
