import { cctvRecord, cctvTime } from './live-contract';

export type SourceChoice = 'recorders' | 'collectors' | 'scopes';
export type SourceOption = {
    id: number;
    name: string;
    collector_id?: number | null;
};
export type SourceChoicePage = {
    items: SourceOption[];
    limit: 20;
    next_href: string | null;
    previous_href: string | null;
};
export type CctvSource = {
    revision: number;
    enabled: boolean;
    recorder_device_id: number;
    recorder_name: string;
    collector_id: number;
    collector_name: string;
    discovery_scope_id: number;
    discovery_scope_name: string;
    console_id: string;
    provider_camera_id: string;
    stream_address: string;
    stream_port: number;
    tls_server_name: string;
    tls_pin: string;
    application_version: string;
    verified_at: string;
    stream_state: 'not_requested' | 'verified' | 'unavailable';
    stream_verified_at: string | null;
};
export type CctvSourceSetup = {
    actor_id: number;
    camera_id: number;
    camera: { id: number; name: string; site: { id: number; name: string } };
    revision: number;
    source: CctvSource | null;
    choices: Record<SourceChoice, SourceChoicePage>;
    choice: SourceChoice | null;
    q: string;
    collector_id: number | null;
};
export type CctvSourceInput = Pick<
    CctvSource,
    | 'recorder_device_id'
    | 'collector_id'
    | 'discovery_scope_id'
    | 'console_id'
    | 'provider_camera_id'
    | 'stream_address'
    | 'stream_port'
    | 'tls_server_name'
    | 'tls_pin'
> & {
    expected_actor_id: number;
    expected_revision: number;
    request_key: string;
    purpose: string;
};
export const sourceBase = (camera: number) =>
    '/security-devices/devices/' + camera + '/live/source';
export const sourceText = (
    value: unknown,
    min = 1,
    max = 500,
): value is string =>
    typeof value === 'string' &&
    [...value].length >= min &&
    [...value].length <= max &&
    !/[\p{Cc}\p{Cf}]/u.test(value);
const id = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
function exact(value: Record<string, unknown>, keys: string[]) {
    if (
        Object.keys(value).length !== keys.length ||
        keys.some((key) => !Object.hasOwn(value, key))
    )
        throw Error('Camera setup changed.');
}
export function sourceHref(
    path: unknown,
    camera: number,
    operation?: 'enable-stream' | 'disable',
): path is string {
    if (typeof path !== 'string' || !id(camera)) return false;
    const base = sourceBase(camera);
    if (operation) return path === base + '/' + operation;
    if (path === base) return true;
    if (!path.startsWith(base + '?') || path.includes('#')) return false;
    const query = new URLSearchParams(path.slice(base.length + 1)),
        keys = [...query.keys()];
    if (
        new Set(keys).size !== keys.length ||
        keys.some(
            (key) => !['choice', 'q', 'cursor', 'collector_id'].includes(key),
        )
    )
        return false;
    if (
        !['recorders', 'collectors', 'scopes'].includes(
            query.get('choice') ?? '',
        )
    )
        return false;
    if (query.has('q') && !sourceText(query.get('q'), 0, 200)) return false;
    if (
        query.has('cursor') &&
        !/^[a-zA-Z0-9_-]{1,256}$/.test(query.get('cursor')!)
    )
        return false;
    if (
        query.has('collector_id') &&
        (query.get('choice') !== 'scopes' ||
            !/^[1-9]\d*$/.test(query.get('collector_id')!) ||
            !id(Number(query.get('collector_id'))))
    )
        return false;
    return true;
}
export function sourceProjection(value: unknown): CctvSource {
    const r = cctvRecord(value);
    exact(r, [
        'revision',
        'enabled',
        'recorder_device_id',
        'recorder_name',
        'collector_id',
        'collector_name',
        'discovery_scope_id',
        'discovery_scope_name',
        'console_id',
        'provider_camera_id',
        'stream_address',
        'stream_port',
        'tls_server_name',
        'tls_pin',
        'application_version',
        'verified_at',
        'stream_state',
        'stream_verified_at',
    ]);
    if (
        ![
            'revision',
            'recorder_device_id',
            'collector_id',
            'discovery_scope_id',
            'stream_port',
        ].every((key) => id(r[key])) ||
        typeof r.enabled !== 'boolean' ||
        Number(r.stream_port) > 65535 ||
        ![
            'recorder_name',
            'collector_name',
            'discovery_scope_name',
            'console_id',
            'provider_camera_id',
            'stream_address',
            'tls_server_name',
            'application_version',
        ].every((key) => sourceText(r[key])) ||
        typeof r.tls_pin !== 'string' ||
        !/^[0-9a-f]{64}$/.test(r.tls_pin) ||
        !['not_requested', 'verified', 'unavailable'].includes(
            String(r.stream_state),
        )
    )
        throw Error('Camera source could not be verified.');
    cctvTime(r.verified_at);
    if (r.stream_verified_at !== null) cctvTime(r.stream_verified_at);
    if (r.stream_state === 'verified' && r.stream_verified_at === null)
        throw Error('Camera stream verification is unavailable.');
    return r as CctvSource;
}
export function sourceSetupReply(
    value: unknown,
    actor: number,
    camera: number,
): CctvSourceSetup {
    const r = cctvRecord(value);
    exact(r, [
        'actor_id',
        'camera_id',
        'camera',
        'revision',
        'source',
        'choices',
        'choice',
        'q',
        'collector_id',
    ]);
    if (
        !id(actor) ||
        !id(camera) ||
        r.actor_id !== actor ||
        r.camera_id !== camera ||
        !Number.isSafeInteger(r.revision) ||
        Number(r.revision) < 0 ||
        (r.choice !== null &&
            !['recorders', 'collectors', 'scopes'].includes(
                String(r.choice),
            )) ||
        !sourceText(r.q, 0, 200) ||
        (r.collector_id !== null &&
            (!id(r.collector_id) || r.choice !== 'scopes'))
    )
        throw Error('Camera setup context changed.');
    const identity = cctvRecord(r.camera),
        site = cctvRecord(identity.site);
    exact(identity, ['id', 'name', 'site']);
    exact(site, ['id', 'name']);
    if (
        identity.id !== camera ||
        !sourceText(identity.name) ||
        !id(site.id) ||
        !sourceText(site.name)
    )
        throw Error('Camera identity changed.');
    if (r.source !== null && sourceProjection(r.source).revision !== r.revision)
        throw Error('Camera source revision changed.');
    const choices = cctvRecord(r.choices);
    exact(choices, ['recorders', 'collectors', 'scopes']);
    for (const kind of ['recorders', 'collectors', 'scopes'] as const) {
        const page = cctvRecord(choices[kind]);
        exact(page, ['items', 'limit', 'next_href', 'previous_href']);
        if (
            page.limit !== 20 ||
            !Array.isArray(page.items) ||
            page.items.length > 20
        )
            throw Error('Camera choices changed.');
        const seen = new Set<number>();
        for (const value of page.items) {
            const option = cctvRecord(value);
            exact(
                option,
                kind === 'scopes'
                    ? ['id', 'name', 'collector_id']
                    : ['id', 'name'],
            );
            if (
                !id(option.id) ||
                seen.has(option.id) ||
                !sourceText(option.name) ||
                (kind === 'scopes' &&
                    option.collector_id !== null &&
                    !id(option.collector_id))
            )
                throw Error('Camera choices changed.');
            seen.add(option.id);
        }
        for (const key of ['next_href', 'previous_href']) {
            const href = page[key];
            if (href === null) continue;
            if (!sourceHref(href, camera))
                throw Error('Camera choices destination changed.');
            const query = new URLSearchParams(href.split('?')[1]);
            if (
                query.get('choice') !== kind ||
                !query.has('cursor') ||
                (query.get('q') ?? '') !== (kind === r.choice ? r.q : '') ||
                (query.get('collector_id') ?? null) !==
                    (kind === 'scopes' &&
                    r.choice === 'scopes' &&
                    r.collector_id !== null
                        ? String(r.collector_id)
                        : null)
            )
                throw Error('Camera choice search changed.');
        }
    }
    return r as CctvSourceSetup;
}
export function sourceWriteReply(
    value: unknown,
    actor: number,
    camera: number,
): CctvSource {
    const r = cctvRecord(value);
    exact(r, ['actor_id', 'camera_id', 'source']);
    if (r.actor_id !== actor || r.camera_id !== camera)
        throw Error('Camera setup context changed.');
    return sourceProjection(r.source);
}
export function sameSourceInput(
    source: CctvSource,
    request: CctvSourceInput,
): boolean {
    return (
        source.revision === request.expected_revision + 1 &&
        source.enabled &&
        (
            [
                'recorder_device_id',
                'collector_id',
                'discovery_scope_id',
                'console_id',
                'provider_camera_id',
                'stream_address',
                'stream_port',
                'tls_server_name',
                'tls_pin',
            ] as const
        ).every((key) => source[key] === request[key])
    );
}

export function sourceAddress(value: string): boolean {
    if (/^(?:0|[1-9]\d{0,2})(?:\.(?:0|[1-9]\d{0,2})){3}$/.test(value))
        return value.split('.').every((part) => Number(part) <= 255);
    if (!/^[0-9a-f:]+$/.test(value) || !value.includes(':')) return false;
    try {
        return (
            new URL('https://[' + value + ']/').hostname ===
                '[' + value + ']' && !value.startsWith('::ffff:')
        );
    } catch {
        return false;
    }
}
