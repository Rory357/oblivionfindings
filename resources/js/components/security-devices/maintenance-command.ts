export type MaintenanceOperation = 'create' | 'update' | 'cancel';
export type MaintenanceCommandScope = {
    site_id: number;
    device_id: number | null;
    monitor_id: number | null;
    monitor_device_id: number | null;
};
export type MaintenancePending = {
    schema_version: 1;
    actor_id: number;
    request_uuid: string;
    operation: MaintenanceOperation;
    target_window_id: number | null;
    expected_version: number | null;
    request_hash: string;
    scope: MaintenanceCommandScope;
};
export type MaintenanceCommandReply = {
    window: { id: number; version: number; state: 'active' | 'cancelled' };
    current_window: {
        id: number;
        version: number;
        state: 'active' | 'cancelled' | 'completed';
    };
    receipt: {
        schema_version: 1;
        owner: 'Monitoring';
        request_uuid: string;
        actor_id: number;
        operation: MaintenanceOperation;
        target_window_id: number | null;
        request_hash: string;
        committed_at: string;
        scope: MaintenanceCommandScope;
    };
};
const positive = (v: unknown): v is number =>
    typeof v === 'number' && Number.isSafeInteger(v) && v > 0;
export const maintenanceUuid = (v: unknown): v is string =>
    typeof v === 'string' &&
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(
        v,
    );
const digest = (v: unknown): v is string =>
    typeof v === 'string' && /^[0-9a-f]{64}$/.test(v);
function requireValue(v: unknown): asserts v {
    if (!v)
        throw Error(
            'The maintenance save could not be verified. Check its original request.',
        );
}
function record(v: unknown): Record<string, unknown> {
    requireValue(v && typeof v === 'object' && !Array.isArray(v));
    return v as Record<string, unknown>;
}
function exact(v: Record<string, unknown>, keys: string[]) {
    requireValue(
        Object.keys(v).length === keys.length &&
            keys.every((k) => Object.hasOwn(v, k)),
    );
}
function scope(v: unknown): MaintenanceCommandScope {
    const s = record(v);
    exact(s, ['site_id', 'device_id', 'monitor_id', 'monitor_device_id']);
    requireValue(
        positive(s.site_id) &&
            [s.device_id, s.monitor_id, s.monitor_device_id].every(
                (v) => v === null || positive(v),
            ) &&
            !(s.monitor_id !== null && s.device_id !== null) &&
            (s.monitor_id === null
                ? s.monitor_device_id === null
                : positive(s.monitor_device_id)),
    );
    return s as MaintenanceCommandScope;
}
export function maintenancePending(v: unknown): MaintenancePending {
    const p = record(v);
    exact(p, [
        'schema_version',
        'actor_id',
        'request_uuid',
        'operation',
        'target_window_id',
        'expected_version',
        'request_hash',
        'scope',
    ]);
    requireValue(
        p.schema_version === 1 &&
            positive(p.actor_id) &&
            maintenanceUuid(p.request_uuid) &&
            digest(p.request_hash) &&
            ['create', 'update', 'cancel'].includes(String(p.operation)),
    );
    requireValue(
        p.operation === 'create'
            ? p.target_window_id === null && p.expected_version === null
            : positive(p.target_window_id) && positive(p.expected_version),
    );
    scope(p.scope);
    return p as unknown as MaintenancePending;
}
export function maintenanceCommandReply(
    value: unknown,
    pending: MaintenancePending,
): MaintenanceCommandReply {
    const p = maintenancePending(pending),
        root = record(value);
    exact(root, ['window', 'current_window', 'receipt']);
    const saved = record(root.window),
        current = record(root.current_window),
        r = record(root.receipt);
    exact(saved, ['id', 'version', 'state']);
    exact(current, ['id', 'version', 'state']);
    exact(r, [
        'schema_version',
        'owner',
        'request_uuid',
        'actor_id',
        'operation',
        'target_window_id',
        'request_hash',
        'committed_at',
        'scope',
    ]);
    requireValue(
        r.schema_version === 1 &&
            r.owner === 'Monitoring' &&
            r.request_uuid === p.request_uuid &&
            r.actor_id === p.actor_id &&
            r.operation === p.operation &&
            r.target_window_id === p.target_window_id &&
            r.request_hash === p.request_hash,
    );
    const captured = scope(r.scope);
    requireValue(
        Object.keys(captured).every(
            (k) =>
                captured[k as keyof MaintenanceCommandScope] ===
                p.scope[k as keyof MaintenanceCommandScope],
        ),
    );
    requireValue(
        positive(saved.id) &&
            saved.version ===
                (p.operation === 'create' ? 1 : p.expected_version! + 1) &&
            saved.state ===
                (p.operation === 'cancel' ? 'cancelled' : 'active') &&
            (p.target_window_id === null || saved.id === p.target_window_id),
    );
    requireValue(
        current.id === saved.id &&
            positive(current.version) &&
            current.version >= Number(saved.version) &&
            ['active', 'cancelled', 'completed'].includes(
                String(current.state),
            ),
    );
    requireValue(
        typeof r.committed_at === 'string' &&
            /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/.test(r.committed_at) &&
            Number.isFinite(Date.parse(r.committed_at)) &&
            new Date(r.committed_at).toISOString().slice(0, 19) ===
                r.committed_at.slice(0, 19),
    );
    return root as unknown as MaintenanceCommandReply;
}
const fields = [
    'expected_actor_id',
    'request_uuid',
    'version',
    'reason',
    'site_id',
    'monitor_id',
    'device_id',
    'name',
    'starts_at',
    'ends_at',
    'recurrence',
    'recurrence_until',
    'timezone',
    'dst_overlap',
    'dst_gap',
];
// Keep this set aligned with the installed Laravel TrimStrings/Str::trim.
// The middleware-path interoperability check exercises Unicode and empty values.
const middlewareInvisible = new Set([
    0x0, 0x9, 0x20, 0xa0, 0xad, 0x34f, 0x61c, 0x115f, 0x1160, 0x17b4, 0x17b5,
    0x180e, 0x2000, 0x2001, 0x2002, 0x2003, 0x2004, 0x2005, 0x2006, 0x2007,
    0x2008, 0x2009, 0x200a, 0x200b, 0x200c, 0x200d, 0x200e, 0x200f, 0x202f,
    0x205f, 0x2060, 0x2061, 0x2062, 0x2063, 0x2064, 0x2065, 0x206a, 0x206b,
    0x206c, 0x206d, 0x206e, 0x206f, 0x3000, 0x2800, 0x3164, 0xfeff, 0xffa0,
    0x1d159, 0x1d173, 0x1d174, 0x1d175, 0x1d176, 0x1d177, 0x1d178, 0x1d179,
    0x1d17a, 0xe0020,
]);
const middlewareWhitespace = /\p{White_Space}/u;
function trimMiddlewareBoundary(value: string): string {
    const characters = Array.from(value);
    const boundary = (character: string) => {
        const point = character.codePointAt(0);
        return (
            middlewareWhitespace.test(character) ||
            (point !== undefined && middlewareInvisible.has(point))
        );
    };
    let first = 0,
        last = characters.length;
    while (first < last && boundary(characters[first])) first++;
    while (last > first && boundary(characters[last - 1])) last--;
    return characters.slice(first, last).join('');
}
export function canonicalMaintenanceInput(
    operation: MaintenanceOperation,
    body: Record<string, unknown>,
): Record<string, unknown> {
    const input: Record<string, unknown> = {};
    const allowed = operation === 'cancel' ? fields.slice(0, 4) : fields;
    requireValue(Object.keys(body).every((key) => allowed.includes(key)));
    for (const key of Object.keys(body).sort()) {
        let value = body[key];
        if (value === undefined) continue;
        if (typeof value === 'string') {
            value = trimMiddlewareBoundary(value);
            if (value === '') value = null;
        }
        if (
            [
                'expected_actor_id',
                'version',
                'site_id',
                'device_id',
                'monitor_id',
            ].includes(key) &&
            value !== null
        ) {
            const id = Number(value);
            requireValue(positive(id));
            value = id;
        }
        if (key === 'request_uuid' && typeof value === 'string')
            value = value.toLowerCase();
        input[key] = value;
    }
    return input;
}
/** Matches the canonical owner intent; the fingerprint grants no authority. */
export async function maintenanceCommandHash(
    operation: MaintenanceOperation,
    target: number | null,
    body: Record<string, unknown>,
): Promise<string> {
    const input = canonicalMaintenanceInput(operation, body);
    requireValue(crypto.subtle && maintenanceUuid(input.request_uuid));
    const json = JSON.stringify({ input, operation, target_window_id: target })
        .replaceAll('\u2028', '\\u2028')
        .replaceAll('\u2029', '\\u2029');
    const bytes = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(json),
    );
    return Array.from(new Uint8Array(bytes), (value) =>
        value.toString(16).padStart(2, '0'),
    ).join('');
}
export const maintenanceMarkerKey = 'monitoring.maintenance.pending.v1';
export function loadMaintenancePending(actor: number | undefined): {
    pending: MaintenancePending | null;
    corrupt: boolean;
} {
    const raw = sessionStorage.getItem(maintenanceMarkerKey);
    if (raw === null) return { pending: null, corrupt: false };
    try {
        requireValue(raw.length <= 2048);
        const p = maintenancePending(JSON.parse(raw));
        if (p.actor_id !== actor) {
            sessionStorage.removeItem(maintenanceMarkerKey);
            return { pending: null, corrupt: false };
        }
        return { pending: p, corrupt: false };
    } catch {
        return { pending: null, corrupt: true };
    }
}
export function keepMaintenancePending(pending: MaintenancePending) {
    maintenancePending(pending);
    const current = loadMaintenancePending(pending.actor_id);
    requireValue(
        !current.corrupt &&
            (!current.pending ||
                current.pending.request_uuid === pending.request_uuid),
    );
    sessionStorage.setItem(maintenanceMarkerKey, JSON.stringify(pending));
    requireValue(
        sessionStorage.getItem(maintenanceMarkerKey) ===
            JSON.stringify(pending),
    );
}
export function forgetMaintenancePending(uuid: string) {
    const raw = sessionStorage.getItem(maintenanceMarkerKey);
    if (raw === null) return;
    const p = maintenancePending(JSON.parse(raw));
    if (p.request_uuid === uuid)
        sessionStorage.removeItem(maintenanceMarkerKey);
}
