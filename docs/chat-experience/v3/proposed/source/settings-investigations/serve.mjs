// Tiny static server for the new-boards folder only (127.0.0.1).
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { join, normalize, extname } from 'node:path';
import { OUT } from './lib.mjs';

const types = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.svg': 'image/svg+xml', '.md': 'text/plain; charset=utf-8' };
const port = Number(process.argv[2] || 8941);
createServer(async (req, res) => {
    const path = normalize(decodeURIComponent(new URL(req.url, 'http://x').pathname)).replace(/^([\\/])+/, '');
    const file = join(OUT, path || 'index.html');
    if (!file.startsWith(OUT)) { res.writeHead(403); return res.end(); }
    try {
        const body = await readFile(file);
        res.writeHead(200, { 'Content-Type': types[extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' });
        res.end(body);
    } catch { res.writeHead(404); res.end('not found'); }
}).listen(port, '127.0.0.1', () => console.log('serving', OUT, 'on', port));
