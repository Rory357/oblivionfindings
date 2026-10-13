import { sourceText } from './_source-contract';
import { cctvRecord, cctvTime, cctvUuid } from './live-contract';

export type RecordingCamera = {
    id: number;
    name: string;
    site: { id: number; name: string };
};
export type CurrentRecordingSource = {
    state: 'ready' | 'withdrawn' | 'changed' | 'unavailable';
    reason_code:
        | null
        | 'source_withdrawn'
        | 'source_changed'
        | 'source_unavailable';
    revision: number | null;
    source_verified_at: string | null;
};
export type RecordingReference = {
    schema_version: 1;
    reference_uuid: string;
    camera_id: number;
    recorder_device_id: number;
    site_id: number;
    provider: 'unifi';
    console_id: string;
    provider_camera_id: string;
    provider_recorder_id: string;
    source_revision: number;
    protect_version: string;
    requested_start_at: string;
    requested_end_at: string;
    created_at: string;
    source_verified_at: string;
    recording_verified_at: null;
    vendor_retention_until: null;
    state: 'reference_only';
    preserved: false;
    binding_basis: 'reviewed_source_revision';
    window_coverage: 'not_verified';
    created_by: { id: number; name: string };
    purpose: string;
    reader_href: string;
};
export type RecordingSummary = Pick<
    RecordingReference,
    | 'reference_uuid'
    | 'requested_start_at'
    | 'requested_end_at'
    | 'created_at'
    | 'source_revision'
    | 'state'
    | 'preserved'
    | 'reader_href'
> & {
    recorder: { id: number; name: string };
    current_source: CurrentRecordingSource;
};
export type RecordingPage = {
    actor_id: number;
    server_now: string;
    camera: RecordingCamera;
    source: null | {
        revision: number;
        recorder_device_id: number;
        recorder_name: string;
        protect_version: string;
        source_verified_at: string;
    };
    current_source: CurrentRecordingSource;
    window_limits: { max_seconds: number; operational_policy_applied: false };
    permissions: { can_view: true; can_create: boolean };
    create_href: string | null;
    request_recovery_base_href: string;
    references: {
        items: RecordingSummary[];
        limit: 20;
        previous_href: string | null;
        next_href: string | null;
    };
};
export type RecordingDetail = {
    actor_id: number;
    server_now: string;
    camera: RecordingCamera;
    recorder: { id: number; name: string };
    reference: RecordingReference;
    current_source: CurrentRecordingSource;
    permissions: { can_view: true; can_create: boolean };
};
export type RecordingRequest = {
    expected_actor_id: number;
    request_key: string;
    expected_source_revision: number;
    requested_start_at: string;
    requested_end_at: string;
    purpose: string;
};
export const recordingBase = (camera: number) =>
    '/security-devices/devices/' + camera + '/recording-references';
export const recordingRecoveryBase =
    '/security-devices/cctv/recording-references/requests';
export const recordingReader = (uuid: string) =>
    '/security-devices/cctv/recording-references/' + uuid;
const id = (n: unknown): n is number =>
    typeof n === 'number' && Number.isSafeInteger(n) && n > 0;
function exact(r: Record<string, unknown>, keys: string[]) {
    if (
        Object.keys(r).length !== keys.length ||
        keys.some((k) => !Object.hasOwn(r, k))
    )
        throw Error('Recording reference changed.');
}
function named(value: unknown) {
    const r = cctvRecord(value);
    exact(r, ['id', 'name']);
    if (!id(r.id) || !sourceText(r.name))
        throw Error('Camera details changed.');
    return r as { id: number; name: string };
}
function camera(value: unknown, expected: number) {
    const r = cctvRecord(value);
    exact(r, ['id', 'name', 'site']);
    if (r.id !== expected || !id(r.id) || !sourceText(r.name))
        throw Error('Camera changed.');
    named(r.site);
    return r as RecordingCamera;
}
function permissions(value: unknown) {
    const r = cctvRecord(value);
    exact(r, ['can_view', 'can_create']);
    if (r.can_view !== true || typeof r.can_create !== 'boolean')
        throw Error('Recording reference access changed.');
}
function interval(r: Record<string, unknown>) {
    const start = cctvTime(r.requested_start_at),
        end = cctvTime(r.requested_end_at),
        created = cctvTime(r.created_at);
    if (start >= end || start > created)
        throw Error('Recording reference times changed.');
}
export function recordingListHref(
    value: unknown,
    expected: number,
): value is string {
    if (!id(expected) || typeof value !== 'string') return false;
    const base = recordingBase(expected);
    if (value === base) return true;
    if (!value.startsWith(base + '?') || value.includes('#')) return false;
    const q = new URLSearchParams(value.slice(base.length + 1));
    return (
        [...q.keys()].length === 1 &&
        q.has('cursor') &&
        /^[a-zA-Z0-9_-]{1,256}$/.test(q.get('cursor') ?? '')
    );
}
function current(value: unknown): CurrentRecordingSource {
    const r = cctvRecord(value);
    exact(r, ['state', 'reason_code', 'revision', 'source_verified_at']);
    const reasons = {
        ready: null,
        withdrawn: 'source_withdrawn',
        changed: 'source_changed',
        unavailable: 'source_unavailable',
    } as const;
    if (
        typeof r.state !== 'string' ||
        !Object.hasOwn(reasons, r.state) ||
        r.reason_code !== reasons[r.state as keyof typeof reasons] ||
        (r.revision !== null && !id(r.revision))
    )
        throw Error('Current camera source changed.');
    if (r.source_verified_at !== null) cctvTime(r.source_verified_at);
    if (
        r.state === 'ready' &&
        (!id(r.revision) || r.source_verified_at === null)
    )
        throw Error('Camera source could not be verified.');
    return r as CurrentRecordingSource;
}
export function recordingPage(
    value: unknown,
    actor: number,
    cameraId: number,
): RecordingPage {
    const r = cctvRecord(value);
    exact(r, [
        'actor_id',
        'server_now',
        'camera',
        'source',
        'current_source',
        'window_limits',
        'permissions',
        'create_href',
        'request_recovery_base_href',
        'references',
    ]);
    if (!id(actor) || r.actor_id !== actor)
        throw Error('Recording reference access changed.');
    camera(r.camera, cameraId);
    cctvTime(r.server_now);
    permissions(r.permissions);
    current(r.current_source);
    if (r.source !== null) {
        const s = cctvRecord(r.source);
        exact(s, [
            'revision',
            'recorder_device_id',
            'recorder_name',
            'protect_version',
            'source_verified_at',
        ]);
        if (
            !id(s.revision) ||
            !id(s.recorder_device_id) ||
            !sourceText(s.recorder_name) ||
            !sourceText(s.protect_version)
        )
            throw Error('Camera source changed.');
        cctvTime(s.source_verified_at);
    }
    const w = cctvRecord(r.window_limits);
    exact(w, ['max_seconds', 'operational_policy_applied']);
    if (
        !id(w.max_seconds) ||
        w.max_seconds < 60 ||
        w.max_seconds > 86400 ||
        w.operational_policy_applied !== false
    )
        throw Error('Recording time limits changed.');
    if (
        (r.create_href !== null && r.create_href !== recordingBase(cameraId)) ||
        r.request_recovery_base_href !== recordingRecoveryBase
    )
        throw Error('Recording reference destination changed.');
    const list = cctvRecord(r.references);
    exact(list, ['items', 'limit', 'previous_href', 'next_href']);
    if (
        list.limit !== 20 ||
        !Array.isArray(list.items) ||
        list.items.length > 20
    )
        throw Error('Recording reference list changed.');
    const seen = new Set<string>();
    for (const item of list.items) {
        const s = cctvRecord(item);
        exact(s, [
            'reference_uuid',
            'requested_start_at',
            'requested_end_at',
            'created_at',
            'source_revision',
            'recorder',
            'state',
            'preserved',
            'reader_href',
            'current_source',
        ]);
        if (
            !cctvUuid(s.reference_uuid) ||
            seen.has(s.reference_uuid) ||
            !id(s.source_revision) ||
            s.state !== 'reference_only' ||
            s.preserved !== false ||
            s.reader_href !== recordingReader(s.reference_uuid)
        )
            throw Error('Recording reference changed.');
        interval(s);
        named(s.recorder);
        current(s.current_source);
        seen.add(s.reference_uuid);
    }
    for (const k of ['previous_href', 'next_href'])
        if (list[k] !== null && !recordingListHref(list[k], cameraId))
            throw Error('Recording reference page changed.');
    return r as RecordingPage;
}
export function recordingDetail(
    value: unknown,
    actor: number,
    cameraId: number,
    expectedUuid?: string,
): RecordingDetail {
    const r = cctvRecord(value);
    exact(r, [
        'actor_id',
        'server_now',
        'camera',
        'recorder',
        'reference',
        'current_source',
        'permissions',
    ]);
    if (!id(actor) || r.actor_id !== actor)
        throw Error('Recording reference access changed.');
    const cam = camera(r.camera, cameraId),
        rec = named(r.recorder);
    cctvTime(r.server_now);
    permissions(r.permissions);
    current(r.current_source);
    const ref = cctvRecord(r.reference);
    exact(ref, [
        'schema_version',
        'reference_uuid',
        'camera_id',
        'recorder_device_id',
        'site_id',
        'provider',
        'console_id',
        'provider_camera_id',
        'provider_recorder_id',
        'source_revision',
        'protect_version',
        'requested_start_at',
        'requested_end_at',
        'created_at',
        'source_verified_at',
        'recording_verified_at',
        'vendor_retention_until',
        'state',
        'preserved',
        'binding_basis',
        'window_coverage',
        'created_by',
        'purpose',
        'reader_href',
    ]);
    if (
        ref.schema_version !== 1 ||
        !cctvUuid(ref.reference_uuid) ||
        (expectedUuid && ref.reference_uuid !== expectedUuid) ||
        ref.camera_id !== cameraId ||
        ref.recorder_device_id !== rec.id ||
        ref.site_id !== cam.site.id ||
        ref.provider !== 'unifi' ||
        !id(ref.source_revision) ||
        !sourceText(ref.console_id) ||
        !sourceText(ref.provider_camera_id) ||
        !sourceText(ref.provider_recorder_id) ||
        !sourceText(ref.protect_version) ||
        !sourceText(ref.purpose, 10, 500) ||
        ref.recording_verified_at !== null ||
        ref.vendor_retention_until !== null ||
        ref.state !== 'reference_only' ||
        ref.preserved !== false ||
        ref.binding_basis !== 'reviewed_source_revision' ||
        ref.window_coverage !== 'not_verified' ||
        ref.reader_href !== recordingReader(ref.reference_uuid)
    )
        throw Error('Recording reference could not be verified.');
    interval(ref);
    if (cctvTime(ref.source_verified_at) > cctvTime(ref.created_at))
        throw Error('Camera source verification time changed.');
    named(ref.created_by);
    return r as RecordingDetail;
}
export function matchesRecordingRequest(
    detail: RecordingDetail,
    request: RecordingRequest,
    recorderId: number,
): boolean {
    const r = detail.reference;
    return (
        detail.actor_id === request.expected_actor_id &&
        r.created_by.id === request.expected_actor_id &&
        r.source_revision === request.expected_source_revision &&
        r.recorder_device_id === recorderId &&
        r.requested_start_at === request.requested_start_at &&
        r.requested_end_at === request.requested_end_at &&
        r.purpose === request.purpose
    );
}
