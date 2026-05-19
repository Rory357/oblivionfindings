// Add `category="…"` to the first <PageHero ...> in selected module dashboards.
// Idempotent: skips files that already have `category=` on PageHero.
import { readFileSync, writeFileSync } from 'node:fs';

const TARGETS = [
    // fleet
    ['resources/js/pages/fleet-assets/dashboard.tsx', 'fleet'],
    ['resources/js/pages/fleet-assets/vehicles/index.tsx', 'fleet'],
    ['resources/js/pages/fleet-assets/assets/index.tsx', 'fleet'],
    ['resources/js/pages/fleet-assets/maintenance/dashboard.tsx', 'fleet'],
    ['resources/js/pages/fleet-assets/incidents/index.tsx', 'incidents'],
    // ops
    ['resources/js/pages/operations/Index.tsx', 'ops'],
    ['resources/js/pages/clients/index.tsx', 'ops'],
    ['resources/js/pages/staff/index.tsx', 'ops'],
    // hr
    ['resources/js/pages/hr/employees/index.tsx', 'hr'],
    ['resources/js/pages/hr/directory/index.tsx', 'hr'],
    ['resources/js/pages/hr/recruitment/index.tsx', 'hr'],
    ['resources/js/pages/hr/training/index.tsx', 'hr'],
    ['resources/js/pages/hr/onboarding/index.tsx', 'hr'],
    ['resources/js/pages/hr/leave/index.tsx', 'hr'],
    ['resources/js/pages/hr/performance/index.tsx', 'hr'],
    ['resources/js/pages/hr/my/index.tsx', 'hr'],
    // compliance
    ['resources/js/pages/compliance/index.tsx', 'compliance'],
    ['resources/js/pages/compliance/hazards/index.tsx', 'compliance'],
    ['resources/js/pages/health-safety/dashboard.tsx', 'compliance'],
    ['resources/js/pages/health-safety/risk-assessments/index.tsx', 'compliance'],
    ['resources/js/pages/health-safety/restraints/index.tsx', 'compliance'],
    ['resources/js/pages/health-safety/procedures/index.tsx', 'compliance'],
    ['resources/js/pages/health-safety/substances/index.tsx', 'compliance'],
    // incidents
    ['resources/js/pages/incidents/index.tsx', 'incidents'],
    ['resources/js/pages/safeguarding/index.tsx', 'incidents'],
    ['resources/js/pages/health-safety/injuries/index.tsx', 'incidents'],
    ['resources/js/pages/health-safety/drills/index.tsx', 'incidents'],
    // governance
    ['resources/js/pages/Governance/Budgets/Index.tsx', 'governance'],
    ['resources/js/pages/Governance/CeoReports/Index.tsx', 'governance'],
    ['resources/js/pages/Governance/Compliance/Index.tsx', 'governance'],
    ['resources/js/pages/Governance/Documents/Index.tsx', 'governance'],
    ['resources/js/pages/Governance/Evaluations/Index.tsx', 'governance'],
    ['resources/js/pages/Governance/Interests/Index.tsx', 'governance'],
    ['resources/js/pages/Governance/Meetings/Index.tsx', 'governance'],
    ['resources/js/pages/Governance/Performance/Index.tsx', 'governance'],
    ['resources/js/pages/Governance/Risks/Index.tsx', 'governance'],
    ['resources/js/pages/Governance/Strategy/Index.tsx', 'governance'],
    ['resources/js/pages/Governance/TeTiriti/Index.tsx', 'governance'],
    ['resources/js/pages/Governance/Actions/Index.tsx', 'governance'],
    ['resources/js/pages/Governance/Policies/Index.tsx', 'governance'],
    ['resources/js/pages/Governance/Resolutions/Index.tsx', 'governance'],
    // sites
    ['resources/js/pages/sites/index.tsx', 'sites'],
];

let touched = 0;
let missing = 0;
let skipped = 0;

for (const [file, category] of TARGETS) {
    let src;
    try {
        src = readFileSync(file, 'utf8');
    } catch {
        missing += 1;
        console.log('missing', file);
        continue;
    }

    if (!src.includes('<PageHero')) {
        skipped += 1;
        console.log('no PageHero', file);
        continue;
    }

    // Find the first `<PageHero` opening tag and look for an existing category= within it.
    const re = /<PageHero\b/;
    const m = re.exec(src);
    if (!m) {
        skipped += 1;
        continue;
    }
    const tagStart = m.index;
    // Find the matching `>` (handles multi-line tags). Naive search for first `>` not inside braces is fine here.
    let depth = 0;
    let tagEnd = -1;
    for (let i = tagStart; i < src.length; i++) {
        const ch = src[i];
        if (ch === '{') depth += 1;
        else if (ch === '}') depth -= 1;
        else if (ch === '>' && depth === 0) {
            tagEnd = i;
            break;
        }
    }
    if (tagEnd === -1) {
        skipped += 1;
        continue;
    }
    const tag = src.slice(tagStart, tagEnd + 1);
    if (/\bcategory=/.test(tag)) {
        skipped += 1;
        console.log('already categorised', file);
        continue;
    }
    // Insert `\n                    category="<cat>"` after `<PageHero`.
    const updated =
        src.slice(0, tagStart) +
        `<PageHero\n                    category="${category}"` +
        src.slice(tagStart + '<PageHero'.length);
    writeFileSync(file, updated, 'utf8');
    touched += 1;
    console.log(`updated ${file} -> ${category}`);
}

console.log(`\nDone. touched=${touched} skipped=${skipped} missing=${missing}`);
