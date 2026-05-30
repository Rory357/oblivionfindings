import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { ShiftAuditTimeline } from './shift-audit-timeline';

describe('ShiftAuditTimeline', () => {
    it('renders audit events with actor, body and reason details', () => {
        render(
            <ShiftAuditTimeline
                entries={[
                    {
                        id: 2,
                        type: 'shift_unassigned',
                        occurred_at: '2026-05-30T09:15:00+12:00',
                        subject: 'Shift unassigned',
                        body: 'Sarah Johnson unassigned from standard shift',
                        visibility: 'internal',
                        meta: {
                            event: 'unassigned',
                            previous_user_name: 'Sarah Johnson',
                            reason: 'Staff called in sick',
                        },
                        actor: { id: 10, name: 'Demo Admin' },
                    },
                    {
                        id: 1,
                        type: 'shift_assigned',
                        occurred_at: '2026-05-29T16:00:00+12:00',
                        subject: 'Shift assigned',
                        body: 'Sarah Johnson assigned to standard shift',
                        visibility: 'internal',
                        meta: {
                            event: 'assigned',
                            assigned_user_name: 'Sarah Johnson',
                        },
                        actor: null,
                    },
                ]}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Audit timeline' }),
        ).toBeVisible();

        const events = screen.getAllByRole('listitem');

        expect(within(events[0]).getByText('Shift unassigned')).toBeVisible();
        expect(within(events[0]).getByText('By Demo Admin')).toBeVisible();
        expect(
            within(events[0]).getByText(
                'Sarah Johnson unassigned from standard shift',
            ),
        ).toBeVisible();
        expect(within(events[0]).getByText('Reason')).toBeVisible();
        expect(
            within(events[0]).getByText('Staff called in sick'),
        ).toBeVisible();

        expect(within(events[1]).getByText('Shift assigned')).toBeVisible();
        expect(within(events[1]).getByText('System')).toBeVisible();
    });

    it('renders an empty state when no audit events exist', () => {
        render(<ShiftAuditTimeline entries={[]} />);

        expect(
            screen.getByText('No audit events recorded for this shift yet.'),
        ).toBeVisible();
    });
});
