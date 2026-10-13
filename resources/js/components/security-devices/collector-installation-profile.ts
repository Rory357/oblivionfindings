import { installationRequest } from './installation-transport';

export type CollectorPlatform = 'windows' | 'linux';
export type CollectorSetupSite = { id: number; name: string };
export type CollectorSetupOptions = {
    platform: CollectorPlatform;
    site_id: number;
    site_label: string;
    deployment_url: string | null;
    server_url: string | null;
    organisation: string | null;
    enrolment_minutes: number | null;
    configured: boolean;
    context_hash: string;
};
export type CollectorSetupProfile = {
    version: 1;
    component: 'site';
    platform: CollectorPlatform;
    deployment_url: string;
    server_url: string;
    server_public_key: string;
    tls_public_key_pin: string;
    organisation: string;
    site_id: number;
    site_label: string;
    collector_uuid: string;
    enrolment_token: string;
    expires_at: string;
};
function object(value: unknown): Record<string, unknown> {
    if (!value || typeof value !== 'object' || Array.isArray(value))
        throw Error('Invalid setup details');
    return value as Record<string, unknown>;
}
function label(value: unknown): value is string {
    return (
        typeof value === 'string' &&
        value.length > 0 &&
        [...value].length <= 300 &&
        value === value.trim() &&
        !/[\p{Cc}\p{Cf}]/u.test(value)
    );
}
function origin(value: unknown): value is string {
    if (
        typeof value !== 'string' ||
        value !== value.trim() ||
        /[\s\\]/.test(value) ||
        value.endsWith('/')
    )
        return false;
    try {
        const url = new URL(value);
        return (
            url.protocol === 'https:' &&
            !url.username &&
            !url.password &&
            !url.search &&
            !url.hash &&
            url.pathname === '/'
        );
    } catch {
        return false;
    }
}
function key(value: unknown): value is string {
    if (typeof value !== 'string' || !/^[A-Za-z0-9+/]{43}=$/.test(value))
        return false;
    try {
        return atob(value).length === 32 && btoa(atob(value)) === value;
    } catch {
        return false;
    }
}
export function collectorSetupOptions(
    value: unknown,
    siteId: number,
    platform: CollectorPlatform,
): CollectorSetupOptions {
    const r = object(value);
    if (
        !Number.isSafeInteger(siteId) ||
        siteId < 1 ||
        r.site_id !== siteId ||
        r.platform !== platform ||
        !label(r.site_label) ||
        typeof r.configured !== 'boolean' ||
        typeof r.context_hash !== 'string' ||
        !/^[a-f0-9]{64}$/.test(r.context_hash)
    )
        throw Error('Setup context changed');
    if (r.configured) {
        if (
            !origin(r.deployment_url) ||
            !origin(r.server_url) ||
            !label(r.organisation) ||
            !Number.isInteger(r.enrolment_minutes) ||
            (r.enrolment_minutes as number) < 1 ||
            (r.enrolment_minutes as number) > 30
        )
            throw Error('Invalid setup destination');
    } else if (
        r.deployment_url !== null ||
        r.server_url !== null ||
        r.organisation !== null ||
        r.enrolment_minutes !== null
    )
        throw Error('Invalid setup details');
    return r as CollectorSetupOptions;
}
export function collectorSetupProfile(
    value: unknown,
    review: CollectorSetupOptions,
): CollectorSetupProfile {
    const r = object(value);
    const fields = [
        'version',
        'component',
        'platform',
        'deployment_url',
        'server_url',
        'server_public_key',
        'tls_public_key_pin',
        'organisation',
        'site_id',
        'site_label',
        'collector_uuid',
        'enrolment_token',
        'expires_at',
    ];
    if (
        !review.configured ||
        Object.keys(r).length !== fields.length ||
        fields.some((k) => !(k in r)) ||
        r.version !== 1 ||
        r.component !== 'site' ||
        r.platform !== review.platform ||
        r.deployment_url !== review.deployment_url ||
        r.server_url !== review.server_url ||
        r.organisation !== review.organisation ||
        r.site_id !== review.site_id ||
        r.site_label !== review.site_label ||
        !key(r.server_public_key) ||
        typeof r.tls_public_key_pin !== 'string' ||
        !r.tls_public_key_pin.startsWith('sha256//') ||
        !key(r.tls_public_key_pin.slice(8)) ||
        typeof r.collector_uuid !== 'string' ||
        !/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(
            r.collector_uuid,
        ) ||
        typeof r.enrolment_token !== 'string' ||
        !/^ofc_enrol_[A-Za-z0-9_-]{64}$/.test(r.enrolment_token) ||
        typeof r.expires_at !== 'string' ||
        !Number.isFinite(Date.parse(r.expires_at)) ||
        Date.parse(r.expires_at) <= Date.now() ||
        Date.parse(r.expires_at) > Date.now() + 31 * 60000
    )
        throw Error('Profile does not match reviewed setup');
    return r as CollectorSetupProfile;
}
export async function loadCollectorSetup(
    siteId: number,
    platform: CollectorPlatform,
    signal: AbortSignal,
) {
    if (
        !Number.isSafeInteger(siteId) ||
        siteId < 1 ||
        !['windows', 'linux'].includes(platform)
    )
        throw Error('Invalid Site');
    return collectorSetupOptions(
        await installationRequest(
            '/security-devices/discovery/collectors/installation-options?site_id=' +
                siteId +
                '&platform=' +
                platform,
            signal,
        ),
        siteId,
        platform,
    );
}
export async function issueCollectorSetup(
    review: CollectorSetupOptions,
    signal: AbortSignal,
) {
    return collectorSetupProfile(
        await installationRequest(
            '/security-devices/discovery/collectors/installation-profile',
            signal,
            {
                site_id: review.site_id,
                platform: review.platform,
                context_hash: review.context_hash,
            },
        ),
        review,
    );
}
