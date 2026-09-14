import { cleanup, render } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn(), replace: vi.fn() },
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

import {
    MeetingPaperWorkspace,
    type PaperResolution,
} from './MeetingPaperWorkspace';

const baseResolution: PaperResolution = {
    id: 40,
    resolution_reference: 'RES-2026-040',
    title: 'Approve the 2027 budget',
    status: 'closed',
    outcome: 'carried',
    my_vote: {
        id: 1,
        vote: 'for',
        voting_method: 'electronic',
        conflict_declared: false,
        voted_at: '2026-09-10T02:30:00Z',
    },
    action_items: [
        {
            id: 7,
            reference: 'ACT-7',
            title: 'Publish the approved budget',
            status: 'open',
            due_label: '1 Oct 2026',
            assignee_name: 'Aroha Ngata',
            is_mine: true,
            can_open: true,
            open_url: '/governance/actions/7',
        },
        {
            id: 8,
            reference: 'ACT-8',
            title: 'Brief the finance committee',
            status: 'open',
            assignee_name: 'Ben Carter',
            is_mine: false,
            can_open: false,
            open_url: null,
        },
    ],
    restricted_action_items_count: 0,
};

afterEach(cleanup);

describe('MeetingPaperWorkspace', () => {
    it('opens permitted follow-up actions with a return to the same paper', () => {
        render(
            <MeetingPaperWorkspace
                resolution={baseResolution}
                meetingId={12}
                meetingTitle="September board"
                onClose={() => {}}
            />,
        );

        const links = Array.from(
            document.querySelectorAll('[data-test="paper-follow-up-action-open"]'),
        );
        // Only the action the viewer may open renders a link.
        expect(links).toHaveLength(1);

        const href = links[0].getAttribute('href') ?? '';
        const [path, query] = href.split('?');
        expect(path).toBe('/governance/actions/7');
        expect(new URLSearchParams(query).get('return')).toBe(
            '/governance/meetings/12?tab=resolutions&paper=40&focus=follow-ups',
        );
        expect(links[0].textContent).toContain('Update');
    });

    it('shows the vote receipt time as an NZ long-form date, not a locale numeric date', () => {
        render(
            <MeetingPaperWorkspace
                resolution={baseResolution}
                meetingId={12}
                onClose={() => {}}
            />,
        );

        const receipt = document.querySelector(
            '[data-test="paper-vote-receipt-time"]',
        ) as HTMLElement;
        // 02:30 UTC on 10 Sep 2026 is 2:30 pm NZST.
        expect(receipt.textContent).toContain('10 September 2026, 2:30 pm');
        expect(receipt.textContent).not.toMatch(/\d{1,2}\/\d{1,2}\/\d{2,4}/);
        expect(receipt.querySelector('time')?.getAttribute('dateTime')).toBe(
            '2026-09-10T02:30:00Z',
        );
    });
});
