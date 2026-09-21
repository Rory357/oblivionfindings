import http from 'node:http';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const directory = path.dirname(fileURLToPath(import.meta.url));
const dist = path.join(directory, 'dist');
const identity = { version: 'PKG-02A-v1', baseline: '2302ca33a95616e442a78e957ddce82d8db98669', worktree: '2b9f', model: 'gpt-6-astra', effort: 'xhigh', synthetic: true };
http.createServer(async (req, res) => {
  res.setHeader('Cache-Control', 'private, no-store, max-age=0');
  res.setHeader('X-PKG-Preview', 'PKG-02A-v1-2b9f');
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('Referrer-Policy', 'no-referrer');
  res.setHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'none'; font-src 'self'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'");
  if (!['GET','HEAD'].includes(req.method)) { res.writeHead(405);res.end('Read-only preview');return; }
  const pathname = new URL(req.url, 'http://127.0.0.1:4332').pathname;
  if (pathname === '/__preview') { res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(identity));return; }
  const file = ['/', '/PKG-02A/v1/', '/index.html'].includes(pathname) ? 'index.html' : /^\/assets\/[a-zA-Z0-9_.-]+$/.test(pathname) ? pathname.slice(1) : null;
  if (!file) {res.writeHead(404);res.end('Outside this synthetic preview');return;}
  try {
    const bytes = await readFile(path.join(dist, file));
    res.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'text/html');
    res.writeHead(200);res.end(req.method === 'HEAD' ? undefined : bytes);
  } catch {res.writeHead(404);res.end('Preview asset unavailable');}
}).listen(4332, '127.0.0.1', () => process.stdout.write('PKG-02A v1 frozen preview: http://127.0.0.1:4332/PKG-02A/v1/\n'));
