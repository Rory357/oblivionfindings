import type { MaintenanceHealthProps } from './maintenance-health';
export function healthFixture(): MaintenanceHealthProps {
    return {
        actor_id: 7,
        financeNote:
            'Costs shown in legacy maintenance history are informational. Finance remains the authoritative financial record.',
        stats: {
            overdue: 1,
            upcoming: 2,
            offline: 1,
            degraded: 1,
            lowBattery: 1,
            critical: 1,
        },
        records: {
            data: [
                {
                    id: 8,
                    device_id: 2,
                    device_name: 'Synthetic pump',
                    device_uid: 'DEV-2',
                    type: 'calibration',
                    status: 'scheduled',
                    description: 'Calibrate synthetic pump',
                    scheduled_for: '2026-10-20',
                    completed_at: null,
                    performed_by: null,
                    vendor_reference: 'WO-42',
                    cost: '45.00',
                    notes: 'Private retained work note',
                    is_overdue: false,
                },
            ],
            meta: { current_page: 1, last_page: 2, total: 31 },
            links: [
                { url: null, label: '&laquo; Previous', active: false },
                {
                    url: '/security-devices/maintenance-health?page=1',
                    label: '1',
                    active: true,
                },
                {
                    url: '/security-devices/maintenance-health?page=2&search=WO-42',
                    label: '2',
                    active: false,
                },
                {
                    url: '/security-devices/maintenance-health?page=2&search=WO-42',
                    label: 'Next &raquo;',
                    active: false,
                },
            ],
        },
        attentionDevices: [
            {
                id: 2,
                name: 'Synthetic pump',
                device_uid: 'DEV-2',
                domain: 'iot_healthcare',
                category: 'medical_device',
                status: 'offline',
                health_status: 'critical',
                battery_level: null,
                last_seen_at: '2026-07-21T23:30:00Z',
            },
        ],
        lowBatteryDevices: [
            {
                id: 3,
                name: 'Synthetic door sensor',
                device_uid: 'DEV-3',
                battery_level: 15,
                battery_updated_at: '2026-07-01T00:00:00Z',
            },
        ],
        filters: { search: 'WO-42', sort: 'created_at', direction: 'desc' },
        can: { manage: true },
    };
}
