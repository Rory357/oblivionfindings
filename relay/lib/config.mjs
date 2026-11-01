// Oblivion Relay settings (spec 10.2), read from the environment and checked at start. A wrong
// value stops start-up with a message that names the setting; a secret's value is never shown.
// Tests pass `overrides` for ephemeral ports, short timings and temporary directories.

import { constants, X509Certificate } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { networkInterfaces } from 'node:os';
import { createSecureContext } from 'node:tls';
import {
    CidrList,
    isLoopback,
    isUnspecified,
    normalizeIp,
    parseCidr,
    parseIp,
} from './addresses.mjs';
import { cryptoAvailable } from './credentials.mjs';
import { parseAllowlist } from './peers.mjs';

export const VERSION = '1.0.0';

export class ConfigError extends Error {
    constructor(setting, message) {
        super(`${setting}: ${message}`);
        this.setting = setting;
    }
}

// Version 1 serves Chat only. The cctv and remote classes are recorded for later (spec 3.2).
export const CLASS_DEFAULTS = Object.freeze({
    chat: {
        tags: ['chat'],
        maxCredentialSeconds: 3600,
        graceSeconds: 14_400,
        peers: 'public',
        maxAllocations: 48,
        allocationKbps: 4000,
        idKbps: 16_000,
    },
});
export const DEFERRED_CLASSES = Object.freeze({
    cctv: ['cctv'],
    remote: ['agent', 'technician'],
});

const PRIVATE_CONTROL = new CidrList(
    [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '100.64.0.0/10',
        'fc00::/7',
    ].map(parseCidr),
);
const LOOPBACK = new CidrList(['127.0.0.0/8', '::1/128'].map(parseCidr));

const SETTINGS = new Set([
    'RELAY_REALM',
    'RELAY_LISTEN',
    'RELAY_LISTEN_IP',
    'RELAY_EXTERNAL_IP',
    'RELAY_LISTEN_IPV6',
    'RELAY_EXTERNAL_IPV6',
    'RELAY_PORT_MIN',
    'RELAY_PORT_MAX',
    'RELAY_TLS_CERT_FILE',
    'RELAY_TLS_KEY_FILE',
    'RELAY_TRUSTED_PROXIES',
    'RELAY_CHAT_SECRET',
    'RELAY_CHAT_SECRET_FILE',
    'RELAY_CHAT_PREVIOUS_SECRET',
    'RELAY_CHAT_PREVIOUS_SECRET_FILE',
    'RELAY_CHAT_MAX_CREDENTIAL_SECONDS',
    'RELAY_CHAT_GRACE_SECONDS',
    'RELAY_CHAT_PEERS',
    'RELAY_CHAT_ALLOWED_PEER_CIDRS',
    'RELAY_CHAT_MAX_ALLOCATIONS',
    'RELAY_CHAT_ALLOCATION_KBPS',
    'RELAY_CHAT_ID_KBPS',
    'RELAY_MAX_ALLOCATIONS',
    'RELAY_MAX_ALLOCATIONS_PER_IP',
    'RELAY_MAX_PERMISSIONS',
    'RELAY_MAX_CHANNELS',
    'RELAY_TOTAL_MBPS',
    'RELAY_MAX_CONNECTIONS',
    'RELAY_MAX_CONNECTIONS_PER_IP',
    'RELAY_UNAUTH_PER_IP_PER_SECOND',
    'RELAY_UNAUTH_PER_SECOND',
    'RELAY_AUTH_FAILURES_PER_MINUTE',
    'RELAY_DENIED_PEER_PORTS',
    'RELAY_MAX_LIFETIME_SECONDS',
    'RELAY_NONCE_SECONDS',
    'RELAY_CLOCK_SKEW_SECONDS',
    'RELAY_STUN_BINDING',
    'RELAY_HEALTH_LISTEN',
    'RELAY_LOG_LEVEL',
    'RELAY_LOG_DIR',
    'RELAY_LOG_RETENTION_DAYS',
    'RELAY_DENY_FILE',
    'RELAY_CONTROL_LISTEN',
    'RELAY_CONTROL_ALLOWED_SOURCES',
    'RELAY_CONTROL_SECRET',
    'RELAY_CONTROL_SECRET_FILE',
    'RELAY_CONTROL_KEY_ID',
    'RELAY_CONTROL_PREVIOUS_SECRET',
    'RELAY_CONTROL_PREVIOUS_SECRET_FILE',
    'RELAY_CONTROL_PREVIOUS_KEY_ID',
    'RELAY_STATE_DIR',
]);

const TIMINGS = Object.freeze({
    sweepMs: 1000,
    proxyHeaderMs: 5000,
    tlsHandshakeMs: 10_000,
    idleConnectionMs: 30_000,
    responseCacheMs: 40_000,
    permissionMs: 300_000,
    channelMs: 600_000,
    channelReserveMs: 300_000,
    logSweepMs: 3_600_000,
    closeMs: 5000,
});

const text = (env, name, fallback = '') => {
    const value = env[name];
    return value === undefined || value.trim() === '' ? fallback : value.trim();
};

function integer(env, name, fallback, min, max) {
    const raw = text(env, name);
    if (raw === '') return fallback;
    if (!/^\d+$/.test(raw))
        throw new ConfigError(name, 'must be a whole number');
    const value = Number(raw);
    if (value < min || value > max)
        throw new ConfigError(name, `must be from ${min} to ${max}`);
    return value;
}

function ipv4(env, name, fallback, required = false) {
    const raw = text(env, name, fallback);
    if (raw === '') {
        if (required) throw new ConfigError(name, 'is required');
        return '';
    }
    const ip = parseIp(raw);
    if (!ip || ip.family !== 4 || raw.includes('['))
        throw new ConfigError(name, 'must be a literal IPv4 address');
    return normalizeIp(raw);
}

function ipv6(env, name) {
    const raw = text(env, name);
    if (raw === '') return '';
    const ip = parseIp(raw);
    if (!ip || ip.family !== 6)
        throw new ConfigError(name, 'must be a literal IPv6 address');
    return normalizeIp(raw);
}

function hostPort(raw, name) {
    const match = /^(\[[0-9a-fA-F:.]+\]|[0-9.]+):(\d{1,5})$/.exec(raw);
    const host = match ? normalizeIp(match[1].replace(/^\[|\]$/g, '')) : null;
    const port = match ? Number(match[2]) : NaN;
    if (!host || !(port >= 1 && port <= 65535)) {
        throw new ConfigError(
            name,
            'must be an IP address and port, such as 127.0.0.1:9478',
        );
    }
    return { host, port };
}

function listeners(raw) {
    const name = 'RELAY_LISTEN';
    const entries = raw
        .split(',')
        .map((entry) => entry.trim())
        .filter(Boolean);
    if (entries.length === 0)
        throw new ConfigError(name, 'must name at least one listener');
    const seen = new Set();
    return entries.map((entry) => {
        const match =
            /^(udp|tcp|tls|tcp\+tls):\/\/(.+?)(\?proxy=required)?$/.exec(entry);
        if (!match)
            throw new ConfigError(name, `has an invalid listener: ${entry}`);
        const { host, port } = hostPort(match[2], name);
        const transport = match[1];
        const proxy = !!match[3];
        if (proxy && transport === 'udp')
            throw new ConfigError(
                name,
                'never accepts the PROXY protocol over UDP',
            );
        const key = `${transport === 'udp' ? 'udp' : 'tcp'}:${host}:${port}`;
        if (seen.has(key))
            throw new ConfigError(
                name,
                `lists the same address twice: ${entry}`,
            );
        seen.add(key);
        return { transport, host, port, proxy, loopback: isLoopback(host) };
    });
}

function ports(env, name, fallback) {
    const raw = text(env, name, fallback);
    const values = raw
        .split(',')
        .map((entry) => entry.trim())
        .filter(Boolean);
    const set = new Set();
    for (const value of values) {
        if (
            !/^\d{1,5}$/.test(value) ||
            Number(value) < 1 ||
            Number(value) > 65535
        ) {
            throw new ConfigError(
                name,
                'must list port numbers from 1 to 65535',
            );
        }
        set.add(Number(value));
    }
    return set;
}

function cidrs(env, name, fallback) {
    try {
        return CidrList.parse(text(env, name, fallback), name);
    } catch (error) {
        throw new ConfigError(name, error.message.replace(`${name} `, ''));
    }
}

function secretSource(env, name) {
    const value = env[name];
    const file = env[`${name}_FILE`];
    const hasValue = value !== undefined && value !== '';
    const hasFile = file !== undefined && file.trim() !== '';
    if (hasValue && hasFile)
        throw new ConfigError(name, `and ${name}_FILE are both set; use one`);
    if (hasFile) return { name: `${name}_FILE`, file: file.trim() };
    if (hasValue) return { name, value };
    return null;
}

function readSecret(source) {
    if (!source) return null;
    let bytes;
    if (source.file) {
        try {
            bytes = readFileSync(source.file);
        } catch {
            throw new ConfigError(
                source.name,
                'names a file that cannot be read',
            );
        }
        // A trailing newline from an editor or `echo` isn't part of the secret. An emptied file
        // means the secret is cleared: how rotation ends with the _FILE form (spec 3.4 and 3.7).
        bytes = Buffer.from(
            bytes.toString('utf8').replace(/\r?\n$/, ''),
            'utf8',
        );
        if (bytes.length === 0) return null;
    } else {
        bytes = Buffer.from(source.value, 'utf8');
    }
    if (bytes.length < 32 || bytes.length > 512)
        throw new ConfigError(source.name, 'must be 32 to 512 bytes');
    return bytes;
}

/**
 * Read every secret (values and files). Runs at start and on SIGHUP; only file-based secrets can
 * change on reload. Throws ConfigError, never with a secret's value.
 */
export function loadSecrets(config) {
    const sources = config.secretSources;
    const chat = {
        current: readSecret(sources.chat.current),
        previous: readSecret(sources.chat.previous),
    };
    const control = {
        current: readSecret(sources.control.current),
        previous: readSecret(sources.control.previous),
    };
    if (chat.previous && !chat.current) {
        throw new ConfigError(
            'RELAY_CHAT_PREVIOUS_SECRET',
            'needs RELAY_CHAT_SECRET as well',
        );
    }
    if (chat.previous && chat.current.equals(chat.previous)) {
        throw new ConfigError(
            'RELAY_CHAT_PREVIOUS_SECRET',
            'must differ from RELAY_CHAT_SECRET',
        );
    }
    if (chat.current && !control.current) {
        throw new ConfigError(
            'RELAY_CONTROL_SECRET',
            'is required while the chat class is on (spec 3.7)',
        );
    }
    if (control.previous && !control.current) {
        throw new ConfigError(
            'RELAY_CONTROL_PREVIOUS_SECRET',
            'needs RELAY_CONTROL_SECRET as well',
        );
    }
    if (control.previous && control.current.equals(control.previous)) {
        throw new ConfigError(
            'RELAY_CONTROL_PREVIOUS_SECRET',
            'must differ from RELAY_CONTROL_SECRET',
        );
    }
    const credentialSecrets = [chat.current, chat.previous].filter(Boolean);
    for (const [slot, secret] of [
        ['RELAY_CONTROL_SECRET', control.current],
        ['RELAY_CONTROL_PREVIOUS_SECRET', control.previous],
    ]) {
        if (secret && credentialSecrets.some((other) => other.equals(secret))) {
            throw new ConfigError(
                slot,
                'must differ from every credential secret',
            );
        }
    }
    if (control.current && !config.controlKeyId) {
        throw new ConfigError(
            'RELAY_CONTROL_KEY_ID',
            'is required with RELAY_CONTROL_SECRET',
        );
    }
    if (
        control.previous &&
        (!config.controlPreviousKeyId ||
            config.controlPreviousKeyId === config.controlKeyId)
    ) {
        throw new ConfigError(
            'RELAY_CONTROL_PREVIOUS_KEY_ID',
            'must name the previous control key, different from RELAY_CONTROL_KEY_ID',
        );
    }
    return { chat, control };
}

// Spec 8: TLS 1.2 or 1.3 only; TLS 1.2 with ECDHE and AES-GCM or ChaCha20-Poly1305; no renegotiation.
const TLS_CIPHERS = [
    'TLS_AES_128_GCM_SHA256',
    'TLS_AES_256_GCM_SHA384',
    'TLS_CHACHA20_POLY1305_SHA256',
    'ECDHE-ECDSA-AES128-GCM-SHA256',
    'ECDHE-RSA-AES128-GCM-SHA256',
    'ECDHE-ECDSA-AES256-GCM-SHA384',
    'ECDHE-RSA-AES256-GCM-SHA384',
    'ECDHE-ECDSA-CHACHA20-POLY1305',
    'ECDHE-RSA-CHACHA20-POLY1305',
].join(':');

export function tlsContext(cert, key) {
    return createSecureContext({
        cert,
        key,
        minVersion: 'TLSv1.2',
        ciphers: TLS_CIPHERS,
        honorCipherOrder: true,
        secureOptions: constants.SSL_OP_NO_RENEGOTIATION ?? 0,
    });
}

/** Read the PEM chain and key. Runs at start and on SIGHUP. */
export function loadTls(config) {
    if (!config.tlsFiles) return null;
    let cert;
    let key;
    try {
        cert = readFileSync(config.tlsFiles.cert);
    } catch {
        throw new ConfigError(
            'RELAY_TLS_CERT_FILE',
            'names a file that cannot be read',
        );
    }
    try {
        key = readFileSync(config.tlsFiles.key);
    } catch {
        throw new ConfigError(
            'RELAY_TLS_KEY_FILE',
            'names a file that cannot be read',
        );
    }
    try {
        return {
            context: tlsContext(cert, key),
            notAfter: new X509Certificate(cert).validTo,
        };
    } catch {
        throw new ConfigError(
            'RELAY_TLS_CERT_FILE',
            'and RELAY_TLS_KEY_FILE do not form a usable certificate and key',
        );
    }
}

/** Build the runtime configuration. */
export function readConfig(env = process.env, overrides = {}) {
    for (const key of Object.keys(env)) {
        if (/^RELAY_(CCTV|REMOTE)_/.test(key)) {
            throw new ConfigError(
                key,
                'is set, but the cctv and remote classes are deferred in version 1 (spec 3.2)',
            );
        }
        if (key.startsWith('RELAY_') && !SETTINGS.has(key))
            throw new ConfigError(key, 'is not an Oblivion Relay setting');
    }
    if (!cryptoAvailable()) {
        throw new ConfigError(
            'crypto',
            'MD5 and HMAC-SHA1 are unavailable (a FIPS-only build); browsers need both',
        );
    }

    const realm = text(env, 'RELAY_REALM');
    if (!realm) throw new ConfigError('RELAY_REALM', 'is required');
    if (!/^[A-Za-z0-9.-]{1,32}$/.test(realm)) {
        throw new ConfigError(
            'RELAY_REALM',
            'must be a DNS name of at most 32 characters',
        );
    }

    const listen = listeners(
        text(
            env,
            'RELAY_LISTEN',
            'udp://0.0.0.0:3478,tcp://0.0.0.0:3478,tcp+tls://0.0.0.0:443',
        ),
    );
    const listenIp = ipv4(env, 'RELAY_LISTEN_IP', '0.0.0.0');
    const externalIp = ipv4(env, 'RELAY_EXTERNAL_IP', '', true);
    if (isUnspecified(externalIp))
        throw new ConfigError('RELAY_EXTERNAL_IP', 'must be a usable address');
    const listenIpv6 = ipv6(env, 'RELAY_LISTEN_IPV6');
    const externalIpv6 = ipv6(env, 'RELAY_EXTERNAL_IPV6');
    if (!!listenIpv6 !== !!externalIpv6) {
        throw new ConfigError(
            listenIpv6 ? 'RELAY_EXTERNAL_IPV6' : 'RELAY_LISTEN_IPV6',
            'must be set together with its pair',
        );
    }
    if (externalIpv6 && isUnspecified(externalIpv6))
        throw new ConfigError(
            'RELAY_EXTERNAL_IPV6',
            'must be a usable address',
        );

    const portMin = integer(env, 'RELAY_PORT_MIN', 49_152, 1024, 65_535);
    const portMax = integer(env, 'RELAY_PORT_MAX', 65_535, 1024, 65_535);
    if (portMin > portMax)
        throw new ConfigError(
            'RELAY_PORT_MIN',
            'must not be above RELAY_PORT_MAX',
        );
    const rangeSize = portMax - portMin + 1;
    const maxAllocationsSet = text(env, 'RELAY_MAX_ALLOCATIONS') !== '';
    const maxAllocations = integer(
        env,
        'RELAY_MAX_ALLOCATIONS',
        Math.min(4000, rangeSize),
        1,
        65_535,
    );
    if (maxAllocationsSet && maxAllocations > rangeSize) {
        throw new ConfigError(
            'RELAY_MAX_ALLOCATIONS',
            'is more than the relayed port range (RELAY_PORT_MIN to RELAY_PORT_MAX) holds',
        );
    }

    const tlsWanted = listen.some(
        (entry) => entry.transport === 'tls' || entry.transport === 'tcp+tls',
    );
    const certFile = text(env, 'RELAY_TLS_CERT_FILE');
    const keyFile = text(env, 'RELAY_TLS_KEY_FILE');
    if (tlsWanted && !certFile)
        throw new ConfigError(
            'RELAY_TLS_CERT_FILE',
            'is required with a TLS listener',
        );
    if (tlsWanted && !keyFile)
        throw new ConfigError(
            'RELAY_TLS_KEY_FILE',
            'is required with a TLS listener',
        );

    const deniedPeerPorts = ports(
        env,
        'RELAY_DENIED_PEER_PORTS',
        '1900,3702,5353,5355,11211',
    );

    const health = hostPort(
        text(env, 'RELAY_HEALTH_LISTEN', '127.0.0.1:9478'),
        'RELAY_HEALTH_LISTEN',
    );
    if (!LOOPBACK.containsText(health.host))
        throw new ConfigError(
            'RELAY_HEALTH_LISTEN',
            'must be a loopback address',
        );

    const control = hostPort(
        text(env, 'RELAY_CONTROL_LISTEN', '127.0.0.1:9479'),
        'RELAY_CONTROL_LISTEN',
    );
    if (
        !LOOPBACK.containsText(control.host) &&
        !PRIVATE_CONTROL.containsText(control.host)
    ) {
        throw new ConfigError(
            'RELAY_CONTROL_LISTEN',
            'must be a loopback or private address, never public, 0.0.0.0 or ::',
        );
    }
    const controlAllowedSources = cidrs(
        env,
        'RELAY_CONTROL_ALLOWED_SOURCES',
        '127.0.0.1/32,::1/128',
    );
    for (const cidr of controlAllowedSources.cidrs) {
        const inside = [...LOOPBACK.cidrs, ...PRIVATE_CONTROL.cidrs].some(
            (range) =>
                range.family === cidr.family &&
                range.prefix <= cidr.prefix &&
                new CidrList([range]).contains(cidr.family, cidr.bytes),
        );
        if (!inside)
            throw new ConfigError(
                'RELAY_CONTROL_ALLOWED_SOURCES',
                'may list loopback and private CIDRs only',
            );
    }
    if (controlAllowedSources.cidrs.length === 0) {
        throw new ConfigError(
            'RELAY_CONTROL_ALLOWED_SOURCES',
            'must list at least one source',
        );
    }

    // The relayed range must never hold the relay's own service ports.
    const servicePorts = [
        ...listen.map((entry) => [entry.port, 'RELAY_LISTEN']),
        [health.port, 'RELAY_HEALTH_LISTEN'],
        [control.port, 'RELAY_CONTROL_LISTEN'],
        [3478, 'RELAY_PORT_MIN'],
        [5349, 'RELAY_PORT_MIN'],
        ...[...deniedPeerPorts].map((port) => [
            port,
            'RELAY_DENIED_PEER_PORTS',
        ]),
    ];
    for (const [port, setting] of servicePorts) {
        if (port >= portMin && port <= portMax) {
            throw new ConfigError(
                setting,
                `port ${port} falls inside the relayed range RELAY_PORT_MIN to RELAY_PORT_MAX`,
            );
        }
    }

    const classes = {};
    for (const [name, defaults] of Object.entries(CLASS_DEFAULTS)) {
        const prefix = `RELAY_${name.toUpperCase()}_`;
        const peers = text(env, `${prefix}PEERS`, defaults.peers);
        if (!['public', 'relay-only'].includes(peers))
            throw new ConfigError(
                `${prefix}PEERS`,
                'must be public or relay-only',
            );
        let allowed;
        try {
            allowed = parseAllowlist(
                text(env, `${prefix}ALLOWED_PEER_CIDRS`),
                `${prefix}ALLOWED_PEER_CIDRS`,
            );
        } catch (error) {
            throw new ConfigError(
                `${prefix}ALLOWED_PEER_CIDRS`,
                error.message.replace(`${prefix}ALLOWED_PEER_CIDRS `, ''),
            );
        }
        classes[name] = {
            name,
            tags: defaults.tags,
            // Chat's five-hour ceiling is the user's decision of 1 November 2026 (spec 13).
            maxCredentialSeconds: integer(
                env,
                `${prefix}MAX_CREDENTIAL_SECONDS`,
                defaults.maxCredentialSeconds,
                60,
                defaults.maxCredentialSeconds,
            ),
            graceSeconds: integer(
                env,
                `${prefix}GRACE_SECONDS`,
                defaults.graceSeconds,
                0,
                defaults.graceSeconds,
            ),
            peers,
            allowed,
            maxAllocations: integer(
                env,
                `${prefix}MAX_ALLOCATIONS`,
                defaults.maxAllocations,
                1,
                1000,
            ),
            allocationKbps: integer(
                env,
                `${prefix}ALLOCATION_KBPS`,
                defaults.allocationKbps,
                64,
                1_000_000,
            ),
            idKbps: integer(
                env,
                `${prefix}ID_KBPS`,
                defaults.idKbps,
                64,
                1_000_000,
            ),
        };
    }

    const controlKeyId = text(env, 'RELAY_CONTROL_KEY_ID');
    const controlPreviousKeyId = text(env, 'RELAY_CONTROL_PREVIOUS_KEY_ID');
    for (const [name, value] of [
        ['RELAY_CONTROL_KEY_ID', controlKeyId],
        ['RELAY_CONTROL_PREVIOUS_KEY_ID', controlPreviousKeyId],
    ]) {
        if (value && !/^[A-Za-z0-9._-]{1,64}$/.test(value)) {
            throw new ConfigError(
                name,
                'must be 1 to 64 letters, digits, dots, dashes or underscores',
            );
        }
    }

    const logLevel = text(env, 'RELAY_LOG_LEVEL', 'info');
    if (!['error', 'warn', 'info', 'debug'].includes(logLevel)) {
        throw new ConfigError(
            'RELAY_LOG_LEVEL',
            'must be error, warn, info or debug',
        );
    }
    const binding = text(env, 'RELAY_STUN_BINDING', 'on');
    if (!['on', 'off'].includes(binding))
        throw new ConfigError('RELAY_STUN_BINDING', 'must be on or off');

    const config = {
        version: VERSION,
        realm,
        listeners: listen,
        listenIp,
        externalIp,
        listenIpv6,
        externalIpv6,
        ipv6: !!(listenIpv6 && externalIpv6),
        portMin,
        portMax,
        tlsFiles: tlsWanted ? { cert: certFile, key: keyFile } : null,
        trustedProxies: cidrs(
            env,
            'RELAY_TRUSTED_PROXIES',
            '127.0.0.1/32,::1/128',
        ),
        classes,
        maxAllocations,
        maxAllocationsPerIp: integer(
            env,
            'RELAY_MAX_ALLOCATIONS_PER_IP',
            500,
            1,
            65_535,
        ),
        maxPermissions: integer(env, 'RELAY_MAX_PERMISSIONS', 64, 1, 1024),
        maxChannels: integer(env, 'RELAY_MAX_CHANNELS', 64, 1, 4096),
        totalMbps: integer(env, 'RELAY_TOTAL_MBPS', 200, 1, 100_000),
        maxConnections: integer(
            env,
            'RELAY_MAX_CONNECTIONS',
            8000,
            1,
            1_000_000,
        ),
        maxConnectionsPerIp: integer(
            env,
            'RELAY_MAX_CONNECTIONS_PER_IP',
            256,
            1,
            65_535,
        ),
        unauthPerIpPerSecond: integer(
            env,
            'RELAY_UNAUTH_PER_IP_PER_SECOND',
            10,
            1,
            100_000,
        ),
        unauthPerSecond: integer(
            env,
            'RELAY_UNAUTH_PER_SECOND',
            2000,
            1,
            1_000_000,
        ),
        authFailuresPerMinute: integer(
            env,
            'RELAY_AUTH_FAILURES_PER_MINUTE',
            20,
            1,
            100_000,
        ),
        deniedPeerPorts,
        maxLifetimeSeconds: integer(
            env,
            'RELAY_MAX_LIFETIME_SECONDS',
            3600,
            600,
            3600,
        ),
        nonceSeconds: integer(env, 'RELAY_NONCE_SECONDS', 600, 30, 3600),
        clockSkewSeconds: integer(env, 'RELAY_CLOCK_SKEW_SECONDS', 30, 0, 300),
        stunBinding: binding === 'on',
        healthListen: health,
        logLevel,
        logDir: text(env, 'RELAY_LOG_DIR', '/var/log/oblivion-relay'),
        logRetentionDays: integer(env, 'RELAY_LOG_RETENTION_DAYS', 14, 1, 90),
        denyFile: text(env, 'RELAY_DENY_FILE'),
        controlListen: control,
        controlAllowedSources,
        controlKeyId,
        controlPreviousKeyId,
        stateDir: text(env, 'RELAY_STATE_DIR', '/var/lib/oblivion-relay'),
        secretSources: {
            chat: {
                current: secretSource(env, 'RELAY_CHAT_SECRET'),
                previous: secretSource(env, 'RELAY_CHAT_PREVIOUS_SECRET'),
            },
            control: {
                current: secretSource(env, 'RELAY_CONTROL_SECRET'),
                previous: secretSource(env, 'RELAY_CONTROL_PREVIOUS_SECRET'),
            },
        },
        interfaceAddresses: Object.values(networkInterfaces())
            .flat()
            .map((entry) => normalizeIp(entry?.address ?? ''))
            .filter(Boolean),
        timings: { ...TIMINGS },
        // Spec 3.7: accepted control nonces held at once. Tests lower it.
        controlNonceLimit: 10_000,
    };
    config.secrets = loadSecrets(config);
    config.tls = loadTls(config);

    return {
        ...config,
        ...overrides,
        timings: { ...config.timings, ...(overrides.timings ?? {}) },
    };
}
