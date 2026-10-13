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
import {
    ServiceActionPolicyEditor,
    servicePolicyReply,
} from './service-action-policy';
vi.mock('axios', () => ({ default: { get: vi.fn(), put: vi.fn() } }));
vi.mock('@inertiajs/react', () => ({ router: { on: () => () => undefined } }));
const device = { id: 9100, name: 'Reception PC' };
function saved() {
    return {
        actor_id: 9001,
        device_id: 9100,
        policy: { version: 2, enabled: false, consent_mode: 'attended' },
        catalogue_digest: 'a'.repeat(64),
        catalogue: [
            {
                name: 'Spooler',
                label: 'Print service',
                operations: ['start', 'stop', 'restart'],
            },
        ],
        available: false,
        can_unattended: false,
    };
}
beforeEach(() => {
    vi.resetAllMocks();
    vi.mocked(axios.get).mockResolvedValue({ data: saved() });
});
afterEach(cleanup);
function mount() {
    return render(<ServiceActionPolicyEditor actorId={9001} device={device} />);
}
async function open() {
    fireEvent.click(
        screen.getByRole('button', { name: 'Service action policy' }),
    );
    await screen.findByLabelText('Reason for this change');
}
async function review() {
    fireEvent.click(
        screen.getByRole('switch', { name: 'Allow approved service actions' }),
    );
    fireEvent.change(screen.getByLabelText('Reason for this change'), {
        target: { value: 'Enable attended support for this workstation.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    await screen.findByRole('button', { name: 'Save policy' });
}
describe('service action policy review and recovery', () => {
    it('loads only on request and saves the exact reviewed scope, version and catalogue', async () => {
        mount();
        expect(axios.get).not.toHaveBeenCalled();
        await open();
        expect(
            screen.getByText(/not available in this deployment/),
        ).toBeInTheDocument();
        expect(screen.getByRole('switch')).not.toBeChecked();
        await review();
        expect(axios.put).not.toHaveBeenCalled();
        expect(
            screen.getByText('Staff allow each action on the computer'),
        ).toBeInTheDocument();
        vi.mocked(axios.put).mockResolvedValue({
            data: {
                ...saved(),
                policy: { version: 3, enabled: true, consent_mode: 'attended' },
            },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
        await screen.findByText('Service action policy saved');
        expect(axios.put).toHaveBeenCalledWith(
            '/it/remote-support/devices/9100/action-policy',
            {
                expected_actor_id: 9001,
                expected_version: 2,
                expected_catalogue_digest: 'a'.repeat(64),
                enabled: true,
                consent_mode: 'attended',
                reason: 'Enable attended support for this workstation.',
            },
            expect.objectContaining({ signal: expect.any(AbortSignal) }),
        );
    });
    it('blocks resubmission after a lost save and reloads the authoritative policy', async () => {
        mount();
        await open();
        await review();
        vi.mocked(axios.put).mockRejectedValue(new Error('Reply lost'));
        fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
        await screen.findByText(/save could not be confirmed/);
        expect(
            screen.queryByRole('button', { name: 'Save policy' }),
        ).not.toBeInTheDocument();
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
    it('rejects a changed actor or unreviewed catalogue in a save reply', async () => {
        mount();
        await open();
        await review();
        vi.mocked(axios.put).mockResolvedValue({
            data: {
                ...saved(),
                catalogue_digest: 'b'.repeat(64),
                policy: { version: 3, enabled: true, consent_mode: 'attended' },
            },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
        await screen.findByText(/save could not be confirmed/);
        expect(
            screen.queryByText('Service action policy saved'),
        ).not.toBeInTheDocument();
        expect(() =>
            servicePolicyReply({ ...saved(), actor_id: 9002 }, 9001, 9100),
        ).toThrow();
    });
    it('discards late policy responses when the selected computer changes', async () => {
        let complete!: (value: unknown) => void;
        vi.mocked(axios.get).mockImplementation(
            () =>
                new Promise((resolve) => {
                    complete = resolve;
                }),
        );
        const ui = mount();
        fireEvent.click(
            screen.getByRole('button', { name: 'Service action policy' }),
        );
        const signal = vi.mocked(axios.get).mock.calls[0][1]!.signal!;
        ui.unmount();
        expect(signal.aborted).toBe(true);
        await act(async () => complete({ data: saved() }));
        expect(
            screen.queryByLabelText('Reason for this change'),
        ).not.toBeInTheDocument();
    });
    it('requires a reason and protects an unsaved policy draft on close', async () => {
        mount();
        await open();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByText(/Give a reason/)).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('Reason for this change'), {
            target: { value: 'Configure this workstation.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            await screen.findByText('Discard unsaved settings?'),
        ).toBeInTheDocument();
        expect(axios.put).not.toHaveBeenCalled();
    });
    it('rejects malformed catalogue targets and operations', () => {
        expect(() =>
            servicePolicyReply(
                {
                    ...saved(),
                    policy: {
                        version: 1,
                        enabled: true,
                        consent_mode: ['attended'],
                    },
                },
                9001,
                9100,
            ),
        ).toThrow();
        for (const catalogue of [
            [{ name: 'Spooler /debug', label: 'Print', operations: ['start'] }],
            [{ name: 'Spooler', label: 'Print', operations: ['execute'] }],
            [{ name: 'Spooler', label: 'Print', operations: [['start']] }],
            [{ name: 'Spooler', label: 'Print', operations: ['stop', 'stop'] }],
            [
                { name: 'Spooler', label: 'Print', operations: ['start'] },
                { name: 'spooler', label: 'Other', operations: ['stop'] },
            ],
        ])
            expect(() =>
                servicePolicyReply({ ...saved(), catalogue }, 9001, 9100),
            ).toThrow();
    });
});
