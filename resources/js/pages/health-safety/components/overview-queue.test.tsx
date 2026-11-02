import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

import { OverviewQueue, queueBlocks, type OverviewQueueData } from './overview-queue';
import type { WorklistsPayload } from './worklists';

const link = (path: string, params: Record<string, string> = {}) => path + (Object.keys(params).length ? '?' + new URLSearchParams(params).toString() : '');

const queue = (canManage: boolean): OverviewQueueData => ({
    set: 'queue',
    can_manage: canManage,
    worksafe: {
        count: 1,
        rows: [{ id: 7, reference: 'HS-2026-0091', title: 'Fall in the shower — Amelia Wilson', severity: 'high', site: 'Harbour Respite', owner: null, reported_at: '2026-10-31T07:40:00Z', step: 'decide', awaiting_owner: true }],
    },
    to_check: canManage ? { count: 0, rows: [] } : null,
    incidents_need_owner: null,
    close_anyway: null,
    stages: { open: 4, investigating: 17, corrective_action: 6, monitoring: 2, closed_30d: 12 },
});

const lists = {
    attention: [{
        key: 'awaiting_hs_acceptance', label: 'Awaiting H&S acceptance', help: '', count: 2, source_scope: 'readable_subset', source_completeness: 'unknown',
        items: [
            { id: 7, event_reference: 'HS-2026-0091', title: 'Fall in the shower — Amelia Wilson', severity: 'high', reported_at: '2026-10-31T07:40:00Z', site: 'Harbour Respite', client: null, owner: null, action_url: '/health-safety/events/7?action=accept-handover' },
            { id: 9, event_reference: 'HS-2026-0023', title: 'Medication given twice — Oliver Chen', severity: 'medium', reported_at: '2026-04-30T09:00:00Z', site: 'Kauri House', client: null, owner: null, action_url: '/health-safety/events/9?action=accept-handover' },
        ],
    }],
    overdue_corrective_actions: [],
    open_investigations: [],
    notifiable_events: [],
    expiring: [],
} as unknown as WorklistsPayload;

describe('Your queue (G2, approved boards M1/M3)', () => {
    it('lists a review once, in its first block, and counts it once', () => {
        const blocks = queueBlocks(queue(true), lists, link);
        const owner = blocks.find((b) => b.key === 'need_owner')!;
        expect(owner.rows.map((r) => r.key)).toEqual(['own-9']);
        expect(owner.count).toBe(1);
        expect(blocks.find((b) => b.key === 'worksafe')!.rows[0].action?.label).toBe('Take it on');
    });

    it('offers a button only to someone who can take the step, and says who does it otherwise', () => {
        render(<OverviewQueue queue={queue(false)} lists={lists} link={link} />);
        expect(screen.queryByRole('link', { name: /Take it on/ })).toBeNull();
        expect(screen.getByText('Health and safety record this')).toBeInTheDocument();
    });

    it('folds empty blocks into one sentence', () => {
        render(<OverviewQueue queue={queue(true)} lists={lists} link={link} />);
        expect(screen.getByText(/Nothing waiting in actions to check, other things due\./)).toBeInTheDocument();
    });
});
