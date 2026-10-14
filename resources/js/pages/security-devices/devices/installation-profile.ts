import { installationRequest } from '@/components/security-devices/installation-transport';
export type InstallationOptions = {
    actor_id: number;
    device_id: number;
    device_label: string;
    device_uid: string;
    site: { id: number; name: string };
    platform: 'windows';
    configured: boolean;
    server_url: string | null;
    organisation: string | null;
    enrolment_minutes: number;
    context_hash: string;
    enrolment: {
        state: 'none' | 'pending' | 'expired' | 'enrolled' | 'revoked';
        uuid: string | null;
        expires_at: string | null;
    };
};
type Profile = {
    version: number;
    platform: string;
    server_url: string;
    organisation: string;
    device_label: string;
    device_uid: string;
    agent_uuid: string;
    server_public_key: string;
    enrolment_token: string;
    expires_at: string;
};
const uuid = (v: unknown): v is string =>
    typeof v === 'string' &&
    /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(
        v,
    );
const label = (v: unknown): v is string =>
    typeof v === 'string' &&
    v.length > 0 &&
    v.length <= 300 &&
    !/[\p{Cc}\p{Cf}]/u.test(v);
function record(v: unknown): Record<string, unknown> {
    if (!v || typeof v !== 'object' || Array.isArray(v))
        throw Error('Invalid installation details');
    return v as Record<string, unknown>;
}
export function installationOptions(
    value: unknown,
    deviceId: number,
    actorId: number,
): InstallationOptions {
    const r = record(value),
        site = record(r.site),
        enrolment = record(r.enrolment);
    if (
        r.device_id !== deviceId ||
        r.actor_id !== actorId ||
        r.platform !== 'windows' ||
        !label(r.device_label) ||
        !label(r.device_uid) ||
        !label(site.name) ||
        !Number.isSafeInteger(site.id) ||
        (site.id as number) < 1 ||
        typeof r.configured !== 'boolean' ||
        !Number.isInteger(r.enrolment_minutes) ||
        (r.enrolment_minutes as number) < 1 ||
        (r.enrolment_minutes as number) > 30 ||
        typeof r.context_hash !== 'string' ||
        !/^[a-f0-9]{64}$/.test(r.context_hash) ||
        !['none', 'pending', 'expired', 'enrolled', 'revoked'].includes(
            enrolment.state as string,
        ) ||
        (enrolment.state === 'none'
            ? enrolment.uuid !== null
            : !uuid(enrolment.uuid)) ||
        (enrolment.expires_at !== null &&
            (typeof enrolment.expires_at !== 'string' ||
                !Number.isFinite(Date.parse(enrolment.expires_at))))
    )
        throw Error('Installation context changed');
    if (r.configured) {
        if (!label(r.organisation) || typeof r.server_url !== 'string')
            throw Error('Invalid destination');
        const url = new URL(r.server_url);
        if (
            url.protocol !== 'https:' ||
            url.username ||
            url.password ||
            url.search ||
            url.hash ||
            url.pathname !== '/' ||
            r.server_url !== r.server_url.trim() ||
            /[\s\\\\]/.test(r.server_url)
        )
            throw Error('Invalid destination');
    } else if (r.server_url !== null || r.organisation !== null)
        throw Error('Invalid destination');
    return r as InstallationOptions;
}
export function installationProfile(
    value: unknown,
    options: InstallationOptions,
): Profile {
    const r = record(value);
    const keys = [
        'version',
        'platform',
        'server_url',
        'organisation',
        'device_label',
        'device_uid',
        'agent_uuid',
        'server_public_key',
        'enrolment_token',
        'expires_at',
    ];
    if (
        Object.keys(r).length !== keys.length ||
        keys.some((k) => !(k in r)) ||
        r.version !== 1 ||
        r.platform !== 'windows' ||
        r.server_url !== options.server_url ||
        r.organisation !== options.organisation ||
        r.device_label !== options.device_label ||
        r.device_uid !== options.device_uid ||
        !uuid(r.agent_uuid) ||
        typeof r.enrolment_token !== 'string' ||
        !/^[a-f0-9]{64}$/.test(r.enrolment_token) ||
        typeof r.server_public_key !== 'string' ||
        !/^[A-Za-z0-9+/]{43}=$/.test(r.server_public_key) ||
        typeof r.expires_at !== 'string' ||
        !Number.isFinite(Date.parse(r.expires_at)) ||
        Date.parse(r.expires_at) <= Date.now() ||
        Date.parse(r.expires_at) > Date.now() + 31 * 60000
    )
        throw Error(
            'The installation profile does not match the reviewed computer',
        );
    return r as Profile;
}

export async function installationJSON(
    deviceId: number,
    action: 'bootstrap-options' | 'bootstrap' | 'revoke',
    signal: AbortSignal,
    data?: unknown,
): Promise<unknown> {
    if (!Number.isSafeInteger(deviceId) || deviceId < 1)
        throw Error('Invalid computer');
    return installationRequest(
        '/security-devices/devices/' + deviceId + '/endpoint/' + action,
        signal,
        data,
    );
}
