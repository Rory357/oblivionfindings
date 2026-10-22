export const scriptBase = '/security-devices/scripts';
export const scriptStatuses = [
    'uploaded',
    'pending_review',
    'published',
    'withdrawn',
] as const;
export type ScriptStatus = (typeof scriptStatuses)[number];
export const scriptStatusLabel: Record<ScriptStatus, string> = {
    uploaded: 'Draft',
    pending_review: 'Awaiting review',
    published: 'Approved',
    withdrawn: 'Withdrawn',
};
export type ScriptAction = 'submit' | 'review' | 'withdraw';
export type ScriptParameter = {
    name: string;
    required: boolean;
} & (
    | { type: 'boolean'; constraints: Record<string, never> }
    | { type: 'integer'; constraints: { minimum: number; maximum: number } }
    | {
          type: 'string';
          constraints: { min_length: number; max_length: number };
      }
    | { type: 'enum'; constraints: { values: string[] } }
);
export type ScriptManifest = {
    script_uuid: string;
    script_version: 1;
    name: string;
    publisher_label: string;
    sha256: string;
    size_bytes: number;
    interpreter: 'powershell7';
    interpreter_version: string;
    runtime_manifest_sha256: string;
    wrapper_sha256: string;
    architecture: 'amd64' | 'arm64';
    parameter_schema: ScriptParameter[];
    execution_policy: {
        runtime_seconds: number;
        output_bytes: number;
        output_retention_hours: number;
        network_mode: 'reviewed_network';
        timeout_mode: 'report_only' | 'terminate_owned_job';
        success_exit_codes: number[];
        expected_observation_schema: string | null;
    };
};
export type ScriptReview = {
    effect: 'read_only' | 'mutating';
    child_processes: string[];
    network_destinations: string[];
    output_sensitivity: 'none' | 'operational' | 'sensitive';
    compensation: string;
    restart_behavior: 'none' | 'may_request_restart';
    no_detached_work: true;
    no_dynamic_code: true;
};
export type ScriptRecord = {
    uuid: string;
    script_version: 1;
    status: ScriptStatus;
    status_version: number;
    content_hash: string;
    manifest: ScriptManifest;
    review: ScriptReview;
    uploaded_by_user_id: number;
    reviewed_by_user_id: number | null;
    can_submit: boolean;
    can_review: boolean;
    can_withdraw: boolean;
    can_read_material: boolean;
    publication_issue: 'runtime_unavailable' | 'observation_unavailable' | null;
    submitted_at: string | null;
    published_at: string | null;
    withdrawn_at: string | null;
};
export type ScriptReceipt = {
    request_key: string;
    event: 'upload' | ScriptAction;
    status: ScriptStatus;
    status_version: number;
    content_hash: string;
};
export type ScriptIndex = {
    actor_id: number;
    can_upload: boolean;
    identity_confirmed: boolean;
    identity_confirm_href: string;
    execution_available: false;
    summary: Record<ScriptStatus, number>;
    query: { status: ScriptStatus | 'all'; search: string };
    pagination: {
        limit: 20;
        shown: number;
        previous_href: string | null;
        next_href: string | null;
        reset_href: string;
        position_reset: boolean;
    };
    scripts: ScriptRecord[];
};
export type ScriptMaterial = {
    actor_id: number;
    content_hash: string;
    manifest: ScriptManifest;
    review: ScriptReview;
    artifact: string;
    runtime_manifest: string;
};
const uuid =
    /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const digest = /^[0-9a-f]{64}$/;
const badUnicode = /[\uD800-\uDFFF]/u;
function requireValue(value: unknown): asserts value {
    if (!value)
        throw Error(
            'Script information could not be verified. Refresh the catalogue.',
        );
}
function object(value: unknown, keys: string[]): Record<string, unknown> {
    requireValue(
        value !== null && typeof value === 'object' && !Array.isArray(value),
    );
    requireValue(
        Object.keys(value).sort().join('\0') === [...keys].sort().join('\0'),
    );
    return value as Record<string, unknown>;
}
const integer = (
    x: unknown,
    min = 0,
    max = Number.MAX_SAFE_INTEGER,
): x is number =>
    typeof x === 'number' && Number.isSafeInteger(x) && x >= min && x <= max;
export const scriptText = (x: unknown, max: number, min = 0): x is string =>
    typeof x === 'string' &&
    Array.from(x).length >= min &&
    Array.from(x).length <= max &&
    !/[\p{Cc}\p{Cf}\uFFFD]/u.test(x) &&
    !badUnicode.test(x);
const hash = (x: unknown): x is string =>
    typeof x === 'string' && digest.test(x);
const identifier = (x: unknown): x is string =>
    typeof x === 'string' && uuid.test(x);
const member = (x: unknown, choices: readonly unknown[]) => choices.includes(x);
const timestamp = (x: unknown) =>
    x === null ||
    (typeof x === 'string' &&
        /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?Z$/.test(x) &&
        Number.isFinite(Date.parse(x)));
function parameters(value: unknown): void {
    requireValue(Array.isArray(value) && value.length <= 16);
    const seen = new Set<string>();
    const reserved = new Set([
        'verbose',
        'debug',
        'erroraction',
        'warningaction',
        'informationaction',
        'progressaction',
        'errorvariable',
        'warningvariable',
        'informationvariable',
        'outvariable',
        'outbuffer',
        'pipelinevariable',
        'whatif',
        'confirm',
    ]);
    for (const raw of value) {
        const p = object(raw, ['name', 'type', 'required', 'constraints']);
        requireValue(
            typeof p.name === 'string' &&
                /^[A-Za-z][A-Za-z0-9_]{0,31}$/.test(p.name) &&
                typeof p.required === 'boolean',
        );
        const key = p.name.toLowerCase();
        requireValue(!seen.has(key) && !reserved.has(key));
        seen.add(key);
        if (p.type === 'boolean') object(p.constraints, []);
        else if (p.type === 'integer') {
            const c = object(p.constraints, ['minimum', 'maximum']);
            requireValue(
                integer(c.minimum, -Number.MAX_SAFE_INTEGER) &&
                    integer(c.maximum, c.minimum),
            );
        } else if (p.type === 'string') {
            const c = object(p.constraints, ['min_length', 'max_length']);
            requireValue(
                integer(c.min_length, 0, 1024) &&
                    integer(c.max_length, c.min_length, 1024),
            );
        } else if (p.type === 'enum') {
            const c = object(p.constraints, ['values']);
            requireValue(
                Array.isArray(c.values) &&
                    c.values.length > 0 &&
                    c.values.length <= 32 &&
                    c.values.every((x) => scriptText(x, 1024, 1)) &&
                    new Set(c.values).size === c.values.length,
            );
        } else requireValue(false);
    }
}
export function scriptManifest(value: unknown): ScriptManifest {
    const m = object(value, [
        'script_uuid',
        'script_version',
        'name',
        'publisher_label',
        'sha256',
        'size_bytes',
        'interpreter',
        'interpreter_version',
        'runtime_manifest_sha256',
        'wrapper_sha256',
        'architecture',
        'parameter_schema',
        'execution_policy',
    ]);
    requireValue(
        identifier(m.script_uuid) &&
            m.script_version === 1 &&
            scriptText(m.name, 200, 1) &&
            scriptText(m.publisher_label, 200, 1),
    );
    requireValue(
        hash(m.sha256) &&
            integer(m.size_bytes, 1, 262144) &&
            hash(m.runtime_manifest_sha256) &&
            hash(m.wrapper_sha256),
    );
    requireValue(
        m.interpreter === 'powershell7' &&
            typeof m.interpreter_version === 'string' &&
            /^7\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,3})$/.test(
                m.interpreter_version,
            ) &&
            member(m.architecture, ['amd64', 'arm64']),
    );
    parameters(m.parameter_schema);
    const p = object(m.execution_policy, [
        'runtime_seconds',
        'output_bytes',
        'output_retention_hours',
        'network_mode',
        'timeout_mode',
        'success_exit_codes',
        'expected_observation_schema',
    ]);
    requireValue(
        integer(p.runtime_seconds, 1, 900) &&
            integer(p.output_bytes, 0, 65536) &&
            integer(p.output_retention_hours, 1, 168) &&
            p.network_mode === 'reviewed_network' &&
            member(p.timeout_mode, ['report_only', 'terminate_owned_job']),
    );
    requireValue(
        Array.isArray(p.success_exit_codes) &&
            p.success_exit_codes.length > 0 &&
            p.success_exit_codes.length <= 16 &&
            p.success_exit_codes.every((x) => integer(x, 0, 4294967295)) &&
            new Set(p.success_exit_codes).size === p.success_exit_codes.length,
    );
    requireValue(
        p.expected_observation_schema === null ||
            hash(p.expected_observation_schema),
    );
    return value as ScriptManifest;
}
function review(value: unknown): ScriptReview {
    const r = object(value, [
        'effect',
        'child_processes',
        'network_destinations',
        'output_sensitivity',
        'compensation',
        'restart_behavior',
        'no_detached_work',
        'no_dynamic_code',
    ]);
    requireValue(
        member(r.effect, ['read_only', 'mutating']) &&
            member(r.output_sensitivity, [
                'none',
                'operational',
                'sensitive',
            ]) &&
            scriptText(r.compensation, 1000, 1),
    );
    requireValue(
        member(r.restart_behavior, ['none', 'may_request_restart']) &&
            (r.effect !== 'read_only' || r.restart_behavior === 'none') &&
            r.no_detached_work === true &&
            r.no_dynamic_code === true,
    );
    for (const key of ['child_processes', 'network_destinations']) {
        const list = r[key];
        requireValue(
            Array.isArray(list) &&
                list.length <= 32 &&
                list.every((x) => scriptText(x, 256, 1)),
        );
    }
    return value as ScriptReview;
}
export function scriptRecord(value: unknown, actor: number): ScriptRecord {
    const r = object(value, [
        'uuid',
        'script_version',
        'status',
        'status_version',
        'content_hash',
        'manifest',
        'review',
        'uploaded_by_user_id',
        'reviewed_by_user_id',
        'can_submit',
        'can_review',
        'can_withdraw',
        'can_read_material',
        'publication_issue',
        'submitted_at',
        'published_at',
        'withdrawn_at',
    ]);
    requireValue(
        integer(actor, 1) &&
            identifier(r.uuid) &&
            r.script_version === 1 &&
            member(r.status, scriptStatuses) &&
            integer(r.status_version, 1) &&
            hash(r.content_hash),
    );
    const m = scriptManifest(r.manifest);
    review(r.review);
    requireValue(
        m.script_uuid === r.uuid &&
            m.script_version === r.script_version &&
            integer(r.uploaded_by_user_id, 1) &&
            (r.reviewed_by_user_id === null ||
                integer(r.reviewed_by_user_id, 1)),
    );
    for (const key of [
        'can_submit',
        'can_review',
        'can_withdraw',
        'can_read_material',
    ])
        requireValue(typeof r[key] === 'boolean');
    requireValue(
        member(r.publication_issue, [
            null,
            'runtime_unavailable',
            'observation_unavailable',
        ]),
    );
    requireValue(
        !r.can_submit ||
            (r.status === 'uploaded' && r.uploaded_by_user_id === actor),
    );
    requireValue(
        !r.can_review ||
            (r.status === 'pending_review' &&
                r.uploaded_by_user_id !== actor &&
                r.can_read_material &&
                r.publication_issue === null),
    );
    requireValue(
        !r.can_withdraw || (r.status !== 'withdrawn' && r.can_read_material),
    );
    for (const key of ['submitted_at', 'published_at', 'withdrawn_at'])
        requireValue(timestamp(r[key]));
    return value as ScriptRecord;
}
function href(value: unknown, actor: number, nullable = false): void {
    if (nullable && value === null) return;
    requireValue(
        typeof value === 'string' &&
            value.startsWith(scriptBase + '?') &&
            value.length <= 4096,
    );
    const url = new URL(value, 'https://catalogue.invalid');
    requireValue(
        url.origin === 'https://catalogue.invalid' &&
            url.pathname === scriptBase &&
            !url.hash,
    );
    const keys = [...url.searchParams.keys()];
    requireValue(
        new Set(keys).size === keys.length &&
            keys.every((x) =>
                ['expected_actor_id', 'status', 'search', 'cursor'].includes(x),
            ) &&
            url.searchParams.get('expected_actor_id') === String(actor),
    );
}
export function scriptIndex(value: unknown): ScriptIndex {
    const x = object(value, [
        'actor_id',
        'can_upload',
        'identity_confirmed',
        'identity_confirm_href',
        'execution_available',
        'summary',
        'query',
        'pagination',
        'scripts',
    ]);
    requireValue(
        integer(x.actor_id, 1) &&
            typeof x.can_upload === 'boolean' &&
            typeof x.identity_confirmed === 'boolean' &&
            x.execution_available === false,
    );
    requireValue(
        x.identity_confirm_href ===
            scriptBase + '/confirm-identity?expected_actor_id=' + x.actor_id,
    );
    const summary = object(x.summary, [...scriptStatuses]);
    Object.values(summary).forEach((n) => requireValue(integer(n)));
    const query = object(x.query, ['status', 'search']);
    requireValue(
        member(query.status, ['all', ...scriptStatuses]) &&
            scriptText(query.search, 200),
    );
    const p = object(x.pagination, [
        'limit',
        'shown',
        'previous_href',
        'next_href',
        'reset_href',
        'position_reset',
    ]);
    requireValue(
        p.limit === 20 &&
            integer(p.shown, 0, 20) &&
            typeof p.position_reset === 'boolean',
    );
    href(p.previous_href, x.actor_id, true);
    href(p.next_href, x.actor_id, true);
    href(p.reset_href, x.actor_id);
    requireValue(Array.isArray(x.scripts) && x.scripts.length === p.shown);
    const rows = x.scripts.map((row) =>
        scriptRecord(row, x.actor_id as number),
    );
    requireValue(
        new Set(rows.map((row) => row.uuid)).size === rows.length &&
            rows.every(
                (row) => query.status === 'all' || row.status === query.status,
            ),
    );
    return value as ScriptIndex;
}
export function scriptReply(
    value: unknown,
    actor: number,
    expected?: ScriptRecord,
    requestKey?: string,
): { script: ScriptRecord; receipt?: ScriptReceipt } {
    const x = object(
        value,
        requestKey ? ['actor_id', 'script', 'receipt'] : ['actor_id', 'script'],
    );
    requireValue(x.actor_id === actor);
    const row = scriptRecord(x.script, actor);
    if (expected)
        requireValue(
            row.uuid === expected.uuid &&
                row.content_hash === expected.content_hash &&
                row.uploaded_by_user_id === expected.uploaded_by_user_id &&
                stable(row.manifest) === stable(expected.manifest) &&
                stable(row.review) === stable(expected.review) &&
                row.status_version >= expected.status_version,
        );
    if (!requestKey) return { script: row };
    const r = object(x.receipt, [
        'request_key',
        'event',
        'status',
        'status_version',
        'content_hash',
    ]);
    requireValue(
        r.request_key === requestKey &&
            identifier(r.request_key) &&
            member(r.event, ['upload', 'submit', 'review', 'withdraw']) &&
            member(r.status, scriptStatuses) &&
            integer(r.status_version, 1) &&
            r.status_version <= row.status_version &&
            r.content_hash === row.content_hash,
    );
    return { script: row, receipt: r as ScriptReceipt };
}
function stable(value: unknown): string {
    if (Array.isArray(value)) return '[' + value.map(stable).join(',') + ']';
    if (value && typeof value === 'object')
        return (
            '{' +
            Object.entries(value)
                .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
                .map(([k, v]) => JSON.stringify(k) + ':' + stable(v))
                .join(',') +
            '}'
        );
    return JSON.stringify(value);
}
export { review as scriptReview, stable as scriptCanonical };
export async function scriptMaterial(
    value: unknown,
    actor: number,
    expected: ScriptRecord,
): Promise<ScriptMaterial> {
    const x = object(value, [
        'actor_id',
        'content_hash',
        'manifest',
        'review',
        'artifact',
        'runtime_manifest',
    ]);
    requireValue(
        x.actor_id === actor && x.content_hash === expected.content_hash,
    );
    scriptManifest(x.manifest);
    review(x.review);
    requireValue(
        stable(x.manifest) === stable(expected.manifest) &&
            stable(x.review) === stable(expected.review),
    );
    requireValue(
        typeof x.artifact === 'string' &&
            typeof x.runtime_manifest === 'string' &&
            !badUnicode.test(x.artifact) &&
            !badUnicode.test(x.runtime_manifest),
    );
    requireValue(
        !x.artifact.startsWith('\uFEFF') && !x.artifact.includes('\0'),
    );
    const bytes = new TextEncoder().encode(x.artifact),
        runtime = new TextEncoder().encode(x.runtime_manifest);
    requireValue(
        bytes.length === expected.manifest.size_bytes &&
            runtime.length > 0 &&
            runtime.length <= 1048576,
    );
    const sha = async (body: Uint8Array) =>
        [
            ...new Uint8Array(
                await crypto.subtle.digest(
                    'SHA-256',
                    new Uint8Array(body).buffer,
                ),
            ),
        ]
            .map((n) => n.toString(16).padStart(2, '0'))
            .join('');
    requireValue(
        (await sha(bytes)) === expected.manifest.sha256 &&
            (await sha(runtime)) === expected.manifest.runtime_manifest_sha256,
    );
    return value as ScriptMaterial;
}
