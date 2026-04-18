import {
    Bell,
    Building2,
    Cctv,
    FileText,
    GitBranch,
    HeartPulse,
    Key,
    LayoutDashboard,
    Link2,
    type LucideIcon,
    Server,
    Siren,
    Smartphone,
    Wrench,
} from 'lucide-react';

export type SecurityDevicesSectionKey =
    | 'dashboard'
    | 'alarms'
    | 'cctv'
    | 'tracking-devices'
    | 'smart-iot-healthcare'
    | 'access-control'
    | 'it-infrastructure'
    | 'facilities'
    | 'device-groups'
    | 'alerts-events'
    | 'maintenance-health'
    | 'integrations'
    | 'reports';

export type SecurityDevicesSecondarySectionKey = Exclude<
    SecurityDevicesSectionKey,
    'dashboard'
>;

export interface SecurityDevicesSectionConfig {
    key: SecurityDevicesSectionKey;
    title: string;
    href: string;
    icon: LucideIcon;
    description: string;
    futureFocus: string;
    capabilities: string[];
}

export const securityDevicesSections: SecurityDevicesSectionConfig[] = [
    {
        key: 'dashboard',
        title: 'Dashboard',
        href: '/security-devices',
        icon: LayoutDashboard,
        description:
            'Future overview for device operations, monitoring posture, integrations, and organisation-wide visibility.',
        futureFocus:
            'This dashboard will become the central landing page for hardware status, rollout progress, and API-led device management.',
        capabilities: [
            'Summarise security and device posture across organisation, site / house, staff, and clients.',
            'Highlight rollout status for alarms, CCTV, tracking, IoT, and healthcare-connected devices.',
            'Surface future integration and API health once hardware adapters are introduced.',
        ],
    },
    {
        key: 'alarms',
        title: 'Alarms',
        href: '/security-devices/alarms',
        icon: Siren,
        description:
            'Future home for alarm panels, alarm events, and vendor-neutral monitoring workflows.',
        futureFocus:
            'This section is reserved for future alarm device management, event handling, and operational alarm visibility.',
        capabilities: [
            'Track alarm devices, panels, and trigger states across sites and houses.',
            'Centralise future alarm-related monitoring APIs without locking the module to a single vendor.',
            'Support alerting and workflow views for operational response teams.',
        ],
    },
    {
        key: 'cctv',
        title: 'CCTV',
        href: '/security-devices/cctv',
        icon: Cctv,
        description:
            'Future home for cameras, recorders, streams, and video monitoring infrastructure.',
        futureFocus:
            'This section will host the future CCTV inventory, camera management, and API-driven video integrations.',
        capabilities: [
            'Track cameras, recorders, and monitoring endpoints in a vendor-neutral structure.',
            'Provide a future home for stream health, device assignment, and site visibility.',
            'Support CCTV-related operations without moving existing pages in this phase.',
        ],
    },
    {
        key: 'tracking-devices',
        title: 'Tracking Devices',
        href: '/security-devices/tracking-devices',
        icon: Smartphone,
        description:
            'Future home for tracking hardware, wearable devices, and assignment workflows.',
        futureFocus:
            'This section will become the future management surface for tracking devices linked to sites, staff, assets, and clients.',
        capabilities: [
            'Define future tracker assignment and lifecycle management.',
            'Support location-aware hardware management across site / house and client contexts.',
            'Create a stable place for future tracking APIs and vendor integrations.',
        ],
    },
    {
        key: 'smart-iot-healthcare',
        title: 'Smart IoT & Healthcare',
        href: '/security-devices/smart-iot-healthcare',
        icon: HeartPulse,
        description:
            'Future home for smart sensors, healthcare-connected devices, and resident-support IoT.',
        futureFocus:
            'This section is reserved for future smart room, sensor, and healthcare device management with clear operational ownership.',
        capabilities: [
            'Support bed sensors, environmental monitors, and smart healthcare-connected hardware.',
            'Keep IoT management vendor-neutral so new integrations can be added cleanly.',
            'Show future assignment and visibility rules for sites, staff, and clients.',
        ],
    },
    {
        key: 'access-control',
        title: 'Access Control',
        href: '/security-devices/access-control',
        icon: Key,
        description:
            'Future home for physical access devices such as doors, readers, locks, badges, and entry events.',
        futureFocus:
            'This section is specifically for physical access hardware and related device management, not software roles or permissions.',
        capabilities: [
            'Manage future physical access hardware and entry-point infrastructure.',
            'Provide a clean place for badge, lock, reader, and door controller integrations.',
            'Separate physical access operations from the existing system RBAC area.',
        ],
    },
    {
        key: 'it-infrastructure',
        title: 'IT Infrastructure',
        href: '/security-devices/it-infrastructure',
        icon: Server,
        description:
            'Servers, networking, storage, UPS, endpoints, voice, printing, and rack infrastructure.',
        futureFocus:
            'This section manages IT infrastructure hardware with operational health, topology, and lifecycle tracking.',
        capabilities: [
            'Track servers, switches, access points, firewalls, and rack infrastructure.',
            'Monitor UPS, PDU, and power infrastructure health.',
            'Manage endpoint devices including tablets, kiosks, and shared devices.',
        ],
    },
    {
        key: 'facilities',
        title: 'Facilities',
        href: '/security-devices/facilities',
        icon: Building2,
        description:
            'Leak detection, gas sensors, cold chain, generators, gate controllers, and building safety devices.',
        futureFocus:
            'This section manages facilities-connected hardware for building safety, environmental monitoring, and utility controls.',
        capabilities: [
            'Track leak sensors, gas detectors, and building safety hardware.',
            'Monitor cold chain sensors for medication and food storage compliance.',
            'Manage gate controllers, barriers, and facility access hardware.',
        ],
    },
    {
        key: 'device-groups',
        title: 'Device Groups',
        href: '/security-devices/device-groups',
        icon: GitBranch,
        description:
            'Future home for logical grouping across organisation, sites, people, categories, and vendors.',
        futureFocus:
            'This section will define future grouping and classification patterns for hardware estates without changing device records yet.',
        capabilities: [
            'Group future devices by organisation, site / house, staff, client, category, or vendor.',
            'Support vendor-neutral device organisation for reporting and API management.',
            'Create a clean shell for future bulk actions and filtered views.',
        ],
    },
    {
        key: 'alerts-events',
        title: 'Alerts & Events',
        href: '/security-devices/alerts-events',
        icon: Bell,
        description:
            'Future home for cross-device alerts, operational events, and notification visibility.',
        futureFocus:
            'This section will become the unified event and alert shell for future device integrations.',
        capabilities: [
            'Collect future event streams from alarms, CCTV, trackers, access, and smart IoT devices.',
            'Support vendor-neutral event modelling without replacing current alert systems in this phase.',
            'Prepare the UI shell for future filtering, triage, and escalation experiences.',
        ],
    },
    {
        key: 'maintenance-health',
        title: 'Maintenance & Health',
        href: '/security-devices/maintenance-health',
        icon: Wrench,
        description:
            'Future home for device health, connectivity, battery, maintenance, and service lifecycle tracking.',
        futureFocus:
            'This section is reserved for future operational maintenance and hardware health workflows across the device estate.',
        capabilities: [
            'Track future device health, service status, and maintenance readiness.',
            'Surface battery, connectivity, and lifecycle status across vendor-neutral inventories.',
            'Provide a future place for maintenance APIs and scheduled service integrations.',
        ],
    },
    {
        key: 'integrations',
        title: 'APIs & Integrations',
        href: '/security-devices/integrations',
        icon: Link2,
        description:
            'Provider connections, site mapping, sync controls, and exceptions for hardware integrations.',
        futureFocus:
            'This section is the system of record for provider credentials, connection health, site mappings, sync schedules, and imported-device exceptions. Device pages show the result of sync; this page controls it.',
        capabilities: [
            'Manage provider connections (UniFi today; Queclink and Milesight planned) without leaking credential UI into site pages.',
            'Map organisation sites to external provider sites / controllers / gateways and track drift between them.',
            'Surface unmapped, duplicate, and ignored devices in a dedicated exceptions workbench.',
        ],
    },
    {
        key: 'reports',
        title: 'Reports',
        href: '/security-devices/reports',
        icon: FileText,
        description:
            'Future home for hardware, monitoring, compliance, and integration reporting.',
        futureFocus:
            'This section will host future operational, compliance, and device estate reporting for the Security & Devices module.',
        capabilities: [
            'Report on device inventory, health, events, and rollout coverage.',
            'Support future exports and operational reporting across organisation and site / house views.',
            'Provide a stable reporting destination before any deeper module consolidation happens.',
        ],
    },
];

export const securityDevicesSectionMap = Object.fromEntries(
    securityDevicesSections.map((section) => [section.key, section]),
) as Record<SecurityDevicesSectionKey, SecurityDevicesSectionConfig>;
