import http from 'node:http';
import {readFile} from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const dist=path.join(path.dirname(fileURLToPath(import.meta.url)),'dist');
const identity={version:'PKG-02B-v7',baseline:'5307692ec59be84f3503c06354419b7da95be805',worktree:'5b0a',model:'gpt-6-astra',effort:'xhigh',synthetic:true};
const reportTiles=new Map();
http.createServer(async(req,res)=>{
res.setHeader('Cache-Control','private, no-store');res.setHeader('X-PKG-Preview','PKG-02B-v7-5b0a');res.setHeader('X-Content-Type-Options','nosniff');
res.setHeader('Content-Security-Policy',"default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://tile.openstreetmap.org https://*.tile.openstreetmap.org https://server.arcgisonline.com; connect-src 'none'; font-src 'self'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'");
if(!['GET','HEAD'].includes(req.method)){res.writeHead(405);res.end('Read-only preview');return;}
const pathname=new URL(req.url,'http://127.0.0.1:4342').pathname;
const tile=pathname.match(/^\/report-tiles\/(\d+)\/(\d+)\/(\d+)\.png$/);
if(tile){
 const [z,x,y]=tile.slice(1).map(Number), n=2**z;
 const lon=(x+.5)/n*360-180,lat=Math.atan(Math.sinh(Math.PI*(1-2*(y+.5)/n)))*180/Math.PI;
 if(z<12||z>16||Math.abs(lon-174.776)>.25||Math.abs(lat+41.29)>.25){res.writeHead(404);res.end('Outside synthetic trip area');return;}
 try{
  let bytes=reportTiles.get(pathname);
  if(!bytes){const upstream=await fetch(`https://tile.openstreetmap.org/${z}/${x}/${y}.png`,{headers:{'User-Agent':'OblivionCare-LocalDesignPreview/1.0 (synthetic trip report)','Referer':'http://127.0.0.1:4342/'},signal:AbortSignal.timeout(12000)});if(!upstream.ok||!upstream.headers.get('content-type')?.includes('image/png'))throw new Error('Tile unavailable');bytes=Buffer.from(await upstream.arrayBuffer());if(reportTiles.size>=64)reportTiles.delete(reportTiles.keys().next().value);reportTiles.set(pathname,bytes);}
  res.setHeader('Content-Type','image/png');res.end(req.method==='HEAD'?undefined:bytes);
 }catch{res.writeHead(502);res.end('Report map imagery unavailable');}return;
}
if(pathname==='/__preview'){res.setHeader('Content-Type','application/json');res.end(JSON.stringify(identity));return;}
const file=['/','/PKG-02B/v7/','/index.html'].includes(pathname)?'index.html':/^\/assets\/[a-zA-Z0-9_.-]+$/.test(pathname)?pathname.slice(1):null;
if(!file){res.writeHead(404);res.end('Outside this synthetic preview');return;}
try{const bytes=await readFile(path.join(dist,file));res.setHeader('Content-Type',file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':'text/html');res.end(req.method==='HEAD'?undefined:bytes);}catch{res.writeHead(404);res.end('Preview file unavailable');}
}).listen(4342,'127.0.0.1',()=>process.stdout.write('PKG-02B v7: http://127.0.0.1:4342/PKG-02B/v7/\n'));
