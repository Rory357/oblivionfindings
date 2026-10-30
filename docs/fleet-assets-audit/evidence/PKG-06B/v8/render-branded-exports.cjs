const fs = require('node:fs/promises');
const path = require('node:path');
const {createRequire} = require('node:module');
const root = path.resolve(__dirname,'../../../../..');
const main = createRequire('C:/Users/steph/Herd/oblivionfindings/package.json');
const runtime = createRequire('C:/Users/steph/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/package.json');
const preview = path.join(root,'docs/fleet-assets-audit/previews/PKG-06B/v8');
(async()=>{
  const bundle = path.join(__dirname,'qr-export-validation.cjs');
  await main('esbuild').build({entryPoints:[path.join(preview,'qr-export.ts')],outfile:bundle,bundle:true,platform:'node',format:'cjs',nodePaths:['C:/Users/steph/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules']});
  const brandBundle = path.join(__dirname,'branding-validation.cjs');
  await main('esbuild').build({entryPoints:[path.join(preview,'asset-branding.ts')],outfile:brandBundle,bundle:true,platform:'node',format:'cjs'});
  const {labelSvg,labelsPdfFromPng} = require(bundle);
  const {sampleBranding} = require(brandBundle);
  const folder = path.join(__dirname,'branded-exports'); await fs.mkdir(folder,{recursive:true});
  const samples=[{layout:'a4',width:70,height:40,copies:1,start:1},{layout:'a4',width:70,height:40,copies:3,start:12},{layout:'single',width:80,height:50,copies:2,start:1},{layout:'single',width:60,height:40,copies:1,start:1}];
  for(const sample of samples){const options={...sample,name:'Transfer hoist',includeName:true,branding:sampleBranding};const svg=labelSvg(options);const png=await runtime('sharp')(Buffer.from(svg),{density:600}).png().toBuffer();const bytes=await labelsPdfFromPng(options,png);const name='AS-104-'+sample.layout+'-'+sample.copies+'-labels-DEMO';await fs.writeFile(path.join(folder,name+'.pdf'),bytes);await fs.writeFile(path.join(folder,name+'.svg'),svg);}
  console.log('Four branded PDFs generated using the shared SVG/layout helpers and Sharp rasterisation. These are source-level export artifacts, not captured browser download bytes.');
})().catch(e=>{console.error(e);process.exit(1)});
