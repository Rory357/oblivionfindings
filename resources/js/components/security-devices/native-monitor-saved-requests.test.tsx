import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    NativeMonitorDialog,
    type NativeMonitorManagement,
} from './native-monitor-dialogs';
import { NativeMonitorSavedRequests } from './native-monitor-saved-requests';
const scope = vi.hoisted(() => ({ actor: 7, manage: true }));
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
                    can: { 'securityDevices.monitoring.manage': scope.manage },
                },
            },
        }),
    };
});
const uuid = '01111111-2222-4333-8444-555555555555';
const management: NativeMonitorManagement = {
    actor_id: 7,
    can_manage: true,
    create_url: '/security-devices/monitoring/native-monitors',
    kinds: [{ value: 'icmp', label: 'ICMP' }],
    devices: [{ id: 10, name: 'Gateway', site: { id: 2, name: 'Coast Site' } }],
    profiles: [{ id: 7, name: 'Standard policy' }],
};
function result(input: Record<string, unknown> = {}) {
    const monitor = {
        id: 92,
        name: input.name ?? 'Original monitor',
        kind: 'icmp',
        profile_id: 7,
        device_id: 10,
        collection_mode: 'central_direct',
        enabled: true,
        affects_availability: input.affects_availability ?? false,
    };
    return {
        monitor,
        current_monitor: { ...monitor },
        receipt: {
            schema_version: 1,
            owner: 'Native monitoring',
            request_uuid: input.request_uuid ?? uuid,
            actor_id: 7,
            operation: 'create',
            target_monitor_id: null,
            monitor_id: 92,
            site_id: 2,
            device_id: 10,
            committed_at: '2026-10-13T00:00:00Z',
            current_matches_saved: true,
            confirms: 'configuration_saved',
            confirms_observation: false,
        },
    };
}
function list(row = result(), cursor: number | null = null) {
    return { data: { actor_id: 7, rows: [row], next_before_id: cursor } };
}
beforeEach(() => {
    vi.resetAllMocks();
    scope.actor = 7;
    scope.manage = true;
    vi.mocked(axios.isAxiosError).mockImplementation((error) =>
        Boolean(error && typeof error === 'object' && 'isAxiosError' in error),
    );
    vi.mocked(axios.get).mockResolvedValue(list());
    vi.mocked(axios.post).mockResolvedValue({ data: result() });
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});
describe('Saved native monitor requests under current authority', () => {
    it('recovers the original create after its lost reply and closed dialog without retaining the draft or repeating creation', async () => {
        let input: Record<string, unknown> = {};
        const close = vi.fn(),
            storage = vi.spyOn(Storage.prototype, 'setItem');
        vi.mocked(axios.post).mockImplementationOnce(async (_url, body) => {
            input = body as Record<string, unknown>;
            throw { isAxiosError: true, response: { status: 504 } };
        });
        const wizard = render(
            <NativeMonitorDialog
                open
                management={management}
                monitor={null}
                onOpenChange={close}
                onSaved={vi.fn()}
            />,
        );
        fireEvent.click(screen.getByText('Device and policy', { exact: true }));
        fireEvent.change(screen.getByLabelText('Monitor name'), {
            target: { value: 'Original monitor' },
        });
        fireEvent.click(screen.getByText('Connection', { exact: true }));
        fireEvent.change(screen.getByLabelText('Approved target'), {
            target: { value: 'PRIVATE-TARGET-ONLY-IN-DRAFT' },
        });
        fireEvent.click(screen.getByText('Review', { exact: true }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Create direct monitor' }),
        );
        await screen.findByRole('button', { name: 'Check saved request' });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard and continue' }),
        );
        expect(close).toHaveBeenCalledWith(false);
        wizard.unmount();
        vi.mocked(axios.get).mockResolvedValue(list(result(input)));
        vi.mocked(axios.post).mockResolvedValue({ data: result(input) });
        render(
            <NativeMonitorSavedRequests
                open
                actorId={7}
                onOpenChange={vi.fn()}
            />,
        );
        await screen.findByText('Original monitor · #92');
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Check saved request for monitor 92',
            }),
        );
        await screen.findByText('Original saved result');
        expect(
            vi
                .mocked(axios.post)
                .mock.calls.filter(
                    ([url]) =>
                        url === '/security-devices/monitoring/native-monitors',
                ),
        ).toHaveLength(1);
        expect(axios.post).toHaveBeenLastCalledWith(
            `/security-devices/monitoring/native-monitors/requests/${input.request_uuid}/recover`,
            {
                expected_actor_id: 7,
                expected_site_id: 2,
                expected_device_id: 10,
            },
            expect.objectContaining({ timeout: 30000 }),
        );
        expect(
            screen.queryByText('PRIVATE-TARGET-ONLY-IN-DRAFT'),
        ).not.toBeInTheDocument();
        expect(storage).not.toHaveBeenCalled();
    });
    it('shows the original saved result separately from later current configuration and can load earlier history', async () => {
        const row = result();
        row.current_monitor.name = 'Later monitor name';
        row.receipt.current_matches_saved = false;
        vi.mocked(axios.get)
            .mockResolvedValueOnce(list(row, 31))
            .mockResolvedValueOnce({
                data: { actor_id: 7, rows: [], next_before_id: null },
            });
        vi.mocked(axios.post).mockResolvedValue({ data: row });
        render(
            <NativeMonitorSavedRequests
                open
                actorId={7}
                onOpenChange={vi.fn()}
            />,
        );
        await screen.findByText('Original monitor · #92');
        fireEvent.click(
            screen.getByRole('button', { name: 'Load earlier changes' }),
        );
        await waitFor(() => expect(axios.get).toHaveBeenCalledTimes(2));
        expect(axios.get).toHaveBeenLastCalledWith(
            '/security-devices/monitoring/native-monitors/requests',
            expect.objectContaining({
                params: { expected_actor_id: 7, before_id: 31 },
            }),
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Check saved request for monitor 92',
            }),
        );
        await screen.findByText('Changed after this result was saved');
        expect(screen.getByText('Later monitor name')).toBeInTheDocument();
        expect(screen.getByText('Original monitor (#92)')).toBeInTheDocument();
        expect(axios.patch).not.toHaveBeenCalled();
    });
    it('hides original actor records and ignores a late recovery reply after actor replacement', async () => {
        let resolve!: (value: unknown) => void;
        vi.mocked(axios.post).mockImplementation(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        const view = render(
            <NativeMonitorSavedRequests
                open
                actorId={7}
                onOpenChange={vi.fn()}
            />,
        );
        await screen.findByText('Original monitor · #92');
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Check saved request for monitor 92',
            }),
        );
        scope.actor = 8;
        view.rerender(
            <NativeMonitorSavedRequests
                open
                actorId={7}
                onOpenChange={vi.fn()}
            />,
        );
        expect(
            screen.queryByText('Original monitor · #92'),
        ).not.toBeInTheDocument();
        resolve({ data: result() });
        await Promise.resolve();
        expect(
            screen.queryByText('Original saved result'),
        ).not.toBeInTheDocument();
    });
    it('keeps denied or mismatched results unavailable and performs no repeat mutation', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce({
            data: { actor_id: 8, rows: [result()], next_before_id: null },
        });
        const view = render(
            <NativeMonitorSavedRequests
                open
                actorId={7}
                onOpenChange={vi.fn()}
            />,
        );
        await screen.findByRole('alert');
        expect(
            screen.queryByText('Original monitor · #92'),
        ).not.toBeInTheDocument();
        view.unmount();
        vi.mocked(axios.get).mockResolvedValue(list());
        vi.mocked(axios.post).mockRejectedValue({ response: { status: 404 } });
        render(
            <NativeMonitorSavedRequests
                open
                actorId={7}
                onOpenChange={vi.fn()}
            />,
        );
        await screen.findByText('Original monitor · #92');
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Check saved request for monitor 92',
            }),
        );
        await screen.findByText(
            'This saved request is unavailable in your current access, or it could not be confirmed. No monitor change was repeated.',
        );
        expect(
            screen.queryByText('Original saved result'),
        ).not.toBeInTheDocument();
        expect(axios.patch).not.toHaveBeenCalled();
    });
    it.each([403, 404, 'mismatched_actor'] as const)(
        'clears populated history and all actions when the next page is denied or untrusted: %s',
        async (denial) => {
            vi.mocked(axios.get).mockResolvedValueOnce(list(result(), 31));
            if (denial === 'mismatched_actor')
                vi.mocked(axios.get).mockResolvedValueOnce({
                    data: {
                        actor_id: 8,
                        rows: [result()],
                        next_before_id: null,
                    },
                });
            else
                vi.mocked(axios.get).mockRejectedValueOnce({
                    response: { status: denial },
                });
            render(
                <NativeMonitorSavedRequests
                    open
                    actorId={7}
                    onOpenChange={vi.fn()}
                />,
            );
            await screen.findByText('Original monitor · #92');
            fireEvent.click(
                screen.getByRole('button', { name: 'Load earlier changes' }),
            );
            await screen.findByRole('alert');
            expect(
                screen.queryByText('Original monitor · #92'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', {
                    name: 'Check saved request for monitor 92',
                }),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: 'Load earlier changes' }),
            ).not.toBeInTheDocument();
            expect(axios.post).not.toHaveBeenCalled();
            expect(axios.patch).not.toHaveBeenCalled();
        },
    );
    it('does not restore private rows from a late pagination reply after actor replacement', async () => {
        let resolve!: (value: unknown) => void;
        vi.mocked(axios.get)
            .mockResolvedValueOnce(list(result(), 31))
            .mockImplementationOnce(
                () =>
                    new Promise((done) => {
                        resolve = done;
                    }),
            );
        const view = render(
            <NativeMonitorSavedRequests
                open
                actorId={7}
                onOpenChange={vi.fn()}
            />,
        );
        await screen.findByText('Original monitor · #92');
        fireEvent.click(
            screen.getByRole('button', { name: 'Load earlier changes' }),
        );
        scope.actor = 8;
        view.rerender(
            <NativeMonitorSavedRequests
                open
                actorId={7}
                onOpenChange={vi.fn()}
            />,
        );
        expect(
            screen.queryByText('Original monitor · #92'),
        ).not.toBeInTheDocument();
        resolve(list());
        await Promise.resolve();
        expect(
            screen.queryByText('Original monitor · #92'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'Check saved request for monitor 92',
            }),
        ).not.toBeInTheDocument();
        expect(axios.post).not.toHaveBeenCalled();
        expect(axios.patch).not.toHaveBeenCalled();
    });
});
