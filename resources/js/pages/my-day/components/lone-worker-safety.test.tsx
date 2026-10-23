import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { MyDayLoneWorkerSession } from '../lib/types';
import { MyDayLoneWorkerSafety } from './lone-worker-safety';

const state = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('axios', () => ({ default: { get: state.get } }));
vi.mock('@inertiajs/react', () => ({
    router: { on: () => () => {} },
    Link: (props: React.AnchorHTMLAttributes<HTMLAnchorElement>) => (
        <a {...props} />
    ),
}));
vi.mock('@/components/health-safety/lone-workers/session-start-form', () => ({
    SessionStartForm: ({
        pickers,
    }: {
        pickers: { shifts: { id: number }[] };
    }) => (
        <label>
            Draft activity
            <input
                data-shifts={pickers.shifts.map((shift) => shift.id).join(',')}
            />
        </label>
    ),
}));
const session = {
    id: 14,
    status: 'active',
    expected_version: 1,
    lock_version: 1,
    can: { check_in: true, panic: true, end: true },
    observation: {
        state: 'no_check_in',
        real_check_in_at: null,
        received_at: null,
        next_due_at: '2026-10-08T05:00:00Z',
        monitor: { state: 'unknown', checked_at: null },
    },
    source_delivery: null,
    expected_end_at: '2026-10-08T06:00:00Z',
    site: { id: 3, name: 'Kauri' },
} as MyDayLoneWorkerSession;
const options = {
    can: { start_own: true },
    pickers: {
        sites: [],
        workers: [],
        clients: [],
        shifts: [
            { id: 12, user_id: 7 },
            { id: 13, user_id: 8 },
        ],
    },
};
beforeEach(() => state.get.mockReset());
afterEach(cleanup);

it('shows actual observation and separate monitor state with canonical guarded action links', () => {
    render(
        <MyDayLoneWorkerSafety
            session={session}
            shiftId={12}
            workerId={7}
            onWorkingChange={vi.fn()}
        />,
    );
    expect(screen.getByText('No check-in received')).toBeInTheDocument();
    expect(screen.getByText('Monitor not confirmed')).toBeInTheDocument();
    expect(
        screen.queryByText(
            /being monitored|watching this shift|notified immediately/i,
        ),
    ).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Check in' })).toHaveAttribute(
        'href',
        '/health-safety/lone-workers/sessions/14?section=check-ins&action=check-in&return_to=%2Fmy-day',
    );
    expect(
        screen.getByRole('link', { name: 'End safety session' }),
    ).toHaveAttribute(
        'href',
        '/health-safety/lone-workers/sessions/14?action=end&return_to=%2Fmy-day',
    );
    expect(state.get).not.toHaveBeenCalled();
});
it('removes actions when fresh source capabilities no longer allow them', () => {
    render(
        <MyDayLoneWorkerSafety
            session={{
                ...session,
                can: {
                    ...session.can,
                    check_in: false,
                    panic: false,
                    end: false,
                },
            }}
            shiftId={12}
            workerId={7}
            onWorkingChange={vi.fn()}
        />,
    );
    expect(
        screen.queryByRole('link', { name: 'Check in' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('link', { name: 'I need help' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('link', { name: 'End safety session' }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('link', { name: 'Open safety session' }),
    ).toBeInTheDocument();
});
it('requires fresh own-start capability and retains an open draft across a late page refresh', async () => {
    state.get.mockResolvedValue({ data: options });
    const onWorkingChange = vi.fn();
    const view = render(
        <MyDayLoneWorkerSafety
            session={null}
            shiftId={12}
            workerId={7}
            onWorkingChange={onWorkingChange}
        />,
    );
    fireEvent.click(
        await screen.findByRole('button', { name: 'Start safety session' }),
    );
    const input = screen.getByRole('textbox', { name: 'Draft activity' });
    expect(input).toHaveAttribute('data-shifts', '12');
    fireEvent.change(input, { target: { value: 'Keep this draft' } });
    view.rerender(
        <MyDayLoneWorkerSafety
            session={session}
            shiftId={12}
            workerId={7}
            onWorkingChange={onWorkingChange}
        />,
    );
    expect(screen.getByRole('textbox', { name: 'Draft activity' })).toHaveValue(
        'Keep this draft',
    );
    expect(onWorkingChange).toHaveBeenLastCalledWith(true);
});
it('hides start when the fresh options reader denies it', async () => {
    state.get.mockResolvedValue({
        data: { ...options, can: { start_own: false } },
    });
    const view = render(
        <MyDayLoneWorkerSafety
            session={null}
            shiftId={12}
            workerId={7}
            onWorkingChange={vi.fn()}
        />,
    );
    await waitFor(() => expect(view.container).toBeEmptyDOMElement());
    expect(
        screen.queryByRole('button', { name: 'Start safety session' }),
    ).not.toBeInTheDocument();
});
it('shows unavailable options as unavailable and permits a fresh retry', async () => {
    state.get
        .mockRejectedValueOnce(new Error('Unavailable'))
        .mockResolvedValueOnce({ data: options });
    render(
        <MyDayLoneWorkerSafety
            session={null}
            shiftId={12}
            workerId={7}
            onWorkingChange={vi.fn()}
        />,
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Try again' }));
    expect(
        await screen.findByRole('button', { name: 'Start safety session' }),
    ).toBeEnabled();
    expect(state.get).toHaveBeenCalledTimes(2);
});
