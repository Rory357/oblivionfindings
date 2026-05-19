// Remove category="..." prop from every PageHero call site to restore one
// unified --primary gradient across the platform.
import { readFileSync, writeFileSync } from 'node:fs';

const files = [
    'resources/js/pages/catering/_hero.tsx',
    'resources/js/pages/clients/index.tsx',
    'resources/js/pages/compliance/hazards/index.tsx',
    'resources/js/pages/compliance/index.tsx',
    'resources/js/pages/fleet-assets/assets/index.tsx',
    'resources/js/pages/fleet-assets/dashboard.tsx',
    'resources/js/pages/fleet-assets/incidents/index.tsx',
    'resources/js/pages/fleet-assets/maintenance/dashboard.tsx',
    'resources/js/pages/fleet-assets/vehicles/index.tsx',
    'resources/js/pages/health-safety/dashboard.tsx',
    'resources/js/pages/health-safety/drills/index.tsx',
    'resources/js/pages/health-safety/injuries/index.tsx',
    'resources/js/pages/health-safety/procedures/index.tsx',
    'resources/js/pages/health-safety/restraints/index.tsx',
    'resources/js/pages/health-safety/risk-assessments/index.tsx',
    'resources/js/pages/health-safety/substances/index.tsx',
    'resources/js/pages/hr/leave/index.tsx',
    'resources/js/pages/hr/recruitment/index.tsx',
    'resources/js/pages/incidents/index.tsx',
    'resources/js/pages/operations/Index.tsx',
    'resources/js/pages/safeguarding/index.tsx',
    'resources/js/pages/sites/index.tsx',
    'resources/js/pages/staff/index.tsx',
];

let touched = 0;
for (const file of files) {
    let src;
    try {
        src = readFileSync(file, 'utf8');
    } catch {
        console.log('skip', file);
        continue;
    }
    const updated = src
        .replace(/^[ \t]*category="(ops|hr|compliance|incidents|governance|sites|fleet)"[ \t]*\r?\n/gm, '')
        .replace(/\s+category="(ops|hr|compliance|incidents|governance|sites|fleet)"/g, '');
    if (updated !== src) {
        writeFileSync(file, updated, 'utf8');
        touched += 1;
        console.log('cleaned', file);
    }
}
console.log(`\nDone. touched=${touched}/${files.length}`);
