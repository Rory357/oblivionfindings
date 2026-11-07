import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { DeferredPanel } from './deferred-panel';

// A background-loaded panel must never read as "nothing here" while it loads
// or after it fails, and must not flash back to the skeleton on a refresh.

type Listener = (event: { detail: Record<string, any> }) => void;

const mocks = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    listeners: {} as Record<string, Listener[]>,
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: {
        on: (name: string, listener: Listener) => {
            (mocks.listeners[name] ??= []).push(listener);
            return () => {
                mocks.listeners[name] = mocks.listeners[name].filter((l) => l !== listener);
            };
        },
        reload: mocks.reload,
    },
    usePage: () => ({ props: mocks.props, url: '/my-day' }),
}));

const emit = (name: string, detail: Record<string, any>) =>
    act(() => (mocks.listeners[name] ?? []).forEach((listener) => listener({ detail })));

type Card = { total: number; unavailable?: boolean };

function Panel() {
    return (
        <DeferredPanel<{ myTasks: Card }>
            data="myTasks"
            label="Other assigned work"
            fallback={<p>skeleton</p>}
            failed={({ myTasks }) => !!myTasks.unavailable}
        >
            {({ myTasks }) => <p>{myTasks.total} assigned to you</p>}
        </DeferredPanel>
    );
}

describe('DeferredPanel', () => {
    beforeEach(() => {
        mocks.props = { auth: { user: { id: 7 } } };
        mocks.listeners = {};
        mocks.reload.mockReset();
    });

    it('shows the skeleton, not a count, while the panel loads', () => {
        render(<Panel />);

        expect(screen.getByText('skeleton')).toBeTruthy();
        expect(screen.getByLabelText('Loading other assigned work').getAttribute('aria-busy')).toBe('true');
        expect(screen.queryByText(/assigned to you/)).toBeNull();
    });

    it('shows the panel once its data arrives', () => {
        const { rerender } = render(<Panel />);
        mocks.props = { ...mocks.props, myTasks: { total: 3 } };
        rerender(<Panel />);

        expect(screen.getByText('3 assigned to you')).toBeTruthy();
    });

    it('keeps the last data through a page refresh instead of flashing the skeleton', () => {
        mocks.props = { ...mocks.props, myTasks: { total: 3 } };
        const { rerender } = render(<Panel />);
        // A full refresh replaces page props; the card is fetched again afterwards.
        mocks.props = { auth: { user: { id: 7 } } };
        rerender(<Panel />);

        expect(screen.getByText('3 assigned to you')).toBeTruthy();
        expect(screen.queryByText('skeleton')).toBeNull();
    });

    it('never shows another person\'s last data', () => {
        mocks.props = { ...mocks.props, myTasks: { total: 3 } };
        const { rerender } = render(<Panel />);
        mocks.props = { auth: { user: { id: 8 } } };
        rerender(<Panel />);

        expect(screen.queryByText('3 assigned to you')).toBeNull();
        expect(screen.getByText('skeleton')).toBeTruthy();
    });

    it('says the panel could not load, with a retry, when its request finishes without it', () => {
        render(<Panel />);
        emit('start', { visit: { only: ['myTasks'] } });
        emit('finish', { visit: { only: ['myTasks'], cancelled: false, interrupted: false } });

        expect(screen.getByText('Other assigned work could not be loaded')).toBeTruthy();
        expect(screen.getByText('This does not mean there is nothing here. Try again.')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        expect(mocks.reload).toHaveBeenCalledWith({ only: ['myTasks'] });
        expect(screen.getByText('skeleton')).toBeTruthy();
    });

    it('ignores other requests and cancelled ones', () => {
        render(<Panel />);
        emit('finish', { visit: { only: ['inbox'], cancelled: false, interrupted: false } });
        emit('finish', { visit: { only: ['myTasks'], cancelled: true, interrupted: false } });

        expect(screen.getByText('skeleton')).toBeTruthy();
    });

    it('does not report a failure when the data arrived with the response', () => {
        render(<Panel />);
        emit('start', { visit: { only: ['myTasks'] } });
        emit('success', { page: { props: { myTasks: { total: 1 } } } });
        emit('finish', { visit: { only: ['myTasks'], cancelled: false, interrupted: false } });

        expect(screen.queryByText(/could not be loaded/)).toBeNull();
    });

    // A panel whose props load in two background groups (H&S Overview "Needs attention": worklists in 'work',
    // kpis in 'headline'). The faster group landing first is normal, not a failure (R3, 31 Oct).
    function SplitPanel() {
        return (
            <DeferredPanel<{ worklists: Card; kpis: Card }>
                data={['worklists', 'kpis']}
                label="Needs attention"
                fallback={<p>skeleton</p>}
            >
                {({ worklists, kpis }) => <p>{worklists.total + kpis.total} need attention</p>}
            </DeferredPanel>
        );
    }

    it('keeps the skeleton, not an error, when one group has landed and the other is still loading', () => {
        render(<SplitPanel />);
        emit('start', { visit: { only: ['worklists', 'backbone'] } });
        emit('start', { visit: { only: ['kpis'] } });
        emit('success', { page: { props: { worklists: { total: 2 }, backbone: {} } } });
        emit('finish', { visit: { only: ['worklists', 'backbone'], cancelled: false, interrupted: false } });

        expect(screen.queryByText('Needs attention could not be loaded')).toBeNull();
        expect(screen.getByText('skeleton')).toBeTruthy();
    });

    it('still reports a failure when a group finishes without its own key', () => {
        render(<SplitPanel />);
        emit('start', { visit: { only: ['kpis'] } });
        emit('success', { page: { props: { worklists: { total: 2 } } } });
        emit('finish', { visit: { only: ['kpis'], cancelled: false, interrupted: false } });

        expect(screen.getByText('Needs attention could not be loaded')).toBeTruthy();
    });

    it('shows the error, not an empty card, when the server says the read failed', () => {
        mocks.props = { ...mocks.props, myTasks: { total: 0, unavailable: true } };
        render(<Panel />);

        expect(screen.getByText('Other assigned work could not be loaded')).toBeTruthy();
        expect(screen.queryByText('0 assigned to you')).toBeNull();
    });

    it('offers a retry if the data never arrives', () => {
        vi.useFakeTimers();
        try {
            render(<Panel />);
            act(() => {
                vi.advanceTimersByTime(45_000);
            });

            expect(screen.getByText('Other assigned work could not be loaded')).toBeTruthy();
        } finally {
            vi.useRealTimers();
        }
    });
});
