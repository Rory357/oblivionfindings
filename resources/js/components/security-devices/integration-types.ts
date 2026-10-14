export type ProviderException = {
    type: string;
    summary: string;
    action: string;
    href: string | null;
    count: number;
};
export type Provider = {
    slug: string;
    name: string;
    vendor: string;
    summary: string;
    device_scope: string[];
    docs_href: string | null;
    connection_status:
        | 'connected'
        | 'disconnected'
        | 'error'
        | 'untested'
        | 'disabled'
        | 'unavailable'
        | 'not_configured';
    connected: boolean;
    last_tested_at: string | null;
    last_synced_at: string | null;
    device_count: number;
    events_24h: number;
    credential?: {
        configured: boolean;
        reference: string | null;
        reference_label: string | null;
        display_state:
            | 'provider_connection_configured'
            | 'site_credentials_configured'
            | 'not_configured';
        rotation_state: string;
        rotation_cadence_days: number;
        rotated_at: string | null;
        created_at: string | null;
        last_tested_at: string | null;
        site_credentials: {
            total: number;
            enabled: number;
            needs_attention: number;
            capabilities: string[];
        };
    };
    site_mapping: {
        total: number;
        mapped: number;
        unmapped: number;
        sites: Array<{ id: number; name: string | null; state: string }>;
    };
    sync: {
        status: string;
        freshness: string;
        last_synced_at: string | null;
        items_processed: number;
        items_errored: number;
        stale_site_count: number;
        affected_site_count: number;
        summary: string | null;
    };
    reconciliation: {
        imported_devices: number;
        unassigned_devices: number;
        duplicate_candidates: number;
        unsupported_checks: number;
    };
    monitoring_support: {
        state: 'supported' | 'capability_absent';
        scope: 'provider';
        note: string;
    };
    health: {
        state:
            | 'unsupported'
            | 'not_run'
            | 'collecting'
            | 'current'
            | 'stale'
            | 'partial'
            | 'failed';
        freshness: 'unsupported' | 'never' | 'current' | 'stale';
        last_attempted_at: string | null;
        last_collected_at: string | null;
        summary: string;
        action: string;
        href: string | null;
    };
    runtime: {
        version: string;
        contract_state:
            | 'native_runtime_only'
            | 'connection_health_only'
            | 'inventory_sync'
            | 'topology_collection'
            | 'monitoring_topology_snapshot_collection'
            | 'monitoring_inventory_sync_webhook';
        contract_label: string;
        contract_note: string;
        capabilities: string[];
        page_limit: number;
        minimum_interval_seconds: number;
        backfill_limit: number;
        cursor_scopes: number;
        partial_scopes: number;
        exception_count: number;
        latest_completed_at: string | null;
        latest_exception_at: string | null;
        exception_codes: string[];
        disconnect_ready: boolean;
        revoke_ready: boolean;
    };
    exceptions: ProviderException[];
    exception_count: number;
};

export type IntegrationsProps = {
    actor_id: number;
    as_of: string;
    providers: Provider[];
    stats: {
        providers_total: number;
        providers_connected: number;
        providers_errored: number;
        imported_devices: number;
        events_24h: number;
        exceptions: number;
    };
    can: { manage: boolean };
    boundaries: {
        sync_stale_after_hours: number;
        credential_rotation_cadence_days: number;
        alert_owner: string;
    };
};
