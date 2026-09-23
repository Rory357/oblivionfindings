import http from 'node:http';
import {readFile} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const dist=path.join(path.dirname(fileURLToPath(import.meta.url)),'dist');
const identity={version:'PKG-02B-v3',baseline:'5307692ec59be84f3503c06354419b7da95be805',worktree:'5b0a',model:'gpt-6-astra',effort:'xhigh',synthetic:true};
http.createServer(async(req,res)=>{
res.setHeader('Cache-Control','private, no-store');res.setHeader('X-PKG-Preview','PKG-02B-v3-5b0a');res.setHeader('X-Content-Type-Options','nosniff');
res.setHeader('Content-Security-Policy',"default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://tile.openstreetmap.org https://server.arcgisonline.com; connect-src 'none'; font-src 'self'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'");
if(!['GET','HEAD'].includes(req.method)){res.writeHead(405);res.end('Read-only preview');return;}
const pathname=new URL(req.url,'http://127.0.0.1:4338').pathname;
if(pathname==='/__preview'){res.setHeader('Content-Type','application/json');res.end(JSON.stringify(identity));return;}
const file=['/','/PKG-02B/v3/','/index.html'].includes(pathname)?'index.html':/^\/assets\/[a-zA-Z0-9_.-]+$/.test(pathname)?pathname.slice(1):null;
if(!file){res.writeHead(404);res.end('Outside this synthetic preview');return;}
try{const bytes=await readFile(path.join(dist,file));res.setHeader('Content-Type',file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':'text/html');res.end(req.method==='HEAD'?undefined:bytes);}catch{res.writeHead(404);res.end('Preview file unavailable');}
}).listen(4338,'127.0.0.1',()=>process.stdout.write('PKG-02B v3: http://127.0.0.1:4338/PKG-02B/v3/\n'));
