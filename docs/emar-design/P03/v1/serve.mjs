// eMAR P03 v1 — read-only local preview server for the built mockup (dist/).
// GET/HEAD only; no application API; synthetic data. Run from any directory:
//   node docs/emar-design/P03/v1/serve.mjs   →   http://127.0.0.1:4387/
// Port 4387 (review session, 30 Sep): P02 4383, P01 v2 4384, P07a 4385, P08a 4386.
import http from 'node:http';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dist = path.join(path.dirname(fileURLToPath(import.meta.url)), 'dist');
const PORT = Number(process.env.PORT) || 4387;
const TYPES = {
    '.html': 'text/html; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.mjs': 'text/javascript; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.png': 'image/png',
    '.svg': 'image/svg+xml',
    '.woff2': 'font/woff2',
};

http.createServer(async (req, res) => {
    res.setHeader('Cache-Control', 'private, no-store');
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('X-Preview', 'eMAR-P03-v1 synthetic');
    res.setHeader(
        'Content-Security-Policy',
        "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; font-src 'self' data:; worker-src 'self' blob:; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'",
    );
    if (!['GET', 'HEAD'].includes(req.method)) {
        res.writeHead(405);
        res.end('Read-only synthetic preview');
        return;
    }
    const pathname = decodeURIComponent(new URL(req.url, `http://127.0.0.1:${PORT}`).pathname);
    const rel = pathname === '/' ? 'index.html' : pathname.replace(/^\/+/, '');
    if (!/^(index\.html|assets\/[\w.-]+)$/.test(rel)) {
        res.writeHead(404);
        res.end('Outside this synthetic preview');
        return;
    }
    try {
        const bytes = await readFile(path.join(dist, rel));
        res.setHeader('Content-Type', TYPES[path.extname(rel)] ?? 'application/octet-stream');
        res.setHeader('Content-Length', bytes.length);
        res.end(req.method === 'HEAD' ? undefined : bytes);
    } catch {
        res.writeHead(404);
        res.end('Preview file unavailable — run the Vite build first');
    }
}).listen(PORT, '127.0.0.1', () => process.stdout.write(`eMAR P03 v1: http://127.0.0.1:${PORT}/\n`));
