import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ChatError, chatRequest } from './api';
import { ChatServiceHealth } from './chat-service-health';

vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
const snapshot = () => ({
    checked_at: new Date().toISOString(),
    rollout_enabled: false,
    services: ['messaging', 'realtime', 'push', 'uploads', 'calling'].map(
        (id) => ({
            id,
            state: id === 'messaging' ? 'passed' : 'not_configured',
            detail: 'Fictional diagnostic result',
            owner: 'Service owner example',
            checked_at: new Date().toISOString(),
            trend: 'unknown',
            compared_at: null,
        }),
    ),
});
beforeEach(() => {
    vi.mocked(chatRequest).mockReset();
    vi.mocked(chatRequest).mockResolvedValue(snapshot());
});
afterEach(() => {
    cleanup();
    vi.useRealTimers();
});
it('starts with all five approved cards unchecked and runs diagnostics only after an explicit action', async () => {
    render(<ChatServiceHealth actorId={1} />);
    expect(screen.getAllByText('Not checked')).toHaveLength(5);
    expect(chatRequest).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Check services' }));
    await screen.findByText('Check passed');
    expect(chatRequest).toHaveBeenCalledWith(
        '/settings/chat/service-health',
        expect.objectContaining({ method: 'POST', body: '{}' }),
    );
    expect(screen.getByText(/Shared Chat rollout is disabled/)).toBeVisible();
    expect(screen.getAllByText('Owner: Service owner example')).toHaveLength(5);
    expect(
        screen.getByRole('heading', { name: 'Push notifications' }),
    ).toBeVisible();
    expect(
        screen.getByText(/not delivery speed or call quality/),
    ).toBeVisible();
});
it.each([403, 419])(
    'keeps previous results explicitly historical on network failure, then clears them on access denial (%s)',
    async (status) => {
        render(<ChatServiceHealth actorId={1} />);
        fireEvent.click(screen.getByRole('button', { name: 'Check services' }));
        await screen.findByText('Check passed');
        vi.mocked(chatRequest).mockRejectedValueOnce(
            new ChatError('private server details', 503),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Check services' }));
        await screen.findByRole('alert');
        expect(screen.getByRole('alert')).toHaveTextContent('not a new check');
        expect(screen.getByText('Check passed')).toBeVisible();
        expect(
            screen.queryByText('private server details'),
        ).not.toBeInTheDocument();
        vi.mocked(chatRequest).mockRejectedValueOnce(
            new ChatError('Denied', status),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Check services' }));
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'no longer have access',
            ),
        );
        expect(screen.getAllByText('Not checked')).toHaveLength(5);
        expect(screen.queryByText('Check passed')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Owner: Service owner example'),
        ).not.toBeInTheDocument();
    },
);
it('ignores incomplete snapshots instead of silently hiding missing service cards', async () => {
    vi.mocked(chatRequest).mockResolvedValue({
        ...snapshot(),
        services: snapshot().services.slice(1),
    });
    render(<ChatServiceHealth actorId={1} />);
    fireEvent.click(screen.getByRole('button', { name: 'Check services' }));
    await screen.findByRole('alert');
    expect(screen.getAllByText('Not checked')).toHaveLength(5);
});
it('prevents overlapping checks and aborts on leaving the settings section', async () => {
    let resolve!: (value: unknown) => void;
    vi.mocked(chatRequest).mockImplementation(
        () =>
            new Promise((done) => {
                resolve = done;
            }),
    );
    const view = render(<ChatServiceHealth actorId={1} />);
    fireEvent.click(screen.getByRole('button', { name: 'Check services' }));
    fireEvent.click(screen.getByRole('button', { name: 'Checking…' }));
    expect(chatRequest).toHaveBeenCalledTimes(1);
    const signal = vi.mocked(chatRequest).mock.calls[0][1]!.signal!;
    view.unmount();
    expect(signal.aborted).toBe(true);
    render(<ChatServiceHealth actorId={1} />);
    await act(async () => {
        resolve(snapshot());
    });
    expect(screen.getAllByText('Not checked')).toHaveLength(5);
});
it('marks an ageing snapshot without silently running another check', async () => {
    vi.useFakeTimers();
    render(<ChatServiceHealth actorId={1} />);
    await act(async () => {
        fireEvent.click(screen.getByRole('button', { name: 'Check services' }));
    });
    await act(async () => {
        vi.advanceTimersByTime(6 * 60000);
    });
    expect(screen.getByRole('status')).toHaveTextContent(
        'over five minutes old',
    );
    expect(chatRequest).toHaveBeenCalledTimes(1);
});

it('clears health results on account replacement and ignores the previous check', async () => {
    let finish!: (value: unknown) => void;
    vi.mocked(chatRequest).mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                finish = resolve;
            }),
    );
    const view = render(<ChatServiceHealth actorId={1} />);
    fireEvent.click(screen.getByRole('button', { name: 'Check services' }));
    const options = vi.mocked(chatRequest).mock.calls[0][1]!;
    expect(new Headers(options.headers).get('X-Chat-Actor')).toBe('1');
    view.rerender(<ChatServiceHealth actorId={2} />);
    expect(options.signal?.aborted).toBe(true);
    await act(async () => {
        finish(snapshot());
    });
    expect(screen.getAllByText('Not checked')).toHaveLength(5);
    fireEvent.click(screen.getByRole('button', { name: 'Check services' }));
    await screen.findByText('Check passed');
    expect(
        new Headers(vi.mocked(chatRequest).mock.calls.at(-1)![1]?.headers).get(
            'X-Chat-Actor',
        ),
    ).toBe('2');
});
