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
import { SoftwarePolicyEditor, softwarePolicyReply } from './software-policy';
vi.mock('axios', () => ({ default: { get: vi.fn(), put: vi.fn() } }));
vi.mock('@inertiajs/react', () => ({ router: { on: () => () => undefined } }));
const device = { id: 9100, name: 'Reception PC' };
const saved = () => ({
    actor_id: 9001,
    device_id: 9100,
    policy: { version: 2, enabled: false, consent_mode: 'attended' },
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
    render(<SoftwarePolicyEditor actorId={9001} device={device} />);
    fireEvent.click(screen.getByRole('button', { name: 'Software policy' }));
    await screen.findByLabelText('Reason for this change');
}
async function review() {
    fireEvent.click(screen.getByRole('switch'));
    fireEvent.change(screen.getByLabelText('Reason for this change'), {
        target: { value: 'Allow reviewed software after staff consent.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Review policy' }));
    await screen.findByRole('button', { name: 'Save policy' });
}
describe('software policy', () => {
    it('reviews exact computer and consent before a version-bound save', async () => {
        await open();
        expect(axios.put).not.toHaveBeenCalled();
        await review();
        vi.mocked(axios.put).mockResolvedValue({
            data: {
                ...saved(),
                policy: { version: 3, enabled: true, consent_mode: 'attended' },
            },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
        await screen.findByText('Software policy saved');
        expect(axios.put).toHaveBeenCalledWith(
            '/security-devices/devices/9100/endpoint/software-policy',
            {
                expected_actor_id: 9001,
                expected_version: 2,
                enabled: true,
                consent_mode: 'attended',
                reason: 'Allow reviewed software after staff consent.',
            },
            expect.objectContaining({ signal: expect.any(AbortSignal) }),
        );
    });
    it('reads the saved policy after a lost save and never automatically repeats it', async () => {
        await open();
        await review();
        vi.mocked(axios.put).mockRejectedValue(Error('Lost response'));
        fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
        await screen.findByText(/save could not be confirmed/);
        expect(
            screen.getByRole('button', { name: 'Save policy' }),
        ).toBeDisabled();
        vi.mocked(axios.get).mockResolvedValue({
            data: {
                ...saved(),
                policy: { version: 3, enabled: true, consent_mode: 'attended' },
            },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload saved policy' }),
        );
        await waitFor(() => expect(screen.getByRole('switch')).toBeChecked());
        expect(axios.put).toHaveBeenCalledTimes(1);
        expect(screen.getByLabelText('Reason for this change')).toHaveValue('');
    });
    it('rejects changed consent, revisions, actor and device', async () => {
        for (const data of [
            { ...saved(), actor_id: 9002 },
            { ...saved(), device_id: 9101 },
            { ...saved(), policy: { ...saved().policy, version: -1 } },
        ])
            expect(() => softwarePolicyReply(data, 9001, 9100)).toThrow();
        await open();
        await review();
        vi.mocked(axios.put).mockResolvedValue({
            data: {
                ...saved(),
                policy: {
                    version: 3,
                    enabled: true,
                    consent_mode: 'unattended',
                },
            },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
        await screen.findByText(/save could not be confirmed/);
        expect(
            screen.queryByText('Software policy saved'),
        ).not.toBeInTheDocument();
    });
    it('disables editing when management permission is removed', async () => {
        vi.mocked(axios.get).mockResolvedValue({
            data: { ...saved(), can_manage: false },
        });
        await open();
        expect(screen.getByRole('switch')).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Review policy' }),
        ).toBeDisabled();
    });
    it('aborts old computer reads and does not restore private fields after closing', async () => {
        let complete!: (value: unknown) => void;
        vi.mocked(axios.get).mockImplementation(
            () =>
                new Promise((resolve) => {
                    complete = resolve;
                }),
        );
        const ui = render(
            <SoftwarePolicyEditor actorId={9001} device={device} />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Software policy' }),
        );
        const signal = vi.mocked(axios.get).mock.calls[0][1]!.signal!;
        ui.unmount();
        expect(signal.aborted).toBe(true);
        await act(async () => complete({ data: saved() }));
        expect(
            screen.queryByLabelText('Reason for this change'),
        ).not.toBeInTheDocument();
    });
});
