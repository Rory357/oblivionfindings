// Migrate `PageHeader` callers to `<PageHero variant="compact">`.
// Idempotent. Handles three import shapes:
//   1) `import PageHeader from '@/components/page-header';`   (default import)
//   2) Same file may already `import { PageHero, ... } from '@/components/page'`
//   3) Same file may already `import { ... } from '@/components/page'` but not PageHero
import { readFileSync, writeFileSync } from 'node:fs';
import { execSync } from 'node:child_process';

const filesRaw = execSync('git grep -l "@/components/page-header" -- resources/js/pages', {
    encoding: 'utf8',
}).trim();
const files = filesRaw.split('\n').filter((s) => s.endsWith('.tsx') || s.endsWith('.ts'));

let touched = 0;
let skipped = 0;

for (const file of files) {
    let src = readFileSync(file, 'utf8');
    if (!src.includes('PageHeader')) {
        skipped += 1;
        continue;
    }

    // 1) JSX tag swap.
    let updated = src
        .replace(/<PageHeader\b/g, '<PageHero variant="compact"')
        .replace(/<\/PageHeader>/g, '</PageHero>');

    // 2) Import handling.
    // Remove the old default import line(s).
    const oldImportRE = /import\s+PageHeader\s+from\s+['"]@\/components\/page-header['"];?\s*\n?/g;
    updated = updated.replace(oldImportRE, '');

    // Now add PageHero to @/components/page imports.
    // Case A: existing `import { X, Y } from '@/components/page';` line — merge.
    const pageImportRE = /import\s+\{\s*([^}]+)\s*\}\s+from\s+['"]@\/components\/page['"];?/;
    const m = updated.match(pageImportRE);
    if (m) {
        const existing = m[1].split(',').map((s) => s.trim()).filter(Boolean);
        // Skip type-only entries when checking for duplicate `PageHero`.
        const hasPageHero = existing.some((id) => id.replace(/^type\s+/, '') === 'PageHero');
        if (!hasPageHero) {
            const newList = [...existing, 'PageHero'].sort().join(', ');
            updated = updated.replace(pageImportRE, `import { ${newList} } from '@/components/page';`);
        }
    } else {
        // Case B: no existing import — add one. Insert after the LAST `@/components/...` import
        // so it stays grouped with neighbouring component imports.
        const componentImportRE = /^import\s+[^;]+?from\s+['"]@\/components\/[^'"]+['"];?\s*$/gm;
        const matches = [...updated.matchAll(componentImportRE)];
        const insertion = "import { PageHero } from '@/components/page';\n";
        if (matches.length > 0) {
            const last = matches[matches.length - 1];
            const idx = last.index + last[0].length;
            updated = updated.slice(0, idx) + '\n' + insertion + updated.slice(idx + 1);
        } else {
            // Fallback: prepend.
            updated = insertion + updated;
        }
    }

    if (updated !== src) {
        writeFileSync(file, updated, 'utf8');
        touched += 1;
        console.log('updated', file);
    } else {
        skipped += 1;
    }
}

console.log(`\nDone. touched=${touched} skipped=${skipped} / ${files.length}`);
