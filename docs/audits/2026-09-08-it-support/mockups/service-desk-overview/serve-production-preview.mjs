import http from 'node:http';
import fs from 'node:fs/promises';
const html = new URL('./production-preview.html', import.meta.url);
const server = http.createServer(async(req,res)=>{
  if(req.url==='/'||req.url?.startsWith('/index.html')) {res.writeHead(200,{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store'});res.end(await fs.readFile(html));}
  else {res.writeHead(404);res.end('Artifact preview only');}
});
server.listen(0,'127.0.0.1',()=>console.log(`Service Desk mockup: http://127.0.0.1:${server.address().port}/`));
