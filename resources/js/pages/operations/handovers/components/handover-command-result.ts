/** Inactive Operations/Attendance contract. No transport, storage or eMAR imports.
 * Exact receipts are historical command evidence, never current permission. */
export type HandoverCommandIdentity = Readonly<{
    version: 1;
    actor_id: number;
    request_id: string;
    action: 'create' | 'update' | 'submit';
    shift_id: number;
    requested_handover_id: number | null;
    expected_version: number | null;
    expected_source: string;
    intent_hash: string;
}>;

export type HandoverCommandSaved = Readonly<{
    handover_id: number;
    shift_id: number;
    version: number;
    status: 'draft' | 'submitted';
    saved_at: string;
}>;

export type HandoverCommandSource = Readonly<{
    version: 1;
    scope: 'operations_handover_command_source';
    actor_id: number;
    shift_id: number;
    handover_id: number | null;
    expected_version: number | null;
    status: 'draft' | 'submitted' | 'acknowledged' | null;
    expected_source: string;
    can: Readonly<{ create: boolean; update: boolean; submit: boolean }>;
}>;

const object = (value: unknown): Record<string, unknown> | null =>
    value !== null && typeof value === 'object' && !Array.isArray(value)
        ? value as Record<string, unknown> : null;
const exactKeys = (data: Record<string, unknown>, keys: readonly string[]) =>
    Object.keys(data).length === keys.length && keys.every((key) => Object.hasOwn(data, key));
const positive = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
const digest = (value: unknown): value is string =>
    typeof value === 'string' && value.length === 64 && /^[a-f0-9]{64}$/.test(value);
const uuid = (value: unknown): value is string => typeof value === 'string' && value.length === 36 &&
    /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(value);
const utc = (value: unknown): value is string => typeof value === 'string' && value.length === 20 &&
    /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(value) &&
    Number.isFinite(Date.parse(value)) && new Date(value).toISOString() === value.replace('Z', '.000Z');
const identityKeys = ['version', 'actor_id', 'request_id', 'action', 'shift_id',
    'requested_handover_id', 'expected_version', 'expected_source', 'intent_hash'] as const;

export function validHandoverCommandIdentity(value: unknown): value is HandoverCommandIdentity {
    const data = object(value);
    if (!data || !exactKeys(data, identityKeys) || data.version !== 1 || !positive(data.actor_id) ||
        !positive(data.shift_id) || !uuid(data.request_id) || !digest(data.expected_source) ||
        !digest(data.intent_hash)) return false;
    if (data.action === 'create') return data.requested_handover_id === null && data.expected_version === null;
    return (data.action === 'update' || data.action === 'submit') &&
        positive(data.requested_handover_id) && positive(data.expected_version);
}

function matchesIdentity(value: unknown, original: HandoverCommandIdentity): boolean {
    if (!validHandoverCommandIdentity(original) || !validHandoverCommandIdentity(value)) return false;
    return identityKeys.every((key) => value[key] === original[key]);
}

/** Only an explicit, identity-bound pre-writer refusal permits editing again.
 * A generic validation/status/redirect response never proves no write. */
export function refusedHandoverCommand(value: unknown, original: HandoverCommandIdentity,
    httpStatus: number): Readonly<Record<string, string>> | null {
    const result = object(value);
    if (![409, 422].includes(httpStatus) || !result ||
        !exactKeys(result, ['version', 'scope', 'identity', 'saved', 'outcome', 'errors']) ||
        result.version !== 1 || result.scope !== 'operations_handover_command' ||
        result.outcome !== 'refused' || result.saved !== null || !matchesIdentity(result.identity, original)) return null;
    const errors = object(result.errors);
    const allowed = new Set(['handover', 'handover_notes', 'client_mood', 'incoming_shift_id', 'incoming_staff_id',
        'medications_due_text', 'incidents_to_note_text', 'follow_up_items_text', 'tasks_pending_text',
        'cd_result', 'cd_witness_id', 'cd_witness_credential', 'cd_notes']);
    if (!errors || Object.keys(errors).length === 0 || Object.keys(errors).length > allowed.size) return null;
    const retained: Record<string, string> = {};
    for (const [field, message] of Object.entries(errors)) {
        if (!allowed.has(field) || typeof message !== 'string' || message.length === 0 || message.length > 300) return null;
        retained[field] = message;
    }
    return Object.freeze(retained);
}

/** Call only with the original immutable identity and a currently admitted
 * owner lease. A caller must recheck that lease after this returns. */
export function confirmedHandoverCommand(
    value: unknown,
    original: HandoverCommandIdentity,
    expectedStatus: 'draft' | 'submitted',
    httpStatus: number,
): HandoverCommandSaved | null {
    const result = object(value);
    if (httpStatus !== 200 || !result ||
        !exactKeys(result, ['version', 'scope', 'identity', 'saved', 'outcome']) ||
        result.version !== 1 || result.scope !== 'operations_handover_command' ||
        result.outcome !== 'confirmed' || !matchesIdentity(result.identity, original) ||
        (expectedStatus !== 'draft' && expectedStatus !== 'submitted') ||
        (original.action === 'submit' && expectedStatus !== 'submitted')) return null;
    const saved = object(result.saved);
    if (!saved || !exactKeys(saved, ['handover_id', 'shift_id', 'version', 'status', 'saved_at']) ||
        !positive(saved.handover_id) || !positive(saved.version) || saved.shift_id !== original.shift_id ||
        saved.status !== expectedStatus || !utc(saved.saved_at) ||
        (original.requested_handover_id !== null && saved.handover_id !== original.requested_handover_id) ||
        (original.expected_version !== null && saved.version <= original.expected_version)) return null;
    return Object.freeze({
        handover_id: saved.handover_id,
        shift_id: original.shift_id,
        version: saved.version,
        status: expectedStatus,
        saved_at: saved.saved_at,
    });
}

/** A current descriptor is a new read, not proof of an earlier save. */
export function currentHandoverCommandSource(
    value: unknown, actorId: number, shiftId: number,
): HandoverCommandSource | null {
    const source = object(value);
    if (!positive(actorId) || !positive(shiftId) || !source ||
        !exactKeys(source, ['version', 'scope', 'actor_id', 'shift_id', 'handover_id',
            'expected_version', 'status', 'expected_source', 'can']) ||
        source.version !== 1 || source.scope !== 'operations_handover_command_source' ||
        source.actor_id !== actorId || source.shift_id !== shiftId || !digest(source.expected_source)) return null;
    const can = object(source.can);
    if (!can || !exactKeys(can, ['create', 'update', 'submit']) ||
        typeof can.create !== 'boolean' || typeof can.update !== 'boolean' || typeof can.submit !== 'boolean') return null;
    if (source.handover_id === null) {
        if (source.expected_version !== null || source.status !== null) return null;
    } else if (!positive(source.handover_id) || !positive(source.expected_version) ||
        !['draft', 'submitted', 'acknowledged'].includes(source.status as string)) return null;
    return Object.freeze({
        version: 1,
        scope: 'operations_handover_command_source',
        actor_id: actorId,
        shift_id: shiftId,
        handover_id: source.handover_id as number | null,
        expected_version: source.expected_version as number | null,
        status: source.status as HandoverCommandSource['status'],
        expected_source: source.expected_source,
        can: Object.freeze({ create: can.create, update: can.update, submit: can.submit }),
    });
}

/** Original lookup can settle historical command evidence while returning
 * separately parsed current facts. It never sends or prepares another save. */
export function recoveredHandoverCommand(
    value: unknown,
    original: HandoverCommandIdentity,
    expectedStatus: 'draft' | 'submitted',
    httpStatus: number,
): Readonly<{ saved: HandoverCommandSaved | null; current: HandoverCommandSource }> | null {
    const lookup = object(value);
    if (httpStatus !== 200 || !lookup ||
        !exactKeys(lookup, ['version', 'scope', 'identity', 'result', 'current']) ||
        lookup.version !== 1 || lookup.scope !== 'operations_handover_attempt' ||
        !matchesIdentity(lookup.identity, original)) return null;
    const current = currentHandoverCommandSource(lookup.current, original.actor_id, original.shift_id);
    if (!current) return null;
    const saved = lookup.result === null ? null : confirmedHandoverCommand(lookup.result, original, expectedStatus, 200);
    if (lookup.result !== null && !saved) return null;
    return Object.freeze({ saved, current });
}
