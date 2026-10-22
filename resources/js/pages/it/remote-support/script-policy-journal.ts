import {
    scriptPolicyReference,
    type ScriptPolicyReference,
} from './script-policy-contract';

export const SCRIPT_POLICY_REFERENCE_LIMIT = 8;
const prefix = 'obli.script-policy.references.v1:';
function key(actor: number, device: number): string {
    if (
        !Number.isSafeInteger(actor) ||
        actor < 1 ||
        !Number.isSafeInteger(device) ||
        device < 1
    )
        throw Error('Invalid recovery scope.');
    return `${prefix}${actor}:${device}`;
}
export function scriptPolicyReferences(
    actor: number,
    device: number,
): ScriptPolicyReference[] {
    const raw = sessionStorage.getItem(key(actor, device));
    if (raw === null) return [];
    if (raw.length > 4096) throw Error('Invalid recovery information.');
    const values: unknown = JSON.parse(raw);
    if (!Array.isArray(values) || values.length > SCRIPT_POLICY_REFERENCE_LIMIT)
        throw Error('Invalid recovery information.');
    const refs = values.map((value) =>
        scriptPolicyReference(value, actor, device),
    );
    if (new Set(refs.map((ref) => ref.request_key)).size !== refs.length)
        throw Error('Conflicting recovery information.');
    return refs;
}
function store(
    actor: number,
    device: number,
    refs: ScriptPolicyReference[],
): void {
    const name = key(actor, device);
    if (refs.length === 0) {
        sessionStorage.removeItem(name);
        if (sessionStorage.getItem(name) !== null)
            throw Error('Recovery information could not be cleared.');
    } else {
        const raw = JSON.stringify(refs);
        sessionStorage.setItem(name, raw);
        if (sessionStorage.getItem(name) !== raw)
            throw Error('Recovery information could not be retained.');
    }
}
export function retainScriptPolicyReference(
    reference: ScriptPolicyReference,
): ScriptPolicyReference[] {
    const ref = scriptPolicyReference(
        reference,
        reference.actor_id,
        reference.device_id,
    );
    const refs = scriptPolicyReferences(ref.actor_id, ref.device_id);
    const same = refs.find((row) => row.request_key === ref.request_key);
    if (same && same.intent_sha256 !== ref.intent_sha256)
        throw Error('Recovery reference changed.');
    if (!same) {
        if (refs.length >= SCRIPT_POLICY_REFERENCE_LIMIT)
            throw Error('Check earlier saves before adding another change.');
        refs.unshift(ref);
    }
    store(ref.actor_id, ref.device_id, refs);
    return refs;
}
export function releaseScriptPolicyReference(
    reference: ScriptPolicyReference,
): ScriptPolicyReference[] {
    const ref = scriptPolicyReference(
        reference,
        reference.actor_id,
        reference.device_id,
    );
    const refs = scriptPolicyReferences(ref.actor_id, ref.device_id);
    const same = refs.find((row) => row.request_key === ref.request_key);
    if (same && same.intent_sha256 !== ref.intent_sha256)
        throw Error('Recovery reference changed.');
    const remaining = refs.filter((row) => row.request_key !== ref.request_key);
    store(ref.actor_id, ref.device_id, remaining);
    return remaining;
}

/** Explicit local cleanup only. Never treats a missing reminder as cancellation. */
export function discardUnreadableScriptPolicyReferences(
    actor: number,
    device: number,
): void {
    let unreadable = false;
    try {
        scriptPolicyReferences(actor, device);
    } catch {
        unreadable = true;
    }
    if (!unreadable)
        throw Error('Recovery information changed. Check it again.');
    store(actor, device, []);
}
