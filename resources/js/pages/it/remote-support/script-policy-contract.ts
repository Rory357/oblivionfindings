import { safeSoftwareText } from '@/pages/security-devices/software/_contracts';

export type ScriptPolicy = {
    version: number;
    enabled: boolean;
    consent_mode: 'attended' | 'unattended';
};
export type ScriptPolicyAttempt = {
    expected_actor_id: number;
    expected_version: number;
    request_key: string;
    enabled: boolean;
    consent_mode: ScriptPolicy['consent_mode'];
    reason: string;
};
export type ScriptPolicyReceipt = {
    actor_id: number;
    device_id: number;
    request_key: string;
    assignment_fingerprint: string;
    policy: ScriptPolicy;
    reason: string;
    recorded_at: string;
};
export type ScriptPolicyReply = {
    actor_id: number;
    device_id: number;
    policy: ScriptPolicy;
    assignment_matches: boolean;
    available: false;
    unavailable_reason: 'script_execution_not_implemented';
    can_manage: boolean;
    can_run: boolean;
    can_unattended: boolean;
    identity_confirmed: boolean;
    receipt?: ScriptPolicyReceipt;
};
const uuid =
    /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
function check(value: unknown): asserts value {
    if (!value) throw Error('Script policy response could not be verified.');
}
function object(value: unknown, fields: string[]): Record<string, unknown> {
    check(value && typeof value === 'object' && !Array.isArray(value));
    check(Object.keys(value).sort().join('|') === fields.sort().join('|'));
    return value as Record<string, unknown>;
}
function policy(value: unknown): ScriptPolicy {
    const p = object(value, ['version', 'enabled', 'consent_mode']);
    check(
        Number.isSafeInteger(p.version) &&
            Number(p.version) >= 0 &&
            Number(p.version) <= 4294967295 &&
            typeof p.enabled === 'boolean' &&
            typeof p.consent_mode === 'string' &&
            ['attended', 'unattended'].includes(p.consent_mode),
    );
    check(p.version !== 0 || (!p.enabled && p.consent_mode === 'attended'));
    return p as ScriptPolicy;
}
export const scriptPolicyReason = (value: string) =>
    safeSoftwareText(value, 500, 10) &&
    Array.from(value.trim()).length >= 10 &&
    !/[\p{Cs}\uFFFD]/u.test(value);

export type ScriptPolicyReference = {
    version: 1;
    actor_id: number;
    device_id: number;
    request_key: string;
    intent_sha256: string;
};

export function scriptPolicyReference(
    value: unknown,
    actor: number,
    device: number,
): ScriptPolicyReference {
    const ref = object(value, [
        'version',
        'actor_id',
        'device_id',
        'request_key',
        'intent_sha256',
    ]);
    check(
        ref.version === 1 && ref.actor_id === actor && ref.device_id === device,
    );
    check(
        Number.isSafeInteger(actor) &&
            actor > 0 &&
            Number.isSafeInteger(device) &&
            device > 0,
    );
    check(
        typeof ref.request_key === 'string' &&
            uuid.test(ref.request_key) &&
            typeof ref.intent_sha256 === 'string' &&
            /^[0-9a-f]{64}$/.test(ref.intent_sha256),
    );
    return ref as ScriptPolicyReference;
}

/** Browser intent proof only; this is not a command signature or execution authority. */
export async function scriptPolicyReferenceFor(
    attempt: ScriptPolicyAttempt,
    device: number,
): Promise<ScriptPolicyReference> {
    object(attempt, [
        'expected_actor_id',
        'expected_version',
        'request_key',
        'enabled',
        'consent_mode',
        'reason',
    ]);
    check(
        Number.isSafeInteger(attempt.expected_version) &&
            attempt.expected_version >= 0 &&
            attempt.expected_version < 4294967295,
    );
    check(
        typeof attempt.reason === 'string' &&
            scriptPolicyReason(attempt.reason) &&
            typeof attempt.enabled === 'boolean',
    );
    policy({
        version: attempt.expected_version + 1,
        enabled: attempt.enabled,
        consent_mode: attempt.consent_mode,
    });
    const bytes = new TextEncoder().encode(
        JSON.stringify([
            'script-policy-intent-v1',
            attempt.expected_actor_id,
            device,
            attempt.expected_version,
            attempt.request_key,
            attempt.enabled,
            attempt.consent_mode,
            attempt.reason,
        ]),
    );
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    return scriptPolicyReference(
        {
            version: 1,
            actor_id: attempt.expected_actor_id,
            device_id: device,
            request_key: attempt.request_key,
            intent_sha256: Array.from(new Uint8Array(digest), (n) =>
                n.toString(16).padStart(2, '0'),
            ).join(''),
        },
        attempt.expected_actor_id,
        device,
    );
}

export async function scriptPolicyRecoveredReply(
    value: unknown,
    actor: number,
    device: number,
    reference: ScriptPolicyReference,
): Promise<ScriptPolicyReply> {
    const ref = scriptPolicyReference(reference, actor, device);
    check(value && typeof value === 'object' && !Array.isArray(value));
    const receipt = (value as { receipt?: unknown }).receipt;
    const r = object(receipt, [
        'actor_id',
        'device_id',
        'request_key',
        'assignment_fingerprint',
        'policy',
        'reason',
        'recorded_at',
    ]);
    const saved = policy(r.policy);
    check(
        r.actor_id === actor &&
            r.device_id === device &&
            r.request_key === ref.request_key &&
            typeof r.reason === 'string',
    );
    const attempt: ScriptPolicyAttempt = {
        expected_actor_id: actor,
        expected_version: saved.version - 1,
        request_key: ref.request_key,
        enabled: saved.enabled,
        consent_mode: saved.consent_mode,
        reason: r.reason,
    };
    const dto = scriptPolicyReply(value, actor, device, attempt);
    const reproduced = await scriptPolicyReferenceFor(attempt, device);
    check(reproduced.intent_sha256 === ref.intent_sha256);
    return dto;
}

export function scriptPolicyReply(
    value: unknown,
    actor: number,
    device: number,
    attempt?: ScriptPolicyAttempt,
): ScriptPolicyReply {
    const fields = [
        'actor_id',
        'device_id',
        'policy',
        'assignment_matches',
        'available',
        'unavailable_reason',
        'can_manage',
        'can_run',
        'can_unattended',
        'identity_confirmed',
    ];
    const raw = object(value, attempt ? [...fields, 'receipt'] : fields);
    check(
        raw.actor_id === actor &&
            raw.device_id === device &&
            raw.available === false &&
            raw.unavailable_reason === 'script_execution_not_implemented',
    );
    for (const field of [
        'assignment_matches',
        'can_manage',
        'can_run',
        'can_unattended',
        'identity_confirmed',
    ])
        check(typeof raw[field] === 'boolean');
    check(raw.can_manage || raw.can_run);
    const current = policy(raw.policy);
    check(
        raw.assignment_matches ||
            (!current.enabled && current.consent_mode === 'attended'),
    );
    if (attempt) {
        check(
            attempt.expected_actor_id === actor &&
                Number.isSafeInteger(attempt.expected_version) &&
                attempt.expected_version >= 0 &&
                raw.can_manage,
        );
        const receipt = object(raw.receipt, [
            'actor_id',
            'device_id',
            'request_key',
            'assignment_fingerprint',
            'policy',
            'reason',
            'recorded_at',
        ]);
        const saved = policy(receipt.policy);
        check(
            receipt.actor_id === actor &&
                receipt.device_id === device &&
                receipt.request_key === attempt.request_key &&
                uuid.test(attempt.request_key),
        );
        check(
            typeof receipt.assignment_fingerprint === 'string' &&
                /^[0-9a-f]{64}$/.test(receipt.assignment_fingerprint),
        );
        check(
            saved.version === attempt.expected_version + 1 &&
                saved.enabled === attempt.enabled &&
                saved.consent_mode === attempt.consent_mode &&
                receipt.reason === attempt.reason,
        );
        check(
            typeof receipt.recorded_at === 'string' &&
                /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/.test(receipt.recorded_at) &&
                Number.isFinite(Date.parse(receipt.recorded_at)),
        );
        check(
            new Date(receipt.recorded_at).toISOString() ===
                receipt.recorded_at.replace('Z', '.000Z'),
        );
        check(current.version >= saved.version);
        if (raw.assignment_matches && current.version === saved.version)
            check(
                current.enabled === saved.enabled &&
                    current.consent_mode === saved.consent_mode,
            );
    }
    return raw as ScriptPolicyReply;
}
