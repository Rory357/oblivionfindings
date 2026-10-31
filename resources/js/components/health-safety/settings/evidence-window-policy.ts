export const EVIDENCE_POLICY_KEY = 'healthSafety.incidents.evidence_window_policy';

export type EvidenceWindowRule = {
    identifier: string;
    trigger: string;
    site_id: number | null;
    before_seconds: number;
    after_seconds: number;
    max_before_seconds: number;
    max_after_seconds: number;
    source_types: string[];
};
export type EvidenceWindowPolicy = { schema_version: 1; enabled: boolean; rules: EvidenceWindowRule[] };
export type EvidenceWindowSnapshot = {
    setting_key: string;
    schema_version: 1;
    configuration_state: 'unconfigured' | 'invalid' | 'disabled' | 'configured';
    configuration_valid: boolean;
    policy_revision: number | null;
    policy_snapshot: EvidenceWindowPolicy | null;
    scope_complete: boolean;
};
export type EvidenceWindowOptions = {
    triggers: Array<{ value: string; label: string }>;
    source_types: Array<{ value: string; label: string }>;
    sites: Array<{ id: number; name: string; is_active: boolean }>;
};
export type EvidenceWindowHistory = {
    data: Array<{
        id: number; revision: number; recorded_at: string; reason: string;
        scope_complete: boolean;
        actor: { id: number; name: string } | null;
        after_value: EvidenceWindowPolicy | null;
    }>;
    current_page: number;
    last_page: number;
    total: number;
};
export type EvidenceWindowCommand = {
    expected_actor_id: number;
    expected_policy_revision: number;
    idempotency_key: string;
    reason: string;
    policy: EvidenceWindowPolicy;
};
export type EvidenceWindowRuleDraft = Omit<EvidenceWindowRule,
    'site_id' | 'before_seconds' | 'after_seconds' | 'max_before_seconds' | 'max_after_seconds'> & {
    site_id: string;
    before_seconds: string;
    after_seconds: string;
    max_before_seconds: string;
    max_after_seconds: string;
};
export function ruleDraft(rule: EvidenceWindowRule): EvidenceWindowRuleDraft {
    return { ...rule, source_types: [...rule.source_types], site_id: rule.site_id === null ? '' : String(rule.site_id),
        before_seconds: String(rule.before_seconds), after_seconds: String(rule.after_seconds),
        max_before_seconds: String(rule.max_before_seconds), max_after_seconds: String(rule.max_after_seconds) };
}
export function blankRule(): EvidenceWindowRuleDraft {
    return { identifier: `rule_${crypto.randomUUID()}`, trigger: '', site_id: '', before_seconds: '',
        after_seconds: '', max_before_seconds: '', max_after_seconds: '', source_types: [] };
}
function canonicalPolicy(policy: EvidenceWindowPolicy): EvidenceWindowPolicy {
    return { schema_version: 1, enabled: policy.enabled, rules: policy.rules.map((rule) => ({
        identifier: rule.identifier, trigger: rule.trigger, site_id: rule.site_id,
        before_seconds: rule.before_seconds, after_seconds: rule.after_seconds,
        max_before_seconds: rule.max_before_seconds, max_after_seconds: rule.max_after_seconds,
        source_types: [...rule.source_types].sort(),
    })).sort((a, b) => a.identifier < b.identifier ? -1 : a.identifier > b.identifier ? 1 : 0) };
}
/** Blank timings are never silently converted into operational defaults. */
export function policyFromDraft(enabled: string, drafts: EvidenceWindowRuleDraft[], options: EvidenceWindowOptions): EvidenceWindowPolicy {
    if (!['enabled', 'disabled'].includes(enabled)) throw new Error('Choose whether to enable evidence windows.');
    if (enabled === 'disabled') return { schema_version: 1, enabled: false, rules: [] };
    if (!drafts.length) throw new Error('Add at least one incident rule.');
    const pairs = new Set<string>();
    const rules = drafts.map((draft, index) => {
        const prefix = `Rule ${index + 1}: `;
        if (!options.triggers.some((item) => item.value === draft.trigger)) throw new Error(`${prefix}choose an incident type.`);
        const siteId = draft.site_id === '' ? null : Number(draft.site_id);
        if (siteId !== null && !options.sites.some((site) => site.id === siteId && site.is_active)) {
            throw new Error(`${prefix}choose an active Site or remove this override.`);
        }
        const pair = `${draft.trigger}:${siteId ?? '*'}`;
        if (pairs.has(pair)) throw new Error(`${prefix}this incident type already has a rule for the selected scope.`);
        pairs.add(pair);
        const seconds = (key: 'before_seconds' | 'after_seconds' | 'max_before_seconds' | 'max_after_seconds') => {
            if (!/^(0|[1-9][0-9]*)$/.test(draft[key]) || Number(draft[key]) > 2147483647) {
                throw new Error(`${prefix}enter each duration as a whole number of seconds, including zero where intended.`);
            }
            return Number(draft[key]);
        };
        const before = seconds('before_seconds'), after = seconds('after_seconds');
        const maximumBefore = seconds('max_before_seconds'), maximumAfter = seconds('max_after_seconds');
        if (before + after === 0 || maximumBefore < before || maximumAfter < after) {
            throw new Error(`${prefix}use a nonempty window and maximum durations at least as large as the review window.`);
        }
        if (!draft.source_types.length || draft.source_types.some((type) => !options.source_types.some((option) => option.value === type))) {
            throw new Error(`${prefix}choose at least one supported source type.`);
        }
        return { identifier: draft.identifier, trigger: draft.trigger, site_id: siteId,
            before_seconds: before, after_seconds: after, max_before_seconds: maximumBefore,
            max_after_seconds: maximumAfter, source_types: [...new Set(draft.source_types)] };
    });
    return canonicalPolicy({ schema_version: 1, enabled: true, rules });
}
/** A successful HTTP response alone does not confirm this exact retained command. */
export function confirmsEvidenceWindowReceipt(data: unknown, command: EvidenceWindowCommand, actorId: number): boolean {
    if (!data || typeof data !== 'object') return false;
    const envelope = data as Record<string, unknown>;
    if (envelope.evidence_window_viewer_id !== actorId || command.expected_actor_id !== actorId) return false;
    const receipt = envelope.receipt as Record<string, unknown> | undefined;
    if (!receipt || receipt.accepted !== true || receipt.setting_key !== EVIDENCE_POLICY_KEY ||
        receipt.schema_version !== 1 || receipt.idempotency_key !== command.idempotency_key ||
        receipt.policy_revision !== command.expected_policy_revision + 1 ||
        !Number.isSafeInteger(receipt.setting_change_id) || Number(receipt.setting_change_id) < 1 ||
        !['changed', 'restored', 'kept'].includes(String(receipt.action)) ||
        typeof receipt.policy_digest !== 'string' || !/^[a-f0-9]{64}$/.test(receipt.policy_digest) ||
        typeof receipt.recorded_at !== 'string' || !Number.isFinite(Date.parse(receipt.recorded_at))) return false;
    try {
        const accepted = receipt.policy_snapshot as EvidenceWindowPolicy;
        return accepted.schema_version === 1 && typeof accepted.enabled === 'boolean' &&
            JSON.stringify(canonicalPolicy(accepted)) === JSON.stringify(canonicalPolicy(command.policy));
    } catch { return false; }
}
