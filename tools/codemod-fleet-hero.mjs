// One-shot codemod for the FleetHero → PageHero rename in PR3-9.
// Touches every file under resources/js/ that imports FleetHero.
// Idempotent: re-running on already-migrated files is a no-op.
import { readFileSync, writeFileSync } from 'node:fs';
import { execSync } from 'node:child_process';

const root = 'resources/js';
const grepOutput = execSync(
    `node -e "const{execSync}=require('child_process');try{process.stdout.write(execSync('git ls-files ${root}', {stdio:['ignore','pipe','ignore']}).toString())}catch(e){}"`,
    { encoding: 'utf8' },
);

const candidates = grepOutput
    .split('\n')
    .map((s) => s.trim())
    .filter((s) => s.endsWith('.tsx') || s.endsWith('.ts'));

let touched = 0;
let skipped = 0;
for (const file of candidates) {
    let src;
    try {
        src = readFileSync(file, 'utf8');
    } catch {
        continue;
    }
    if (!src.includes('FleetHero')) {
        continue;
    }
    if (file.endsWith('fleet-hero.tsx')) {
        // The shim file itself — leave alone (it IS the migration target).
        skipped += 1;
        continue;
    }
    let updated = src;
    // 1) Rename the default import to a named import from @/components/page.
    updated = updated.replace(
        /import\s+FleetHero\s+from\s+['"]@\/components\/fleet-hero['"];?/g,
        "import { PageHero } from '@/components/page';",
    );
    // 2) Rename the JSX element wherever it appears.
    updated = updated.replace(/<FleetHero\b/g, '<PageHero');
    updated = updated.replace(/<\/FleetHero>/g, '</PageHero>');

    if (updated !== src) {
        writeFileSync(file, updated, 'utf8');
        touched += 1;
        console.log('updated', file);
    } else {
        skipped += 1;
    }
}
console.log(`\nDone. touched=${touched} skipped=${skipped}`);
