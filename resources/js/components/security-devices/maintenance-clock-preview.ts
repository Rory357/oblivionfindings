export type MaintenanceClockPreview = {
    schema_version: 1;
    actor_id: number;
    owner: 'Monitoring';
    scope: {
        site_id: number;
        device_id: number | null;
        monitor_id: number | null;
    };
    timezone: string;
    dst_overlap: 'reject' | 'earlier' | 'later';
    dst_gap: 'reject' | 'skip';
    precision: 'seconds';
    commits_change: false;
    scheduled_occurrences: number;
    skipped_occurrences: number;
    truncated: boolean;
    occurrences: Array<{
        local_start: string;
        local_end: string;
        timezone: string;
        state: 'ready' | 'skipped';
        reason_code: 'nonexistent_local_time' | null;
        starts_at: string | null;
        ends_at: string | null;
    }>;
};
function requireValue(value: unknown): asserts value {
    if (!value)
        throw Error(
            'The maintenance time preview could not be verified. Review the dates and try again.',
        );
}
function record(value: unknown): Record<string, unknown> {
    requireValue(value && typeof value === 'object' && !Array.isArray(value));
    return value as Record<string, unknown>;
}
function exact(value: Record<string, unknown>, keys: string[]) {
    requireValue(
        Object.keys(value).length === keys.length &&
            keys.every((k) => Object.hasOwn(value, k)),
    );
}
function positive(value: unknown): value is number {
    return (
        typeof value === 'number' && Number.isSafeInteger(value) && value > 0
    );
}
function instant(value: unknown): value is string {
    return (
        typeof value === 'string' &&
        /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)$/.test(value) &&
        Number.isFinite(Date.parse(value)) &&
        new Date(value).toISOString().slice(0, 19) === value.slice(0, 19)
    );
}
export function maintenanceClockPreview(
    value: unknown,
    actor: number,
    payload: Record<string, unknown>,
): MaintenanceClockPreview {
    const p = record(value);
    exact(p, [
        'schema_version',
        'actor_id',
        'owner',
        'scope',
        'timezone',
        'dst_overlap',
        'dst_gap',
        'precision',
        'commits_change',
        'scheduled_occurrences',
        'skipped_occurrences',
        'truncated',
        'occurrences',
    ]);
    const scope = record(p.scope);
    exact(scope, ['site_id', 'device_id', 'monitor_id']);
    const nullableId = (v: unknown) => v === null || positive(v);
    const inputId = (v: unknown) =>
        v === null || v === undefined ? null : Number(v);
    requireValue(
        positive(actor) &&
            p.actor_id === actor &&
            p.schema_version === 1 &&
            p.owner === 'Monitoring' &&
            p.precision === 'seconds' &&
            p.commits_change === false &&
            p.timezone === payload.timezone &&
            p.dst_overlap === payload.dst_overlap &&
            p.dst_gap === payload.dst_gap &&
            ['reject', 'earlier', 'later'].includes(p.dst_overlap as string) &&
            ['reject', 'skip'].includes(p.dst_gap as string),
    );
    requireValue(
        positive(scope.site_id) &&
            nullableId(scope.device_id) &&
            nullableId(scope.monitor_id) &&
            scope.site_id === Number(payload.site_id) &&
            scope.device_id === inputId(payload.device_id) &&
            scope.monitor_id === inputId(payload.monitor_id) &&
            !(scope.monitor_id !== null && scope.device_id !== null),
    );
    requireValue(
        positive(p.scheduled_occurrences) &&
            p.scheduled_occurrences <= 367 &&
            Number.isSafeInteger(p.skipped_occurrences) &&
            (p.skipped_occurrences as number) >= 0 &&
            (p.skipped_occurrences as number) <= 367,
    );
    const total =
        (p.scheduled_occurrences as number) + (p.skipped_occurrences as number);
    requireValue(
        total <= 367 &&
            Array.isArray(p.occurrences) &&
            p.occurrences.length === Math.min(10, total) &&
            p.truncated === total > 10,
    );
    for (const value of p.occurrences) {
        const r = record(value);
        exact(r, [
            'local_start',
            'local_end',
            'timezone',
            'state',
            'reason_code',
            'starts_at',
            'ends_at',
        ]);
        requireValue(
            r.timezone === p.timezone &&
                typeof r.local_start === 'string' &&
                /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}$/.test(
                    r.local_start,
                ) &&
                typeof r.local_end === 'string' &&
                /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}$/.test(
                    r.local_end,
                ),
        );
        requireValue(
            r.state === 'skipped'
                ? r.reason_code === 'nonexistent_local_time' &&
                      r.starts_at === null &&
                      r.ends_at === null
                : r.state === 'ready' &&
                      r.reason_code === null &&
                      instant(r.starts_at) &&
                      instant(r.ends_at) &&
                      Date.parse(r.ends_at) > Date.parse(r.starts_at),
        );
    }
    const ready = p.occurrences.filter(
        (row: unknown) => record(row).state === 'ready',
    ).length;
    const skipped = p.occurrences.length - ready;
    requireValue(
        ready <= (p.scheduled_occurrences as number) &&
            skipped <= (p.skipped_occurrences as number),
    );
    if (!p.truncated)
        requireValue(
            ready === p.scheduled_occurrences &&
                skipped === p.skipped_occurrences,
        );
    const first = record(p.occurrences[0]);
    requireValue(
        first.state === 'ready' &&
            typeof payload.starts_at === 'string' &&
            instant(first.starts_at) &&
            Date.parse(first.starts_at) === Date.parse(payload.starts_at),
    );
    const expectedEnd = Math.min(
        Date.parse(String(payload.ends_at)),
        payload.recurrence_until
            ? Date.parse(String(payload.recurrence_until))
            : Infinity,
    );
    requireValue(
        instant(first.ends_at) && Date.parse(first.ends_at) === expectedEnd,
    );
    return p as unknown as MaintenanceClockPreview;
}
