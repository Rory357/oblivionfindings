import {
    hikBase,
    hikDirectoryReply,
    hikIdentityReply,
    type HikChoice,
    type HikIdentity,
    type HikPage,
} from './_hikvision-contract';
import { sourceAddress, sourceText } from './_source-contract';
import { cctvRecord, cctvTime, cctvUuid } from './live-contract';

export type RecorderSetup = {
    provider: 'hikvision_recorder';
    actor_id: number;
    recorder_id: number;
    recorder: {
        id: number;
        name: string;
        site: { id: number; name: string };
        model: string | null;
        serial: string | null;
        ip_address: string | null;
    };
    revision: 0;
    readiness: { can_discover: boolean };
    choices: Record<HikChoice, HikPage>;
    choice: HikChoice | null;
    q: string;
    collector_id: number | null;
    recent_requests: {
        request_uuid: string;
        status: string;
        created_at: string;
        reader_href: string;
    }[];
};
export type RecorderInput = {
    id: string;
    proxy_protocol: string;
    addressing_type: 'ipaddress' | 'hostname';
    address: string | null;
    management_port: number | null;
    source_input_id: string | null;
    online: boolean | null;
    camera_identity: null;
    association: 'unverified';
};
export type RecorderChannel = {
    id: string;
    input_id: string;
    input_kind: 'digital_proxy' | 'local_video';
    name: string;
    codec: string;
    enabled: boolean;
    video_enabled: boolean;
    width: number;
    height: number;
    fps100: number;
    audio_enabled: boolean | null;
};
export type RecorderDiscovery = {
    actor_id: number;
    request_uuid: string;
    device_id: number;
    site_id: number;
    source_revision: 0;
    status: string;
    reason_code: string | null;
    current_context: boolean;
    execution_enabled: boolean;
    created_at: string;
    observed_at: string | null;
    inventory: {
        identity: HikIdentity;
        channels: RecorderChannel[];
        inputs: RecorderInput[];
        response_sha256: Record<
            'device_info' | 'streaming_proxy' | 'input_status',
            string
        >;
    } | null;
    reader_href: string;
};
const statuses = [
    'queued',
    'preparing',
    'dispatched',
    'observed',
    'failed',
    'revoked',
    'expired',
];
const id = (v: unknown): v is number =>
    typeof v === 'number' && Number.isSafeInteger(v) && v > 0;
const identifier = (v: unknown): v is string =>
    typeof v === 'string' && /^[1-9]\d{0,8}$/.test(v);
const text = (v: unknown, max = 128) => v === null || sourceText(v, 0, max);
const hash = (v: unknown) => typeof v === 'string' && /^[a-f0-9]{64}$/.test(v);
function reportedAddress(value: string, kind: unknown): boolean {
    if (kind === 'hostname')
        return /^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/.test(value);
    if (/^(?:0|[1-9]\d{0,2})(?:\.(?:0|[1-9]\d{0,2})){3}$/.test(value))
        return value.split('.').every((part) => Number(part) <= 255);
    if (!/^[0-9a-fA-F:.]+$/.test(value) || !value.includes(':')) return false;
    try {
        return new URL('https://[' + value + ']/').hostname.startsWith('[');
    } catch {
        return false;
    }
}
function requireValue(v: unknown): asserts v {
    if (!v) throw Error('Recorder information could not be verified.');
}
function exact(v: Record<string, unknown>, keys: string[]) {
    requireValue(
        Object.keys(v).length === keys.length &&
            keys.every((k) => Object.hasOwn(v, k)),
    );
}
function list(v: unknown): Record<string, unknown>[] {
    requireValue(Array.isArray(v) && v.length <= 256);
    return v.map(cctvRecord);
}
export function recorderSetupReply(
    value: unknown,
    actor: number,
    device: number,
): RecorderSetup {
    const v = cctvRecord(value);
    exact(v, [
        'provider',
        'actor_id',
        'recorder_id',
        'recorder',
        'revision',
        'readiness',
        'choices',
        'choice',
        'q',
        'collector_id',
        'recent_requests',
    ]);
    requireValue(
        id(actor) &&
            id(device) &&
            v.provider === 'hikvision_recorder' &&
            v.actor_id === actor &&
            v.recorder_id === device &&
            v.revision === 0,
    );
    const d = cctvRecord(v.recorder),
        site = cctvRecord(d.site);
    exact(d, ['id', 'name', 'site', 'model', 'serial', 'ip_address']);
    exact(site, ['id', 'name']);
    requireValue(
        d.id === device &&
            sourceText(d.name) &&
            id(site.id) &&
            sourceText(site.name) &&
            text(d.model) &&
            text(d.serial) &&
            (d.ip_address === null || sourceAddress(d.ip_address as string)),
    );
    const r = cctvRecord(v.readiness);
    exact(r, ['can_discover']);
    requireValue(typeof r.can_discover === 'boolean');
    requireValue(
        (v.choice === null ||
            ['collectors', 'scopes', 'credentials'].includes(
                v.choice as string,
            )) &&
            sourceText(v.q, 0, 200) &&
            (v.collector_id === null ||
                (v.choice === 'scopes' && id(v.collector_id))),
    );
    hikDirectoryReply(
        v.choices,
        device,
        v as Pick<RecorderSetup, 'choice' | 'q' | 'collector_id'>,
    );
    requireValue(
        Array.isArray(v.recent_requests) && v.recent_requests.length <= 10,
    );
    const seen = new Set<string>();
    for (const raw of v.recent_requests) {
        const item = cctvRecord(raw);
        exact(item, ['request_uuid', 'status', 'created_at', 'reader_href']);
        requireValue(
            cctvUuid(item.request_uuid) &&
                !seen.has(item.request_uuid as string) &&
                statuses.includes(item.status as string) &&
                item.reader_href ===
                    '/security-devices/cctv/hikvision-discoveries/' +
                        item.request_uuid,
        );
        cctvTime(item.created_at);
        seen.add(item.request_uuid as string);
    }
    return v as RecorderSetup;
}
export function recorderDiscoveryReply(
    value: unknown,
    setup: RecorderSetup,
    uuid?: string,
): RecorderDiscovery {
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
            v.device_id === setup.recorder_id &&
            v.site_id === setup.recorder.site.id &&
            v.source_revision === 0 &&
            cctvUuid(v.request_uuid) &&
            (!uuid || v.request_uuid === uuid) &&
            statuses.includes(v.status as string) &&
            text(v.reason_code, 64) &&
            typeof v.current_context === 'boolean' &&
            typeof v.execution_enabled === 'boolean' &&
            v.reader_href ===
                '/security-devices/cctv/hikvision-discoveries/' +
                    v.request_uuid,
    );
    cctvTime(v.created_at);
    if (v.observed_at !== null) cctvTime(v.observed_at);
    if (v.inventory !== null) {
        const inventory = cctvRecord(v.inventory);
        exact(inventory, ['identity', 'channels', 'inputs', 'response_sha256']);
        hikIdentityReply(inventory.identity);
        const channels = list(inventory.channels),
            inputs = list(inventory.inputs);
        requireValue(
            new Set(channels.map((c) => c.id)).size === channels.length &&
                new Set(inputs.map((i) => i.id)).size === inputs.length,
        );
        for (const c of channels) {
            exact(c, [
                'id',
                'input_id',
                'input_kind',
                'name',
                'codec',
                'enabled',
                'video_enabled',
                'width',
                'height',
                'fps100',
                'audio_enabled',
            ]);
            requireValue(
                identifier(c.id) &&
                    identifier(c.input_id) &&
                    ['digital_proxy', 'local_video'].includes(
                        c.input_kind as string,
                    ) &&
                    sourceText(c.name, 0, 128) &&
                    sourceText(c.codec, 1, 32) &&
                    typeof c.enabled === 'boolean' &&
                    typeof c.video_enabled === 'boolean' &&
                    (c.audio_enabled === null ||
                        typeof c.audio_enabled === 'boolean') &&
                    id(c.width) &&
                    c.width <= 16384 &&
                    id(c.height) &&
                    c.height <= 16384 &&
                    Number.isInteger(c.fps100) &&
                    (c.fps100 as number) >= 0 &&
                    (c.fps100 as number) <= 12000,
            );
        }
        for (const i of inputs) {
            exact(i, [
                'id',
                'proxy_protocol',
                'addressing_type',
                'address',
                'management_port',
                'source_input_id',
                'online',
                'camera_identity',
                'association',
            ]);
            requireValue(
                identifier(i.id) &&
                    sourceText(i.proxy_protocol, 1, 32) &&
                    ['ipaddress', 'hostname'].includes(
                        i.addressing_type as string,
                    ) &&
                    (i.address === null ||
                        (sourceText(i.address, 1, 253) &&
                            reportedAddress(
                                i.address as string,
                                i.addressing_type,
                            ))) &&
                    (i.management_port === null ||
                        (Number.isInteger(i.management_port) &&
                            (i.management_port as number) >= 0 &&
                            (i.management_port as number) <= 65535)) &&
                    (i.source_input_id === null ||
                        i.source_input_id === '0' ||
                        identifier(i.source_input_id)) &&
                    (i.online === null || typeof i.online === 'boolean') &&
                    i.camera_identity === null &&
                    i.association === 'unverified',
            );
        }
        const hashes = cctvRecord(inventory.response_sha256);
        exact(hashes, ['device_info', 'streaming_proxy', 'input_status']);
        requireValue(Object.values(hashes).every(hash));
    }
    requireValue(
        v.status !== 'observed' ||
            (v.inventory !== null &&
                v.observed_at !== null &&
                v.reason_code === null),
    );
    return v as RecorderDiscovery;
}
export const recorderSetupHref = (id: number) => hikBase(id) + '/setup';
