export const INCIDENT_ROUTING_KEY = 'healthSafety.incidents.owner_routing';
export type RoutingRule = {
    site_id: number;
    scope: 'ordinary' | 'restricted';
    lead_user_id: number;
    effective_from: string;
    effective_until: string | null;
    cover_user_id: number | null;
    cover_from: string | null;
    cover_until: string | null;
};
export type RoutingTriage = {
    user_id: number;
    escalation_user_id: number;
    escalation_after_minutes: number;
};
export type VisibleRoutingConfiguration = {
    schema_version: 1;
    routes: RoutingRule[];
    triage:
        | {
              visibility: 'readable';
              state: 'configured' | 'not_configured';
              values: RoutingTriage | null;
              display_names?: { user: string; escalation: string };
          }
        | { visibility: 'restricted'; state: 'configured' };
};
export type RoutingSnapshot = {
    setting_key: string;
    revision: number | null;
    configuration_valid: boolean;
    state: 'unconfigured' | 'configured' | 'repair_required';
    configuration: VisibleRoutingConfiguration | null;
    digest: string | null;
    setting_change_id: number | null;
    recorded_at: string | null;
};
export type RoutingHistory = {
    data: Array<{
        id: number;
        revision: number;
        integrity: 'recorded' | 'repair_required';
        action: 'changed' | 'kept' | null;
        configuration: VisibleRoutingConfiguration | null;
        reason: string | null;
        actor: { id: number; name: string } | null;
        recorded_at: string | null;
    }>;
    last_page: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
};
export type RoutingCommand = {
    configuration: {
        schema_version: 1;
        routes: RoutingRule[];
        triage?: RoutingTriage | null;
    };
    expected_revision: number;
    request_uuid: string;
    reason: string;
};
export function confirmsRoutingReceipt(
    value: unknown,
    input: RoutingCommand,
    actorId: number,
): boolean {
    if (!value || typeof value !== 'object') return false;
    const receipt = value as Record<string, unknown>;
    return (
        receipt.accepted === true &&
        receipt.setting_key === INCIDENT_ROUTING_KEY &&
        receipt.request_uuid === input.request_uuid &&
        receipt.actor_id === actorId &&
        Number.isSafeInteger(input.expected_revision) &&
        input.expected_revision >= 0 &&
        receipt.revision === input.expected_revision + 1 &&
        (receipt.action === 'changed' || receipt.action === 'kept') &&
        typeof receipt.configuration_digest === 'string' &&
        /^[a-f0-9]{64}$/.test(receipt.configuration_digest)
    );
}
