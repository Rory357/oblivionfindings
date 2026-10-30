import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { RemoteConnectionDialog } from './_dialogs';
import { remoteJSON, stopRemote } from './remote-viewer';
const stopViewer = vi.hoisted(() => vi.fn());
vi.mock('./remote-viewer', async (importOriginal) => {
    const actual = await importOriginal<typeof import('./remote-viewer')>();
    return {
        ...actual,
        remoteJSON: vi.fn(),
        stopRemote: vi.fn(),
        RemoteViewer: class {
            input = { release: vi.fn(), send: vi.fn() };
            start = vi.fn();
            stop = stopViewer;
        },
    };
});
const options = {
    actor_id: 9001,
    device_id: 9100,
    policy_version: 1,
    enabled: true,
    consent_mode: 'attended',
    allow_control: true,
    max_minutes: 30,
};
const session = {
    uuid: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    device_id: 9100,
    status: 'requested',
    access_mode: 'view',
    consent_mode: 'attended',
    recording: false,
    session_expires_at: new Date(Date.now() + 60000).toISOString(),
    request_expires_at: new Date(Date.now() + 30000).toISOString(),
};
const props = {
    open: true,
    onClose: vi.fn(),
    actorId: 9001,
    device: { id: 9100, name: 'Reception PC' },
    ticketId: 7,
};
beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(remoteJSON).mockResolvedValue(options);
});
afterEach(cleanup);
async function request() {
    await screen.findByRole('button', { name: 'Request connection' });
    fireEvent.change(screen.getByLabelText('Support reason'), {
        target: { value: 'Investigate the printer connection' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Request connection' }));
}
describe('technician request dialog', () => {
    it('defaults to viewing and displays only separately permitted control', async () => {
        vi.mocked(remoteJSON).mockResolvedValue({
            ...options,
            allow_control: false,
        });
        render(<RemoteConnectionDialog {...props} />);
        expect(
            await screen.findByRole('button', { name: 'View screen' }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(
            screen.getByRole('button', { name: 'View and control' }),
        ).toBeDisabled();
        expect(remoteJSON).toHaveBeenCalledTimes(1);
    });
    it('rejects a changed operator or device before offering connection', async () => {
        vi.mocked(remoteJSON).mockResolvedValue({ ...options, actor_id: 8 });
        render(<RemoteConnectionDialog {...props} />);
        await screen.findByText(
            'Connection permissions could not be verified. Close this window and try again.',
        );
        expect(
            screen.queryByRole('button', { name: 'Request connection' }),
        ).not.toBeInTheDocument();
    });
    it('rechecks the identical request after a lost response without changing purpose or scope', async () => {
        const json = vi.mocked(remoteJSON);
        json.mockResolvedValueOnce(options)
            .mockRejectedValueOnce(Error('Lost response'))
            .mockResolvedValueOnce(session);
        render(<RemoteConnectionDialog {...props} />);
        await request();
        await screen.findByRole('button', { name: 'Check same request' });
        expect(screen.getByLabelText('Support reason')).toBeDisabled();
        const first = json.mock.calls[1][2];
        expect(first).toMatchObject({
            expected_actor_id: 9001,
            expected_policy_version: 1,
            access_mode: 'view',
            ticket_id: 7,
            purpose: 'Investigate the printer connection',
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Check same request' }),
        );
        await screen.findByRole('region', { name: 'Remote screen' });
        expect(json.mock.calls[2][2]).toEqual(first);
        expect(json).toHaveBeenCalledTimes(3);
    });
    it('stops a request that resolves after its dialog has closed', async () => {
        let resolve!: (v: unknown) => void;
        vi.mocked(remoteJSON)
            .mockResolvedValueOnce(options)
            .mockImplementationOnce(
                () =>
                    new Promise((r) => {
                        resolve = r;
                    }),
            );
        const view = render(<RemoteConnectionDialog {...props} />);
        await request();
        view.unmount();
        await act(async () => resolve(session));
        await waitFor(() =>
            expect(stopRemote).toHaveBeenCalledWith(session.uuid),
        );
    });
    it('stops an open viewer when its tab becomes hidden and again on unmount safely', async () => {
        vi.mocked(remoteJSON)
            .mockResolvedValueOnce(options)
            .mockResolvedValueOnce(session);
        const view = render(<RemoteConnectionDialog {...props} />);
        await request();
        await screen.findByRole('region', { name: 'Remote screen' });
        const previous = Object.getOwnPropertyDescriptor(document, 'hidden');
        Object.defineProperty(document, 'hidden', {
            configurable: true,
            value: true,
        });
        fireEvent(document, new Event('visibilitychange'));
        expect(stopViewer).toHaveBeenCalledWith(
            'The viewer was hidden. Start a new request to reconnect.',
        );
        view.unmount();
        expect(stopViewer).toHaveBeenCalledTimes(2);
        if (previous) Object.defineProperty(document, 'hidden', previous);
        else Reflect.deleteProperty(document, 'hidden');
    });
});
