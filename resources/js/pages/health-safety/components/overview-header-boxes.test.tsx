import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

import { OverviewHeaderBoxes, OverviewHeaderBoxesLoading, trendText, type HeaderBoxes } from './overview-header-boxes';

const data: HeaderBoxes = {
    set: 'incidents',
    since: '2026-10-01',
    boxes: [
        { key: 'incidents_need_owner', label: 'Incidents need an owner', value: 4, tone: 'alert', href: '/incidents?owner=needs_owner', trend: null, weeks: Array(8).fill(null) },
        { key: 'open_incidents', label: 'Open incidents', value: 14, tone: null, href: '/incidents?tab=open', trend: { delta: 3, since: '2026-10-01' }, weeks: [10, 11, 12, 12, 13, 11, 12, 14] },
        { key: 'actions_overdue', label: 'Actions overdue', value: 5, tone: 'alert', href: '/health-safety/corrective-actions?tab=overdue', trend: { delta: -2, since: '2026-10-01' }, weeks: [7, 7, 6, 6, 5, 6, 5, 5] },
        { key: 'open_hazards', label: 'Open hazards', value: 2, tone: null, href: '/compliance/hazards?tab=active', trend: { delta: 0, since: '2026-10-01' }, weeks: [2, 2, 2, 2, 2, 2, 2, 2] },
    ],
};

describe('Overview header boxes (G2, Stephan’s header)', () => {
    it('says how each box moved, or that there is no trend yet', () => {
        expect(trendText({ trend: { delta: 3, since: '2026-10-01' } })).toBe('↑ 3 since 1 Oct');
        expect(trendText({ trend: { delta: -2, since: '2026-10-01' } })).toBe('↓ 2 since 1 Oct');
        expect(trendText({ trend: { delta: 0, since: '2026-10-01' } })).toBe('No change since 1 Oct');
        expect(trendText({ trend: null })).toBe('No trend yet');
    });

    it('links every box to its list and draws bars only where weeks are known', () => {
        render(<OverviewHeaderBoxes data={data} />);
        expect(screen.getByRole('link', { name: /Incidents need an owner: 4\. No trend yet/ })).toHaveAttribute('href', '/incidents?owner=needs_owner');
        expect(screen.getByRole('link', { name: /Open incidents: 14\. ↑ 3 since 1 Oct/ })).toHaveAttribute('href', '/incidents?tab=open');
        const noTrend = screen.getByRole('link', { name: /Incidents need an owner/ });
        expect(noTrend.querySelectorAll('i')).toHaveLength(0);
        expect(screen.getByRole('link', { name: /Open incidents/ }).querySelectorAll('i')).toHaveLength(8);
    });

    it('names the person’s own four boxes while the numbers load, never a zero', () => {
        render(<OverviewHeaderBoxesLoading set="queue" />);
        for (const label of ['Need an owner', 'WorkSafe: decide or tell', 'Actions overdue', 'Actions to check']) {
            expect(screen.getByText(label)).toBeInTheDocument();
        }
        expect(screen.getAllByText('Counting…')).toHaveLength(4);
        expect(screen.queryByText('0')).toBeNull();
    });
});
