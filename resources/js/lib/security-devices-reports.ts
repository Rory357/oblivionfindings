import { AlertOctagon, HardDrive, Wrench } from 'lucide-react';
export type ReportKey = 'devices' | 'events' | 'maintenance';
export const reports = [
    {
        key: 'devices' as const,
        title: 'Device inventory',
        icon: HardDrive,
        href: '/security-devices/reports/devices.csv',
        description:
            'Device records in your permitted scope, including their recorded status, health and hardware details.',
        rows: 'One row per permitted device, including devices with any recorded status.',
        fields: [
            'Record ID',
            'Device ID',
            'Name',
            'Domain',
            'Category',
            'Subcategory',
            'Manufacturer',
            'Model',
            'Serial number',
            'MAC address',
            'IMEI',
            'Asset tag',
            'Firmware version',
            'Status',
            'Health',
            'Provider',
            'Battery level',
            'Last seen',
            'Last signal',
            'Next service due',
            'Created',
        ],
    },
    {
        key: 'events' as const,
        title: 'Device events',
        icon: AlertOctagon,
        href: '/security-devices/reports/events.csv',
        description:
            'Recent device signals, alarms and state changes with their recorded times and sources.',
        rows: 'One row per event permitted for where the device was assigned when it happened.',
        fields: [
            'Event ID',
            'Device record ID',
            'Device name',
            'Event type',
            'Severity',
            'Source',
            'Occurred',
            'Processed',
        ],
    },
    {
        key: 'maintenance' as const,
        title: 'Maintenance log',
        icon: Wrench,
        href: '/security-devices/reports/maintenance.csv',
        description:
            'Scheduled and completed device work, including recorded staff, work references and cost.',
        rows: 'One row per work record for devices in your current permitted scope.',
        fields: [
            'Work ID',
            'Device record ID',
            'Device name',
            'Work type',
            'Status',
            'Description',
            'Scheduled',
            'Completed',
            'Recorded by',
            'Work reference',
            'Recorded cost',
        ],
    },
];
export function downloadReport(key: ReportKey) {
    const report = reports.find((report) => report.key === key);
    if (report) window.location.assign(report.href);
}
