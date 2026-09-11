// Local-only browser fault injection. One synthetic handoff POST fails before
// reaching Laravel; discovery, recovery and cancellation still use the real app.
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const crypto = require('node:crypto');

const [token, fingerprint] = process.argv.slice(2);
const repo = fs.realpathSync(path.resolve(__dirname, '../../../..'));
if (!/^[a-f0-9]{16}$/.test(token ?? '') || !/^[a-f0-9]{64}$/.test(fingerprint ?? '') ||
    repo.replaceAll('\\', '/').toLowerCase() !== 'c:/users/steph/herd/oblivionfindings') process.exit(2);
const root = path.join(repo, 'storage/framework/testing/it-draft-browser-' + token);
const owner = JSON.parse(fs.readFileSync(path.join(root, 'owner.json'), 'utf8'));
const ready = JSON.parse(fs.readFileSync(path.join(root, 'ready.json'), 'utf8'));
if (owner.token !== token || owner.approval_fingerprint !== fingerprint ||
    owner.database !== 'oblivion_it_draft_browser_' + token || ready.database !== owner.database ||
    !ready.ready || !owner.monitoring_fixtures || fs.realpathSync(root).toLowerCase() !== root.toLowerCase()) process.exit(3);
const alertId = ready.monitoring_fixtures?.handoffs?.cancel?.alert_id;
if (!Number.isSafeInteger(alertId) || alertId < 1) process.exit(3);
const failedPath = `/it/control-room/alerts/${alertId}/handoff`;
let injected = false;
let server;
let expiry;
const upstreams = new Set();
function shutdown() {
    clearTimeout(expiry);
    for (const request of upstreams) request.destroy();
    if (!server) return process.exit(0);
    server.close(() => process.exit(0));
    server.closeAllConnections();
}
process.stdin.setEncoding('utf8');
process.stdin.on('data', chunk => { if (chunk.includes('quit')) shutdown(); });
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);

http.get('http://127.0.0.1:8766/__it-draft-verification', { timeout: 5000 }, response => {
    let body = '';
    response.on('data', chunk => { body += chunk; if (body.length > 16000) response.destroy(); });
    response.on('end', () => {
        let identity;
        try { identity = JSON.parse(body); } catch { return process.exit(4); }
        if (response.statusCode !== 200 || identity.token !== token || identity.database !== owner.database ||
            identity.asset_manifest_sha256 !== owner.asset_manifest_sha256 || identity.mail_driver !== 'array' ||
            identity.queue_driver !== 'sync' || identity.csrf_testing_bypass !== false) return process.exit(4);
        server = http.createServer((incoming, outgoing) => {
            if (incoming.socket.remoteAddress !== '127.0.0.1' || incoming.headers.host !== '127.0.0.1:8767') {
                incoming.resume(); outgoing.writeHead(403); outgoing.end(); return;
            }
            if (incoming.method === 'POST' && incoming.url === failedPath) {
                const chunks = [];
                const forwarded = injected;
                let size = 0;
                incoming.on('data', chunk => {
                    size += chunk.length;
                    if (size > 1000000) return incoming.destroy();
                    chunks.push(chunk);
                });
                incoming.on('end', () => {
                    const raw = Buffer.concat(chunks);
                    let uuid;
                    try { uuid = JSON.parse(raw.toString()).request_uuid; } catch { return; }
                    if (typeof uuid !== 'string' || !/^[a-f0-9-]{36}$/.test(uuid)) return;
                    console.log(JSON.stringify({ token, alert_id: alertId, request_uuid: uuid,
                        body_sha256: crypto.createHash('sha256').update(raw).digest('hex'), forwarded }));
                });
            }
            if (!injected && incoming.method === 'POST' && incoming.url === failedPath) {
                injected = true;
                incoming.resume();
                outgoing.writeHead(503, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
                outgoing.end(JSON.stringify({ code: 'synthetic_transport_unavailable' }));
                console.log(JSON.stringify({ token, alert_id: alertId, injected_once: true, forwarded: false }));
                return;
            }
            const request = http.request({ hostname: '127.0.0.1', port: 8766, method: incoming.method,
                path: incoming.url, headers: { ...incoming.headers, host: '127.0.0.1:8766', 'accept-encoding': 'identity' }, timeout: 20000 }, response => {
                const isHtml = String(response.headers['content-type']).includes('text/html');
                const isJson = String(response.headers['content-type']).includes('application/json');
                if (isHtml || isJson) {
                    let html = '';
                    response.setEncoding('utf8');
                    response.on('data', chunk => { html += chunk; if (html.length > 5000000) response.destroy(); });
                    response.on('end', () => {
                        // Keep modules on the browser's actual origin. Asset bytes and
                        // application behavior are forwarded unchanged by this proxy.
                        let rendered = html;
                        if (isHtml) {
                            rendered = html.replaceAll('http://127.0.0.1:8766/', 'http://127.0.0.1:8767/');
                        } else {
                            // The command contract correctly refuses another origin.
                            // Translate only canonical href origins, not outcome data.
                            const translate = value => {
                                if (!value || typeof value !== 'object') return;
                                for (const [key, entry] of Object.entries(value)) {
                                    if (key === 'href' && typeof entry === 'string' && entry.startsWith('http://127.0.0.1:8766/it/tickets/')) {
                                        value[key] = entry.replace('http://127.0.0.1:8766/', 'http://127.0.0.1:8767/');
                                    } else translate(entry);
                                }
                            };
                            try { const payload = JSON.parse(html); translate(payload); rendered = JSON.stringify(payload); }
                            catch { /* Preserve non-JSON errors for truthful client recovery. */ }
                        }
                        const headers = { ...response.headers, 'content-length': Buffer.byteLength(rendered) };
                        delete headers['transfer-encoding'];
                        outgoing.writeHead(response.statusCode, headers);
                        outgoing.end(rendered);
                    });
                    return;
                }
                outgoing.writeHead(response.statusCode, response.headers);
                response.pipe(outgoing);
            });
            upstreams.add(request);
            request.on('close', () => upstreams.delete(request));
            request.on('timeout', () => request.destroy());
            request.on('error', () => {
                if (!outgoing.headersSent) outgoing.writeHead(503, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
                outgoing.end(JSON.stringify({ code: 'synthetic_upstream_unavailable' }));
            });
            incoming.on('aborted', () => request.destroy());
            incoming.pipe(request);
        });
        server.on('error', () => { console.error('Owned fault proxy could not bind; no existing listener was stopped.'); process.exit(5); });
        server.listen(8767, '127.0.0.1', () => {
            console.log(JSON.stringify({ token, ready: true, pid: process.pid, started_at: new Date().toISOString(), port: 8767, upstream: 8766, alert_id: alertId, maximum_lifetime_seconds: 300 }));
            expiry = setTimeout(shutdown, 300000);
        });
    });
}).on('error', () => { console.error('Owned upstream identity was unavailable.'); process.exit(4); })
    .on('timeout', function () { this.destroy(); });
