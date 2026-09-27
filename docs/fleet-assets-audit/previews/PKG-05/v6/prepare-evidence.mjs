import fs from 'node:fs/promises';import path from 'node:path';import {fileURLToPath} from 'node:url';
const here=path.dirname(fileURLToPath(import.meta.url));
let source=await fs.readFile(path.join(here,'collect-evidence.mjs'),'utf8');
source=source.replaceAll('../v4/','../v5/').replaceAll('FromV4','FromV5').replace("['v1','v2','v3','v4']","['v1','v2','v3','v4','v5']").replace("'verify-final','verify-navigation'","'verify-final','verify-navigation','verify-overview'");
source=source.replace("const response=await fetch", "const overview=await read(path.join(here,'qa-overview.json'));\nconst response=await fetch");
source=source.replace('domain.results.length+custody.results.length+planning.results.length','domain.results.length+custody.results.length+planning.results.length+overview.results.length').replace('suites,domain,custody,planning,server','suites,domain,custody,planning,overview,server').replace('Two PDF downloads were smoke-tested','Three PDF downloads were smoke-tested');
source=source.replace("url:'http://127.0.0.1:4400/#/fleet-assets/transports/planner'","url:'http://127.0.0.1:4400/#/fleet-assets/transports/overview'");
await fs.writeFile(path.join(here,'collect-evidence.mjs'),source);
let freeze=await fs.readFile(path.join(here,'freeze.mjs'),'utf8');
freeze=freeze.replace('4400/#/fleet-assets/transports/returns','4400/#/fleet-assets/transports/overview').replace('119 browser assertions; 81 synthetic domain assertions','168 browser assertions; 93 synthetic domain assertions').replace('Two PDF downloads','Three PDF downloads').replace('327 prior frozen files','420 prior frozen files');
// Guard before any write when a candidate has already been frozen.
const guard="try{await fs.access(path.join(here,'FREEZE.txt'));throw Error('Already frozen; preserve this candidate')}catch(e){if(e.code!=='ENOENT')throw e}";
freeze=freeze.replace(guard,'');freeze=freeze.replace('const sourceFiles=',guard+'\nconst sourceFiles=');
await fs.writeFile(path.join(here,'freeze.mjs'),freeze);
console.log('Prepared v6 evidence collector and freeze script.');
