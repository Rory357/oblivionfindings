export type ApprovalMode = 'separate_specialist' | 'authorised_reviewer';
export type ApprovalSnapshot = {
    setting_key: string;
    schema_version: number | null;
    mode: ApprovalMode | null;
    policy_revision: number | null;
    policy_digest: string | null;
    policy_snapshot: { schema_version: 1; mode: ApprovalMode } | null;
    setting_change_id: number | null;
    origin:
        | 'application_default'
        | 'organisation_setting'
        | 'configuration_invalid';
    reviewed_at: string | null;
    configuration_valid: boolean;
    configuration_error: string | null;
};
export type ApprovalReceipt = {
    accepted: true;
    idempotency_key: string;
    setting_key: string;
    setting_change_id: number;
    policy_revision: number;
    schema_version: 1;
    mode: ApprovalMode;
    policy_snapshot: { schema_version: 1; mode: ApprovalMode };
    policy_digest: string;
    action: 'changed' | 'kept' | 'restored';
    origin: 'organisation_setting';
    recorded_at: string;
    replayed: boolean;
};
export type ApprovalHistory = {
    data: {
        id: number;
        revision: number;
        action: 'application_default' | 'changed' | 'kept' | 'restored';
        origin: string;
        before_value: unknown;
        after_value: unknown;
        actor: { id: number; name: string } | null;
        recorded_at: string;
        reason: string | null;
        audit_id: number | null;
    }[];
    total: number;
    current_page: number;
    last_page: number;
    links: { url: string | null; label: string; active: boolean }[];
};
export const APPROVAL_MODES = [
    {
        key: 'separate_specialist' as const,
        label: 'Separate specialist decision',
        description:
            'A designated approver records a separate specialist decision for the exact plan version. Routine plan review does not approve it.',
    },
    {
        key: 'authorised_reviewer' as const,
        label: 'Authorised reviewer decision',
        description:
            'An authorised plan reviewer or manager records an explicit approval for the exact plan version. Separate specialist permission is not required.',
    },
];
export function approvalModeLabel(mode: ApprovalMode | null): string {
    return (
        APPROVAL_MODES.find((option) => option.key === mode)?.label ??
        'Configuration needs review'
    );
}
/** Never print arbitrary malformed stored configuration into history. */
export function historicalPolicyLabel(value: unknown): string {
    if (value === null) return 'No policy value recorded';
    if (typeof value !== 'object' || Array.isArray(value))
        return 'Invalid configuration';
    const policy = value as Record<string, unknown>;
    return policy.schema_version === 1 &&
        Object.keys(policy).length === 2 &&
        APPROVAL_MODES.some((mode) => mode.key === policy.mode)
        ? approvalModeLabel(policy.mode as ApprovalMode)
        : 'Invalid configuration';
}
export function confirmsApprovalPolicyReceipt(
    value: unknown,
    input: {
        mode: ApprovalMode | '';
        expected_policy_revision: number;
        idempotency_key: string;
    },
    settingKey: string,
): value is ApprovalReceipt {
    if (!value || typeof value !== 'object') return false;
    const receipt = value as ApprovalReceipt;
    return (
        APPROVAL_MODES.some((mode) => mode.key === input.mode) &&
        Number.isSafeInteger(input.expected_policy_revision) &&
        input.expected_policy_revision >= 0 &&
        receipt.accepted === true &&
        receipt.idempotency_key === input.idempotency_key &&
        receipt.setting_key === settingKey &&
        receipt.mode === input.mode &&
        receipt.policy_revision === input.expected_policy_revision + 1 &&
        Number.isSafeInteger(receipt.setting_change_id) &&
        receipt.setting_change_id > 0 &&
        receipt.schema_version === 1 &&
        receipt.policy_snapshot?.schema_version === 1 &&
        receipt.policy_snapshot.mode === input.mode &&
        Object.keys(receipt.policy_snapshot).length === 2 &&
        typeof receipt.policy_digest === 'string' &&
        /^[0-9a-f]{64}$/i.test(receipt.policy_digest) &&
        ['changed', 'kept', 'restored'].includes(receipt.action) &&
        receipt.origin === 'organisation_setting' &&
        typeof receipt.recorded_at === 'string' &&
        /(?:Z|[+-]\d{2}:\d{2})$/.test(receipt.recorded_at) &&
        Number.isFinite(Date.parse(receipt.recorded_at)) &&
        typeof receipt.replayed === 'boolean'
    );
}
