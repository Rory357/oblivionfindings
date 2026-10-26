/** Props of pages/control-room/settings.tsx (ControlRoomSettingsController::index). */

export type SettingsTab =
    | 'rules'
    | 'queues'
    | 'sources'
    | 'maintenance'
    | 'signal-outbox'
    | 'ticket-options';

export interface SignalTypeOption {
    id: number;
    code: string;
    name: string;
    category: string;
    default_severity: string;
}

export interface SignalSourceOption {
    id: number;
    name: string;
    slug: string;
    vendor: string;
    status: 'active' | 'inactive' | 'maintenance';
    capabilities: string[];
    last_heartbeat_at: string | null;
    last_signal_at: string | null;
    signal_count_24h: number;
    is_healthy: boolean;
}

export interface SignalRuleData {
    id: number;
    name: string;
    signal_type_id: number | null;
    signal_type_code: string | null;
    signal_type_name: string | null;
    signal_source_id: number | null;
    signal_source_name: string | null;
    priority: number;
    conditions: Record<string, unknown> | null;
    output_severity: string | null;
    output_escalation_level: number | null;
    output_tier: number | null;
    dedup_window_minutes: number | null;
    deduplicate: boolean;
    suppress_in_maintenance: boolean;
    notify_roles: string[];
    notify_users: number[];
    playbook_id: number | null;
    playbook_name: string | null;
    is_active: boolean;
}

export interface TriageQueueData {
    id: number;
    name: string;
    code: string;
    tier: number;
    description: string | null;
    handle_severities: string[];
    handle_sources: string[];
    handle_alert_types: string[];
    assigned_roles: string[];
    assigned_users: number[];
    auto_escalate_after_minutes: number | null;
    escalate_to_queue_id: number | null;
    escalate_to_queue_name: string | null;
    is_active: boolean;
    open_alert_count: number;
}

export interface MaintenanceWindowData {
    id: number;
    name: string;
    description: string | null;
    signal_source_id: number | null;
    signal_source_name: string | null;
    site_id: number | null;
    starts_at: string;
    ends_at: string;
    status: 'scheduled' | 'active' | 'completed' | 'cancelled';
    created_by_name: string | null;
}

export interface SignalOutboxRow {
    id: number;
    status: 'failed' | 'dead_letter' | 'unroutable';
    attempts: number;
    last_attempt_at: string | null;
    last_error: string | null;
    created_at: string | null;
    updated_at: string | null;
    can_retry: boolean;
    signal: {
        id: number;
        asset_id: number;
        device_id: number | null;
        signal_type: string;
        severity_hint: string;
        occurred_at: string | null;
    } | null;
}

export interface PlaybookOption {
    id: number;
    name: string;
    code: string;
}

export interface SiteOption {
    id: number;
    name: string;
}

export interface ConfigOption {
    id: number;
    group: string;
    value: string;
    label: string;
    color: string | null;
    description: string | null;
    sort_order: number;
    is_active: boolean;
}

export type OptionGroup = 'category' | 'resolution_code' | 'task_category';

export interface ControlRoomSettingsProps {
    activeTab: string;
    signalRules: SignalRuleData[];
    signalTypes: SignalTypeOption[];
    signalSources: SignalSourceOption[];
    triageQueues: TriageQueueData[];
    maintenanceWindows: MaintenanceWindowData[];
    signalOutbox: SignalOutboxRow[];
    playbooks: PlaybookOption[];
    sites: SiteOption[];
    configOptions: Record<string, ConfigOption[]>;
}

export const SEVERITIES = ['low', 'medium', 'high', 'critical'] as const;

/** Where an alert can come from (TriageQueue::handle_sources). */
export const ALERT_SOURCES = [
    'fleet',
    'compliance',
    'medication',
    'incident',
    'device',
    'manual',
    'integration',
] as const;

/** Roles a queue can be given to. */
export const QUEUE_ROLES = [
    'control_room_operator',
    'control_room_supervisor',
    'site_manager',
    'clinical_lead',
    'on_call_manager',
] as const;

export const OPTION_GROUPS: ReadonlyArray<{
    key: OptionGroup;
    title: string;
    caption: string;
}> = [
    {
        key: 'category',
        title: 'Alert categories',
        caption: 'Used to group alerts in lists and reports.',
    },
    {
        key: 'resolution_code',
        title: 'Resolution codes',
        caption: 'Picked when an alert is resolved, so reports can say how it ended.',
    },
    {
        key: 'task_category',
        title: 'Task categories',
        caption: 'Used to sort the tasks added to alerts.',
    },
];
