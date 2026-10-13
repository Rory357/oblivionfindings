import type { MaintenanceClockPreview } from './maintenance-clock-preview';
import type { MonitoringPolicyWorkspace } from './monitoring-policy-settings';
export function maintenanceWorkspace(): MonitoringPolicyWorkspace {
    return {
        actor_id: 7,
        visible: true,
        can_manage: true,
        can_manage_application: true,
        retention_confirmation: 'CONFIRM RETENTION CHANGE',
        sites: [{ id: 14, name: 'Synthetic Clock Site' }],
        devices: [
            {
                id: 41,
                name: 'Synthetic gateway',
                site_id: 14,
                site_name: 'Synthetic Clock Site',
            },
        ],
        monitors: [
            {
                id: 91,
                name: 'Synthetic reachability',
                kind: 'icmp',
                device_id: 41,
                device_name: 'Synthetic gateway',
                site_id: 14,
                site_name: 'Synthetic Clock Site',
                enabled: true,
            },
        ],
        catalogs: {
            domains: [
                {
                    value: 'it_infrastructure',
                    label: 'IT Infrastructure',
                    categories: [{ value: 'network', label: 'Network' }],
                },
            ],
            capabilities: [
                {
                    value: 'reachability',
                    label: 'Reachability',
                    monitor_kind: 'icmp',
                },
            ],
            data_classes: ['operational'],
            privacy_classes: ['standard'],
            timezones: ['Pacific/Auckland', 'UTC'],
        },
        profiles: [],
        coverage: [],
        dependencies: [],
        maintenance: [],
        retention: [],
    };
}
export function maintenancePreview(
    body: Record<string, unknown>,
): MaintenanceClockPreview {
    const starts = String(body.starts_at),
        ends = String(body.ends_at),
        zone = String(body.timezone);
    const local = (value: string) =>
        value
            .replace(/(?:Z|[+-]\d\d:\d\d)$/, '')
            .replace(/(T\d\d:\d\d)$/, '$1:00') + '.000000';
    return {
        schema_version: 1,
        actor_id: Number(body.expected_actor_id),
        owner: 'Monitoring',
        scope: {
            site_id: Number(body.site_id),
            device_id: body.device_id ? Number(body.device_id) : null,
            monitor_id: body.monitor_id ? Number(body.monitor_id) : null,
        },
        timezone: zone,
        dst_overlap: body.dst_overlap as 'reject',
        dst_gap: body.dst_gap as 'reject',
        precision: 'seconds',
        commits_change: false,
        scheduled_occurrences: 1,
        skipped_occurrences: 0,
        truncated: false,
        occurrences: [
            {
                local_start: local(starts),
                local_end: local(ends),
                timezone: zone,
                state: 'ready',
                reason_code: null,
                starts_at: new Date(starts)
                    .toISOString()
                    .replace('.000Z', '+00:00'),
                ends_at: new Date(ends)
                    .toISOString()
                    .replace('.000Z', '+00:00'),
            },
        ],
    };
}
