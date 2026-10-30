import http from 'node:http';
import path from 'node:path';
import fs from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {spawn} from 'node:child_process';
const root=path.dirname(fileURLToPath(import.meta.url));
const port=Number(process.argv[2]||4400);
const types={'.html':'text/html; charset=utf-8','.js':'application/javascript','.css':'text/css','.json':'application/json','.png':'image/png','.svg':'image/svg+xml','.md':'text/plain; charset=utf-8','.woff2':'font/woff2'};
const python='C:/Users/steph/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe';
function pdf(body){return new Promise((resolve,reject)=>{const p=spawn(python,[path.join(root,'report.py')],{cwd:root,windowsHide:true}),out=[],err=[];const timer=setTimeout(()=>{p.kill();reject(Error('PDF generation timed out'))},15000);p.stdout.on('data',v=>out.push(v));p.stderr.on('data',v=>err.push(v));p.on('error',e=>{clearTimeout(timer);reject(e)});p.on('close',code=>{clearTimeout(timer);code===0?resolve(Buffer.concat(out)):reject(Error(Buffer.concat(err).toString()))});p.stdin.end(body)});}
http.createServer(async(req,res)=>{try{
 const url=new URL(req.url,'http://127.0.0.1');
 if(req.method==='POST'&&url.pathname==='/preview-report.pdf'){
  if(req.headers.origin!==`http://127.0.0.1:${port}`){res.writeHead(403);return res.end('Local preview origin required');}
  const chunks=[];let size=0;for await(const chunk of req){size+=chunk.length;if(size>262144){res.writeHead(413);return res.end('Report too large')}chunks.push(chunk)}
  const body=Buffer.concat(chunks);let report;try{report=JSON.parse(body);if(typeof report.title!=='string'||!Array.isArray(report.sections)||!Array.isArray(report.scope)||report.sections.length>20||!Number.isFinite(report.count))throw Error();}catch{res.writeHead(400);return res.end('Invalid report');}
  const data=await pdf(body);res.writeHead(200,{'Content-Type':'application/pdf','Content-Disposition':'attachment; filename="transport-preview.pdf"','Cache-Control':'no-store','X-PKG05-Version':'v6'});return res.end(data);
 }
 if(req.method!=='GET'&&req.method!=='HEAD'){res.writeHead(405);return res.end();}
 let target=path.resolve(root,'.'+decodeURIComponent(url.pathname));if(target!==root&&!target.startsWith(root+path.sep)){res.writeHead(403);return res.end();}if((await fs.stat(target)).isDirectory())target=path.join(target,'index.html');const data=await fs.readFile(target);res.writeHead(200,{'Content-Type':types[path.extname(target)]||'application/octet-stream','Cache-Control':'no-store','X-PKG05-Baseline':'4ea64c547','X-PKG05-Version':'v6'});res.end(req.method==='HEAD'?undefined:data);
 }catch(e){console.error(e.message);res.writeHead(req.method==='POST'?500:404);res.end(req.method==='POST'?'PDF could not be generated':'PKG-05 preview file not found');}
}).listen(port,'127.0.0.1',()=>console.log(`PKG-05 v6 · ${root} · http://127.0.0.1:${port}/`));
