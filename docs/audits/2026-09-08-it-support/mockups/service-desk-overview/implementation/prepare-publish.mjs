import {execFileSync} from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const output = path.join(root, 'docs/audits/2026-09-08-it-support/mockups/service-desk-overview/implementation/publish');
fs.mkdirSync(output, {recursive:true});
const git = (...args) => execFileSync('git', args, {cwd:root, encoding:'utf8', maxBuffer:12*1024*1024});
const base = git('rev-parse','origin/main').trim();
const read = file => fs.readFileSync(path.join(root,file),'utf8').replace(/\r\n/g,'\n');
const original = file => git('show',`${base}:${file}`);
const replaceOnce = (text, before, after) => {
  if(text.split(before).length !== 2) throw new Error('Expected one integration anchor');
  return text.replace(before,after);
};
const controller = 'app/Http/Controllers/It/ItProvisioningController.php';
const index = 'resources/js/pages/it/index.tsx';
let controllerText = original(controller);
const controllerAnchor = "            'avg_first_response_mins' => $avg !== null ? (int) round((float) $avg) : null,\n";
const boardLine = "            'workboard' => app(\\App\\Domain\\It\\Presenters\\ItOverviewWorkboardPresenter::class)->present($user),\n";
if(!read(controller).includes(controllerAnchor + boardLine)) throw new Error('Working controller integration changed');
controllerText = replaceOnce(controllerText,controllerAnchor,controllerAnchor+boardLine);
let indexText = original(index);
const workingIndex = read(index);
const drawer = workingIndex.match(/            <TicketDrawer\n[\s\S]*?            \/>/)?.[0];
if(!drawer || !drawer.includes("only: ['overview', 'summary']")) throw new Error('Drawer integration missing');
indexText = replaceOnce(indexText, '            <TicketDrawer ticketId={peekId} onClose={() => setPeekId(null)} />', drawer);
const oldOverview = indexText.match(/                            <ItOverview\n[\s\S]*?                            \/>/)?.[0];
const newOverview = workingIndex.match(/                            <ItOverview\n[\s\S]*?                            \/>/)?.[0];
if(!oldOverview || !newOverview || !newOverview.includes('propertyMutation.submit')) throw new Error('Overview integration missing');
indexText = replaceOnce(indexText, oldOverview, newOverview);
const completeFiles = [
  'app/Domain/It/Presenters/ItOverviewWorkboardPresenter.php',
  'resources/js/components/it/it-overview.tsx',
  'resources/js/components/it/it-overview-board.tsx',
  'resources/js/components/it/it-overview-workboard.ts',
  'resources/js/components/it/__tests__/it-overview-board.test.tsx',
  'tests/Feature/It/ItOverviewWorkboardTest.php',
];
const prepared = new Map(completeFiles.map(file=>[file,read(file)]));
prepared.set(controller,controllerText);
prepared.set(index,indexText);
for(const [file,text] of prepared) {
  const target=path.join(output,file);
  fs.mkdirSync(path.dirname(target),{recursive:true});
  fs.writeFileSync(target,text);
}
fs.writeFileSync(path.join(output,'manifest.json'), JSON.stringify({base,files:[...prepared.keys()]},null,2)+'\n');
fs.writeFileSync(path.join(output,'commit-message.txt'), 'Improve Service Desk Overview actions and queue visibility\n\nPrioritize first replies, unassigned work and ageing follow-ups with separate\nserver-calculated SLA evidence and complete permission-scoped queue totals.\nReuse ticket drawer and confirmed property mutations; retain the existing hero.\n\nValidation: 30 UI tests and the 35-case backend group passed; current-build\nbrowser checked queue selection, 3-to-6 expansion and drawer close at 1280x720.\nFull TypeScript check retains three unrelated existing My Day test errors.\n');
console.log(JSON.stringify({base,prepared:[...prepared.keys()],output},null,2));
