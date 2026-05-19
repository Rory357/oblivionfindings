// Surgical token swap for inline hero banners that still use legacy
// text-white / bg-white/* / border-white/* (instead of the proper
// --primary-foreground tokens). Targets the explicit file list because the
// affected lines are concentrated inside their gradient hero JSX blocks.
//
// Idempotent: re-running on an already-migrated file is a no-op.
import { readFileSync, writeFileSync } from 'node:fs';

const targets = [
    'resources/js/pages/operations/clients/show.tsx',
    'resources/js/pages/hr/employees/show.tsx',
    'resources/js/pages/hr/directory/show.tsx',
    'resources/js/pages/hr/candidates/show.tsx',
    'resources/js/pages/hr/training/index.tsx',
    'resources/js/pages/hr/training/course.tsx',
    'resources/js/pages/hr/training/catalog.tsx',
    'resources/js/pages/hr/goals/show.tsx',
    'resources/js/pages/hr/feedback/index.tsx',
    'resources/js/pages/hr/feedback/respond.tsx',
    'resources/js/pages/hr/feedback/summary.tsx',
    'resources/js/pages/timeline/index.tsx',
];

const replacements = [
    // text-white/X → text-primary-foreground/X
    [/\btext-white(\/\d+)\b/g, 'text-primary-foreground$1'],
    // text-white (bare) → text-primary-foreground
    [/\btext-white\b(?!\/)/g, 'text-primary-foreground'],
    // bg-white/X → bg-primary-foreground/X
    [/\bbg-white(\/\d+)\b/g, 'bg-primary-foreground$1'],
    // border-white/X → border-primary-foreground/X
    [/\bborder-white(\/\d+)\b/g, 'border-primary-foreground$1'],
    // hover:bg-white/X → hover:bg-primary-foreground/X
    [/\bhover:bg-white(\/\d+)\b/g, 'hover:bg-primary-foreground$1'],
    // hover:text-white → hover:text-primary-foreground
    [/\bhover:text-white(\/\d+)?\b/g, (_m, frac) => 'hover:text-primary-foreground' + (frac || '')],
];

let touched = 0;
for (const file of targets) {
    let src;
    try {
        src = readFileSync(file, 'utf8');
    } catch (err) {
        console.log('skip (read failed)', file, err.message);
        continue;
    }
    let updated = src;
    for (const [pattern, replacement] of replacements) {
        updated = updated.replace(pattern, replacement);
    }
    if (updated !== src) {
        writeFileSync(file, updated, 'utf8');
        touched += 1;
        console.log('updated', file);
    } else {
        console.log('unchanged', file);
    }
}
console.log(`\nDone. touched=${touched}/${targets.length}`);
