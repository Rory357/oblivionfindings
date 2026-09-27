const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const port = Number(process.argv[2] || 8897);
const allowed = new Set(['index.html','app.js','app.css']);
http.createServer((req,res)=>{
  if(req.method!=='GET' && req.method!=='HEAD'){res.writeHead(405);return res.end('Read-only design preview');}
  const url = new URL(req.url,'http://127.0.0.1');
  const name = url.pathname==='/'?'index.html':url.pathname.slice(1);
  if(!allowed.has(name)){res.writeHead(404);return res.end('Not a preview resource');}
  res.setHeader('Content-Type',name.endsWith('.js')?'text/javascript':name.endsWith('.css')?'text/css':'text/html');
  res.setHeader('Cache-Control','no-store');
  res.setHeader('X-PKG-06B-Source','8821-v2');
  res.setHeader('Content-Security-Policy',"default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' blob: data:; connect-src 'none'; font-src 'self'; base-uri 'none'; form-action 'none'");
  res.end(fs.readFileSync(path.join(__dirname,name)));
}).listen(port,'127.0.0.1',()=>console.log(JSON.stringify({preview:'PKG-06B v2',port,root:__dirname,pid:process.pid})));
