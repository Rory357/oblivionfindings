// The signed control channel (spec 3.7): how the app ends a person's relay use at once. It listens
// on loopback or a private address only, answers only listed sources, and accepts a request once,
// within 30 seconds of its timestamp, signed with HMAC-SHA256 under the control secret.

import { createHmac, timingSafeEqual } from 'node:crypto';
import { createServer } from 'node:http';
import { normalizeIp } from './addresses.mjs';
import { parseOpaqueId } from './credentials.mjs';
import { respondJson } from './health.mjs';
import { FailureLimiter, OncePer } from './limits.mjs';

export const CONTROL_REASONS = new Set([
    'offboarded',
    'left_call',
    'call_ended',
    'access_removed',
    'hand',
]);
const MAX_BODY_BYTES = 64 * 1024;
const MAX_ENTRIES = 500;
const NONCE_LIMIT = 10_000;
const NONCE_MS = 60_000;
const TIMESTAMP_SKEW_SECONDS = 30;
const ROUTES = { '/control/revoke': 'POST', '/control/status': 'GET' };

/** base64url (no padding) of HMAC-SHA256(secret, method \n path \n key id \n timestamp \n nonce \n body). */
export function controlSignature(
    secret,
    method,
    path,
    keyId,
    timestamp,
    nonce,
    body,
) {
    return createHmac('sha256', secret)
        .update(
            `${method}\n${path}\n${keyId}\n${timestamp}\n${nonce}\n`,
            'latin1',
        )
        .update(body)
        .digest('base64url');
}

/** Every accepted nonce, for 60 seconds, up to 10,000 at once. */
export class NonceStore {
    constructor(limit = NONCE_LIMIT, windowMs = NONCE_MS) {
        this.limit = limit;
        this.windowMs = windowMs;
        this.seen = new Map();
    }

    prune(now) {
        for (const [nonce, until] of this.seen) {
            if (until > now) break;
            this.seen.delete(nonce);
        }
    }

    has(nonce) {
        return this.seen.has(nonce);
    }

    get full() {
        return this.seen.size >= this.limit;
    }

    add(nonce, now) {
        this.seen.set(nonce, now + this.windowMs);
    }
}

function readBody(request, limit) {
    return new Promise((resolve) => {
        const chunks = [];
        let size = 0;
        let over = false;
        request.on('data', (chunk) => {
            size += chunk.length;
            if (size > limit) over = true;
            else chunks.push(chunk);
        });
        request.on('end', () => resolve(over ? null : Buffer.concat(chunks)));
        request.on('error', () => resolve(null));
    });
}

/** Parse and check a revoke body. Returns { entries, reason } or null; nothing is applied on null. */
export function parseRevoke(raw, { classes, nowSeconds, skewSeconds }) {
    let body;
    try {
        body = JSON.parse(raw.toString('utf8'));
    } catch {
        return null;
    }
    if (!body || typeof body !== 'object' || Array.isArray(body)) return null;
    const keys = Object.keys(body);
    if (
        keys.length !== 2 ||
        !keys.includes('revocations') ||
        !keys.includes('reason')
    )
        return null;
    if (!CONTROL_REASONS.has(body.reason)) return null;
    const list = body.revocations;
    if (!Array.isArray(list) || list.length < 1 || list.length > MAX_ENTRIES)
        return null;
    const entries = [];
    for (const entry of list) {
        if (!entry || typeof entry !== 'object' || Array.isArray(entry))
            return null;
        const entryKeys = Object.keys(entry);
        if (
            entryKeys.length !== 2 ||
            !entryKeys.includes('id') ||
            !entryKeys.includes('expires_up_to')
        )
            return null;
        const parsed = parseOpaqueId(entry.id);
        // Version 1: only chat ids. A deferred class's tag (cctv, agent, technician) is invalid.
        const cls = parsed
            ? Object.values(classes).find((candidate) =>
                  candidate.tags.includes(parsed.tag),
              )
            : null;
        if (!cls) return null;
        const until = entry.expires_up_to;
        if (
            !Number.isSafeInteger(until) ||
            until <= 0 ||
            until > nowSeconds + cls.maxCredentialSeconds + skewSeconds
        )
            return null;
        entries.push({ id: parsed.opaqueId, expiresUpTo: until });
    }
    return { entries, reason: body.reason };
}

export function createControlServer({
    relay,
    config,
    clock,
    ops,
    allocationLog,
}) {
    const failures = new FailureLimiter(10);
    const nonces = new NonceStore(config.controlNonceLimit ?? NONCE_LIMIT);
    const once = new OncePer(60_000);
    let refusedSinceSummary = 0;

    const refuse = (response, source) => {
        const now = clock.now();
        relay.metrics.control.refused++;
        refusedSinceSummary++;
        if (once.allow(`auth:${source}`, now))
            allocationLog.write('auth_limit', {
                reason: 'control_auth',
                source_ip: source,
            });
        if (failures.fail(source, now))
            allocationLog.write('rate_limit', {
                reason: 'control_failures',
                source_ip: source,
            });
        if (once.allow('ops', now)) {
            ops.log('warn', 'auth_limit', {
                reason: 'control',
                count: refusedSinceSummary,
            });
            refusedSinceSummary = 0;
        }
        respondJson(response, 401, {});
    };

    const server = createServer(async (request, response) => {
        const source =
            normalizeIp(request.socket.remoteAddress ?? '') ?? 'unknown';
        // Checked per request as well, since a kept-alive connection outlives the silencing.
        if (
            !config.controlAllowedSources.containsText(source) ||
            failures.silenced(source, clock.now())
        ) {
            request.socket.destroy();
            return;
        }
        const path = request.url ?? '';
        const method = ROUTES[path];
        if (!method) return respondJson(response, 404, { error: 'not found' });
        if (request.method !== method)
            return respondJson(response, 405, { error: 'method not allowed' });
        const raw = await readBody(request, MAX_BODY_BYTES);
        if (raw === null) {
            response.setHeader('Connection', 'close');
            return respondJson(response, 413, { error: 'too large' });
        }

        const keyId = request.headers['x-relay-key'];
        const timestamp = request.headers['x-relay-timestamp'];
        const nonce = request.headers['x-relay-nonce'];
        const signature = request.headers['x-relay-signature'];
        // Secrets and key ids as of the last reload.
        const secrets = relay.secrets().control;
        const secret =
            keyId && keyId === secrets.keyId
                ? secrets.current
                : keyId && keyId === secrets.previousKeyId
                  ? secrets.previous
                  : null;
        if (
            !secret ||
            typeof timestamp !== 'string' ||
            !/^\d{1,12}$/.test(timestamp) ||
            typeof nonce !== 'string' ||
            !/^[0-9a-f]{32}$/.test(nonce) ||
            typeof signature !== 'string' ||
            !/^[A-Za-z0-9_-]{43}$/.test(signature)
        ) {
            return refuse(response, source);
        }
        const expected = Buffer.from(
            controlSignature(
                secret,
                request.method,
                path,
                keyId,
                timestamp,
                nonce,
                raw,
            ),
            'latin1',
        );
        if (!timingSafeEqual(expected, Buffer.from(signature, 'latin1')))
            return refuse(response, source);
        const now = clock.now();
        if (
            Math.abs(Math.floor(now / 1000) - Number(timestamp)) >
            TIMESTAMP_SKEW_SECONDS
        )
            return refuse(response, source);
        nonces.prune(now);
        if (nonces.has(nonce)) return refuse(response, source);
        if (nonces.full) return respondJson(response, 503, { error: 'busy' });
        nonces.add(nonce, now);
        relay.metrics.control.accepted++;

        if (method === 'GET') {
            return respondJson(response, 200, {
                ...relay.healthBody(),
                revocations_held: relay.core.held.size,
                control: {
                    key_id: secrets.keyId,
                    previous_key: secrets.previous
                        ? secrets.previousKeyId
                        : null,
                },
            });
        }
        if (
            !String(request.headers['content-type'] ?? '').startsWith(
                'application/json',
            )
        ) {
            return respondJson(response, 400, { error: 'invalid' });
        }
        const revoke = parseRevoke(raw, {
            classes: config.classes,
            nowSeconds: Math.floor(now / 1000),
            skewSeconds: config.clockSkewSeconds,
        });
        if (!revoke) return respondJson(response, 400, { error: 'invalid' });
        try {
            return respondJson(
                response,
                200,
                relay.revoke(revoke.entries, revoke.reason),
            );
        } catch {
            return respondJson(response, 500, { error: 'not applied' });
        }
    });
    server.headersTimeout = 5000;
    server.requestTimeout = 10_000;
    server.on('clientError', (error, socket) => socket.destroy());
    // Sources outside RELAY_CONTROL_ALLOWED_SOURCES, or silenced ones, get no reply at all.
    server.on('connection', (socket) => {
        const source = normalizeIp(socket.remoteAddress ?? '');
        if (
            !source ||
            !config.controlAllowedSources.containsText(source) ||
            failures.silenced(source, clock.now())
        ) {
            socket.destroy();
        }
    });
    server.prune = (now) => {
        nonces.prune(now);
        failures.prune(now);
        once.prune(now);
    };
    return server;
}
