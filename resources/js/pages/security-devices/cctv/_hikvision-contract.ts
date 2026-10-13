import { sourceAddress, sourceText } from './_source-contract';
import { cctvRecord, cctvTime, cctvUuid } from './live-contract';

export const hikProfile = 'hikvision_rtsp_https_digest_v1';
export type HikChoice = 'collectors' | 'scopes' | 'credentials';
export type HikOption = { id: number; name: string; collector_id?: number };
export type HikPage = {
    items: HikOption[];
    limit: 20;
    next_href: string | null;
    previous_href: string | null;
};
export type HikSource = {
    id: number;
    revision: number;
    enabled: boolean;
    stream_state: 'not_requested' | 'verified' | 'unavailable';
    stream_verified_at: string | null;
    channel_name: string | null;
    observed_at: string | null;
};
export type HikSetup = {
    provider: 'hikvision';
    actor_id: number;
    camera_id: number;
    camera: {
        id: number;
        name: string;
        site: { id: number; name: string };
        model: string | null;
        serial: string | null;
        ip_address: string | null;
    };
    revision: number;
    source: HikSource | null;
    active_source: HikSource | null;
    readiness: {
        can_discover: boolean;
        can_map: boolean;
        can_enable: boolean;
        can_disable: boolean;
        can_view: boolean;
        reason_code:
            | 'not_mapped'
            | 'connection_changed'
            | 'connection_unavailable'
            | 'active_connection_exists'
            | null;
    };
    choices: Record<HikChoice, HikPage>;
    choice: HikChoice | null;
    q: string;
    collector_id: number | null;
};
export type HikIdentity = {
    model: string;
    serial: string;
    device_id: string;
    firmware: string;
    firmware_build: string;
};
export type HikChannel = {
    id: string;
    input_id: string;
    name: string;
    codec: string;
    enabled: boolean;
    video_enabled: boolean;
    width: number;
    height: number;
    fps100: number;
    audio_enabled: boolean | null;
};
export type HikReview = {
    actor_id: number;
    camera_id: number;
    site_id: number;
    discovery_uuid: string;
    expected_revision: number;
    result_sha256: string;
    identity: HikIdentity | null;
    observed_at: string;
    transport_profile: typeof hikProfile;
    channels: (HikChannel & {
        can_select: boolean;
        reason_code: string | null;
    })[];
    current_context: boolean;
    mapping_enabled: boolean;
    live_available: false;
};
export type HikDiscovery = {
    actor_id: number;
    request_uuid: string;
    device_id: number;
    site_id: number;
    source_revision: number;
    status:
        | 'queued'
        | 'preparing'
        | 'dispatched'
        | 'observed'
        | 'failed'
        | 'revoked'
        | 'expired';
    reason_code: string | null;
    current_context: boolean;
    execution_enabled: boolean;
    created_at: string;
    observed_at: string | null;
    inventory: { identity: HikIdentity; channels: HikChannel[] } | null;
    reader_href: string;
};
const choices: HikChoice[] = ['collectors', 'scopes', 'credentials'];
const id = (v: unknown): v is number =>
    typeof v === 'number' && Number.isSafeInteger(v) && v > 0;
const revision = (v: unknown) =>
    typeof v === 'number' && Number.isSafeInteger(v) && v >= 0;
function requireValue(ok: unknown): asserts ok {
    if (!ok) throw Error('Camera setup could not be verified.');
}
function exact(v: Record<string, unknown>, keys: string[]) {
    requireValue(
        Object.keys(v).length === keys.length &&
            keys.every((k) => Object.hasOwn(v, k)),
    );
}
const nullableText = (v: unknown, max = 128) =>
    v === null || sourceText(v, 0, max);
function time(v: unknown, nullable = false) {
    if (nullable && v === null) return;
    cctvTime(v);
}
export const hikBase = (camera: number) =>
    '/security-devices/devices/' + camera + '/live/hikvision';
export function hikSetupHref(path: unknown, camera: number): boolean {
    if (!id(camera) || typeof path !== 'string') return false;
    const base = hikBase(camera) + '/setup';
    if (path === base) return true;
    if (!path.startsWith(base + '?') || path.includes('#')) return false;
    const q = new URLSearchParams(path.slice(base.length + 1)),
        keys = [...q.keys()];
    return (
        new Set(keys).size === keys.length &&
        keys.every((k) =>
            ['choice', 'q', 'cursor', 'collector_id'].includes(k),
        ) &&
        choices.includes(q.get('choice') as HikChoice) &&
        (!q.has('q') || sourceText(q.get('q'), 0, 200)) &&
        (!q.has('cursor') || /^[a-zA-Z0-9_-]{1,256}$/.test(q.get('cursor')!)) &&
        (!q.has('collector_id') ||
            (q.get('choice') === 'scopes' &&
                /^[1-9]\d*$/.test(q.get('collector_id')!) &&
                id(Number(q.get('collector_id')))))
    );
}
export function hikPath(
    camera: number,
    actor: number,
    path: string,
    write: boolean,
): boolean {
    if (!id(camera) || !id(actor)) return false;
    const base = hikBase(camera) + '/source',
        discovery = '/security-devices/cctv/hikvision-discoveries/';
    if (write)
        return [
            base,
            base + '/enable',
            base + '/disable',
            '/security-devices/devices/' + camera + '/hikvision-discoveries',
        ].includes(path);
    if (hikSetupHref(path, camera)) return true;
    if (path.startsWith(base + '/discoveries/'))
        return cctvUuid(path.slice((base + '/discoveries/').length));
    for (const prefix of [base + '/requests/', discovery + 'requests/']) {
        if (path.startsWith(prefix)) {
            const parts = path.slice(prefix.length).split('?');
            return (
                parts.length === 2 &&
                cctvUuid(parts[0]) &&
                parts[1] === 'expected_actor_id=' + actor
            );
        }
    }
    return path.startsWith(discovery) && cctvUuid(path.slice(discovery.length));
}
function summary(value: unknown): HikSource {
    const v = cctvRecord(value);
    exact(v, [
        'id',
        'revision',
        'enabled',
        'stream_state',
        'stream_verified_at',
        'channel_name',
        'observed_at',
    ]);
    requireValue(
        id(v.id) &&
            id(v.revision) &&
            typeof v.enabled === 'boolean' &&
            ['not_requested', 'verified', 'unavailable'].includes(
                v.stream_state as string,
            ) &&
            nullableText(v.channel_name),
    );
    time(v.stream_verified_at, true);
    time(v.observed_at, true);
    requireValue(
        v.stream_state !== 'verified' || v.stream_verified_at !== null,
    );
    return v as HikSource;
}
export function hikSetupReply(
    value: unknown,
    actor: number,
    camera: number,
): HikSetup {
    const v = cctvRecord(value);
    exact(v, [
        'provider',
        'actor_id',
        'camera_id',
        'camera',
        'revision',
        'source',
        'active_source',
        'readiness',
        'choices',
        'choice',
        'q',
        'collector_id',
    ]);
    requireValue(
        v.provider === 'hikvision' &&
            v.actor_id === actor &&
            id(actor) &&
            v.camera_id === camera &&
            id(camera) &&
            revision(v.revision),
    );
    const c = cctvRecord(v.camera);
    exact(c, ['id', 'name', 'site', 'model', 'serial', 'ip_address']);
    const site = cctvRecord(c.site);
    exact(site, ['id', 'name']);
    requireValue(
        c.id === camera &&
            sourceText(c.name) &&
            id(site.id) &&
            sourceText(site.name) &&
            nullableText(c.model) &&
            nullableText(c.serial) &&
            (c.ip_address === null ||
                (typeof c.ip_address === 'string' &&
                    sourceAddress(c.ip_address))),
    );
    if (v.source !== null) {
        const s = summary(v.source);
        requireValue(s.revision === v.revision);
    }
    if (v.active_source !== null) {
        const s = summary(v.active_source);
        requireValue(s.enabled && s.revision <= (v.revision as number));
    }
    const r = cctvRecord(v.readiness);
    exact(r, [
        'can_discover',
        'can_map',
        'can_enable',
        'can_disable',
        'can_view',
        'reason_code',
    ]);
    requireValue(
        [
            'can_discover',
            'can_map',
            'can_enable',
            'can_disable',
            'can_view',
        ].every((k) => typeof r[k] === 'boolean') &&
            [
                null,
                'not_mapped',
                'connection_changed',
                'connection_unavailable',
                'active_connection_exists',
            ].includes(r.reason_code as null) &&
            r.can_disable === (v.active_source !== null) &&
            (!r.can_view || v.active_source !== null) &&
            (!r.can_enable ||
                (v.source !== null && !(v.source as HikSource).enabled)),
    );
    requireValue(
        (v.choice === null || choices.includes(v.choice as HikChoice)) &&
            sourceText(v.q, 0, 200) &&
            (v.collector_id === null ||
                (v.choice === 'scopes' && id(v.collector_id))),
    );
    hikDirectoryReply(
        v.choices,
        camera,
        v as Pick<HikSetup, 'choice' | 'q' | 'collector_id'>,
    );
    return v as HikSetup;
}
function identity(value: unknown): HikIdentity {
    const v = cctvRecord(value);
    exact(v, ['model', 'serial', 'device_id', 'firmware', 'firmware_build']);
    requireValue(
        ['model', 'serial', 'device_id', 'firmware'].every((k) =>
            sourceText(v[k], 1, 128),
        ) && sourceText(v.firmware_build, 0, 128),
    );
    return v as HikIdentity;
}
function channel(value: unknown, review = false): HikChannel {
    const v = cctvRecord(value);
    exact(v, [
        'id',
        'input_id',
        'name',
        'codec',
        'enabled',
        'video_enabled',
        'width',
        'height',
        'fps100',
        'audio_enabled',
        ...(review ? ['can_select', 'reason_code'] : []),
    ]);
    requireValue(
        ['id', 'input_id'].every(
            (k) =>
                typeof v[k] === 'string' &&
                /^[1-9]\d{0,8}$/.test(v[k] as string),
        ) &&
            sourceText(v.name, 0, 128) &&
            sourceText(v.codec, 1, 32) &&
            ['enabled', 'video_enabled'].every(
                (k) => typeof v[k] === 'boolean',
            ) &&
            (v.audio_enabled === null || typeof v.audio_enabled === 'boolean'),
    );
    for (const [key, max] of [
        ['width', 16384],
        ['height', 16384],
        ['fps100', 100000],
    ] as const)
        requireValue(id(v[key]) && (v[key] as number) <= max);
    if (review)
        requireValue(
            typeof v.can_select === 'boolean' &&
                nullableText(v.reason_code, 64) &&
                (v.can_select
                    ? v.reason_code === null &&
                      v.enabled &&
                      v.video_enabled &&
                      ['H264', 'H.264'].includes(v.codec as string)
                    : v.reason_code !== null),
        );
    return v as HikChannel;
}
function channels(value: unknown, review = false): HikChannel[] {
    requireValue(Array.isArray(value) && value.length <= 256);
    const parsed = value.map((v) => channel(v, review));
    requireValue(new Set(parsed.map((v) => v.id)).size === parsed.length);
    return parsed;
}
export function hikDiscoveryReply(
    value: unknown,
    setup: HikSetup,
    requestUuid?: string,
): HikDiscovery {
    const v = cctvRecord(value);
    exact(v, [
        'actor_id',
        'request_uuid',
        'device_id',
        'site_id',
        'source_revision',
        'status',
        'reason_code',
        'current_context',
        'execution_enabled',
        'created_at',
        'observed_at',
        'inventory',
        'reader_href',
    ]);
    requireValue(
        v.actor_id === setup.actor_id &&
            v.device_id === setup.camera_id &&
            v.site_id === setup.camera.site.id &&
            v.source_revision === setup.revision &&
            cctvUuid(v.request_uuid) &&
            (!requestUuid || v.request_uuid === requestUuid) &&
            [
                'queued',
                'preparing',
                'dispatched',
                'observed',
                'failed',
                'revoked',
                'expired',
            ].includes(v.status as string) &&
            nullableText(v.reason_code, 64) &&
            typeof v.current_context === 'boolean' &&
            typeof v.execution_enabled === 'boolean' &&
            v.reader_href ===
                '/security-devices/cctv/hikvision-discoveries/' +
                    v.request_uuid,
    );
    time(v.created_at);
    time(v.observed_at, true);
    if (v.inventory !== null) {
        const i = cctvRecord(v.inventory);
        exact(i, ['identity', 'channels']);
        identity(i.identity);
        channels(i.channels);
    }
    requireValue(
        v.status !== 'observed' ||
            (v.inventory !== null &&
                v.observed_at !== null &&
                v.reason_code === null),
    );
    return v as HikDiscovery;
}
export function hikReviewReply(
    value: unknown,
    setup: HikSetup,
    uuid: string,
): HikReview {
    const v = cctvRecord(value);
    exact(v, [
        'actor_id',
        'camera_id',
        'site_id',
        'discovery_uuid',
        'expected_revision',
        'result_sha256',
        'identity',
        'observed_at',
        'transport_profile',
        'channels',
        'current_context',
        'mapping_enabled',
        'live_available',
    ]);
    requireValue(
        v.actor_id === setup.actor_id &&
            v.camera_id === setup.camera_id &&
            v.site_id === setup.camera.site.id &&
            v.discovery_uuid === uuid &&
            cctvUuid(uuid) &&
            v.expected_revision === setup.revision &&
            typeof v.result_sha256 === 'string' &&
            /^[a-f0-9]{64}$/.test(v.result_sha256) &&
            v.transport_profile === hikProfile &&
            typeof v.current_context === 'boolean' &&
            typeof v.mapping_enabled === 'boolean' &&
            v.live_available === false,
    );
    time(v.observed_at);
    if (v.identity !== null) identity(v.identity);
    channels(v.channels, true);
    requireValue(
        !(v.channels as HikReview['channels']).some((c) => c.can_select) ||
            (v.current_context &&
                v.identity !== null &&
                (v.identity as HikIdentity).model === setup.camera.model &&
                (v.identity as HikIdentity).serial === setup.camera.serial),
    );
    return v as HikReview;
}
export function hikMappingReply(
    value: unknown,
    setup: HikSetup,
    review: HikReview,
    selected: HikChannel,
): number {
    const v = cctvRecord(value);
    exact(v, [
        'actor_id',
        'camera_id',
        'source',
        'mapping_enabled',
        'live_available',
    ]);
    requireValue(
        v.actor_id === setup.actor_id &&
            v.camera_id === setup.camera_id &&
            typeof v.mapping_enabled === 'boolean' &&
            v.live_available === false,
    );
    const s = cctvRecord(v.source);
    exact(s, [
        'id',
        'revision',
        'provider',
        'kind',
        'recorder_device_id',
        'current_mapping',
        'enabled',
        'stream_state',
        'stream_verified_at',
        'identity',
        'channel',
        'transport_profile',
        'video_codec',
        'audio_enabled',
        'observed_at',
        'mapped_at',
    ]);
    requireValue(
        id(s.id) &&
            s.revision === setup.revision + 1 &&
            s.provider === 'hikvision' &&
            s.kind === 'direct_camera' &&
            s.recorder_device_id === null &&
            s.current_mapping === true &&
            typeof s.enabled === 'boolean' &&
            ['not_requested', 'verified', 'unavailable'].includes(
                s.stream_state as string,
            ) &&
            s.transport_profile === hikProfile &&
            s.video_codec === 'H264' &&
            s.audio_enabled === false &&
            s.observed_at === review.observed_at,
    );
    identity(s.identity);
    channel(s.channel);
    time(s.mapped_at);
    time(s.stream_verified_at, true);
    requireValue(
        JSON.stringify(s.identity) === JSON.stringify(review.identity) &&
            JSON.stringify(s.channel) ===
                JSON.stringify(
                    Object.fromEntries(
                        Object.entries(selected).filter(
                            ([k]) => !['can_select', 'reason_code'].includes(k),
                        ),
                    ),
                ),
    );
    return s.id;
}
export function hikActionReply(
    value: unknown,
    setup: HikSetup,
    expected: HikSource,
): { enabled: boolean; stream_state: string } {
    const v = cctvRecord(value);
    exact(v, ['actor_id', 'camera_id', 'source']);
    const s = cctvRecord(v.source);
    exact(s, [
        'id',
        'revision',
        'enabled',
        'stream_state',
        'stream_verified_at',
    ]);
    requireValue(
        v.actor_id === setup.actor_id &&
            v.camera_id === setup.camera_id &&
            s.id === expected.id &&
            s.revision === expected.revision &&
            typeof s.enabled === 'boolean' &&
            ['not_requested', 'verified', 'unavailable'].includes(
                s.stream_state as string,
            ),
    );
    time(s.stream_verified_at, true);
    return s as { enabled: boolean; stream_state: string };
}

export function hikDirectoryReply(
    value: unknown,
    camera: number,
    v: Pick<HikSetup, 'choice' | 'q' | 'collector_id'>,
): Record<HikChoice, HikPage> {
    const pages = cctvRecord(value);
    exact(pages, choices);
    for (const kind of choices) {
        const p = cctvRecord(pages[kind]);
        exact(p, ['items', 'limit', 'next_href', 'previous_href']);
        requireValue(
            p.limit === 20 && Array.isArray(p.items) && p.items.length <= 20,
        );
        const seen = new Set<number>();
        for (const raw of p.items) {
            const o = cctvRecord(raw);
            exact(
                o,
                kind === 'scopes'
                    ? ['id', 'name', 'collector_id']
                    : ['id', 'name'],
            );
            requireValue(
                id(o.id) &&
                    !seen.has(o.id) &&
                    sourceText(o.name) &&
                    (kind !== 'scopes' ||
                        (id(o.collector_id) &&
                            (v.choice !== 'scopes' ||
                                v.collector_id === null ||
                                o.collector_id === v.collector_id))),
            );
            seen.add(o.id);
        }
        for (const key of ['next_href', 'previous_href']) {
            requireValue(p[key] === null || hikSetupHref(p[key], camera));
            if (p[key] !== null) {
                const q = new URLSearchParams((p[key] as string).split('?')[1]);
                requireValue(
                    q.get('choice') === kind &&
                        (q.get('q') ?? '') === (kind === v.choice ? v.q : '') &&
                        (q.get('collector_id') ?? null) ===
                            (kind === 'scopes' && v.collector_id !== null
                                ? String(v.collector_id)
                                : null) &&
                        q.has('cursor'),
                );
            }
        }
    }
    return pages as Record<HikChoice, HikPage>;
}
export function hikIdentityReply(value: unknown): HikIdentity {
    return identity(value);
}
