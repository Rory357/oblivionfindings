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
        expect(dailyCheck).toContain(
            "tone={summary.unchecked > 0 ? 'warning' : 'success'}",
        );
        expect(dailyCheck).toContain(
            "'border-status-warning/30 bg-status-warning-bg'",
        );
        expect(dailyCheck).toContain(
            "'border-status-critical/30 bg-status-critical-bg'",
        );
        expect(dailyCheck).toContain(
            'text-status-warning shrink-0',
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
        expect(incidents).toContain(
            "reported: { label: 'Reported', cls: 'bg-status-info-bg text-status-info', icon: Clock }",
        );
        expect(incidents).toContain(
            "investigating: { label: 'Investigating', cls: 'bg-status-warning-bg text-status-warning', icon: Search }",
        );
        expect(incidents).toContain(
            "resolved: { label: 'Resolved', cls: 'bg-status-success-bg text-status-success', icon: CheckCircle2 }",
        );
        expect(incidents).toContain(
            "closed: { label: 'Closed', cls: 'bg-muted text-muted-foreground', icon: CheckCircle2 }",
        );
        expect(incidents).toMatch(/<StatusIcon[^>]*\/>/);
        expect(incidents).toContain('{stat.label}');
    });
});
