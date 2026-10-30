import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
const here=path.dirname(fileURLToPath(import.meta.url));
const files={'/':'index.html','/app.js':'app.js','/app.css':'app.css','/favicon.ico':null};
http.createServer((req,res)=>{
 const pathname=new URL(req.url,'http://localhost').pathname;
 res.setHeader('X-Preview-Identity','PKG-09B-implementation-926b4981');
 res.setHeader('Cache-Control','no-store');
 if(!(pathname in files)){res.writeHead(404);res.end('Browser test fixtures are injected explicitly.');return;}
 const file=files[pathname]; if(!file){res.writeHead(204);res.end();return;}
 res.setHeader('Content-Type',file.endsWith('.html')?'text/html':file.endsWith('.css')?'text/css':'text/javascript');
 res.end(fs.readFileSync(path.join(here,file)));
}).listen(8973,'127.0.0.1',()=>console.log('Reports implementation preview http://127.0.0.1:8973'));
