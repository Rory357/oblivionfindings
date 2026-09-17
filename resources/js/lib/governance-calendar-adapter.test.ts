import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';

import type { Decorated } from '@/pages/sites/calendar/_parts';

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { visit } }));

import {
    createGovernanceCalendarAdapter,
    GOVERNANCE_CALENDAR_SOURCES,
    meetingCreateHref,
} from './governance-calendar-adapter';

describe('Governance calendar adapter', () => {
    afterEach(() => {
        visit.mockReset();
        vi.unstubAllGlobals();
    });

    it('colours every Governance source with a --src token triple in app.css', () => {
        const css = readFileSync(resolve(__dirname, '../../css/app.css'), 'utf8');

        for (const source of GOVERNANCE_CALENDAR_SOURCES) {
            expect(css).toMatch(new RegExp(`--src-${source.key}:\\s*oklch\\(`));
            expect(css).toMatch(new RegExp(`--src-${source.key}-bg:\\s*oklch\\(`));
            expect(css).toMatch(new RegExp(`--src-${source.key}-ln:\\s*oklch\\(`));
        }
    });

    it('uses plain source names that say where an entry comes from', () => {
        expect(GOVERNANCE_CALENDAR_SOURCES.map((s) => s.label)).toEqual([
            'Meetings',
            'Voting deadlines',
            'Requirements',
            'Policy reviews',
        ]);
        for (const source of GOVERNANCE_CALENDAR_SOURCES) {
            expect(source.note).toBeTruthy();
            expect(source.note).not.toMatch(/obligation|manual|auto-synced/i);
        }
    });

    it('hides the approval meter and points "Mine" at My work', () => {
        const adapter = createGovernanceCalendarAdapter();

        expect(adapter.showApprovalMeter).toBe(false);
        expect(adapter.mineLink).toEqual({ href: '/governance/my-work', label: 'Open My work' });
        expect(adapter.initialSources).toEqual(['meetings', 'decisions', 'obligations', 'policies']);
        expect(adapter.backLink).toBeUndefined();
    });

    it('starts with only the sources the server allowed', () => {
        const adapter = createGovernanceCalendarAdapter({
            sourceFilters: GOVERNANCE_CALENDAR_SOURCES.slice(0, 2),
        });

        expect(adapter.initialSources).toEqual(['meetings', 'decisions']);
    });

    it('loads the feed for the window and keeps source availability', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                events: [{ id: 'governance:meeting:1' }],
                availability: { obligations: 'unavailable' },
            }),
        });
        vi.stubGlobal('fetch', fetchMock);

        const adapter = createGovernanceCalendarAdapter();
        const result = await adapter.loadItems({
            start: new Date('2026-09-01T00:00:00Z'),
            end: new Date('2026-10-01T00:00:00Z'),
            committeeId: 4,
        });

        const url = String(fetchMock.mock.calls[0][0]);
        expect(url).toContain('/governance/calendar/items?');
        expect(url).toContain('committee_id=4');
        expect(result.events).toHaveLength(1);
        expect(result.availability).toEqual({ obligations: 'unavailable' });
    });

    it('reports a failed load with its status so the calendar can explain it', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 403 }));

        const adapter = createGovernanceCalendarAdapter();

        await expect(
            adapter.loadItems({ start: new Date(), end: new Date() }),
        ).rejects.toMatchObject({ status: 403 });
    });

    it('seeds a new meeting with the NZ calendar date, not the UTC date', () => {
        // 12:30 UTC on 17 September is 00:30 on 18 September in New Zealand.
        expect(meetingCreateHref({ date: new Date('2026-09-17T12:30:00Z'), hour: 9 })).toBe(
            '/governance/meetings/create?date=2026-09-18&hour=9',
        );
        expect(meetingCreateHref(null)).toBe('/governance/meetings/create');

        createGovernanceCalendarAdapter().onCreate?.({ date: new Date('2026-09-17T12:30:00Z') });
        expect(visit).toHaveBeenCalledWith('/governance/meetings/create?date=2026-09-18');
    });

    it('opens an entry at its link', () => {
        createGovernanceCalendarAdapter().onOpenItem?.({ link: '/governance/meetings/7' } as Decorated);

        expect(visit).toHaveBeenCalledWith('/governance/meetings/7');
    });
});
