// Oblivion Live gateway configuration, read from the environment.
// Every limit has a default from the spec (docs/chat-experience/clean-room/specs/oblivion-live.md);
// tests pass smaller timings so they finish quickly.

const DEFAULTS = Object.freeze({
    host: '127.0.0.1',
    port: 8091,
    internalPort: 8092,
    path: '/chat-live',
    audience: 'oblivion-live',
    // Section 4 limits.
    maxMessageBytes: 64 * 1024,
    maxSignalBytes: 48 * 1024,
    rateLimitMessages: 40,
    rateLimitWindowMs: 10_000,
    pingIntervalMs: 25_000,
    maxMissedPongs: 2,
    maxConnectionsPerUser: 10,
    maxConnections: 10_000,
    // Section 3 timings.
    helloTimeoutMs: 5_000,
    sessionLifetimeMs: 15 * 60_000,
    renewBeforeMs: 2 * 60_000,
    clockLeewaySeconds: 5,
    ticketMaxLifetimeSeconds: 60,
    // Section 5.
    internalMaxSkewSeconds: 30,
    internalMaxBodyBytes: 1024 * 1024,
    maxRecipientsPerEvent: 500,
    // Presence: a reload closes and reopens within a second, so "offline" waits briefly.
    offlineGraceMs: 10_000,
    presenceConcurrency: 4,
    presenceTimeoutMs: 5_000,
    // A browser that stops reading must not hold unbounded memory.
    maxBufferedBytes: 1024 * 1024,
    closeTimeoutMs: 2_000,
});

function integer(value, fallback, name) {
    if (value === undefined || value === '') return fallback;
    const parsed = Number(value);
    if (!Number.isInteger(parsed) || parsed < 0) {
        throw new Error(`${name} must be a whole number`);
    }
    return parsed;
}

function origins(value) {
    if (!value) return [];
    return value
        .split(',')
        .map((entry) => entry.trim())
        .filter(Boolean)
        .map((entry) => {
            let url;
            try {
                url = new URL(entry);
            } catch {
                throw new Error(
                    `LIVE_ALLOWED_ORIGINS has an invalid origin: ${entry}`,
                );
            }
            if (
                !['http:', 'https:'].includes(url.protocol) ||
                url.origin !== entry.replace(/\/$/, '')
            ) {
                throw new Error(
                    `LIVE_ALLOWED_ORIGINS entries must be exact origins such as https://care.example.org: ${entry}`,
                );
            }
            return url.origin;
        });
}

function secret(value, name) {
    if (!value) return null;
    if (Buffer.byteLength(value, 'utf8') < 32) {
        throw new Error(`${name} must be at least 32 characters`);
    }
    return Buffer.from(value, 'utf8');
}

/** Build the runtime configuration. `overrides` lets tests and tools adjust any value. */
export function readConfig(env = process.env, overrides = {}) {
    const current = secret(env.LIVE_SECRET, 'LIVE_SECRET');
    const keyId = (env.LIVE_KEY_ID ?? '').trim();
    if (!current || !keyId) {
        throw new Error('LIVE_SECRET and LIVE_KEY_ID are required');
    }
    const keys = new Map([[keyId, current]]);
    const previous = secret(env.LIVE_PREVIOUS_SECRET, 'LIVE_PREVIOUS_SECRET');
    const previousKeyId = (env.LIVE_PREVIOUS_KEY_ID ?? '').trim();
    if (previous) {
        if (!previousKeyId || previousKeyId === keyId) {
            throw new Error(
                'LIVE_PREVIOUS_SECRET needs its own LIVE_PREVIOUS_KEY_ID',
            );
        }
        keys.set(previousKeyId, previous);
    }
    const appUrl = (env.LIVE_APP_URL ?? '').trim().replace(/\/+$/, '');
    if (appUrl) {
        const parsed = new URL(appUrl);
        if (!['http:', 'https:'].includes(parsed.protocol)) {
            throw new Error('LIVE_APP_URL must be an http or https URL');
        }
    }

    return {
        ...DEFAULTS,
        host: env.LIVE_HOST?.trim() || DEFAULTS.host,
        port: integer(env.LIVE_PORT, DEFAULTS.port, 'LIVE_PORT'),
        internalPort: integer(
            env.LIVE_INTERNAL_PORT,
            DEFAULTS.internalPort,
            'LIVE_INTERNAL_PORT',
        ),
        path: env.LIVE_PATH?.trim() || DEFAULTS.path,
        maxConnections: integer(
            env.LIVE_MAX_CONNECTIONS,
            DEFAULTS.maxConnections,
            'LIVE_MAX_CONNECTIONS',
        ),
        allowedOrigins: origins(env.LIVE_ALLOWED_ORIGINS),
        appUrl,
        keyId,
        // Tickets name their key; the internal API and presence reports accept either secret.
        keys,
        signingSecret: current,
        acceptedSecrets: [...keys.values()],
        ...overrides,
    };
}

export { DEFAULTS };
