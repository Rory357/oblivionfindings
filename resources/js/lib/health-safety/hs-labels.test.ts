import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

import { HS_LABELS, hsBadge, hsLabel, humaniseHsValue } from './hs-labels';

const fixture = JSON.parse(
    readFileSync(resolve(__dirname, '../../../../tests/fixtures/health-safety/labels.json'), 'utf8'),
) as { labels: unknown; humanise: Record<string, string> };

describe('H&S labels', () => {
    it('matches the shared contract the server mirror also checks', () => {
        // Apostrophes are typographic on screen; the fixture keeps plain ones so PHP and JSON stay readable.
        const plain = JSON.parse(JSON.stringify(HS_LABELS).replaceAll('’', "'"));
        expect(plain).toEqual(fixture.labels);
    });

    it('makes unknown values readable instead of showing snake_case', () => {
        for (const [input, expected] of Object.entries(fixture.humanise)) {
            expect(humaniseHsValue(input)).toBe(expected);
        }
        expect(hsLabel('review_category', 'new_kind_of_thing')).toBe('New kind of thing');
    });

    it('uses the approved words and says when a value is missing', () => {
        expect(hsLabel('review_category', 'near_miss')).toBe('Near miss');
        expect(hsLabel('action_priority', 'critical')).toBe('Urgent');
        expect(hsLabel('action_status', 'completed')).toBe('Done — waiting to be checked');
        expect(hsLabel('severity', null)).toBe('Not set');
        expect(hsLabel('record_state', undefined)).toBe('Not available');
        expect(hsBadge('severity', 'high')).toEqual({ label: 'High', variant: 'critical' });
        expect(hsBadge('severity', 'odd')).toEqual({ label: 'Odd', variant: 'neutral' });
    });
});
