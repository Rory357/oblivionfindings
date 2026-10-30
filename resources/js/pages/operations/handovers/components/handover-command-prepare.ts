import {
    currentHandoverCommandSource,
    validHandoverCommandIdentity,
    type HandoverCommandIdentity,
    type HandoverCommandSource,
} from './handover-command-result';

export type HandoverCommandFields = Readonly<Record<string, string | number | boolean | null>>;
export type PreparedHandoverCommand = Readonly<{
    identity: HandoverCommandIdentity;
    fields: HandoverCommandFields;
    expectedStatus: 'draft' | 'submitted';
    intentJson: string;
}>;

const textFields = new Set(['client_mood', 'medications_due_text', 'incidents_to_note_text',
    'follow_up_items_text', 'tasks_pending_text', 'cd_result', 'cd_notes']);
const idFields = new Set(['incoming_shift_id', 'incoming_staff_id', 'cd_witness_id']);
const wellFormed = (value: string) =>
    !/[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?:^|[^\uD800-\uDBFF])[\uDC00-\uDFFF]/.test(value);

/** Only canonical wizard wire fields. Witness credentials are sent once by
 * the transport, separately; they are never copied or hashed here. */
function retainedFields(
    raw: unknown, action: HandoverCommandIdentity['action'],
): HandoverCommandFields | null {
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return null;
    const fields = raw as Record<string, unknown>;
    const keys = Object.keys(fields).sort();
    if (action === 'submit') return keys.length === 0 ? Object.freeze({}) : null;
    if (!keys.includes('handover_notes') || !keys.includes('submit')) return null;
    const copy: Record<string, string | number | boolean | null> = {};
    for (const key of keys) {
        const value = fields[key];
        if (key === 'handover_notes') {
            if (typeof value !== 'string' || !wellFormed(value)) return null;
            copy[key] = value;
        } else if (key === 'submit') {
            if (typeof value !== 'boolean') return null;
            copy[key] = value;
        } else if (textFields.has(key)) {
            if (value !== null && (typeof value !== 'string' || !wellFormed(value))) return null;
            copy[key] = value as string | null;
        } else if (idFields.has(key)) {
            if (value !== null && (typeof value !== 'number' || !Number.isSafeInteger(value) || value <= 0)) return null;
            copy[key] = value as number | null;
        } else return null;
    }
    return Object.freeze(copy);
}

/** The owner must provide a permanent committed-context lease, rather than
 * equality that can become true after account or permission restoration. */
export async function prepareHandoverCommand(
    lease: Readonly<{ source: HandoverCommandSource; isCurrent: () => boolean }>,
    action: HandoverCommandIdentity['action'],
    requestId: string,
    rawFields: unknown,
    subtle: Pick<SubtleCrypto, 'digest'> | undefined = globalThis.crypto?.subtle,
): Promise<PreparedHandoverCommand | null> {
    if (!lease.isCurrent() || !subtle) return null;
    const source = currentHandoverCommandSource(lease.source, lease.source.actor_id, lease.source.shift_id);
    if (!source || !source.can[action] ||
        (action === 'create' ? source.handover_id !== null : source.handover_id === null)) return null;
    const fields = retainedFields(rawFields, action);
    if (!fields || !lease.isCurrent()) return null;
    const identity: HandoverCommandIdentity = Object.freeze({
        version: 1,
        actor_id: source.actor_id,
        request_id: requestId,
        action,
        shift_id: source.shift_id,
        requested_handover_id: source.handover_id,
        expected_version: source.expected_version,
        expected_source: source.expected_source,
        intent_hash: '0'.repeat(64),
    });
    if (!validHandoverCommandIdentity(identity)) return null;
    // These are the recursively sorted exact keys of the v1 intent object.
    // Fields were copied in lexical order; no clinical text is normalised.
    const intentJson = JSON.stringify({
        action,
        expected_source: identity.expected_source,
        expected_version: identity.expected_version,
        fields,
        handover_id: identity.requested_handover_id,
        shift_id: identity.shift_id,
    });
    let digest: ArrayBuffer;
    try {
        digest = await subtle.digest('SHA-256', new TextEncoder().encode(intentJson));
    } catch {
        return null;
    }
    if (!lease.isCurrent() || digest.byteLength !== 32) return null;
    const intentHash = Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
    return Object.freeze({
        identity: Object.freeze({ ...identity, intent_hash: intentHash }),
        fields,
        expectedStatus: action === 'submit' || fields.submit === true ? 'submitted' : 'draft',
        intentJson,
    });
}
