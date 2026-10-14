import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    DeliveryRecoveryCard,
    type DeliveryRecovery,
} from './delivery-recovery-card';
import {
    confirmedRecovery,
    type RecoveryAttempt,
} from './delivery-recovery-data';
const scope = vi.hoisted(() => ({
    actor: 7,
    manage: true,
    profiles: true,
    reload: vi.fn(),
}));
vi.mock('axios');
vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );
    return {
        ...actual,
        usePage: () => ({
            props: {
                auth: {
                    user: { id: scope.actor },
                    can: {
                        'securityDevices.integrations.manage': scope.manage,
                        'securityDevices.devices.view': scope.profiles,
                    },
                },
            },
        }),
        router: {
            ...actual.router,
            on: (...args: Parameters<typeof actual.router.on>) =>
                actual.router.on(...args),
            reload: scope.reload,
        },
    };
});
const fixture: DeliveryRecovery = {
    actor_id: 7,
    contracts: {
        envelope_current: 2,
        envelope_accepted: [1, 2],
        payloads: {
            observation: { current: 2, accepted: [1, 2] },
            event: { current: 2, accepted: [1, 2] },
        },
        commands: {
            standard_current: 6,
            break_glass_current: 7,
            accepted: [6, 7],
            retry_policy: 'Reconcile actual state before any retry',
        },
    },
    dead_letters: {
        visible: true,
        total: 2,
        shown: 2,
        truncated: false,
        note: 'Safe recovery metadata.',
        rows: [
            {
                id: 11,
                review_token: 'a'.repeat(64),
                reason_code: 'handler_failed',
                reason_message: 'Full bounded handler failure detail.',
                consumer: 'observation-projector',
                message_reference: 'message11',
                site: {
                    id: 2,
                    name: 'Coast Site',
                    href: '/security-devices/sites/2',
                },
                replay_count: 0,
                created_at: '2026-10-13T00:00:00Z',
                schema_version: 2,
                payload_version: 2,
                message_type: 'observation',
                can_replay: true,
                can_discard: false,
                pending_replay: false,
                operator_note: 'Review the original signed evidence.',
            },
            {
                id: 12,
                review_token: 'b'.repeat(64),
                reason_code: 'invalid_signature',
                reason_message: 'Full bounded authentication failure detail.',
                consumer: 'event-projector',
                message_reference: 'message12',
                site: null,
                replay_count: 0,
                created_at: null,
                schema_version: null,
                payload_version: null,
                message_type: null,
                can_replay: false,
                can_discard: true,
                pending_replay: false,
                operator_note: 'Original evidence is retained.',
            },
        ],
    },
};
function reply(
    body: Record<string, unknown>,
    operation: 'replay' | 'discard' = 'replay',
    id = 11,
) {
    return {
        decision: {
            dead_letter_id: id,
            site_id: id === 11 ? 2 : null,
            consumer: id === 11 ? 'observation-projector' : 'event-projector',
            message_reference: id === 11 ? 'message11' : 'message12',
            reason_code: id === 11 ? 'handler_failed' : 'invalid_signature',
            confirms:
                operation === 'replay'
                    ? 'replay_request_recorded'
                    : 'discard_recorded',
            confirms_processing: false as const,
            confirms_device_command: false as const,
        },
        receipt: {
            schema_version: 1,
            owner: 'Monitoring delivery recovery',
            request_uuid: String(body.request_uuid),
            actor_id: 7,
            operation,
            dead_letter_id: id,
            site_id: id === 11 ? 2 : null,
            committed_at: '2026-10-13T00:00:00Z',
        },
        current: {
            state:
                operation === 'replay'
                    ? ('replay_requested' as const)
                    : ('discarded' as const),
            replay_count: 0,
            resolved_at:
                operation === 'discard' ? '2026-10-13T00:00:00Z' : null,
        },
    };
}
function open(kind: 'Replay' | 'Discard' = 'Replay') {
    const name = kind === 'Replay' ? 'Handler Failed' : 'Invalid Signature';
    fireEvent.keyDown(
        screen.getByRole('button', { name: 'Actions for ' + name }),
        { key: 'ArrowDown' },
    );
    fireEvent.click(screen.getByRole('menuitem', { name: kind }));
}
function reason(value = 'Original signed evidence reviewed') {
    fireEvent.change(screen.getByLabelText('Operational reason'), {
        target: { value },
    });
}
const failure = (status: number, errors?: Record<string, string[]>) => ({
    isAxiosError: true,
    response: { status, data: { errors } },
});
beforeEach(() => {
    vi.resetAllMocks();
    scope.actor = 7;
    scope.manage = true;
    scope.profiles = true;
    vi.mocked(axios.isAxiosError).mockImplementation((e) =>
        Boolean(e && typeof e === 'object' && 'isAxiosError' in e),
    );
    vi.mocked(axios.post).mockImplementation(async (url, body) => ({
        data: reply(
            body as Record<string, unknown>,
            String(url).endsWith('/discard') ? 'discard' : 'replay',
            String(url).includes('/12/') ? 12 : 11,
        ),
    }));
    vi.mocked(axios.get).mockResolvedValue({
        data: { actor_id: 7, rows: [], next_before_id: null },
    });
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});
describe('Delivery recovery shared records and original decisions', () => {
    it('retains all version and failure facts in compact records and shared evidence details', () => {
        const view = render(
            <DeliveryRecoveryCard delivery={fixture} view="table" />,
        );
        expect(screen.getByText('Event payload')).toBeInTheDocument();
        expect(screen.getAllByText('v2')).toHaveLength(3);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View evidence for Handler Failed',
            }),
        );
        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText('Full bounded handler failure detail.'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText('Review the original signed evidence.'),
        ).toBeInTheDocument();
        expect(within(dialog).getByText('message11')).toBeInTheDocument();
        expect(
            within(dialog).getByText('Transport version'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText('Replay available'),
        ).toBeInTheDocument();
        fireEvent.click(
            within(dialog).getAllByRole('button', {
                name: 'Close',
            })[0],
        );
        scope.profiles = false;
        view.rerender(<DeliveryRecoveryCard delivery={fixture} view="table" />);
        expect(
            screen.queryByRole('link', { name: 'Coast Site' }),
        ).not.toBeInTheDocument();
    });
    it('keeps a reason until explicit discard and starts another record with a blank reason', () => {
        const storage = vi.spyOn(Storage.prototype, 'setItem');
        render(<DeliveryRecoveryCard delivery={fixture} />);
        open();
        reason('PRIVATE-REASON-FIRST-RECORD');
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(screen.getByLabelText('Operational reason')).toHaveValue(
            'PRIVATE-REASON-FIRST-RECORD',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Discard and continue',
            }),
        );
        open('Discard');
        expect(screen.getByLabelText('Operational reason')).toHaveValue('');
        expect(storage).not.toHaveBeenCalled();
    });
    it('records one pending request and keeps its acknowledged decision until Done', async () => {
        let resolve!: (value: unknown) => void;
        vi.mocked(axios.post).mockImplementation(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        render(<DeliveryRecoveryCard delivery={fixture} />);
        open();
        reason();
        const submit = screen.getByRole('button', {
            name: 'Request replay',
        });
        fireEvent.click(submit);
        fireEvent.click(submit);
        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(
            screen.getByRole('button', { name: 'Recording…' }),
        ).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        const body = vi.mocked(axios.post).mock.calls[0][1] as Record<
            string,
            unknown
        >;
        resolve({ data: reply(body) });
        await screen.findByRole('heading', { name: 'Replay request recorded' });
        expect(scope.reload).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        expect(scope.reload).toHaveBeenCalledOnce();
        expect(axios.patch).not.toHaveBeenCalled();
    });
    it('retains server validation and permits a corrected new UUID without reusing the failed request', async () => {
        vi.mocked(axios.post).mockRejectedValueOnce(
            failure(422, { reason: ['Record what you verified.'] }),
        );
        render(<DeliveryRecoveryCard delivery={fixture} />);
        open();
        reason('Draft reason preserved');
        fireEvent.click(screen.getByRole('button', { name: 'Request replay' }));
        await screen.findByText('Record what you verified.');
        expect(screen.getByLabelText('Operational reason')).toHaveValue(
            'Draft reason preserved',
        );
        reason('Corrected operational reason');
        fireEvent.click(screen.getByRole('button', { name: 'Request replay' }));
        await screen.findByRole('heading', { name: 'Replay request recorded' });
        const calls = vi.mocked(axios.post).mock.calls;
        expect((calls[0][1] as Record<string, unknown>).request_uuid).not.toBe(
            (calls[1][1] as Record<string, unknown>).request_uuid,
        );
    });
    it('recovers a lost response using the original UUID and never repeats a recovery operation', async () => {
        let input: Record<string, unknown> = {};
        vi.mocked(axios.post)
            .mockImplementationOnce(async (_url, body) => {
                input = body as Record<string, unknown>;
                throw failure(504);
            })
            .mockImplementationOnce(async () => ({ data: reply(input) }));
        render(<DeliveryRecoveryCard delivery={fixture} />);
        open();
        reason();
        fireEvent.click(screen.getByRole('button', { name: 'Request replay' }));
        await screen.findByRole('button', { name: 'Check saved request' });
        expect(
            screen.getByRole('button', { name: 'Request replay' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved request' }),
        );
        await screen.findByRole('heading', { name: 'Replay request recorded' });
        expect(axios.post).toHaveBeenLastCalledWith(
            `/security-devices/monitoring/dead-letters/requests/${input.request_uuid}/recover`,
            { expected_actor_id: 7, expected_site_id: 2 },
            expect.objectContaining({ timeout: 30000 }),
        );
        expect(
            vi
                .mocked(axios.post)
                .mock.calls.filter(([url]) =>
                    String(url).endsWith('/11/replay'),
                ),
        ).toHaveLength(1);
    });
    it('finds the original decision from the server-owned entry after an uncertain dialog closes', async () => {
        let input: Record<string, unknown> = {};
        vi.mocked(axios.post).mockImplementationOnce(async (_url, body) => {
            input = body as Record<string, unknown>;
            throw failure(504);
        });
        const view = render(<DeliveryRecoveryCard delivery={fixture} />);
        open();
        reason();
        fireEvent.click(screen.getByRole('button', { name: 'Request replay' }));
        await screen.findByRole('button', { name: 'Check saved request' });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard and continue' }),
        );
        view.unmount();
        vi.mocked(axios.get).mockResolvedValue({
            data: { actor_id: 7, rows: [reply(input)], next_before_id: null },
        });
        vi.mocked(axios.post).mockResolvedValue({ data: reply(input) });
        render(<DeliveryRecoveryCard delivery={fixture} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Saved recovery decisions' }),
        );
        await screen.findByRole('button', {
            name: 'Check recorded decision for failure 11',
        });
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Check recorded decision for failure 11',
            }),
        );
        await screen.findByText('Original recorded decision');
        expect(
            vi
                .mocked(axios.post)
                .mock.calls.filter(([url]) =>
                    String(url).endsWith('/11/replay'),
                ),
        ).toHaveLength(1);
        expect(
            screen.queryByText('Original signed evidence reviewed'),
        ).not.toBeInTheDocument();
    });
    it('hides private records and rejects late replies when the actor or permission changes', async () => {
        let resolve!: (value: unknown) => void;
        vi.mocked(axios.post).mockImplementation(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        const view = render(<DeliveryRecoveryCard delivery={fixture} />);
        open();
        reason('PRIVATE-OLD-ACTOR-REASON');
        fireEvent.click(screen.getByRole('button', { name: 'Request replay' }));
        const input = vi.mocked(axios.post).mock.calls[0][1] as Record<
            string,
            unknown
        >;
        scope.actor = 8;
        view.rerender(<DeliveryRecoveryCard delivery={fixture} />);
        expect(screen.queryByText('Handler Failed')).not.toBeInTheDocument();
        expect(
            screen.queryByText('PRIVATE-OLD-ACTOR-REASON'),
        ).not.toBeInTheDocument();
        resolve({ data: reply(input) });
        await Promise.resolve();
        expect(scope.reload).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('heading', { name: 'Replay request recorded' }),
        ).not.toBeInTheDocument();
    });
    it('does not acknowledge an unrelated record or a claim that processing or a device command was confirmed', () => {
        const body = { request_uuid: '01111111-2222-4333-8444-555555555555' },
            attempt: RecoveryAttempt = {
                uuid: body.request_uuid,
                actor: 7,
                site: 2,
                letter: 11,
                operation: 'replay',
            };
        expect(
            confirmedRecovery(reply(body), attempt).receipt.dead_letter_id,
        ).toBe(11);
        for (const patch of [
            { dead_letter_id: 12 },
            { site_id: 9 },
            { confirms_processing: true },
            { confirms_device_command: true },
        ]) {
            const data = reply(body);
            Object.assign(data.decision, patch);
            expect(() => confirmedRecovery(data, attempt)).toThrow();
        }
    });
    it('keeps denied or conflicting reasons without offering a repeat operation', async () => {
        for (const status of [403, 409]) {
            vi.mocked(axios.post).mockRejectedValueOnce(failure(status));
            const view = render(<DeliveryRecoveryCard delivery={fixture} />);
            open('Discard');
            reason('Reviewed invalid evidence');
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Discard and retain evidence',
                }),
            );
            await screen.findByRole('alert');
            expect(screen.getByLabelText('Operational reason')).toHaveValue(
                'Reviewed invalid evidence',
            );
            expect(
                screen.getByRole('button', {
                    name: 'Discard and retain evidence',
                }),
            ).toBeDisabled();
            view.unmount();
        }
    });
});
