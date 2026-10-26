export type RecordingChoice = {
    camera: { id: number; name: string };
    recorder: { id: number; name: string };
    site_id: number;
    references_href: string;
    in_active_window: boolean;
    can_record_window: boolean;
};

export type RecordingPreview = {
    schema_version: 1;
    actor_id: number;
    incident_id: number;
    incident_evidence_version: number;
    active_window_revision: number | null;
    policy: {
        state: 'configured' | 'unavailable';
        revision: number | null;
        rule: {
            identifier: string;
            before_seconds: number;
            after_seconds: number;
            max_before_seconds: number;
            max_after_seconds: number;
            default_start_at: string;
            default_end_at: string;
            earliest_start_at: string;
            latest_end_at: string;
        } | null;
    };
    permissions: { can_record: boolean; can_expand: boolean; can_attach: boolean };
    sources: { items: RecordingChoice[]; limit: 64; complete: boolean };
};

export type RecordingWindowCommand = {
    expected_actor_id: number;
    idempotency_key: string;
    expected_incident_evidence_version: number;
    expected_policy_revision: number;
    requested_start_at: string;
    requested_end_at: string;
    approved_sources: Array<{ camera_id: number; recorder_device_id: number; site_id: number }>;
    reason: string;
};

const object = (value: unknown): Record<string, unknown> | null =>
    typeof value === 'object' && value !== null && !Array.isArray(value)
        ? value as Record<string, unknown> : null;
const positive = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
const instant = (value: unknown, fractional = false): value is string => {
    if (typeof value !== 'string' || !(fractional
        ? /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/
        : /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/).test(value)) return false;
    const milliseconds = Date.parse(value);
    return Number.isFinite(milliseconds) &&
        new Date(milliseconds).toISOString().slice(0, 19) === value.slice(0, 19);
};

export const recordingChoiceKey = (source: RecordingChoice) =>
    `${source.camera.id}:${source.recorder.id}:${source.site_id}`;

export function validRecordingPreview(value: unknown, actorId: number, incidentId: number): value is RecordingPreview {
    const data = object(value), policy = object(data?.policy), permissions = object(data?.permissions), sources = object(data?.sources);
    const duration = (n: unknown): n is number => typeof n === 'number' && Number.isSafeInteger(n) && n >= 0 && n <= 2147483647;
    if (!data || !policy || !permissions || !sources || data.schema_version !== 1 ||
        !positive(actorId) || !positive(incidentId) || data.actor_id !== actorId || data.incident_id !== incidentId ||
        !duration(data.incident_evidence_version) ||
        !(data.active_window_revision === null || positive(data.active_window_revision)) ||
        !['can_record', 'can_expand', 'can_attach'].every((key) => typeof permissions[key] === 'boolean') ||
        (permissions.can_expand === true && permissions.can_record !== true) ||
        sources.limit !== 64 || typeof sources.complete !== 'boolean' || !Array.isArray(sources.items) || sources.items.length > 64)
        return false;
    const keys = new Set<string>();
    for (const item of sources.items) {
        const source = object(item), camera = object(source?.camera), recorder = object(source?.recorder);
        if (!source || !camera || !recorder || !positive(camera.id) || !positive(recorder.id) || camera.id === recorder.id ||
            !positive(source.site_id) || typeof camera.name !== 'string' || typeof recorder.name !== 'string' ||
            typeof source.can_record_window !== 'boolean' || typeof source.in_active_window !== 'boolean' ||
            source.references_href !== `/security-devices/devices/${camera.id}/recording-references`) return false;
        const key = `${camera.id}:${recorder.id}:${source.site_id}`;
        if (keys.has(key)) return false;
        keys.add(key);
    }
    if (policy.state === 'unavailable')
        return policy.revision === null && policy.rule === null && permissions.can_record === false && permissions.can_expand === false;
    const rule = object(policy.rule);
    if (policy.state !== 'configured' || !positive(policy.revision) || !rule || typeof rule.identifier !== 'string' ||
        rule.identifier.length === 0 || !duration(rule.before_seconds) || !duration(rule.after_seconds) ||
        !duration(rule.max_before_seconds) || !duration(rule.max_after_seconds) ||
        rule.max_before_seconds < rule.before_seconds || rule.max_after_seconds < rule.after_seconds ||
        !instant(rule.default_start_at) || !instant(rule.default_end_at) ||
        !instant(rule.earliest_start_at) || !instant(rule.latest_end_at)) return false;
    const occurred = Date.parse(rule.default_start_at) + rule.before_seconds * 1000;
    return Date.parse(rule.default_start_at) < Date.parse(rule.default_end_at) &&
        occurred + rule.after_seconds * 1000 === Date.parse(rule.default_end_at) &&
        occurred - rule.max_before_seconds * 1000 === Date.parse(rule.earliest_start_at) &&
        occurred + rule.max_after_seconds * 1000 === Date.parse(rule.latest_end_at);
}

/** Offsets use the server's incident instant, never the browser's local timezone. */
export function recordingWindowInterval(preview: RecordingPreview, before: string, after: string) {
    const rule = preview.policy.rule;
    if (!rule || !instant(rule.default_start_at) || !instant(rule.default_end_at))
        throw new Error('Reload the incident to check the current evidence rule.');
    const seconds = (value: string) => {
        if (!/^(0|[1-9]\d*)$/.test(value) || !Number.isSafeInteger(Number(value)))
            throw new Error('Enter whole seconds before and after the incident.');
        return Number(value);
    };
    const beforeSeconds = seconds(before), afterSeconds = seconds(after);
    const maxBefore = preview.permissions.can_expand ? rule.max_before_seconds : rule.before_seconds;
    const maxAfter = preview.permissions.can_expand ? rule.max_after_seconds : rule.after_seconds;
    if (beforeSeconds < rule.before_seconds || afterSeconds < rule.after_seconds ||
        beforeSeconds > maxBefore || afterSeconds > maxAfter || beforeSeconds + afterSeconds === 0)
        throw new Error('Keep at least the standard review period and stay within your permitted limits.');
    const occurrence = Date.parse(rule.default_start_at) + rule.before_seconds * 1000;
    if (occurrence + rule.after_seconds * 1000 !== Date.parse(rule.default_end_at))
        throw new Error('Reload the incident to check its occurrence time.');
    return {
        requested_start_at: new Date(occurrence - beforeSeconds * 1000).toISOString().replace('.000Z', 'Z'),
        requested_end_at: new Date(occurrence + afterSeconds * 1000).toISOString().replace('.000Z', 'Z'),
    };
}

/** Verify the immutable confirmation; a newer current pointer is valid on recovery. */
export function confirmsRecordingWindow(
    value: unknown, submitted: RecordingWindowCommand, incidentId: number, ruleIdentifier: string,
): boolean {
    const body = object(value), command = object(body?.command), window = object(body?.window);
    const current = object(body?.current);
    if (!body || !command || !window || !current) return false;
    const version = submitted.expected_incident_evidence_version + 1;
    return body.schema_version === 1 && body.actor_id === submitted.expected_actor_id &&
        body.incident_id === incidentId && command.idempotency_key === submitted.idempotency_key &&
        command.kind === 'record_evidence_window' && command.confirmed_incident_evidence_version === version &&
        positive(command.confirmed_evidence_window_revision) && instant(command.confirmed_at, true) &&
        window.revision === command.confirmed_evidence_window_revision &&
        window.start_at === submitted.requested_start_at && window.end_at === submitted.requested_end_at &&
        window.rule_identifier === ruleIdentifier && window.rule_revision === submitted.expected_policy_revision &&
        typeof window.window_sha256 === 'string' && /^[a-f0-9]{64}$/.test(window.window_sha256) &&
        positive(current.incident_evidence_version) && current.incident_evidence_version >= version &&
        (current.evidence_window_revision === null || positive(current.evidence_window_revision));
}
