import { build } from 'esbuild';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {groups} from './src/screens.js';
const dir = path.dirname(fileURLToPath(import.meta.url));
await build({entryPoints:[path.join(dir,'src/review.jsx')],bundle:true,format:'iife',outfile:path.join(dir,'review.js'),minify:true,define:{'process.env.NODE_ENV':'"production"'},jsx:'automatic',logLevel:'info'});
const js=(await fs.readFile(path.join(dir,'review.js'),'utf8')).replaceAll('</script','<\\/script');
const css=(await fs.readFile(path.join(dir,'review.css'),'utf8'))+'\n'+(await fs.readFile(path.join(dir,'incoming-calls.css'),'utf8'))+'\n'+(await fs.readFile(path.join(dir,'v2.css'),'utf8'));
const hash=createHash('sha256').update(js).digest('base64');
const html=(await fs.readFile(path.join(dir,'index.html'),'utf8'))
 .replace("script-src 'self'",`script-src 'sha256-${hash}'`)
 .replace('<link rel="stylesheet" href="./review.css" />',()=>`<style>${css}</style>`)
 .replace('<link rel="stylesheet" href="./incoming-calls.css" />','').replace('<link rel="stylesheet" href="./v2.css" />','')
 .replace('<script src="./review.js"></script>',()=>`<script>${js}</script>`);
await fs.writeFile(path.join(dir,'review.html'),html);
await fs.writeFile(path.join(dir,'coverage.json'),JSON.stringify({status:'Complete revised mockup set ready for design review; application implementation remains unapproved',groups:groups.map(group=>({...group,url:`http://127.0.0.1:8879/review.html#${group.id}/${group.variants[0].id}`,screenshots:{desktop:`screenshots/${group.id}-desktop.jpg`,phoneWeb:`screenshots/${group.id}-phone-web.jpg`}})),scenarios:groups.reduce((sum,group)=>sum+group.variants.length,0)},null,2)+'\n');
