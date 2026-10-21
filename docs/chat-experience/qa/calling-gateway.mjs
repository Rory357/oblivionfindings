// Local development stand-in for the production authorization gateway described in
// docs/chat-experience/self-hosted-calling.md ("Gateway contract"). Loopback only.
//
//  - TLS on 127.0.0.1:7443 with Herd's already-trusted oblivionfindings.test certificate,
//    so the browser connects to wss://oblivionfindings.test:7443 (Herd DNS → 127.0.0.1).
//  - Every /rtc request and WebSocket upgrade is authorised by the application
//    (GET /api/chat/calling/authorize with X-Chat-Access-Token; only 204 passes)
//    before it is piped to the local LiveKit engine on 127.0.0.1:7880.
//  - /twirp/* (server-side room administration) is forwarded without participant
//    authorisation. In production this API is a separate, protected address.
//
// Usage: node docs/chat-experience/qa/calling-gateway.mjs <app-origin, e.g. http://127.0.0.1:8767>
import fs from 'node:fs';
import http from 'node:http';
import https from 'node:https';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';

const app = new URL(process.argv[2] ?? 'http://127.0.0.1:8767');
if (!['127.0.0.1', 'localhost'].includes(app.hostname)) throw new Error('The development gateway only authorises against a loopback application.');
const certificates = path.join(os.homedir(), '.config/herd/config/valet/Certificates');
const engine = { host: '127.0.0.1', port: 7880 };

function authorise(token) {
    return new Promise((resolve) => {
        if (!token) return resolve(false);
        const request = http.request({ host: app.hostname, port: app.port || 80, path: '/api/chat/calling/authorize', method: 'GET',
            headers: { 'X-Chat-Access-Token': token, Accept: 'application/json' }, timeout: 5000 }, (response) => {
            response.resume();
            resolve(response.statusCode === 204);
        });
        request.on('timeout', () => request.destroy());
        request.on('error', () => resolve(false));
        request.end();
    });
}

// GATEWAY_DEBUG=1 prints only the path (no query string or token) and the decision.
const debug = (...parts) => process.env.GATEWAY_DEBUG === '1' && console.log(new Date().toISOString().slice(11, 19), ...parts);
const tokenOf = (url) => new URL(url, 'https://gateway.invalid').searchParams.get('access_token');
const isRtc = (url) => url === '/rtc' || url.startsWith('/rtc/') || url.startsWith('/rtc?');
const isAdmin = (url) => url.startsWith('/twirp/');

const server = https.createServer({
    cert: fs.readFileSync(path.join(certificates, 'oblivionfindings.test.crt')),
    key: fs.readFileSync(path.join(certificates, 'oblivionfindings.test.key')),
}, async (req, res) => {
    // Never log tokens, query strings or authorisation headers.
    if (!isRtc(req.url) && !isAdmin(req.url)) {
        debug('http', req.method, req.url.split('?')[0], 'not routed');
        res.writeHead(404).end();
        return;
    }
    if (isRtc(req.url)) {
        // The client SDK's validate request uses CORS from the application origin.
        res.setHeader('Access-Control-Allow-Origin', '*');
        const allowed = await authorise(tokenOf(req.url));
        debug('http', req.method, req.url.split('?')[0], allowed ? 'allowed' : 'denied');
        if (!allowed) {
            res.writeHead(401).end();
            return;
        }
    }
    const upstream = http.request({ ...engine, path: req.url, method: req.method, headers: req.headers }, (response) => {
        res.writeHead(response.statusCode ?? 502, response.headers);
        response.pipe(res);
    });
    upstream.on('error', () => res.writeHead(502).end());
    req.pipe(upstream);
});

server.on('upgrade', async (req, socket, head) => {
    const allowed = isRtc(req.url) && (await authorise(tokenOf(req.url)));
    debug('upgrade', req.url.split('?')[0], allowed ? 'allowed' : 'denied');
    if (!allowed) {
        socket.end('HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n');
        return;
    }
    const upstream = net.connect(engine.port, engine.host, () => {
        const lines = [`${req.method} ${req.url} HTTP/1.1`];
        for (let i = 0; i < req.rawHeaders.length; i += 2) lines.push(`${req.rawHeaders[i]}: ${req.rawHeaders[i + 1]}`);
        upstream.write(lines.join('\r\n') + '\r\n\r\n');
        if (head?.length) upstream.write(head);
        socket.pipe(upstream).pipe(socket);
    });
    const close = () => {
        socket.destroy();
        upstream.destroy();
    };
    upstream.on('error', close);
    socket.on('error', close);
});

server.listen(7443, '127.0.0.1', () => console.log(JSON.stringify({ gateway: 'wss://oblivionfindings.test:7443', authorises_with: app.origin })));
