import { fireEvent, render, screen, within } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

import {
    FrontlineIncidentTable,
    FrontlineNumbers,
    whereItsUpTo,
    type FrontlineIncidentRow,
} from './frontline-incidents';

const row = (
    overrides: Partial<FrontlineIncidentRow>,
): FrontlineIncidentRow => ({
    id: 1,
    ref: 'INC-2026-0397',
    occurred_at: '2026-10-26T06:40:00Z',
    type: 'fall',
    severity: 'medium',
    status: 'submitted',
    potential_severity: null,
    ownership: { state: 'unassigned', owner: null } as never,
    client: {
        id: 7,
        first_name: 'Aroha',
        last_name: 'Kingi',
        site: 'Kauri House',
    },
    reporter: { name: 'Mere Tane' },
    reported_by_me: false,
    ...overrides,
});

// Board G4-4 (Stephan, 1 Nov): her four words, the who-line, frontline boxes.
describe('a support worker’s Incidents list', () => {
    it('says where each incident is up to in her words, with who has it', () => {
        expect(whereItsUpTo(row({}))).toEqual({
            word: 'sent',
            who: 'A manager at Kauri House takes it on',
        });
        expect(
            whereItsUpTo(
                row({
                    ownership: {
                        state: 'assigned',
                        owner: { id: 3, name: 'Hemi Walker' },
                    } as never,
                }),
            ),
        ).toEqual({ word: 'followed_up', who: 'Hemi Walker has it' });
        expect(
            whereItsUpTo(row({ status: 'draft', reported_by_me: true })),
        ).toEqual({
            word: 'draft',
            who: 'Waiting for you to send it',
        });
        expect(
            whereItsUpTo(
                row({ status: 'draft', reporter: { name: 'Rāwiri Ngata' } }),
            ),
        ).toEqual({
            word: 'draft',
            who: 'Waiting for Rāwiri Ngata to send it',
        });
        expect(whereItsUpTo(row({ status: 'closed' }))).toEqual({
            word: 'closed',
            who: null,
        });
        // An owner who has lost access needs replacing: nobody has it yet.
        expect(
            whereItsUpTo(
                row({
                    ownership: {
                        state: 'replacement_needed',
                        owner: null,
                    } as never,
                }),
            ).word,
        ).toBe('sent');
    });

    it('offers Finish only on her own draft, and uses the one severity colour map', () => {
        const onOpen = vi.fn();
        render(
            <FrontlineIncidentTable
                rows={[
                    row({
                        id: 1,
                        status: 'draft',
                        reported_by_me: true,
                        severity: 'low',
                    }),
                    row({
                        id: 2,
                        status: 'draft',
                        reported_by_me: false,
                        severity: 'high',
                    }),
                ]}
                onOpen={onOpen}
                finishHref={(id) => `/incidents/${id}?return_to=%2Fincidents`}
            />,
        );
        const rows = screen.getAllByRole('row').slice(1);
        expect(within(rows[0]).getByText('Your draft')).toBeInTheDocument();
        expect(
            within(rows[0]).getByRole('link', { name: 'Finish' }),
        ).toHaveAttribute('href', '/incidents/1?return_to=%2Fincidents');
        expect(
            within(rows[1]).queryByRole('link', { name: 'Finish' }),
        ).toBeNull();
        expect(within(rows[0]).getByText('Low')).toHaveClass('bg-muted');
        expect(within(rows[1]).getByText('High')).toHaveClass(
            'bg-status-critical-bg',
        );
        expect(
            screen.queryByRole('button', { name: /Actions for/ }),
        ).toBeNull();
        fireEvent.click(rows[1]);
        expect(onOpen).toHaveBeenCalledWith(2);
    });

    it('shows her four boxes, drafts alone in the alert tone', () => {
        const onTab = vi.fn();
        render(
            <FrontlineNumbers
                counts={{
                    own_drafts: 1,
                    drafts: 2,
                    sent: 2,
                    followed_up: 4,
                    closed_recent: 2,
                }}
                tab="all"
                onTab={onTab}
            />,
        );
        for (const label of [
            'Your drafts to finish',
            'Sent',
            'Being followed up',
            'Closed · last 30 days',
        ]) {
            expect(screen.getByText(label)).toBeInTheDocument();
        }
        expect(screen.queryByText('WorkSafe to notify')).toBeNull();
        expect(screen.queryByText('Control room alerts')).toBeNull();
        fireEvent.click(screen.getByText('Your drafts to finish'));
        expect(onTab).toHaveBeenCalledWith('drafts');
    });
});
