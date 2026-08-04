<?php

namespace App\Domain\SecurityDevices\Config;

/**
 * Authoritative configuration for each category page in the Security & Devices module.
 *
 * Each entry defines the domain/category scope, display metadata, and empty-state
 * messaging for one category page. The CategoryPageController uses this to build
 * scoped queries and return the correct props to the frontend.
 *
 * Adding a new category page = add one entry here + one route + one controller method.
 */
class CategoryPageConfig
{
    /**
     * @return array<string, array{slug: string, title: string, description: string, emptyTitle: string, emptyDescription: string, icon: string, domain: string, categories: string[]|null}>
     */
    public static function all(): array
    {
        return [
            'network-it' => [
                'slug' => 'network-it',
                'title' => 'Network & IT',
                'description' => 'Network, server, storage, power, endpoint, voice, printing, and rack infrastructure across all sites.',
                'emptyTitle' => 'No network or IT devices registered',
                'emptyDescription' => 'Register infrastructure devices to build the application-wide technology estate.',
                'icon' => 'network-it',
                'domain' => 'it_infrastructure',
                'categories' => null,
            ],
            'security' => [
                'slug' => 'security',
                'title' => 'Security',
                'description' => 'CCTV, alarms, physical access control, perimeter devices, and security events across all sites.',
                'emptyTitle' => 'No security devices registered',
                'emptyDescription' => 'Register cameras, alarms, access-control, and perimeter devices to build the security estate.',
                'icon' => 'security',
                'domain' => 'security',
                'categories' => null,
            ],
            'healthcare' => [
                'slug' => 'healthcare',
                'title' => 'Healthcare',
                'description' => 'Technical health, connectivity, assignment, calibration, and maintenance for healthcare-connected devices.',
                'emptyTitle' => 'No healthcare devices registered',
                'emptyDescription' => 'Register healthcare-connected devices while keeping clinical readings in Client Health Monitoring.',
                'icon' => 'healthcare',
                'domain' => 'iot_healthcare',
                'categories' => null,
            ],
            'tracking' => [
                'slug' => 'tracking',
                'title' => 'Tracking',
                'description' => 'Personal safety, Fleet, and asset tracking devices with distinct assignment and consent context.',
                'emptyTitle' => 'No tracking devices registered',
                'emptyDescription' => 'Register personal-safety, vehicle, and asset trackers to manage their technical state.',
                'icon' => 'tracking',
                'domain' => 'tracking',
                'categories' => null,
            ],
            'facilities-iot' => [
                'slug' => 'facilities-iot',
                'title' => 'Facilities & IoT',
                'description' => 'Environmental sensors, building systems, utilities, and connected facility devices across all sites.',
                'emptyTitle' => 'No facilities or IoT devices registered',
                'emptyDescription' => 'Register environmental, utility, and building-system devices to manage their technical state.',
                'icon' => 'facilities-iot',
                'domain' => 'facilities',
                'categories' => null,
            ],
            'alarms' => [
                'slug' => 'alarms',
                'title' => 'Alarms',
                'description' => 'Alarm panels, sensors, sirens, duress devices, and perimeter detection across all sites.',
                'emptyTitle' => 'No alarm devices registered',
                'emptyDescription' => 'Register alarm panels, sensors, and perimeter devices to build your security posture.',
                'icon' => 'alarms',
                'domain' => 'security',
                'categories' => ['alarm', 'perimeter'],
            ],
            'cctv' => [
                'slug' => 'cctv',
                'title' => 'CCTV',
                'description' => 'Cameras, recorders, video analytics, and recording infrastructure.',
                'emptyTitle' => 'No CCTV devices registered',
                'emptyDescription' => 'Register cameras, NVRs, and video infrastructure to manage your CCTV estate.',
                'icon' => 'cctv',
                'domain' => 'security',
                'categories' => ['cctv'],
            ],
            'access-control' => [
                'slug' => 'access-control',
                'title' => 'Access Control',
                'description' => 'Card readers, locks, barriers, intercoms, and physical access infrastructure.',
                'emptyTitle' => 'No access control devices registered',
                'emptyDescription' => 'Register readers, locks, and access panels to manage physical entry points.',
                'icon' => 'access-control',
                'domain' => 'security',
                'categories' => ['access_control'],
            ],
            'tracking-devices' => [
                'slug' => 'tracking-devices',
                'title' => 'Tracking Devices',
                'description' => 'Vehicle trackers, personal GPS, lone worker devices, asset tags, and telematics.',
                'emptyTitle' => 'No tracking devices registered',
                'emptyDescription' => 'Register GPS trackers, wearables, and telematics units to track vehicles, people, and assets.',
                'icon' => 'tracking-devices',
                'domain' => 'tracking',
                'categories' => null, // all tracking categories
            ],
            'smart-iot-healthcare' => [
                'slug' => 'smart-iot-healthcare',
                'title' => 'Smart IoT & Healthcare',
                'description' => 'Fall detection, bed sensors, nurse call, medication monitoring, environmental sensors, and smart appliance controls.',
                'emptyTitle' => 'No IoT or healthcare devices registered',
                'emptyDescription' => 'Register smart sensors, nurse call units, and healthcare monitoring devices.',
                'icon' => 'smart-iot-healthcare',
                'domain' => 'iot_healthcare',
                'categories' => null,
            ],
            'it-infrastructure' => [
                'slug' => 'it-infrastructure',
                'title' => 'IT Infrastructure',
                'description' => 'Servers, networking, storage, UPS, endpoints, voice, printing, and rack infrastructure.',
                'emptyTitle' => 'No IT infrastructure devices registered',
                'emptyDescription' => 'Register servers, switches, access points, and infrastructure hardware.',
                'icon' => 'it-infrastructure',
                'domain' => 'it_infrastructure',
                'categories' => null,
            ],
            'facilities' => [
                'slug' => 'facilities',
                'title' => 'Facilities',
                'description' => 'Leak detection, gas sensors, cold chain, generators, gate controllers, and building safety devices.',
                'emptyTitle' => 'No facilities devices registered',
                'emptyDescription' => 'Register leak sensors, gas detectors, cold chain monitors, and building safety hardware.',
                'icon' => 'facilities',
                'domain' => 'facilities',
                'categories' => null,
            ],
        ];
    }

    /**
     * Get config for a specific category page by slug.
     */
    public static function get(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }
}
