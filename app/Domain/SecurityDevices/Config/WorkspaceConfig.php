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
     *         categories?: array<int, string>
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
                    self::tab('map', 'Map', 'Discovered relationships and topology evidence.', 'not_configured'),
                    self::tab('devices', 'Devices', 'Canonical network and IT device inventory.'),
                    self::tab('interfaces', 'Interfaces', 'Observed ports, interfaces, links, and counters.', 'not_configured'),
                    self::tab('services', 'Services', 'Service checks, dependencies, and monitoring coverage.', 'not_configured'),
                    self::tab('traffic-capacity', 'Traffic & capacity', 'Retained traffic and utilisation evidence.', 'not_configured'),
                    self::tab('configuration-firmware', 'Configuration & firmware', 'Observed configuration, drift, and firmware evidence.', 'not_configured'),
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
                    self::tab('events', 'Security events', 'Canonical device events and Control Room context.', 'not_configured'),
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
                    self::tab('client-devices', 'Client devices', 'Permission-safe client assignments and technical state.', 'not_configured'),
                    self::tab('shared-site-devices', 'Shared & site devices', 'Shared equipment, location, and service responsibility.', 'not_configured'),
                    self::tab('data-flow', 'Connectivity & data flow', 'Connectivity, integration, and delivery freshness.', 'not_configured'),
                    self::tab('calibration-maintenance', 'Calibration & maintenance', 'Calibration and maintenance evidence.', 'not_configured'),
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
                    self::tab('personal-safety', 'Personal safety', 'Authorised personal-safety tracker context.', 'not_configured'),
                    self::tab('fleet', 'Fleet', 'Vehicle tracker hardware and Fleet links.', 'not_configured'),
                    self::tab('assets', 'Assets', 'Asset tracker assignments and technical state.', 'not_configured'),
                    self::tab('geofences', 'Geofences', 'Purpose-aware geofence definitions and state.', 'not_configured'),
                    self::tab('history', 'History', 'Permission and retention-aware tracking history.', 'not_configured'),
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
    ): array {
        return array_filter([
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'state' => $state,
            'categories' => $categories,
        ], fn ($value) => $value !== null);
    }
}
