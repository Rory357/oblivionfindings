import type { ControlRoomAlertAccess } from '@/components/security-devices/permission-destinations';
import type { DeviceListItem } from '../devices/shared';
export interface SiteTechnology {
    id: number;
    name: string;
    type: string | null;
    city: string | null;
    address: string;
    is_active: boolean;
}

export interface SiteSummary {
    health: string;
    devices: number;
    attention_devices: number;
    offline_devices: number;
    monitored_devices: number | null;
    unmonitored_devices: number | null;
    coverage_percent: number | null;
    failed_monitors: number | null;
    active_findings: number | null;
    active_control_room_alerts: number | null;
    open_it_work: number | null;
    overdue_maintenance: number | null;
    collector: {
        state: string;
        label: string;
        count: number;
        last_seen_at: string | null;
    };
    last_change_at: string | null;
}

export type DeviceGroup = {
    id: number;
    name: string;
    type: string;
    device_count: number;
    href: string;
};

export type Monitor = {
    id: number;
    device_id: number;
    device_name: string;
    name: string;
    kind: string | null;
    state: string;
    collector_id?: number | null;
    collector_name?: string | null;
    runs_from?: string;
    last_observation_at: string | null;
};

export type Alert = {
    id: number;
    reference: string | null;
    title: string | null;
    severity: string | null;
    status: string | null;
    triggered_at: string | null;
    href: string | null;
    access: ControlRoomAlertAccess;
};

export type ItWork = {
    id: number;
    reference: string | null;
    title: string;
    work_type: string;
    status: string;
    priority: string;
    next_action: string | null;
    updated_at: string | null;
    href: string;
};

export type Maintenance = {
    id: number;
    device_id: number;
    device_name: string | null;
    type: string;
    status: string;
    description: string;
    scheduled_for: string | null;
    is_overdue: boolean;
};

export type Collector = {
    id: number;
    uuid: string;
    name: string;
    status: string;
    state: string;
    freshness_state?: string;
    software_version?: string | null;
    last_seen_at: string | null;
    last_heartbeat_at?: string | null;
    revoked_at?: string | null;
    is_home_site?: boolean;
    home_site?: { id: number; name: string; href: string } | null;
    monitor_load?: number;
    scope_load?: number;
    covered_site_ids?: number[];
    covered_sites?: Array<{ id: number; name: string; href: string }>;
    update_url?: string | null;
};

export type ContactItem = {
    id: number;
    type: string;
    name: string;
    role: string | null;
    phone: string | null;
    email: string | null;
    is_primary: boolean;
};

export type SiteTopologyTreeNode = {
    id: number;
    device_uid: string;
    name: string;
    tier: 'gateway' | 'switch' | 'access_point' | 'device' | string;
    domain: string | null;
    category: string | null;
    subcategory: string | null;
    manufacturer: string | null;
    model: string | null;
    ip_address: string | null;
    mac_address: string | null;
    status: string;
    health: string;
    href: string;
    uplink: {
        protocol: string;
        label: string;
        port: string | null;
    } | null;
    children: SiteTopologyTreeNode[];
};

export type SiteTopologyTree = {
    tier_counts: {
        gateways: number;
        switches: number;
        access_points: number;
        devices: number;
    };
    sources: string[];
    last_captured_at: string | null;
    mapped_count: number;
    roots: SiteTopologyTreeNode[];
    unplaced: SiteTopologyTreeNode[];
};

export type SiteTechnologyProps = {
    site: SiteTechnology;
    devices: DeviceListItem[];
    summary: SiteSummary;
    wan: {
        known: boolean;
        label: string;
        devices: DeviceListItem[];
    };
    topology: {
        device_count: number;
        edge_count: number;
        is_complete: boolean;
        edges: Array<{
            id: number;
            parent_name: string | null;
            child_name: string | null;
            type: string;
            port: string | null;
        }>;
        tree?: SiteTopologyTree;
    };
    deviceGroups: DeviceGroup[];
    monitoring: {
        total_devices: number;
        monitored_devices: number | null;
        unmonitored_devices: number | null;
        failed_monitors: number | null;
        uncertain_monitors: number | null;
        monitors: Monitor[];
    };
    alerts: Alert[];
    itWork: ItWork[];
    maintenance: Maintenance[];
    collectors: Collector[];
    hub_wizard?: {
        options_url: string;
        issue_url: string;
        sites: Array<{ id: number; name: string }>;
    } | null;
    changes: Array<{
        key: string;
        device_name: string | null;
        summary: string;
        at: string;
        href: string;
    }>;
    contacts: ContactItem[];
    can: {
        view_control_room: boolean;
        view_it_work: boolean;
        view_site_profile: boolean;
        manage_collectors?: boolean;
        export: boolean;
    };
};
