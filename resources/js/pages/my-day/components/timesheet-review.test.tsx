import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { MyDayTimesheet } from '../lib/types';
import { TimesheetReviewDialog } from './timesheet-review';

const api = vi.hoisted(() => ({ request: vi.fn(), reload: vi.fn() }));
vi.mock('../lib/task-api', () => ({ taskRequest: api.request }));
vi.mock('@inertiajs/react', () => ({ router: { reload: api.reload } }));
const sheet = {
    id: 5,
    shift_id: 9,
    status: 'draft',
    hours: 8,
    work_date: 'Sat, 12 Sep 2026',
    work_date_iso: '2026-09-12',
    starts_at: '2026-09-12T07:00:00+12:00',
    ends_at: '2026-09-12T15:30:00+12:00',
    break_minutes: 30,
    allocation_method: 'single',
    allocation_revision: 'a'.repeat(64),
    can_save_allocation: true,
    can_submit: true,
    clients_candidates: [
        { id: 1, name: 'Mere Demo', is_primary: true },
        { id: 2, name: 'James Demo', is_primary: false },
        { id: 3, name: 'Aroha Demo', is_primary: false },
    ],
    client_allocations: [
        {
            id: null,
            client_id: 1,
            hours: 8,
            allocation_method: 'single',
            starts_at: null,
            ends_at: null,
            notes: null,
            sort_order: 0,
        },
    ],
} as MyDayTimesheet;

describe('support worker timesheet review', () => {
    beforeEach(() => {
        api.request.mockReset();
        api.reload.mockReset();
    });

    it('makes all three people selectable and preserves the exact paid total when saving', async () => {
        api.request.mockResolvedValue({
            revision: 'b'.repeat(64),
            status: 'draft',
        });
        render(
            <TimesheetReviewDialog
                timesheet={sheet}
                open
                onOpenChange={vi.fn()}
            />,
        );
        expect(screen.getAllByRole('checkbox')).toHaveLength(3);
        fireEvent.click(
            screen.getByRole('button', { name: 'Select everyone' }),
        );
        expect(screen.getByText('3 people selected')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Split evenly' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        await waitFor(() => expect(api.request).toHaveBeenCalledTimes(1));
        const command = api.request.mock.calls[0];
        expect(command.slice(0, 2)).toEqual([
            '/my-day/timesheets/5/allocations',
            'PUT',
        ]);
        expect(
            command[2].client_allocations.map(
                (row: { hours: number }) => row.hours,
            ),
        ).toEqual([2.66, 2.67, 2.67]);
        expect(
            command[2].client_allocations.map(
                (row: { client_id: number }) => row.client_id,
            ),
        ).toEqual([1, 2, 3]);
        expect(
            await screen.findByText('Time split saved to your draft.'),
        ).toBeVisible();
    });

    it('retains unsaved work after a failed draft save and requires an explicit close choice', async () => {
        api.request.mockRejectedValue(new Error('Connection interrupted'));
        const close = vi.fn();
        render(
            <TimesheetReviewDialog
                timesheet={sheet}
                open
                onOpenChange={close}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Select everyone' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Connection interrupted',
        );
        expect(close).not.toHaveBeenCalled();
        expect(screen.getByText('3 people selected')).toBeVisible();
        fireEvent.click(screen.getAllByRole('button', { name: 'Close' })[0]);
        expect(
            screen.getByRole('button', { name: 'Keep editing' }),
        ).toBeVisible();
        expect(close).not.toHaveBeenCalled();
    });

    it('allows drafting on an open shift while withholding submission until clock-out', () => {
        render(
            <TimesheetReviewDialog
                timesheet={{ ...sheet, clock_running: true, can_submit: false }}
                open
                onOpenChange={vi.fn()}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            screen.getByRole('button', { name: 'Submit for approval' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Save draft' }),
        ).toBeEnabled();
    });
});
