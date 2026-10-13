import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { RestartPolicyEditor, restartPolicyReply } from './restart-policy';
vi.mock('axios', () => ({ default: { get: vi.fn(), put: vi.fn() } }));
vi.mock('@inertiajs/react', () => ({ router: { on: () => () => undefined } }));
const device = { id: 9100, name: 'Reception PC' };
const saved = () => ({
    actor_id: 9001,
    device_id: 9100,
    policy: {
        version: 2,
        enabled: false,
        consent_mode: 'attended',
        minimum_warning_seconds: 60,
    },
    available: false,
    can_manage: true,
    can_unattended: false,
});
beforeEach(() => {
    vi.resetAllMocks();
    vi.mocked(axios.get).mockResolvedValue({ data: saved() });
});
afterEach(cleanup);
async function open() {
    render(<RestartPolicyEditor actorId={9001} device={device} />);
    fireEvent.click(screen.getByRole('button', { name: 'Restart policy' }));
    await screen.findByLabelText('Reason for this change');
}
async function review() {
    fireEvent.click(screen.getByRole('switch'));
    fireEvent.change(
        screen.getByLabelText('Minimum visible warning (seconds)'),
        { target: { value: '120' } },
    );
    fireEvent.change(screen.getByLabelText('Reason for this change'), {
        target: {
            value: 'Require visible warning before restarting this computer.',
        },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    await screen.findByRole('button', { name: 'Save policy' });
}
describe('restart policy review', () => {
    it('reviews exact consent and warning before saving an actor-bound policy', async () => {
        await open();
        expect(screen.getByRole('switch')).not.toBeChecked();
        expect(axios.put).not.toHaveBeenCalled();
        await review();
        expect(screen.getByText('120 seconds')).toBeInTheDocument();
        vi.mocked(axios.put).mockResolvedValue({
            data: {
                ...saved(),
                policy: {
                    version: 3,
                    enabled: true,
                    consent_mode: 'attended',
                    minimum_warning_seconds: 120,
                },
            },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
        await screen.findByText('Restart policy saved');
        expect(axios.put).toHaveBeenCalledWith(
            '/security-devices/devices/9100/endpoint/restart-policy',
            {
                expected_actor_id: 9001,
                expected_version: 2,
                enabled: true,
                consent_mode: 'attended',
                minimum_warning_seconds: 120,
                reason: 'Require visible warning before restarting this computer.',
            },
            expect.objectContaining({ signal: expect.any(AbortSignal) }),
        );
    });
    it('recovers a lost save by reading rather than submitting again', async () => {
        await open();
        await review();
        vi.mocked(axios.put).mockRejectedValue(Error('Lost reply'));
        fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
        await screen.findByText(/save could not be confirmed/);
        expect(
            screen.queryByRole('button', { name: 'Save policy' }),
        ).not.toBeInTheDocument();
        vi.mocked(axios.get).mockResolvedValue({
            data: {
                ...saved(),
                policy: {
                    version: 3,
                    enabled: true,
                    consent_mode: 'attended',
                    minimum_warning_seconds: 120,
                },
            },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload saved policy' }),
        );
        await waitFor(() => expect(screen.getByRole('switch')).toBeChecked());
        expect(axios.put).toHaveBeenCalledTimes(1);
        expect(screen.getByLabelText('Reason for this change')).toHaveValue('');
    });
    it('rejects a different warning or actor in the save response', async () => {
        await open();
        await review();
        vi.mocked(axios.put).mockResolvedValue({
            data: {
                ...saved(),
                policy: {
                    version: 3,
                    enabled: true,
                    consent_mode: 'attended',
                    minimum_warning_seconds: 60,
                },
            },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
        await screen.findByText(/save could not be confirmed/);
        expect(() =>
            restartPolicyReply({ ...saved(), actor_id: 9002 }, 9001, 9100),
        ).toThrow();
        for (const minimum_warning_seconds of [0, 59, 3601, 60.5, '60'])
            expect(() =>
                restartPolicyReply(
                    {
                        ...saved(),
                        policy: { ...saved().policy, minimum_warning_seconds },
                    },
                    9001,
                    9100,
                ),
            ).toThrow();
    });
    it('blocks changes after manage permission is removed', async () => {
        vi.mocked(axios.get).mockResolvedValue({
            data: { ...saved(), can_manage: false },
        });
        await open();
        expect(screen.getByRole('switch')).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
        expect(axios.put).not.toHaveBeenCalled();
    });
    it('discards late policy data after the computer changes', async () => {
        let complete!: (value: unknown) => void;
        vi.mocked(axios.get).mockImplementation(
            () =>
                new Promise((resolve) => {
                    complete = resolve;
                }),
        );
        const ui = render(
            <RestartPolicyEditor actorId={9001} device={device} />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Restart policy' }));
        const signal = vi.mocked(axios.get).mock.calls[0][1]!.signal!;
        ui.unmount();
        expect(signal.aborted).toBe(true);
        await act(async () => complete({ data: saved() }));
        expect(
            screen.queryByLabelText('Reason for this change'),
        ).not.toBeInTheDocument();
    });
});
