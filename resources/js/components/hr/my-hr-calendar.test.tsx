import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { MyHrCalendar } from './my-hr-calendar';

// Cancelling approved leave from the footer calendar must ask first: the
// menu item only opens the confirmation, and the request goes from there.

type VisitOptions = { onStart?: () => void; onFinish?: () => void };

const router = vi.hoisted(() => ({
    visit: vi.fn(),
    delete: vi.fn((_url: string, options?: VisitOptions) => {
        options?.onStart?.();
        options?.onFinish?.();
    }),
}));

vi.mock('@inertiajs/react', () => ({ router }));
vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const pad = (n: number) => String(n).padStart(2, '0');
const now = new Date();
const month = `${now.getFullYear()}-${pad(now.getMonth() + 1)}`;
const today = `${month}-${pad(now.getDate())}`;

const renderCalendar = () =>
    render(
        <MyHrCalendar
            open
            onClose={vi.fn()}
            feed={{
                month,
                events: {
                    [today]: [
                        {
                            type: 'leave',
                            title: 'Annual Leave',
                            note: 'Approved',
                            ref_id: 42,
                        },
                    ],
                },
            }}
        />,
    );

const openCancelLeave = () => {
    fireEvent.click(
        screen.getByRole('button', { name: 'Actions for Annual Leave' }),
    );
    fireEvent.click(screen.getByRole('menuitem', { name: 'Cancel leave' }));
};

const dialog = () => screen.getByRole('alertdialog');

beforeEach(() => {
    router.delete.mockClear();
});

describe('My HR calendar: cancel approved leave', () => {
    it('asks before cancelling and sends nothing from the menu', () => {
        renderCalendar();
        openCancelLeave();

        expect(router.delete).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Cancel this leave?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            'Annual Leave is cancelled in full, including any other days it covers.',
        );
        expect(dialog()).toHaveTextContent('your approver is told');
    });

    it('keeps the leave when the person backs out', async () => {
        renderCalendar();
        openCancelLeave();

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Keep leave' }),
        );

        expect(router.delete).not.toHaveBeenCalled();
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });

    it('cancels the leave request once confirmed', async () => {
        renderCalendar();
        openCancelLeave();

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel leave' }),
        );

        expect(router.delete).toHaveBeenCalledTimes(1);
        expect(router.delete).toHaveBeenCalledWith(
            '/hr/my/leave/42',
            expect.objectContaining({ preserveScroll: true }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });
});
