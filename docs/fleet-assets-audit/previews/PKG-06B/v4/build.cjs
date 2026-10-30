const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const root = path.resolve(__dirname, '../../../../..');
const dependencyRoot = 'C:/Users/steph/Herd/oblivionfindings';
const dep = createRequire(dependencyRoot + '/package.json');
(async () => {
  const build = await dep('esbuild').build({entryPoints:[path.join(__dirname,'profile.tsx')],outfile:path.join(__dirname,'app.js'),bundle:true,minify:true,jsx:'automatic',platform:'browser',format:'esm',nodePaths:[dependencyRoot+'/node_modules'],alias:{'@':root+'/resources/js'},define:{'process.env.NODE_ENV':'"production"'},metafile:true});
  const componentCss = fs.existsSync(path.join(__dirname,'app.css')) ? fs.readFileSync(path.join(__dirname,'app.css'),'utf8') : '';
  const tw = dep('@tailwindcss/node');
  const Scanner = dep('@tailwindcss/oxide').Scanner;
  const candidates = new Scanner({sources:[{base:__dirname,pattern:'*.tsx',negated:false},{base:root+'/resources/js/components',pattern:'**/*.{ts,tsx}',negated:false}]}).scan();
  // Read authoritative checkout CSS; resolving its package imports uses the existing dependency directory.
  const css = fs.readFileSync(root+'/resources/css/app.css','utf8');
  const compiled = await tw.compile(css,{base:dependencyRoot+'/resources/css',onDependency:()=>{}});
  fs.writeFileSync(path.join(__dirname,'app.css'),compiled.build(candidates)+'\n'+componentCss+'\n'+fs.readFileSync(path.join(__dirname,'profile.css'),'utf8'));
  fs.writeFileSync(path.join(__dirname,'../../../evidence/PKG-06B/v4/build-inputs.json'),JSON.stringify(Object.keys(build.metafile.inputs),null,2));
  console.log('PKG-06B v4 built; shared components bundled read-only.');
})().catch(e=>{console.error(e);process.exit(1)});
