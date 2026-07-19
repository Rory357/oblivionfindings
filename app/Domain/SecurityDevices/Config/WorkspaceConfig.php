<?php

namespace App\Domain\SecurityDevices\Config;

class WorkspaceConfig
{
    /**
     * @return array<string, array{
     *     slug: string,
     *     title: string,
     *     description: string,
     *     canonicalHref: string,
     *     domain: string,
     *     tabs: array<int, array{
     *         key: string,
     *         label: string,
     *         description: string,
     *         state: 'available'|'not_configured',
     *         categories?: array<int, string>,
     *         requiredPermission?: string,
     *         requiredAnyPermission?: array<int, string>
     *     }>
     * }>
     */
    public static function all(): array
    {
        return [
            'network-it' => [
                'slug' => 'network-it',
                'title' => 'Network & IT',
                'description' => 'Network, server, storage, power, endpoint, voice, printing, and rack infrastructure across authorised sites.',
                'canonicalHref' => '/security-devices/network-it',
                'domain' => 'it_infrastructure',
                'tabs' => [
                    self::tab('overview', 'Overview', 'Network and IT technology posture across authorised sites.'),
                    self::tab('map', 'Map', 'Known relationships and topology evidence.'),
                    self::tab('devices', 'Devices', 'Canonical network and IT device inventory.'),
                    self::tab('interfaces', 'Interfaces', 'Observed ports, interfaces, links, and counters.'),
                    self::tab('services', 'Services', 'Service checks, dependencies, and monitoring coverage.'),
                    self::tab('traffic-capacity', 'Traffic & capacity', 'Retained traffic and utilisation evidence.'),
                    self::tab('configuration-firmware', 'Configuration & firmware', 'Observed configuration, drift, and firmware evidence.'),
                ],
            ],
            'security' => [
                'slug' => 'security',
                'title' => 'Security',
                'description' => 'CCTV, alarms, physical access control, and security-device events across authorised sites.',
                'canonicalHref' => '/security-devices/security',
                'domain' => 'security',
                'tabs' => [
                    self::tab('overview', 'Overview', 'Physical security technology posture across authorised sites.'),
                    self::tab('cctv', 'CCTV', 'Cameras, recorders, and video infrastructure.', categories: ['cctv']),
                    self::tab('alarms', 'Alarms', 'Alarm panels, zones, sensors, sirens, and perimeter devices.', categories: ['alarm', 'perimeter']),
                    self::tab('access-control', 'Access Control', 'Doors, locks, readers, panels, and physical access hardware.', categories: ['access_control']),
                    self::tab(
                        'events',
                        'Security events',
                        'Canonical device events and Control Room context.',
                        requiredPermission: 'securityDevices.events.view',
                    ),
                ],
            ],
            'healthcare' => [
                'slug' => 'healthcare',
                'title' => 'Healthcare',
                'description' => 'Technical health, connectivity, assignment, calibration, and maintenance for healthcare-connected devices.',
                'canonicalHref' => '/security-devices/healthcare',
                'domain' => 'iot_healthcare',
                'tabs' => [
                    self::tab('overview', 'Overview', 'Healthcare-device technical posture without clinical values.'),
                    self::tab(
                        'client-devices',
                        'Client devices',
                        'Permission-safe client assignments and technical state.',
                        requiredAnyPermission: ['clients.viewAny', 'clients.viewAssigned'],
                    ),
                    self::tab('shared-site-devices', 'Shared & site devices', 'Shared equipment, location, and service responsibility.'),
                    self::tab('data-flow', 'Connectivity & data flow', 'Connectivity, integration, and delivery freshness.'),
                    self::tab(
                        'calibration-maintenance',
                        'Calibration & maintenance',
                        'Calibration and maintenance evidence.',
                        requiredPermission: 'securityDevices.maintenance.view',
                    ),
                ],
            ],
            'tracking' => [
                'slug' => 'tracking',
                'title' => 'Tracking',
                'description' => 'Personal safety, Fleet, and asset tracking devices with distinct purpose, consent, and retention context.',
                'canonicalHref' => '/security-devices/tracking',
                'domain' => 'tracking',
                'tabs' => [
                    self::tab('overview', 'Overview', 'Tracking hardware posture separated by operational purpose.'),
                    self::tab(
                        'personal-safety',
                        'Personal safety',
                        'Authorised client and lone-worker tracker context.',
                        requiredAnyPermission: [
                            'hazards.view',
                            'fleet.viewAny',
                            'assets.viewAny',
                            'assets.viewAssigned',
                        ],
                    ),
                    self::tab(
                        'fleet',
                        'Fleet',
                        'Vehicle tracker hardware and canonical Fleet links.',
                        requiredPermission: 'fleet.viewAny',
                    ),
                    self::tab(
                        'assets',
                        'Assets',
                        'Asset tracker assignments and technical state.',
                        requiredAnyPermission: ['assets.viewAny', 'assets.viewAssigned'],
                    ),
                    self::tab(
                        'geofences',
                        'Geofences',
                        'Purpose-aware geofence definitions and state.',
                        requiredAnyPermission: ['fleet.viewAny', 'assets.viewAny'],
                    ),
                    self::tab(
                        'history',
                        'History',
                        'Permission and retention-aware tracking history.',
                        requiredPermission: 'assets.telemetry.view',
                    ),
                ],
            ],
            'facilities-iot' => [
                'slug' => 'facilities-iot',
                'title' => 'Facilities & IoT',
                'description' => 'Environmental sensors, building systems, utilities, and connected facility devices across authorised sites.',
                'canonicalHref' => '/security-devices/facilities-iot',
                'domain' => 'facilities',
                'tabs' => [
                    self::tab('overview', 'Overview', 'Facilities and IoT technical posture across authorised sites.'),
                    self::tab('environment', 'Environment', 'Environmental sensors, freshness, and threshold-event evidence.', 'not_configured'),
                    self::tab('building-systems', 'Building systems', 'Connected building and safety systems.', 'not_configured'),
                    self::tab('utilities', 'Utilities', 'Observed utility services and metering integrations.', 'not_configured'),
                    self::tab('automations', 'Automations', 'Supported facility automations and last execution state.', 'not_configured'),
                    self::tab('history', 'History', 'Canonical event and observation history.', 'not_configured'),
                ],
            ],
        ];
    }

    public static function get(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    public static function activeTab(array $workspace, ?string $requested): array
    {
        return collect($workspace['tabs'])
            ->firstWhere('key', $requested)
            ?? $workspace['tabs'][0];
    }

    private static function tab(
        string $key,
        string $label,
        string $description,
        string $state = 'available',
        ?array $categories = null,
        ?string $requiredPermission = null,
        ?array $requiredAnyPermission = null,
    ): array {
        return array_filter([
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'state' => $state,
            'categories' => $categories,
            'requiredPermission' => $requiredPermission,
            'requiredAnyPermission' => $requiredAnyPermission,
        ], fn ($value) => $value !== null);
    }
}
