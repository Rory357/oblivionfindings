import type { StatusVariant } from '@/components/ui/status-badge';

/**
 * One name for one thing across the S&D rebuild (docs/security-devices/BUILD-CONVENTIONS.md).
 * The server decides each device's kind and state (app/Domain/SecurityDevices/Screens/DeviceKind
 * and DeviceState); these are the words and badge tones the pages show for them.
 */

export type DeviceKind =
    | 'switch'
    | 'router'
    | 'wifi_point'
    | 'network_device'
    | 'camera'
    | 'recorder'
    | 'alarm_panel'
    | 'door'
    | 'sensor'
    | 'sensor_gateway'
    | 'care_tracker'
    | 'vehicle_tracker'
    | 'computer'
    | 'ups'
    | 'printer'
    | 'hub'
    | 'other';

export type DeviceState =
    | 'online'
    | 'problem'
    | 'offline'
    | 'cant_check'
    | 'not_watched'
    | 'retired';

export type DevicesTab =
    | 'all'
    | 'network'
    | 'cameras'
    | 'alarms_doors'
    | 'sensors'
    | 'trackers'
    | 'computers'
    | 'other'
    | 'upkeep';

export const deviceKindLabel: Record<DeviceKind, string> = {
    switch: 'Network switch',
    router: 'Router',
    wifi_point: 'Wi-Fi point',
    network_device: 'Network device',
    camera: 'Camera',
    recorder: 'Video recorder',
    alarm_panel: 'Alarm panel',
    door: 'Door',
    sensor: 'Sensor',
    sensor_gateway: 'Sensor gateway',
    care_tracker: 'Care tracker',
    vehicle_tracker: 'Vehicle tracker',
    computer: 'Computer',
    ups: 'UPS (power)',
    printer: 'Printer',
    hub: 'Hub',
    other: 'Other device',
};

export const deviceStateLabel: Record<DeviceState, string> = {
    online: 'Online',
    problem: 'Needs attention',
    offline: 'Offline',
    cant_check: 'Can’t check',
    not_watched: 'Not watched',
    retired: 'Retired',
};

/** "Can't check" is grey, never red: something in front of the device is down. */
export const deviceStateTone: Record<DeviceState, StatusVariant> = {
    online: 'success',
    problem: 'warning',
    offline: 'critical',
    cant_check: 'neutral',
    not_watched: 'neutral',
    retired: 'neutral',
};

export const devicesTabLabel: Record<DevicesTab, string> = {
    all: 'All',
    network: 'Network',
    cameras: 'Cameras',
    alarms_doors: 'Alarms & doors',
    sensors: 'Sensors',
    trackers: 'Trackers',
    computers: 'Computers',
    other: 'Other',
    upkeep: 'Upkeep',
};

/** Support levels on Setup › Supported devices; "Tested on real hardware" only after a walk proved it. */
export const supportLevelLabel = {
    tested_on_hardware: 'Tested on real hardware',
    built_from_docs: 'Built from vendor docs',
    planned: 'Planned',
    view_only: 'View only',
} as const;
