import http from 'node:http';
import path from 'node:path';
import fs from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
const root=path.dirname(fileURLToPath(import.meta.url));
const port=Number(process.argv[2]||4397);
const types={'.html':'text/html; charset=utf-8','.js':'application/javascript','.css':'text/css','.json':'application/json','.png':'image/png','.svg':'image/svg+xml','.md':'text/plain; charset=utf-8','.woff2':'font/woff2'};
http.createServer(async(req,res)=>{try{if(req.method!=='GET'&&req.method!=='HEAD'){res.writeHead(405);return res.end();}const url=new URL(req.url,'http://127.0.0.1');let target=path.resolve(root,'.'+decodeURIComponent(url.pathname));if(target!==root&&!target.startsWith(root+path.sep)){res.writeHead(403);return res.end();}if((await fs.stat(target)).isDirectory())target=path.join(target,'index.html');const data=await fs.readFile(target);res.writeHead(200,{'Content-Type':types[path.extname(target)]||'application/octet-stream','Cache-Control':'no-store','X-PKG05-Baseline':'4ea64c547','X-PKG05-Version':'v3'});res.end(req.method==='HEAD'?undefined:data);}catch{res.writeHead(404);res.end('PKG-05 preview file not found');}}).listen(port,'127.0.0.1',()=>console.log(`PKG-05 v3 · ${root} · http://127.0.0.1:${port}/`));
