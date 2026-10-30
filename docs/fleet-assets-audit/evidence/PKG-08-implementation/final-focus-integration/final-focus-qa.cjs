const fs = require('node:fs');
const cp = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = 'C:/Users/steph/.codex/worktrees/pkg08-settings-implementation/oblivionfindings';
const stage = 'C:/Users/steph/.codex/visualizations/2026/09/27/01a0e1a8-1548-7873-a062-e0db43eed7e1';
const packet = `${root}/docs/fleet-assets-audit/evidence/PKG-08-implementation/final-focus-integration`;
const job = process.argv[2];
const attempt = process.argv[3] || '';
assert(/^[a-z0-9-]*$/.test(attempt));
const label = `${job}${attempt ? `-${attempt}` : ''}`;
const files = ['resources/js/pages/fleet-assets/settings/_maps.tsx', 'resources/js/pages/fleet-assets/settings/_ui.tsx', 'resources/js/pages/fleet-assets/settings/maps-focus.test.tsx'];
const args = {
  tests: [`${stage}/final-focus-vite-worker.mjs`, 'tests'],
  build: [`${stage}/final-focus-vite-worker.mjs`, 'build'],
  lint: ['node_modules/eslint/bin/eslint.js', ...files, '--max-warnings=0', '--format=json', '--output-file', `${packet}/eslint.json`],
  types: ['node_modules/typescript/bin/tsc', '--noEmit'],
}[job];
assert(args, 'Unknown QA job');
if ((job==='tests'||job==='build') && attempt) args.push(attempt);
const branch = cp.execFileSync('git',['-C',root,'branch','--show-current'],{encoding:'utf8'}).trim();
assert.equal(branch,'codex/pkg08-final-focus-integration');
assert(!fs.existsSync(`${packet}/${label}-result.json`), 'Keep prior run evidence; choose a new attempt name before a rerun');
const log = fs.openSync(`${packet}/${label}.log`,'wx');
const startedAt = new Date().toISOString();
const env = {...process.env, NODE_OPTIONS:'--max-old-space-size=8192', NO_COLOR:'1'};
const pathKey = Object.keys(env).find(k=>k.toLowerCase()==='path') || 'PATH';
env[pathKey] = ['C:/Users/steph/.config/herd/bin/php84', path.dirname(process.execPath), env[pathKey]].join(path.delimiter);
const proc = cp.spawn(process.execPath,args,{cwd:root,env,stdio:['ignore',log,log],windowsHide:true});
console.log(JSON.stringify({job,attempt,pid:proc.pid,startedAt,log:`${packet}/${label}.log`}));
proc.on('error', error=>{ fs.closeSync(log); throw error; });
proc.on('exit',(code,signal)=>{
  fs.closeSync(log);
  const result = {job,attempt,command:{executable:process.execPath,args,cwd:root},configLoader:(job==='tests'||job==='build')?'runner with repository-root __dirname shim':undefined,cacheDirectory:(job==='tests'||job==='build')?'storage/framework/pkg08-map-regression/final-focus-qa-cache':undefined,startedAt,finishedAt:new Date().toISOString(),exitCode:code,signal};
  fs.writeFileSync(`${packet}/${label}-result.json`,JSON.stringify(result,null,2)+'\n');
  console.log(JSON.stringify(result));
  console.log(fs.readFileSync(`${packet}/${label}.log`,'utf8').split(/\r?\n/).slice(-12).join('\n'));
  process.exitCode = code ?? 1;
});
