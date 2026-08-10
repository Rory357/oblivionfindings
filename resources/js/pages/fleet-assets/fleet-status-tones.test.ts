import { readFileSync } from 'node:fs';

import { describe, expect, it } from 'vitest';

const dailyCheck = readFileSync(
    'resources/js/pages/fleet-assets/daily-check.tsx',
    'utf8',
);
const incidents = readFileSync(
    'resources/js/pages/fleet-assets/incidents/index.tsx',
    'utf8',
);

describe('Fleet semantic status tones', () => {
    it('treats unchecked daily checks as due, while failed checks remain critical', () => {
        expect(dailyCheck).toMatch(
            /tone=\{\s*summary\.unchecked\s*>\s*0\s*\?\s*'warning'\s*:\s*'success'\s*}/,
        );
        expect(dailyCheck).toMatch(
            /vehicle\.checked_today\s*\?\s*vehicle\.check_result\s*===\s*'good'\s*\?\s*'border-primary\/30 bg-primary\/5 dark:bg-primary\/10'\s*:\s*'border-status-critical\/30 bg-status-critical-bg'\s*:\s*'border-status-warning\/30 bg-status-warning-bg'/,
        );
        expect(dailyCheck).toMatch(
            /<Clock\b[^>]*className="(?=[^"]*\bshrink-0\b)(?=[^"]*\btext-status-warning\b)[^"]*"[^>]*\/>/,
        );
    });

    it('maps incident severity to neutral, warning, and critical semantics', () => {
        expect(incidents).toContain(
            "minor: { tone: 'neutral', label: 'Minor' }",
        );
        expect(incidents).toContain(
            "moderate: { tone: 'warning', label: 'Moderate' }",
        );
        expect(incidents).toContain(
            "major: { tone: 'critical', label: 'Major' }",
        );
        expect(incidents).toContain(
            "critical: { tone: 'critical', label: 'Critical' }",
        );
    });

    it('uses visible labels and icons with the incident lifecycle tones', () => {
        expect(incidents).toMatch(
            /reported:\s*{\s*label: 'Reported',\s*cls: 'bg-status-info-bg text-status-info',\s*icon: Clock,?\s*}/,
        );
        expect(incidents).toMatch(
            /investigating:\s*{\s*label: 'Investigating',\s*cls: 'bg-status-warning-bg text-status-warning',\s*icon: Search,?\s*}/,
        );
        expect(incidents).toMatch(
            /resolved:\s*{\s*label: 'Resolved',\s*cls: 'bg-status-success-bg text-status-success',\s*icon: CheckCircle2,?\s*}/,
        );
        expect(incidents).toMatch(
            /closed:\s*{\s*label: 'Closed',\s*cls: 'bg-muted text-muted-foreground',\s*icon: CheckCircle2,?\s*}/,
        );
        expect(incidents).toMatch(/<StatusIcon[^>]*\/>/);
        expect(incidents).toContain('{stat.label}');
    });
});
