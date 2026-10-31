// Tiny static server for checking the boards: serves only the new-boards folder, on 127.0.0.1.
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { join, normalize, extname, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const TYPES = { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.svg': 'image/svg+xml', '.js': 'text/javascript', '.png': 'image/png' };
const port = Number(process.argv[2] || 8931);
createServer(async (req, res) => {
    const path = normalize(decodeURIComponent(new URL(req.url, 'http://x').pathname)).replace(/^([/\\])+/, '');
    const file = join(ROOT, path || 'index.html');
    if (!file.startsWith(ROOT)) { res.writeHead(403); return res.end(); }
    try { const body = await readFile(file); res.writeHead(200, { 'Content-Type': TYPES[extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' }); res.end(body); }
    catch { res.writeHead(404); res.end('not found'); }
}).listen(port, '127.0.0.1', () => console.log('serving', ROOT, 'on', port));
