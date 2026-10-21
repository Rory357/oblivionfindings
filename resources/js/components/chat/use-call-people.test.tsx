import { Button } from '@/components/ui/button';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { chatRequest } from './api';
import { useCallPeople } from './use-call-people';

vi.mock('./api', () => ({ chatRequest: vi.fn() }));
vi.mock('sonner', () => ({ toast: vi.fn() }));
const refreshed = vi.fn(async () => undefined);
const options = {
    reserved: 2,
    max_participants: 3,
    people: [
        { id: 22, name: 'Mere', available: true },
        { id: 33, name: 'Rohan', available: true },
        { id: 44, name: 'Nina', available: false },
    ],
};
function View({
    actor = 11,
    call = 'call-a',
    host = true,
}: {
    actor?: number;
    call?: string;
    host?: boolean;
}) {
    const people = useCallPeople({
        actorId: actor,
        callId: call,
        deviceId: 'device-a',
        invitationId: `invite-${actor}`,
        isHost: host,
        onRefresh: refreshed,
    });
    return (
        <>
            <Button onClick={people.invite}>Invite people</Button>
            <Button
                onClick={() =>
                    people.remove({
                        id: 22,
                        name: 'Mere',
                        status: 'connected',
                        invitation_id: 'mere-invite',
                    })
                }
            >
                Remove Mere
            </Button>
            {people.dialog}
        </>
    );
}
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((done) => {
        resolve = done;
    });
    return { promise, resolve };
}
beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(chatRequest).mockImplementation(async (path) =>
        path.includes('/people?') ? options : undefined,
    );
});
afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

describe('live call people review', () => {
    it('releases a stalled request after a bounded wait without replacing the pending command', async () => {
        vi.mocked(chatRequest).mockImplementation((path, request) =>
            path.includes('/people?')
                ? Promise.resolve(options)
                : new Promise<unknown>((_resolve, reject) => {
                      request?.signal?.addEventListener(
                          'abort',
                          () =>
                              reject(
                                  new DOMException('Timed out', 'AbortError'),
                              ),
                          { once: true },
                      );
                  }),
        );
        render(<View />);
        fireEvent.click(screen.getByText('Invite people'));
        fireEvent.click(await screen.findByRole('checkbox', { name: 'Mere' }));
        vi.useFakeTimers();
        fireEvent.click(screen.getByText('Invite selected people'));
        await act(async () => {
            await vi.advanceTimersByTimeAsync(30000);
        });
        expect(screen.getByText('Retry same action')).toBeEnabled();
        expect(screen.getByText('Close for now')).toBeEnabled();
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.filter(([path]) => path.endsWith('/invite')),
        ).toHaveLength(1);
    });

    it('checks capacity and availability and serialises one invitation with the rendered actor and device', async () => {
        const pending = deferred<unknown>();
        vi.mocked(chatRequest).mockImplementation((path) =>
            path.includes('/people?')
                ? Promise.resolve(options)
                : pending.promise,
        );
        render(<View />);
        fireEvent.click(screen.getByText('Invite people'));
        const mere = await screen.findByRole('checkbox', { name: 'Mere' });
        expect(screen.getByRole('checkbox', { name: /Nina/ })).toBeDisabled();
        fireEvent.click(mere);
        expect(
            screen.getByText('3 / 3 places, including invitations'),
        ).toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: 'Rohan' })).toBeDisabled();
        const submit = screen.getByRole('button', {
            name: 'Invite selected people',
        });
        fireEvent.click(submit);
        fireEvent.click(submit);
        const commands = vi
            .mocked(chatRequest)
            .mock.calls.filter(([path]) => path.endsWith('/invite'));
        expect(commands).toHaveLength(1);
        const body = JSON.parse(commands[0][1]!.body as string);
        expect(body).toMatchObject({
            device_id: 'device-a',
            actor_invitation_id: 'invite-11',
            participant_ids: [22],
        });
        expect(new Headers(commands[0][1]!.headers).get('X-Chat-Actor')).toBe(
            '11',
        );
        expect(body.mutation_id).toMatch(/^[a-f0-9-]{36}$/);
        await act(async () => pending.resolve(undefined));
        expect(refreshed).toHaveBeenCalledTimes(1);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('retains the identical command across an uncertain response closing and reopening', async () => {
        let attempts = 0;
        vi.mocked(chatRequest).mockImplementation(async (path) => {
            if (path.includes('/people?')) return options;
            if (++attempts === 1)
                throw Object.assign(new Error('Network interrupted'), {
                    status: 503,
                });
        });
        render(<View />);
        fireEvent.click(screen.getByText('Invite people'));
        fireEvent.click(await screen.findByRole('checkbox', { name: 'Mere' }));
        fireEvent.click(screen.getByText('Invite selected people'));
        await screen.findByText('Retry same action');
        expect(screen.getByRole('checkbox', { name: 'Mere' })).toBeDisabled();
        fireEvent.click(screen.getByText('Close for now'));
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        // A different action cannot replace the unresolved invitation.
        fireEvent.click(screen.getByText('Remove Mere'));
        expect(
            screen.getByRole('heading', { name: 'Invite to this call' }),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByText('Retry same action'));
        await waitFor(() => expect(refreshed).toHaveBeenCalledTimes(1));
        const commands = vi
            .mocked(chatRequest)
            .mock.calls.filter(([path]) => path.endsWith('/invite'));
        expect(commands).toHaveLength(2);
        expect(commands[1][1]!.body).toBe(commands[0][1]!.body);
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.some(([path]) => path.endsWith('/remove')),
        ).toBe(false);
    });

    it('reviews the exact participant removal and does not imply conversation membership changes', async () => {
        const view = render(<View host={false} />);
        fireEvent.click(screen.getByText('Remove Mere'));
        expect(
            screen.getByText(/Their conversation membership stays the same/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Remove from call' }),
        ).toBeDisabled();
        view.rerender(<View host />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove from call' }),
        );
        await waitFor(() => expect(refreshed).toHaveBeenCalledTimes(1));
        const command = vi
            .mocked(chatRequest)
            .mock.calls.find(([path]) => path.endsWith('/remove'))!;
        expect(JSON.parse(command[1]!.body as string)).toMatchObject({
            participant_id: 22,
            invitation_id: 'mere-invite',
            actor_invitation_id: 'invite-11',
        });
    });

    it('discards late options and acknowledgements after the account or call changes', async () => {
        const pendingOptions = deferred<typeof options>();
        vi.mocked(chatRequest).mockImplementation(() => pendingOptions.promise);
        const view = render(<View />);
        fireEvent.click(screen.getByText('Invite people'));
        const oldSignal = vi.mocked(chatRequest).mock.calls[0][1]!.signal!;
        view.rerender(<View actor={55} />);
        expect(oldSignal.aborted).toBe(true);
        await act(async () => pendingOptions.resolve(options));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        const command = deferred<unknown>();
        vi.mocked(chatRequest).mockImplementation((path) =>
            path.includes('/people?')
                ? Promise.resolve(options)
                : command.promise,
        );
        fireEvent.click(screen.getByText('Invite people'));
        fireEvent.click(await screen.findByRole('checkbox', { name: 'Mere' }));
        fireEvent.click(screen.getByText('Invite selected people'));
        view.rerender(<View actor={55} call="call-b" />);
        await act(async () => command.resolve(undefined));
        expect(refreshed).not.toHaveBeenCalled();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
});
