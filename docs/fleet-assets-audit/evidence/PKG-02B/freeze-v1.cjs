const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const repo = path.resolve(__dirname, '../../../..');
const preview = 'docs/fleet-assets-audit/previews/PKG-02B/v1';
const evidence = 'docs/fleet-assets-audit/evidence/PKG-02B/v1';
const sha = (value) => crypto.createHash('sha256').update(value).digest('hex');
const entry = (relative) => {
  const bytes = fs.readFileSync(path.join(repo, relative));
  return { path: relative.replaceAll('\\', '/'), bytes: bytes.length, sha256: sha(bytes) };
};
function walk(relative) {
  return fs.readdirSync(path.join(repo, relative), {withFileTypes:true})
    .filter(x => x.name !== '.vite')
    .flatMap(x => x.isDirectory() ? walk(`${relative}/${x.name}`) : [`${relative}/${x.name}`]);
}
const manifestPath = path.join(repo, evidence, 'manifest.json');
if (fs.existsSync(manifestPath)) throw new Error('v1 is already frozen; create a new version.');
const files = walk(preview).sort().map(entry);
const candidateId = sha(files.map(x => `${x.path}\t${x.sha256}\n`).join(''));
const references = [
  'AGENTS.md', 'DESIGN.md', 'design_styles/POPUP_STYLE_GUIDE.md',
  'design_styles/WORK_RECORD_STYLE_GUIDE.md', 'docs/architecture/single-tenant-application.md',
  'routes/fleet-assets.php', 'resources/js/pages/fleet-assets/vehicles/show.tsx',
  ...['Vehicle','Checklist','DailyCheck','Inspection','ServiceSchedule','Mileage','Compliance','MaintenanceAttachment'].map(x => `app/Http/Controllers/FleetAssets/${x}Controller.php`),
  ...['Asset','FleetChecklistTemplate','FleetChecklistRun','FleetServiceSchedule','FleetVehicleStateSnapshot','FleetVehicleBooking'].map(x => `app/Models/${x}.php`),
  ...['MaintenanceCheck','MaintenanceReport','MaintenanceRestriction'].map(x => `app/Services/Fleet/${x}Service.php`),
  'resources/css/app.css', 'resources/css/maintenance-date-time.css',
  ...['page/page-header','page/grouped-profile-nav','wizard/shell','ui/button','ui/dialog','ui/status-badge','ui/popover','ui/command','ui/file-dropzone','fleet-assets/maintenance/date-time-field','hr/leave-calendar-range'].map(x => `resources/js/components/${x}.tsx`),
  'docs/fleet-assets-audit/evidence/PKG-02B/reuse-contract-v1.md',
].sort().map(entry);
const manifest = {
  version:'PKG-02B-v1', candidateId, frozenAt:new Date().toISOString(),
  previewUrl:'http://127.0.0.1:4336/PKG-02B/v1/',
  branch:'codex/pkg-02b-vehicle-profile-design', base:'5307692ec59be84f3503c06354419b7da95be805',
  task:'01a0c2bb-fcff-7cb1-8bab-882d84477c6c', worktree:'5b0a', model:'gpt-6-astra', effort:'xhigh',
  status:'Frozen design candidate; Main review and Stephan exact mockup approval pending; no implementation release',
  candidateIdAlgorithm:'SHA256 of UTF-8 concatenation of sorted relative path, TAB, lowercase SHA256, LF for each preview file. Includes sources, README and dist; excludes .vite. Does not hash this manifest into itself.',
  files, references,
  evidence:walk(evidence).filter(x => !x.endsWith('/manifest.json')).sort().map(entry),
  limitations:['Genuine 200% browser zoom unverified; resized viewports are not zoom evidence.','Synthetic UI only; no backend, authorisation, persistence, production or operational acceptance proof.','Files 01–20 are development/pre-freeze evidence; only numbered files 21 onward show the exact final bundle.'],
};
fs.writeFileSync(manifestPath,JSON.stringify(manifest,null,2)+'\n');
console.log(JSON.stringify({candidateId,files:files.length,references:references.length,evidence:manifest.evidence.length,manifest:manifestPath},null,2));
