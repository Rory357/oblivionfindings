export type EstateSiteImpact = {
    id: number;
    name: string;
    city: string | null;
    health: string;
    devices: number;
    monitored_devices: number | null;
    unmonitored_devices: number | null;
    coverage_percent: number | null;
    active_findings: number | null;
    open_it_work: number | null;
    overdue_maintenance: number | null;
    collector: {
        state: string;
        label: string;
        count?: number | null;
        last_seen_at?: string | null;
    } | null;
    type?: string | null;
    is_active?: boolean;
    attention_devices?: number;
    offline_devices?: number;
    failed_monitors?: number | null;
    uncertain_monitors?: number | null;
    active_control_room_alerts?: number | null;
    requires_action?: boolean;
    last_change_at: string | null;
    href: string | null;
};

export type EstateProps = {
    actor_id: number;
    as_of: string;
    stats: {
        totalDevices: number;
        active: number;
        offline: number;
        degraded: number;
        lowBattery: number;
        overdueMaintenance: number | null;
        serviceDueOverdue: number;
        serviceDueIn30d: number;
        criticalEvents24h: number | null;
        warningEvents24h: number | null;
    };
    domainSummary: Array<{ domain: string; label: string; count: number }>;
    healthSummary: Array<{ status: string; label: string; count: number }>;
    attentionDevices: Array<{
        id: number;
        name: string;
        device_uid: string;
        domain: string;
        category: string;
        status: string;
        health_status: string;
        battery_level: number | null;
        last_seen_at: string | null;
    }>;
    recentEvents: Array<{
        id: number;
        device_id: number;
        device_name: string | null;
        source?: string | null;
        event_type: string;
        severity: string;
        occurred_at: string;
    }>;
    overdueMaintenance: Array<{
        id: number;
        device_id: number;
        device_name: string | null;
        type: string;
        vendor_reference?: string | null;
        description: string;
        scheduled_for: string | null;
    }>;
    groupCount: number;
    operations: {
        coverage: {
            total_devices: number;
            monitored_devices: number;
            unmonitored_devices: number;
            percent: number | null;
        };
        summary: {
            affected_sites: number;
            active_findings: number;
            open_it_work: number | null;
            failed_monitors: number;
            overdue_maintenance: number;
        };
        site_impact: EstateSiteImpact[];
        action_queue: Array<{
            key: string;
            label: string;
            count: number | null;
            href: string | null;
            restriction_reason: string | null;
        }>;
        recent_changes: Array<{
            key: string;
            device_id?: number;
            kind: string;
            device_name: string | null;
            summary: string;
            at: string | null;
            href: string | null;
        }>;
    };
    can: {
        create: boolean;
        export: boolean;
        view_devices: boolean;
        view_events: boolean;
        view_maintenance: boolean;
    };
};
