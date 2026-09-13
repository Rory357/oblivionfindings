// Local static preview only: no application, database, uploads or write endpoints.
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const mime = {'.html':'text/html; charset=utf-8','.js':'text/javascript; charset=utf-8','.css':'text/css; charset=utf-8','.woff2':'font/woff2','.md':'text/plain; charset=utf-8'};
const port = Number(process.env.TICKET_MOCKUP_PORT || 8793);
http.createServer((req,res)=>{
  if(req.method !== 'GET' && req.method !== 'HEAD'){res.writeHead(405);return res.end();}
  let file;
  try { file = path.resolve(__dirname, '.' + decodeURIComponent(new URL(req.url,'http://127.0.0.1').pathname === '/' ? '/index.html' : new URL(req.url,'http://127.0.0.1').pathname)); }
  catch {res.writeHead(400);return res.end();}
  if(!file.startsWith(__dirname + path.sep)){res.writeHead(403);return res.end();}
  fs.readFile(file,(error,data)=>{
    if(error){res.writeHead(404);return res.end('Not found');}
    res.writeHead(200,{'Content-Type':mime[path.extname(file)] || 'application/octet-stream','Cache-Control':'no-store','X-Content-Type-Options':'nosniff'});
    res.end(req.method === 'HEAD' ? undefined : data);
  });
}).listen(port,'127.0.0.1',()=>console.log(`Ticket mockup: http://127.0.0.1:${port}`));
