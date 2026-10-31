export const INCIDENT_INDEPENDENCE_KEY =
    'healthSafety.incidents.independence_policy';
export type IndependenceMode = 'block_involved_access' | 'assignment_only';
export type IndependencePolicy = {
    schema_version: 1;
    mode: IndependenceMode | null;
    policy_revision: number | null;
    configured: boolean;
    configuration_valid: boolean;
    origin:
        | 'application_default_unreviewed'
        | 'organisation_setting'
        | 'repair_required';
    policy_digest: string | null;
    setting_change_id: number | null;
    reviewed_by: number | null;
    reviewed_at: string | null;
};
export type IndependenceHistory = {
    data: Array<{
        id: number;
        revision: number;
        integrity: 'recorded' | 'repair_required';
        mode: IndependenceMode | null;
        actor: { id: number; name: string } | null;
        reason: string | null;
        recorded_at: string | null;
    }>;
    last_page: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
};
export type IndependenceCommand = {
    mode: IndependenceMode | '';
    expected_policy_revision: number;
    request_uuid: string;
    reason: string;
};
export function independenceModeLabel(mode: IndependenceMode | null): string {
    return mode === 'block_involved_access'
        ? 'Restrict access and assignment'
        : mode === 'assignment_only'
          ? 'Restrict assignment only'
          : 'Unavailable';
}
export function confirmsIndependenceReceipt(
    value: unknown,
    command: IndependenceCommand,
    actorId: number,
): boolean {
    if (
        !value ||
        typeof value !== 'object' ||
        !Number.isSafeInteger(actorId) ||
        actorId < 1
    )
        return false;
    const receipt = value as Record<string, unknown>;
    return (
        receipt.accepted === true &&
        receipt.setting_key === INCIDENT_INDEPENDENCE_KEY &&
        receipt.request_uuid === command.request_uuid &&
        receipt.actor_id === actorId &&
        Number.isSafeInteger(command.expected_policy_revision) &&
        command.expected_policy_revision >= 0 &&
        receipt.policy_revision === command.expected_policy_revision + 1 &&
        command.mode !== '' &&
        receipt.mode === command.mode &&
        (receipt.action === 'changed' || receipt.action === 'kept') &&
        typeof receipt.policy_digest === 'string' &&
        /^[a-f0-9]{64}$/.test(receipt.policy_digest)
    );
}
