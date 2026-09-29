// P11 v4 preview server — serves this version’s built dist on 127.0.0.1:4375.
// GET/HEAD only; no application API exists behind it (synthetic data only).
import http from 'node:http';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dist = path.join(path.dirname(fileURLToPath(import.meta.url)), 'dist');
const PORT = 4375;
const TYPES = { '.js': 'text/javascript', '.css': 'text/css', '.html': 'text/html; charset=utf-8', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
http.createServer(async (req, res) => {
    res.setHeader('Cache-Control', 'no-store');
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'none'; font-src 'self' data:; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'");
    if (!['GET', 'HEAD'].includes(req.method)) { res.writeHead(405); res.end('Read-only preview'); return; }
    const pathname = new URL(req.url, `http://127.0.0.1:${PORT}`).pathname;
    const file = pathname === '/' || pathname === '/index.html' ? 'index.html' : /^\/assets\/[A-Za-z0-9_.-]+$/.test(pathname) ? pathname.slice(1) : null;
    if (!file) { res.writeHead(404); res.end('Outside this synthetic preview'); return; }
    try {
        const bytes = await readFile(path.join(dist, file));
        res.setHeader('Content-Type', TYPES[path.extname(file)] || 'application/octet-stream');
        res.end(req.method === 'HEAD' ? undefined : bytes);
    } catch { res.writeHead(404); res.end('Preview file unavailable — run the build first'); }
}).listen(PORT, '127.0.0.1', () => process.stdout.write(`P11 v4: http://127.0.0.1:${PORT}/\n`));
