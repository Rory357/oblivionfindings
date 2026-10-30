import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import type { Room } from 'livekit-client';
import { afterEach, expect, it, vi } from 'vitest';
import {
    CallConnectionQuality,
    useCallConnectionQuality,
} from './call-connection-quality';

vi.mock('livekit-client', () => ({
    RoomEvent: {
        ConnectionQualityChanged: 'quality',
        ConnectionStateChanged: 'state',
    },
}));
function fixture(initialQuality = 'unknown') {
    const listeners = new Map<string, Set<(...args: unknown[]) => void>>();
    const raw = {
        state: 'connected',
        localParticipant: { connectionQuality: initialQuality },
        on: vi.fn((event: string, fn: (...args: unknown[]) => void) => {
            const list = listeners.get(event) ?? new Set();
            list.add(fn);
            listeners.set(event, list);
        }),
        off: vi.fn((event: string, fn: (...args: unknown[]) => void) =>
            listeners.get(event)?.delete(fn),
        ),
    };
    const emit = (event: string, ...args: unknown[]) =>
        listeners.get(event)?.forEach((fn) => fn(...args));
    return {
        raw,
        room: raw as unknown as Room,
        listeners,
        emit,
        state: (state: string) => {
            raw.state = state;
            emit('state', state);
        },
    };
}
function View({
    room,
    compact = false,
}: {
    room?: Room | null;
    compact?: boolean;
}) {
    return (
        <CallConnectionQuality
            report={useCallConnectionQuality(room)}
            compact={compact}
        />
    );
}
afterEach(() => cleanup());

it('does not infer good quality from a connected room and does not invent a timestamp', async () => {
    const call = fixture();
    render(<View room={call.room} />);
    await waitFor(() => expect(call.raw.on).toHaveBeenCalledTimes(2));
    expect(screen.getByRole('status')).toHaveTextContent(
        'Waiting for a quality report',
    );
    expect(screen.queryByText(/Last quality update/)).not.toBeInTheDocument();
});

it('reads a quality snapshot received before the listeners finished attaching without inventing its age', async () => {
    const call = fixture();
    render(<View room={call.room} />);
    call.raw.localParticipant.connectionQuality = 'good';
    await waitFor(() =>
        expect(screen.getByRole('status')).toHaveTextContent('Good'),
    );
    expect(screen.queryByText(/Last quality update/)).not.toBeInTheDocument();
});

it('uses only the local participant report and records when that report arrived', async () => {
    const call = fixture();
    render(<View room={call.room} />);
    await waitFor(() => expect(call.raw.on).toHaveBeenCalledTimes(2));
    await act(async () =>
        call.emit('quality', 'poor', { connectionQuality: 'poor' }),
    );
    expect(screen.getByRole('status')).toHaveTextContent(
        'Waiting for a quality report',
    );
    await act(async () =>
        call.emit('quality', 'poor', call.raw.localParticipant),
    );
    expect(screen.getByRole('status')).toHaveTextContent('Weak');
    expect(screen.getByRole('status')).toHaveTextContent('Last quality update');
    expect(screen.getByRole('status')).toHaveTextContent('continue by text');
    await act(async () => call.emit('quality', 'excellent', {}));
    expect(screen.getByRole('status')).toHaveTextContent('Weak');
});

it.each(['reconnecting', 'signalReconnecting'])(
    'discards previous quality during %s and waits for a fresh local report',
    async (state) => {
        const call = fixture('excellent');
        render(<View room={call.room} />);
        await waitFor(() => expect(call.raw.on).toHaveBeenCalledTimes(2));
        expect(screen.getByRole('status')).toHaveTextContent('Excellent');
        await act(async () => call.state(state));
        expect(screen.getByRole('status')).toHaveTextContent('Reconnecting');
        await act(async () =>
            call.emit('quality', 'good', call.raw.localParticipant),
        );
        expect(screen.getByRole('status')).toHaveTextContent('Reconnecting');
        await act(async () => call.state('connected'));
        expect(screen.getByRole('status')).toHaveTextContent(
            'Waiting for a quality report',
        );
        await act(async () =>
            call.emit('quality', 'good', call.raw.localParticipant),
        );
        expect(screen.getByRole('status')).toHaveTextContent('Good');
    },
);

it('never shows quality from an earlier call or a late detached listener', async () => {
    const old = fixture('poor');
    const next = fixture();
    const view = render(<View room={old.room} />);
    await waitFor(() => expect(old.raw.on).toHaveBeenCalledTimes(2));
    const oldListener = [...old.listeners.get('quality')!][0];
    view.rerender(<View room={next.room} />);
    await act(async () => oldListener('excellent', old.raw.localParticipant));
    expect(screen.getByRole('status')).toHaveTextContent(
        'Waiting for a quality report',
    );
    expect(old.raw.off).toHaveBeenCalledTimes(2);
    view.rerender(<View room={null} />);
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
});

it('shows compact guidance only for weak or interrupted reported audio', async () => {
    const call = fixture('good');
    render(<View room={call.room} compact />);
    await waitFor(() => expect(call.raw.on).toHaveBeenCalledTimes(2));
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
    await act(async () =>
        call.emit('quality', 'lost', call.raw.localParticipant),
    );
    expect(screen.getByRole('status')).toHaveTextContent('Interrupted');
    expect(screen.queryByText(/Last quality update/)).not.toBeInTheDocument();
    await act(async () =>
        call.emit('quality', 'good', call.raw.localParticipant),
    );
    expect(screen.queryByRole('status')).not.toBeInTheDocument();
});

it('shows disconnected state instead of a prior good report', async () => {
    const call = fixture('good');
    render(<View room={call.room} />);
    await waitFor(() => expect(call.raw.on).toHaveBeenCalledTimes(2));
    await act(async () => call.state('disconnected'));
    expect(screen.getByRole('status')).toHaveTextContent('Disconnected');
    await act(async () =>
        call.emit('quality', 'excellent', call.raw.localParticipant),
    );
    expect(screen.getByRole('status')).not.toHaveTextContent('Excellent');
});

it('treats unknown future SDK values as unavailable rather than healthy', async () => {
    const call = fixture();
    render(<View room={call.room} />);
    await waitFor(() => expect(call.raw.on).toHaveBeenCalledTimes(2));
    await act(async () =>
        call.emit('quality', 'perfect', call.raw.localParticipant),
    );
    expect(screen.getByRole('status')).toHaveTextContent(
        'Waiting for a quality report',
    );
    expect(screen.queryByText(/Last quality update/)).not.toBeInTheDocument();
});
