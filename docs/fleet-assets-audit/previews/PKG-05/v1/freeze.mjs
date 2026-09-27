import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {createHash} from 'node:crypto';
const root=path.dirname(fileURLToPath(import.meta.url));
const repo=path.resolve(root,'../../../../..');
const sha=b=>createHash('sha256').update(b).digest('hex');
const read=p=>fs.readFileSync(p);
const context=JSON.parse(read(path.join(root,'context-manifest.json')));
const domainPaths=[
 'app/Models/ClientTransportBooking.php','app/Http/Controllers/Operations/ClientTransportBookingController.php',
 'app/Models/FleetVehicleBooking.php','app/Http/Controllers/FleetAssets/VehicleBookingController.php',
 'app/Services/Fleet/VehicleBookingAccessService.php','app/Services/Fleet/VehicleReadinessService.php',
 'app/Services/Fleet/ResidentTransportJourneyService.php','app/Services/Fleet/ResidentTransportJourneyScope.php',
 'app/Http/Controllers/FleetAssets/ResidentTransportController.php',
 'app/Models/FleetKeyLog.php','app/Models/FleetShiftHandover.php','app/Http/Controllers/FleetAssets/HandoverController.php',
 'app/Models/FleetOuting.php','app/Models/FleetOutingResident.php','app/Http/Controllers/FleetAssets/OutingController.php',
 'app/Models/FleetChecklistRun.php','app/Models/FleetChecklistTemplateVersion.php',
 'app/Models/FleetChecklistRunAmendment.php','app/Http/Controllers/FleetAssets/ChecklistController.php',
 'app/Http/Controllers/FleetAssets/VehicleEvidenceController.php','app/Services/Fleet/MaintenanceRestrictionService.php',
 'resources/js/pages/fleet-assets/transports/index.tsx','resources/js/pages/fleet-assets/transports/create.tsx',
 'resources/js/pages/fleet-assets/transports/show.tsx','resources/js/pages/fleet-assets/transports/pre-check.tsx',
 'resources/js/pages/fleet-assets/transports/medications.tsx','resources/js/pages/fleet-assets/handovers/show.tsx',
 'resources/js/pages/fleet-assets/outings/show.tsx','resources/css/app.css','resources/css/maintenance-date-time.css'
];
fs.writeFileSync(path.join(root,'canonical-source-hashes.json'),JSON.stringify({baseline:context.head,files:domainPaths.map(p=>({path:p,sha256:sha(read(path.join(repo,p)))}))},null,2)+'\n');
const verification=context.references.map(r=>{const current=sha(read(r.path));return {path:r.path,recorded:r.sha256.toLowerCase(),current,unchanged:current===r.sha256.toLowerCase()}});
fs.writeFileSync(path.join(root,'reference-verification.json'),JSON.stringify({checkedAt:new Date().toISOString(),allUnchanged:verification.every(r=>r.unchanged),references:verification},null,2)+'\n');
const names=['index.html','app.tsx','ui.tsx','preview.css','build.mjs','serve.mjs','check-types.mjs','freeze.mjs','build-inputs.json','context-manifest.json','canonical-source-hashes.json','reference-verification.json','REVIEW-PACKET.md',
 'verify-normal.js','verify-scenarios.js','verify-custody.js','verify-desktop.js','verify-environment.js','verify-outstanding.js',
 'qa-normal.log','qa-scenarios.log','qa-custody.log','qa-desktop.log','qa-environment.log','qa-outstanding.log','qa-console.log','qa-network.log','qa-types.json',
 'dist/app.js','dist/app.js.map','dist/app.css',...fs.readdirSync(path.join(root,'screenshots')).filter(n=>n.endsWith('.png')&&!n.startsWith('debug-')).map(n=>'screenshots/'+n)].sort();
const files=names.map(p=>{const b=read(path.join(root,p));return {path:p,bytes:b.length,sha256:sha(b)}});
const runtimeNames=['index.html','preview.css','dist/app.js','dist/app.css'];
const runtimeSha256=sha(JSON.stringify(files.filter(f=>runtimeNames.includes(f.path))));
const manifest={candidate:'PKG-05 v1',status:'Awaiting exact design approval',frozenAt:new Date().toISOString(),url:'http://127.0.0.1:4395/#/fleet-assets/transports',baseline:context.head,runtimeSha256,fileSetSha256:sha(JSON.stringify(files)),algorithm:'SHA-256 over UTF-8 JSON.stringify of sorted file records (path,bytes,sha256)',files};
const content=JSON.stringify(manifest,null,2)+'\n';fs.writeFileSync(path.join(root,'artifact-manifest.json'),content);
fs.writeFileSync(path.join(root,'FREEZE.txt'),`PKG-05 v1 — Transport allocation and practical handover\nStatus: awaiting Stephan's exact design approval; no implementation.\nFrozen: ${manifest.frozenAt}\nURL: ${manifest.url}\nRuntime SHA-256: ${runtimeSha256}\nFile-set SHA-256: ${manifest.fileSetSha256}\nartifact-manifest.json SHA-256: ${sha(content)}\nFiles: ${files.length}; screenshots: ${names.filter(n=>n.startsWith('screenshots/')).length}\nBaseline: ${context.head}\nDo not overwrite this candidate after approval; create a new version for requested revisions.\nAuthoring helpers, temporary browser snapshots, debug image and mutable server logs are excluded from the approval identity.\n`);
console.log(JSON.stringify({candidate:manifest.candidate,runtimeSha256,fileSetSha256:manifest.fileSetSha256,manifestSha256:sha(content),files:files.length,protectedReferencesUnchanged:verification.every(r=>r.unchanged)},null,2));
