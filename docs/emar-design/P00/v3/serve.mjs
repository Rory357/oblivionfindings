// Read-only local preview for eMAR P00 v3 (design mockup, synthetic data).
// Usage: node docs/emar-design/P00/v3/serve.mjs  →  http://127.0.0.1:4360/
import http from 'node:http';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(fileURLToPath(import.meta.url));
const files = { '/': 'index.html', '/index.html': 'index.html', '/mockup.css': 'mockup.css', '/mockup.js': 'mockup.js' };
const types = { html: 'text/html; charset=utf-8', css: 'text/css; charset=utf-8', js: 'text/javascript; charset=utf-8' };

http.createServer(async (req, res) => {
    res.setHeader('Cache-Control', 'no-store');
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('Content-Security-Policy', "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'none'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'");
    if (!['GET', 'HEAD'].includes(req.method)) { res.writeHead(405); res.end('Read-only preview'); return; }
    const file = files[new URL(req.url, 'http://127.0.0.1:4360').pathname];
    if (!file) { res.writeHead(404); res.end('Outside this mockup'); return; }
    try {
        const bytes = await readFile(path.join(root, file));
        res.setHeader('Content-Type', types[file.split('.').pop()]);
        res.end(req.method === 'HEAD' ? undefined : bytes);
    } catch { res.writeHead(404); res.end('Mockup file unavailable'); }
}).listen(4360, '127.0.0.1', () => process.stdout.write('eMAR P00 v3: http://127.0.0.1:4360/\n'));
