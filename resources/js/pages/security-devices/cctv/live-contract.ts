import {
    validRelayCandidate,
    validRelayConnections,
    validRelayPolicy,
    type CctvRelayPolicy,
} from './relay-policy';
export type CctvStatus =
    | 'requested'
    | 'negotiating'
    | 'ready'
    | 'stopped'
    | 'expired'
    | 'revoked'
    | 'failed';
export type CctvReason =
    | 'stopped'
    | 'browser_closed'
    | 'source_unavailable'
    | 'source_changed'
    | 'unsupported_version'
    | 'unsupported_codec'
    | 'worker_unavailable'
    | 'authority_lost'
    | 'lease_expired'
    | 'session_expired'
    | 'capacity_unavailable'
    | 'negotiation_failed'
    | 'transport_unavailable';
export type CctvIceServer = CctvRelayPolicy & {
    urls: string[];
    username: string;
    credential: string;
    expires_at: string;
};
export type CctvSession = {
    uuid: string;
    actor_id: number;
    camera_id: number;
    negotiation_uuid: string | null;
    generation: number;
    status: CctvStatus;
    reason_code: CctvReason | null;
    server_now: string;
    authority_until: string;
    session_expires_at: string;
    ice_expires_at: string | null;
    transport: 'webrtc';
    video_codec: 'H264';
    audio_enabled: false;
    ice_servers: CctvIceServer[];
    answer: { type: 'answer'; sdp: string } | null;
    status_href: string;
    offer_href: string;
    renew_href: string;
    stop_href: string;
};

export const cctvUuid = (value: unknown): value is string =>
    typeof value === 'string' &&
    /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(
        value,
    );
export const activeCctv = (status: CctvStatus) =>
    ['requested', 'negotiating', 'ready'].includes(status);
export function cctvRecord(value: unknown): Record<string, unknown> {
    if (!value || typeof value !== 'object' || Array.isArray(value))
        throw Error('Camera connection could not be verified.');
    return value as Record<string, unknown>;
}
function exact(value: Record<string, unknown>, fields: string[]) {
    if (
        Object.keys(value).length !== fields.length ||
        fields.some((key) => !Object.hasOwn(value, key))
    )
        throw Error('Camera connection changed.');
}
function positive(value: unknown): value is number {
    return (
        typeof value === 'number' && Number.isSafeInteger(value) && value > 0
    );
}
export function cctvTime(value: unknown): number {
    if (
        typeof value !== 'string' ||
        !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(value)
    )
        throw Error('Camera connection time is unavailable.');
    const time = Date.parse(value);
    if (
        !Number.isFinite(time) ||
        new Date(time).toISOString().replace('.000Z', 'Z') !== value
    )
        throw Error('Camera connection time is unavailable.');
    return time;
}
function safeText(value: unknown, max: number): value is string {
    return (
        typeof value === 'string' &&
        value.length > 0 &&
        value.length <= max &&
        !/[\p{Cc}\p{Cf}]/u.test(value)
    );
}
function turnUrl(value: unknown): value is string {
    if (typeof value !== 'string' || value.length > 512) return false;
    const match =
        /^(turns?):(\[[0-9a-fA-F:.]+\]|[a-zA-Z0-9.-]+):(\d{1,5})(?:\?transport=(tcp|udp))?$/.exec(
            value,
        );
    if (
        !match ||
        Number(match[3]) < 1 ||
        Number(match[3]) > 65535 ||
        (match[1] === 'turns' && match[4] === 'udp')
    )
        return false;
    try {
        new URL('https://' + match[2] + ':' + match[3]);
        if (
            !match[2].startsWith('[') &&
            (match[2].length > 253 ||
                match[2]
                    .split('.')
                    .some(
                        (label) =>
                            !/^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/.test(
                                label,
                            ),
                    ))
        )
            return false;
        return true;
    } catch {
        return false;
    }
}

export function validateCctvSdp(
    value: unknown,
    type: 'offer' | 'answer',
    policy: CctvRelayPolicy,
): string {
    if (
        typeof value !== 'string' ||
        new TextEncoder().encode(value).length > 32768 ||
        Array.from(value).some((character) => {
            const code = character.charCodeAt(0);
            return code > 126 || (code < 32 && ![9, 10, 13].includes(code));
        })
    )
        throw Error('Camera video negotiation is unavailable.');
    const lines = value.split(/\r?\n/).filter(Boolean);
    const media = lines.filter((line) => line.startsWith('m='));
    const fingerprints = lines.filter((line) =>
        line.startsWith('a=fingerprint:'),
    );
    const candidates = lines.filter((line) => line.startsWith('a=candidate:'));
    const direction = type === 'offer' ? 'recvonly' : 'sendonly';
    const codecs = lines.filter((line) => line.startsWith('a=rtpmap:'));
    if (
        lines[0] !== 'v=0' ||
        media.length !== 1 ||
        !/^m=video \d+ UDP\/TLS\/RTP\/SAVPF(?: \d+)+$/.test(media[0]) ||
        fingerprints.length !== 1 ||
        !/^a=fingerprint:sha-256 (?:[0-9A-Fa-f]{2}:){31}[0-9A-Fa-f]{2}$/.test(
            fingerprints[0],
        ) ||
        candidates.length < 1 ||
        candidates.some((line) => !validRelayCandidate(line, policy)) ||
        !validRelayConnections(lines, policy) ||
        !lines.includes('a=' + direction) ||
        lines.some((line) =>
            /^a=(?:sendrecv|inactive|sctp-port|sctpmap)(?::|$)/.test(line),
        ) ||
        lines.includes(type === 'offer' ? 'a=sendonly' : 'a=recvonly') ||
        codecs.length < 1 ||
        codecs.some((line) => !/^a=rtpmap:\d+ H264\/90000$/i.test(line)) ||
        !lines.includes('a=rtcp-mux')
    )
        throw Error('Camera video negotiation could not be verified.');
    return value;
}

export function cctvSessionReply(
    value: unknown,
    actor: number,
    camera: number,
    expected?: CctvSession,
): CctvSession {
    const envelope = cctvRecord(value);
    exact(envelope, ['actor_id', 'camera_id', 'session']);
    if (
        !positive(actor) ||
        !positive(camera) ||
        envelope.actor_id !== actor ||
        envelope.camera_id !== camera
    )
        throw Error('Camera viewing context changed.');
    const r = cctvRecord(envelope.session);
    exact(r, [
        'uuid',
        'actor_id',
        'camera_id',
        'negotiation_uuid',
        'generation',
        'status',
        'reason_code',
        'server_now',
        'authority_until',
        'session_expires_at',
        'ice_expires_at',
        'transport',
        'video_codec',
        'audio_enabled',
        'ice_servers',
        'answer',
        'status_href',
        'offer_href',
        'renew_href',
        'stop_href',
    ]);
    const statuses: CctvStatus[] = [
        'requested',
        'negotiating',
        'ready',
        'stopped',
        'expired',
        'revoked',
        'failed',
    ];
    const reasons: CctvReason[] = [
        'stopped',
        'browser_closed',
        'source_unavailable',
        'source_changed',
        'unsupported_version',
        'unsupported_codec',
        'worker_unavailable',
        'authority_lost',
        'lease_expired',
        'session_expired',
        'capacity_unavailable',
        'negotiation_failed',
        'transport_unavailable',
    ];
    if (
        !cctvUuid(r.uuid) ||
        r.actor_id !== actor ||
        r.camera_id !== camera ||
        !positive(r.generation) ||
        (r.negotiation_uuid !== null && !cctvUuid(r.negotiation_uuid)) ||
        !statuses.includes(r.status as CctvStatus) ||
        (r.reason_code !== null &&
            !reasons.includes(r.reason_code as CctvReason)) ||
        r.transport !== 'webrtc' ||
        r.video_codec !== 'H264' ||
        r.audio_enabled !== false
    )
        throw Error('Camera viewing context changed.');
    const now = cctvTime(r.server_now),
        authority = cctvTime(r.authority_until),
        end = cctvTime(r.session_expires_at);
    if (
        authority > now + 15000 ||
        authority > end ||
        end > now + 3600000 ||
        (activeCctv(r.status as CctvStatus) && (authority <= now || end <= now))
    )
        throw Error('Camera viewing permission expired.');
    const base = '/security-devices/cctv/live-sessions/' + r.uuid;
    if (
        r.status_href !== base ||
        r.offer_href !== base + '/offer' ||
        r.renew_href !== base + '/renew' ||
        r.stop_href !== base + '/stop'
    )
        throw Error('Camera connection destination changed.');
    if (!Array.isArray(r.ice_servers) || r.ice_servers.length > 1)
        throw Error('Camera relay is unavailable.');
    for (const value of r.ice_servers) {
        const ice = cctvRecord(value);
        exact(ice, [
            'urls',
            'username',
            'credential',
            'expires_at',
            'relay_cidrs',
            'relay_port_min',
            'relay_port_max',
        ]);
        if (
            !Array.isArray(ice.urls) ||
            ice.urls.length < 1 ||
            ice.urls.length > 4 ||
            !ice.urls.every(turnUrl) ||
            !safeText(ice.username, 256) ||
            !safeText(ice.credential, 256) ||
            !validRelayPolicy(ice)
        )
            throw Error('Camera relay is unavailable.');
        const expiry = cctvTime(ice.expires_at);
        if (
            expiry > end ||
            (activeCctv(r.status as CctvStatus) && expiry <= now)
        )
            throw Error('Camera relay expired.');
    }
    if (
        r.ice_servers.length === 0
            ? r.ice_expires_at !== null
            : r.ice_expires_at !== cctvRecord(r.ice_servers[0]).expires_at
    )
        throw Error('Camera relay expiry changed.');
    if (
        expected &&
        ((!activeCctv(expected.status) && activeCctv(r.status as CctvStatus)) ||
            (r.generation === expected.generation &&
                authority > cctvTime(expected.authority_until)) ||
            (expected.status === 'ready' &&
                activeCctv(r.status as CctvStatus) &&
                r.status !== 'ready') ||
            (expected.answer !== null &&
                activeCctv(r.status as CctvStatus) &&
                JSON.stringify(r.answer) !== JSON.stringify(expected.answer)))
    )
        throw Error('Camera session authority changed.');
    if (r.answer !== null) {
        const answer = cctvRecord(r.answer);
        exact(answer, ['type', 'sdp']);
        if (answer.type !== 'answer' || !cctvUuid(r.negotiation_uuid))
            throw Error('Camera answer changed.');
        if (r.ice_servers.length !== 1)
            throw Error('Camera relay is unavailable.');
        validateCctvSdp(
            answer.sdp,
            'answer',
            r.ice_servers[0] as CctvIceServer,
        );
    }
    if (r.status === 'ready' && (!r.answer || r.ice_servers.length !== 1))
        throw Error('Camera connection is incomplete.');
    if (
        expected &&
        (r.uuid !== expected.uuid ||
            r.generation < expected.generation ||
            r.session_expires_at !== expected.session_expires_at ||
            (expected.negotiation_uuid !== null &&
                r.negotiation_uuid !== expected.negotiation_uuid) ||
            (expected.ice_servers.length > 0 &&
                activeCctv(r.status as CctvStatus) &&
                JSON.stringify(r.ice_servers) !==
                    JSON.stringify(expected.ice_servers)))
    )
        throw Error('Camera session changed.');
    return r as CctvSession;
}

export function cctvDeadline(
    session: CctvSession,
    requestStartedAt: number,
): number {
    const end = Math.min(
        cctvTime(session.authority_until),
        cctvTime(session.session_expires_at),
        ...session.ice_servers.map((ice) => cctvTime(ice.expires_at)),
    );
    return requestStartedAt + Math.max(0, end - cctvTime(session.server_now));
}
