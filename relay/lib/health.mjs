// Health and metrics on loopback only (spec section 9). No authentication, because nothing here is
// secret: no addresses, ids, usernames or credentials.

import { createServer } from 'node:http';

export function respondJson(response, status, body) {
    const text = JSON.stringify(body);
    response.writeHead(status, {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(text),
        'Cache-Control': 'no-store',
    });
    response.end(text);
}

export function createHealthServer({ relay }) {
    const server = createServer((request, response) => {
        const path = request.url ?? '';
        if (path !== '/health' && path !== '/metrics') {
            respondJson(response, 404, { error: 'not found' });
            return;
        }
        if (request.method !== 'GET') {
            respondJson(response, 405, { error: 'method not allowed' });
            return;
        }
        if (path === '/metrics') {
            respondJson(response, 200, relay.metricsBody());
            return;
        }
        const body = relay.healthBody();
        respondJson(response, body.status === 'down' ? 503 : 200, body);
    });
    server.headersTimeout = 5000;
    server.requestTimeout = 5000;
    server.on('clientError', (error, socket) => socket.destroy());
    return server;
}
