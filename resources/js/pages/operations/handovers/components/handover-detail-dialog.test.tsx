import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { HandoverDetailDialog } from './handover-detail-dialog';
import type { Handover } from './shared';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        children,
        href,
        ...props
    }: {
        children: ReactNode;
        href: string;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

const handover: Handover = {
    id: 1,
    status: 'draft',
    handover_notes: 'Primary note',
    client_mood: null,
    medications_due: [],
    cd_verification: null,
    cd_required: false,
    version: 2,
    edit_lock: null,
    incidents_to_note: [],
    follow_up_items: [],
    tasks_pending: [],
    created_at: '2026-09-13T08:00:00+12:00',
    submitted_at: null,
    acknowledged_at: null,
    client: { id: 1, first_name: 'Mere', last_name: 'Demo', site_id: 1 },
    site: { id: 1, name: 'Demo site' },
    outgoing_staff: { id: 1, name: 'Taylor' },
    incoming_staff: null,
    acknowledger: null,
    outgoing_shift: {
        id: 1,
        starts_at: '2026-09-13T08:00:00+12:00',
        ends_at: '2026-09-13T16:00:00+12:00',
        label: 'Day',
        shift_type: 'support',
    },
    incoming_shift: null,
    can_edit: true,
    can_submit: true,
    can_acknowledge: false,
    lock: { locked: false, reason: 'draft', days_left: null, age_days: null },
    worker_notes: {
        shared_notes: 'Towels restocked.',
        people: [
            {
                client_id: 1,
                name: 'Mere Demo',
                notes: 'Activity bag ready.',
                no_updates: false,
                follow_up_needed: false,
            },
            {
                client_id: 2,
                name: 'James Demo',
                notes: 'Walk planned.',
                no_updates: false,
                follow_up_needed: true,
            },
        ],
    },
};
const props = () => ({
    handover,
    open: true,
    onOpenChange: vi.fn(),
    onEdit: vi.fn(),
    onSubmit: vi.fn(),
    onAcknowledge: vi.fn(),
});

describe('handover review', () => {
    it('opens with named notes and keeps history and extra links in their section', () => {
        render(<HandoverDetailDialog {...props()} />);
        expect(
            screen.getByRole('dialog', { name: 'Shift handover' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Activity bag ready.')).toBeVisible();
        expect(screen.getByText('Walk planned.')).toBeVisible();
        expect(screen.queryByText('Created')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'View client' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: /History and links/ }),
        );
        expect(screen.getByText('Created')).toBeVisible();
        expect(
            screen.queryByRole('link', { name: 'Open on MAR chart' }),
        ).not.toBeInTheDocument();
    });
    it('routes an unpaired draft to choose the incoming shift instead of submitting', () => {
        const callbacks = props();
        render(<HandoverDetailDialog {...callbacks} />);
        expect(
            screen.queryByRole('button', { name: 'Send handover' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Choose incoming shift' }),
        );
        expect(callbacks.onEdit).toHaveBeenCalledWith(handover);
        expect(callbacks.onSubmit).not.toHaveBeenCalled();
    });
    it('uses the acknowledgement action for the permitted incoming worker', () => {
        const callbacks = props();
        const submitted = {
            ...handover,
            status: 'submitted',
            can_edit: false,
            can_acknowledge: true,
        };
        render(<HandoverDetailDialog {...callbacks} handover={submitted} />);
        fireEvent.click(
            screen.getByRole('button', { name: "I've read this handover" }),
        );
        expect(callbacks.onAcknowledge).toHaveBeenCalledWith(submitted);
        expect(
            screen.queryByRole('button', { name: /Edit/ }),
        ).not.toBeInTheDocument();
    });
});
