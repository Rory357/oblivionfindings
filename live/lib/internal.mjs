// The internal API the app calls (spec section 5). Loopback only; every request is signed
// with the shared secret, at most 30 seconds old, and never accepted twice.

import { createServer } from 'node:http';
import { ReplayGuard, signatureMatches } from './signing.mjs';

function respond(response, status, body) {
    const text = JSON.stringify(body);
    response.writeHead(status, {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(text),
        'Cache-Control': 'no-store',
    });
    response.end(text);
}

function readBody(request, limit) {
    return new Promise((resolve, reject) => {
        const chunks = [];
        let size = 0;
        request.on('data', (chunk) => {
            size += chunk.length;
            if (size > limit) {
                reject(
                    Object.assign(new Error('Body too large'), { status: 413 }),
                );
                request.destroy();
                return;
            }
            chunks.push(chunk);
        });
        request.on('end', () => resolve(Buffer.concat(chunks, size)));
        request.on('error', reject);
    });
}

function userIds(value, limit) {
    return (
        Array.isArray(value) &&
        value.length > 0 &&
        value.length <= limit &&
        value.every((id) => Number.isSafeInteger(id) && id > 0)
    );
}

export function createInternalServer({ hub, config, log }) {
    const replay = new ReplayGuard(config.internalMaxSkewSeconds);

    const routes = {
        'POST /internal/publish': (body) => {
            const problem = hub.validateEvents(body.events);
            if (problem) return [422, { error: problem }];
            return [200, { delivered: hub.publish(body.events) }];
        },
        'POST /internal/revoke': (body) => {
            if (!userIds(body.user_ids, 5000))
                return [422, { error: 'user_ids must list user IDs' }];
            const sessions = body.session_ids ?? [];
            if (
                !Array.isArray(sessions) ||
                sessions.length > 100 ||
                !sessions.every(
                    (sid) =>
                        typeof sid === 'string' && /^[a-f0-9]{64}$/.test(sid),
                )
            ) {
                return [422, { error: 'session_ids must list session hashes' }];
            }
            return [200, { closed: hub.revoke(body.user_ids, sessions) }];
        },
        'POST /internal/call-grant': (body) => {
            if (
                typeof body.call_id !== 'string' ||
                !/^[A-Za-z0-9-]{1,64}$/.test(body.call_id)
            )
                return [422, { error: 'call_id is required' }];
            if (!userIds(body.user_ids, 64))
                return [422, { error: 'user_ids must list user IDs' }];
            const now = hub.nowSeconds();
            if (
                !Number.isSafeInteger(body.until) ||
                body.until <= now ||
                body.until > now + 86_400
            ) {
                return [422, { error: 'until must be within the next day' }];
            }
            hub.grantCall(body.call_id, body.user_ids, body.until);
            return [200, { granted: body.user_ids.length }];
        },
        'POST /internal/call-revoke': (body) => {
            if (
                typeof body.call_id !== 'string' ||
                !/^[A-Za-z0-9-]{1,64}$/.test(body.call_id)
            )
                return [422, { error: 'call_id is required' }];
            if (!userIds(body.user_ids, 64))
                return [422, { error: 'user_ids must list user IDs' }];
            hub.revokeCall(body.call_id, body.user_ids);
            return [200, { revoked: body.user_ids.length }];
        },
        'GET /internal/health': () => [200, hub.health()],
    };

    const server = createServer(async (request, response) => {
        try {
            const pathname = new URL(request.url, 'http://gateway.invalid')
                .pathname;
            const route = routes[`${request.method} ${pathname}`];
            if (!route) {
                respond(response, 404, { error: 'not found' });
                return;
            }
            const raw = await readBody(request, config.internalMaxBodyBytes);
            const timestamp = request.headers['x-live-timestamp'];
            const now = hub.nowSeconds();
            if (
                typeof timestamp !== 'string' ||
                !/^\d{1,12}$/.test(timestamp) ||
                Math.abs(now - Number(timestamp)) >
                    config.internalMaxSkewSeconds
            ) {
                respond(response, 401, { error: 'stale or missing timestamp' });
                return;
            }
            const signature = request.headers['x-live-signature'];
            if (
                !signatureMatches(
                    config.acceptedSecrets,
                    timestamp,
                    raw,
                    signature,
                )
            ) {
                respond(response, 401, { error: 'bad signature' });
                return;
            }
            // Bodies carry a nonce, so two honest requests never share a signature. Health reads
            // change nothing and may repeat.
            if (request.method !== 'GET' && !replay.accept(signature, now)) {
                respond(response, 409, { error: 'replayed request' });
                return;
            }
            let body = {};
            if (request.method === 'POST') {
                if (
                    !String(request.headers['content-type'] ?? '').startsWith(
                        'application/json',
                    )
                ) {
                    respond(response, 415, { error: 'JSON required' });
                    return;
                }
                try {
                    body = JSON.parse(raw.toString('utf8'));
                } catch {
                    respond(response, 400, { error: 'invalid JSON' });
                    return;
                }
                if (!body || typeof body !== 'object' || Array.isArray(body)) {
                    respond(response, 400, { error: 'invalid JSON' });
                    return;
                }
            }
            const [status, result] = route(body);
            respond(response, status, result);
        } catch (error) {
            if (response.headersSent) return;
            respond(response, error.status ?? 500, {
                error: error.status ? error.message : 'gateway error',
            });
            if (!error.status)
                log('error', 'internal.error', { message: error.message });
        }
    });
    server.headersTimeout = 5_000;
    server.requestTimeout = 10_000;
    return server;
}
