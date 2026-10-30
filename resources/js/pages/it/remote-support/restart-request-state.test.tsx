import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    RestartRequestState,
    type RestartRequest,
} from './restart-request-state';
const inertia = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: inertia }));
beforeEach(() => vi.clearAllMocks());
afterEach(cleanup);
const request: RestartRequest = {
    executeAt: '2026-10-08T03:00:00Z',
    warningSeconds: 120,
    consentMode: 'attended',
    state: 'scheduled',
    cancelRequestedAt: null,
    canCancel: true,
    cancelHref: '/security-devices/devices/42/commands/99/cancel',
};
function mount(restart = request, actorId: number | undefined = 9001) {
    return render(
        <RestartRequestState
            restart={restart}
            actorId={actorId}
            deviceId={42}
            commandId={99}
        />,
    );
}
describe('restart cancellation state', () => {
    it('shows pending cancellation and accepted shutdown without claiming completion', () => {
        const ui = mount({
            ...request,
            state: 'cancel_requested',
            canCancel: false,
        });
        expect(
            screen.getByText(/Do not assume the restart has stopped/),
        ).toBeInTheDocument();
        ui.rerender(
            <RestartRequestState
                restart={{
                    ...request,
                    state: 'awaiting_boot',
                    canCancel: false,
                }}
                actorId={9001}
                deviceId={42}
                commandId={99}
            />,
        );
        expect(
            screen.getByText('Waiting for restart confirmation'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Cancel restart request' }),
        ).not.toBeInTheDocument();
    });
    it('reviews a cancellation reason and retains uncertainty until a fresh read', () => {
        mount();
        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel restart request' }),
        );
        expect(inertia.post).not.toHaveBeenCalled();
        fireEvent.change(screen.getByLabelText('Reason for cancellation'), {
            target: {
                value: 'Staff need this computer for an urgent care task.',
            },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Request cancellation' }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            request.cancelHref,
            {
                expected_actor_id: 9001,
                reason: 'Staff need this computer for an urgent care task.',
            },
            expect.objectContaining({ preserveScroll: true }),
        );
        act(() => inertia.post.mock.calls[0][2].onFinish());
        expect(
            screen.getByText(/cancellation could not be confirmed/),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Check request status' }),
        );
        expect(inertia.reload).toHaveBeenCalledTimes(1);
        expect(inertia.post).toHaveBeenCalledTimes(1);
        act(() => {
            inertia.reload.mock.calls[0][0].onSuccess();
            inertia.reload.mock.calls[0][0].onFinish();
        });
        expect(
            screen.getByRole('button', { name: 'Request cancellation' }),
        ).toBeEnabled();
    });
    it('rejects a foreign cancellation link or missing actor', () => {
        const ui = mount({
            ...request,
            cancelHref: '/security-devices/devices/43/commands/99/cancel',
        });
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
        ui.rerender(
            <RestartRequestState
                restart={request}
                deviceId={42}
                commandId={99}
            />,
        );
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
});
