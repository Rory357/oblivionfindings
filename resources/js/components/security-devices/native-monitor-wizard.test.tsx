import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    NativeMonitorDeactivateDialog,
    NativeMonitorDialog,
    type NativeMonitorManagement,
    type NativeMonitorTarget,
} from './native-monitor-dialogs';
import {
    confirmedNativeMonitor,
    initialNativeDraft,
    nativeDraftErrors,
    nativePayload,
    type NativeMonitorAttempt,
} from './native-monitor-wizard-data';
const mocks = vi.hoisted(() => ({ actor: 7, manage: true }));
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
                    user: { id: mocks.actor },
                    can: {
                        securityDevices: {
                            viewAny: true,
                            monitoringManage: mocks.manage,
                        },
                    },
                },
            },
        }),
    };
});
const management: NativeMonitorManagement = {
    actor_id: 7,
    can_manage: true,
    create_url: '/security-devices/monitoring/native-monitors',
    kinds: [
        'icmp',
        'tcp',
        'dns',
        'http',
        'tls',
        'snmp',
        'ssh_inventory',
        'winrm_inventory',
    ].map((value) => ({ value, label: value })),
    profiles: [{ id: 7, name: 'Standard policy' }],
    devices: [
        { id: 41, name: 'Gateway', site: { id: 14, name: 'Coast Site' } },
    ],
};
const monitor: NativeMonitorTarget = {
    id: 91,
    name: 'Gateway reachability',
    kind: 'icmp',
    enabled: true,
    affects_availability: true,
    profile: { id: 7, name: 'Standard policy' },
    device: { id: 41, name: 'Gateway', site: { id: 14, name: 'Coast Site' } },
    actions: {
        actor_id: 7,
        review_token: 'a'.repeat(64),
        can_manage: true,
        update_url: '/security-devices/monitoring/native-monitors/91',
        deactivate_url:
            '/security-devices/monitoring/native-monitors/91/deactivate',
    },
};
function reply(body: Record<string, unknown>, operation = 'create', id = 91) {
    const saved = {
        id,
        name: body.name ?? monitor.name,
        kind: body.kind ?? monitor.kind,
        device_id: 41,
        profile_id: body.profile_id ?? 7,
        enabled: operation !== 'deactivate',
        affects_availability: body.affects_availability ?? true,
        collection_mode: 'central_direct',
    };
    return {
        monitor: saved,
        current_monitor: { ...saved, review_token: 'b'.repeat(64) },
        receipt: {
            schema_version: 1,
            owner: 'Native monitoring',
            request_uuid: body.request_uuid,
            actor_id: 7,
            operation,
            target_monitor_id: operation === 'create' ? null : 91,
            monitor_id: id,
            device_id: 41,
            site_id: 14,
            committed_at: '2026-10-13T00:00:00Z',
            current_matches_saved: true,
            confirms: 'configuration_saved',
            confirms_observation: false,
        },
    };
}
function step(label: string) {
    fireEvent.click(screen.getByText(label, { exact: true }));
}
function fillCreate() {
    step('Device and policy');
    fireEvent.change(screen.getByLabelText('Monitor name'), {
        target: { value: 'Reviewed gateway' },
    });
    step('Connection');
    fireEvent.change(screen.getByLabelText('Approved target'), {
        target: { value: '10.44.0.10' },
    });
    step('Review');
}
function serverError(status: number, errors?: Record<string, string[]>) {
    return { isAxiosError: true, response: { status, data: { errors } } };
}
afterEach(cleanup);
beforeEach(() => {
    mocks.actor = 7;
    mocks.manage = true;
    vi.resetAllMocks();
    vi.mocked(axios.isAxiosError).mockImplementation(
        (value): value is import('axios').AxiosError =>
            Boolean(
                value && typeof value === 'object' && 'isAxiosError' in value,
            ),
    );
    vi.mocked(axios.post).mockImplementation(async (_url, body) => ({
        data: reply(body as Record<string, unknown>),
    }));
    vi.mocked(axios.patch).mockImplementation(async (_url, body) => ({
        data: reply(body as Record<string, unknown>, 'update'),
    }));
});
describe('native monitor wizard intent and recovery', () => {
    it('preserves all eight protocol-specific creation payloads and blank hidden edit values', () => {
        for (const kind of management.kinds.map((k) => k.value)) {
            const draft = {
                ...initialNativeDraft(management, null),
                kind,
                name: 'Reviewed check',
                target: '10.44.0.10',
                port: '443',
                dnsName: 'example.test',
                expectedAnswers: 'one,two',
                expectedStatus: '200,204',
                warnDays: '30',
                credentialReference: 'vault:site/approved',
                inventoryProfile:
                    kind === 'ssh_inventory' ? 'linux.basic' : 'windows.basic',
                hostKeyFingerprint: 'SHA256:' + 'A'.repeat(43),
            };
            expect(nativeDraftErrors(draft, management, null)).toEqual({});
            const body = nativePayload(draft, null);
            expect(body.kind).toBe(kind);
            expect(body.device_id).toBe(41);
            if (kind === 'http')
                expect(body.expected_status).toEqual([200, 204]);
            if (kind === 'dns')
                expect(body.expected_answers).toEqual(['one', 'two']);
            if (kind === 'ssh_inventory')
                expect(body.host_key_fingerprint).toBe(
                    draft.hostKeyFingerprint,
                );
        }
        const dns = { ...monitor, kind: 'dns' },
            draft = initialNativeDraft(management, dns);
        expect(nativePayload(draft, dns)).toEqual({
            profile_id: 7,
            name: monitor.name,
            affects_availability: true,
        });
        const bad = {
            ...initialNativeDraft(management, null),
            kind: 'http',
            name: 'Reviewed check',
            target: 'https://example.test',
            expectedStatus: '200,nope',
        };
        expect(
            nativeDraftErrors(bad, management, null).expected_status,
        ).toBeDefined();
    });
    it('requires exact actor/request/Site/device/operation and flags before acknowledging a save', () => {
        const body = {
            request_uuid: 'test-id',
            name: 'Reviewed check',
            kind: 'icmp',
            profile_id: 7,
            affects_availability: true,
        };
        const attempt: NativeMonitorAttempt = {
            uuid: 'test-id',
            actor: 7,
            site: 14,
            device: 41,
            target: null,
            operation: 'create',
            payload: body,
            expected: {
                name: 'Reviewed check',
                kind: 'icmp',
                profile: 7,
                availability: true,
                enabled: true,
            },
        };
        expect(confirmedNativeMonitor(reply(body), attempt).monitor.id).toBe(
            91,
        );
        for (const patch of [
            { actor_id: 8 },
            { site_id: 99 },
            { request_uuid: 'another-id' },
            { operation: 'deactivate' },
            { confirms_observation: true },
            { committed_at: '13 October 2026' },
        ]) {
            const bad = reply(body);
            Object.assign(bad.receipt, patch);
            expect(() => confirmedNativeMonitor(bad, attempt)).toThrow();
        }
    });
    it('rejects a receipt that claims unchanged current settings while showing a different current monitor', () => {
        const body = {
            request_uuid: 'test-id',
            name: 'Reviewed check',
            kind: 'icmp',
            profile_id: 7,
            affects_availability: true,
        };
        const attempt: NativeMonitorAttempt = {
            uuid: 'test-id',
            actor: 7,
            site: 14,
            device: 41,
            target: null,
            operation: 'create',
            payload: body,
            expected: { enabled: true },
        };
        const changed = reply(body);
        changed.current_monitor.name = 'Later name';
        expect(() => confirmedNativeMonitor(changed, attempt)).toThrow();
        changed.receipt.current_matches_saved = false;
        expect(confirmedNativeMonitor(changed, attempt).monitor.name).toBe(
            'Reviewed check',
        );
    });
    it('keeps a dirty connection draft until explicit discard and never stores it in browser storage', () => {
        const close = vi.fn(),
            storage = vi.spyOn(Storage.prototype, 'setItem');
        render(
            <NativeMonitorDialog
                open
                onOpenChange={close}
                management={management}
                monitor={null}
                onSaved={vi.fn()}
            />,
        );
        step('Connection');
        fireEvent.change(screen.getByLabelText('Approved target'), {
            target: { value: 'PRIVATE-DRAFT-TARGET' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.getByRole('alertdialog')).toBeInTheDocument();
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(screen.getByLabelText('Approved target')).toHaveValue(
            'PRIVATE-DRAFT-TARGET',
        );
        expect(close).not.toHaveBeenCalled();
        expect(storage).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard and continue' }),
        );
        expect(close).toHaveBeenCalledWith(false);
        storage.mockRestore();
    });
    it('blocks duplicate pending saves and exposes a confirmed success pane until Done', async () => {
        let resolve!: (value: unknown) => void;
        vi.mocked(axios.post).mockImplementation(
            () =>
                new Promise((r) => {
                    resolve = r;
                }),
        );
        const done = vi.fn();
        render(
            <NativeMonitorDialog
                open
                onOpenChange={vi.fn()}
                management={management}
                monitor={null}
                onSaved={done}
            />,
        );
        fillCreate();
        const save = screen.getByRole('button', {
            name: 'Create direct monitor',
        });
        fireEvent.click(save);
        fireEvent.click(save);
        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        const body = vi.mocked(axios.post).mock.calls[0][1] as Record<
            string,
            unknown
        >;
        resolve({ data: reply(body) });
        await screen.findByRole('heading', {
            name: 'Monitor configuration saved',
        });
        expect(done).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        expect(done).toHaveBeenCalledOnce();
    });
    it('returns validation to its field step with entries retained and allows a corrected fresh save', async () => {
        vi.mocked(axios.post).mockRejectedValueOnce(
            serverError(422, { name: ['Use a clearer monitor name.'] }),
        );
        render(
            <NativeMonitorDialog
                open
                onOpenChange={vi.fn()}
                management={management}
                monitor={null}
                onSaved={vi.fn()}
            />,
        );
        fillCreate();
        fireEvent.click(
            screen.getByRole('button', { name: 'Create direct monitor' }),
        );
        await screen.findByText('Use a clearer monitor name.');
        expect(
            screen.getByRole('progressbar', {
                name: 'Required sections completed',
            }),
        ).toHaveAttribute('aria-valuenow', '67');
        expect(screen.getByLabelText('Monitor name')).toHaveValue(
            'Reviewed gateway',
        );
        fireEvent.change(screen.getByLabelText('Monitor name'), {
            target: { value: 'Corrected gateway' },
        });
        step('Review');
        fireEvent.click(
            screen.getByRole('button', { name: 'Create direct monitor' }),
        );
        await screen.findByRole('heading', {
            name: 'Monitor configuration saved',
        });
        expect(axios.post).toHaveBeenCalledTimes(2);
        expect(
            (vi.mocked(axios.post).mock.calls[0][1] as Record<string, unknown>)
                .request_uuid,
        ).not.toBe(
            (vi.mocked(axios.post).mock.calls[1][1] as Record<string, unknown>)
                .request_uuid,
        );
    });
    it('recovers a lost response read-only using the original request, without repeating creation', async () => {
        let original: Record<string, unknown> = {};
        vi.mocked(axios.post)
            .mockImplementationOnce(async (_url, body) => {
                original = body as Record<string, unknown>;
                throw serverError(504);
            })
            .mockImplementationOnce(async () => ({ data: reply(original) }));
        render(
            <NativeMonitorDialog
                open
                onOpenChange={vi.fn()}
                management={management}
                monitor={null}
                onSaved={vi.fn()}
            />,
        );
        fillCreate();
        fireEvent.click(
            screen.getByRole('button', { name: 'Create direct monitor' }),
        );
        await screen.findByRole('button', { name: 'Check saved request' });
        expect(
            screen.getByRole('button', { name: 'Create direct monitor' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Check saved request' }),
        );
        await screen.findByRole('heading', {
            name: 'Monitor configuration saved',
        });
        expect(vi.mocked(axios.post).mock.calls[1][0]).toBe(
            `/security-devices/monitoring/native-monitors/requests/${original.request_uuid}/recover`,
        );
        expect(vi.mocked(axios.post).mock.calls[1][1]).toEqual({
            expected_actor_id: 7,
            expected_site_id: 14,
            expected_device_id: 41,
        });
    });
    it('does not convert a malformed success or wrong-ID update into confirmation', async () => {
        vi.mocked(axios.patch).mockImplementation(async (_url, body) => ({
            data: reply(body as Record<string, unknown>, 'update', 999),
        }));
        const saved = vi.fn();
        render(
            <NativeMonitorDialog
                open
                onOpenChange={vi.fn()}
                management={management}
                monitor={monitor}
                onSaved={saved}
            />,
        );
        step('Review');
        fireEvent.click(
            screen.getByRole('button', { name: 'Apply monitor update' }),
        );
        await screen.findByRole('button', { name: 'Check saved request' });
        expect(
            screen.queryByRole('heading', {
                name: 'Monitor configuration saved',
            }),
        ).not.toBeInTheDocument();
        expect(saved).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Apply monitor update' }),
        ).toBeDisabled();
    });
    it('conceals stale editor fields after actor replacement and ignores a late response', async () => {
        let resolve!: (value: unknown) => void;
        vi.mocked(axios.post).mockImplementation(
            () =>
                new Promise((r) => {
                    resolve = r;
                }),
        );
        const saved = vi.fn();
        const { rerender } = render(
            <NativeMonitorDialog
                open
                onOpenChange={vi.fn()}
                management={management}
                monitor={null}
                onSaved={saved}
            />,
        );
        fillCreate();
        fireEvent.click(
            screen.getByRole('button', { name: 'Create direct monitor' }),
        );
        const body = vi.mocked(axios.post).mock.calls[0][1] as Record<
            string,
            unknown
        >;
        mocks.actor = 8;
        rerender(
            <NativeMonitorDialog
                open
                onOpenChange={vi.fn()}
                management={management}
                monitor={null}
                onSaved={saved}
            />,
        );
        expect(
            screen.getByRole('heading', { name: 'Refresh monitor context' }),
        ).toBeInTheDocument();
        expect(screen.queryByText('Coast Site')).not.toBeInTheDocument();
        resolve({ data: reply(body) });
        await waitFor(() => expect(saved).not.toHaveBeenCalled());
        expect(
            screen.queryByRole('heading', {
                name: 'Monitor configuration saved',
            }),
        ).not.toBeInTheDocument();
    });
    it('distinguishes access denial and changed-review conflicts without replaying either request', async () => {
        vi.mocked(axios.post).mockRejectedValueOnce(serverError(403));
        const { unmount } = render(
            <NativeMonitorDialog
                open
                onOpenChange={vi.fn()}
                management={management}
                monitor={null}
                onSaved={vi.fn()}
            />,
        );
        fillCreate();
        fireEvent.click(
            screen.getByRole('button', { name: 'Create direct monitor' }),
        );
        await screen.findByText(/Your current access or reviewed Site context/);
        expect(
            screen.getByRole('button', { name: 'Create direct monitor' }),
        ).toBeDisabled();
        unmount();
        vi.mocked(axios.patch).mockRejectedValueOnce(serverError(409));
        render(
            <NativeMonitorDialog
                open
                onOpenChange={vi.fn()}
                management={management}
                monitor={monitor}
                onSaved={vi.fn()}
            />,
        );
        step('Review');
        fireEvent.click(
            screen.getByRole('button', { name: 'Apply monitor update' }),
        );
        await screen.findByText(/This monitor or request changed after review/);
        expect(
            screen.getByRole('button', { name: 'Apply monitor update' }),
        ).toBeDisabled();
    });
    it('keeps a deactivation reason draft and confirms the exact disabled monitor before notifying its parent', async () => {
        const done = vi.fn();
        vi.mocked(axios.post).mockImplementation(async (_url, body) => ({
            data: reply(body as Record<string, unknown>, 'deactivate'),
        }));
        render(
            <NativeMonitorDeactivateDialog
                open
                onOpenChange={vi.fn()}
                monitor={monitor}
                onDeactivated={done}
            />,
        );
        fireEvent.click(
            screen.getByRole('combobox', { name: 'Operational reason' }),
        );
        fireEvent.click(screen.getByRole('option', { name: 'Obsolete check' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Deactivate monitor' }),
        );
        await screen.findByRole('heading', {
            name: 'Monitor deactivation confirmed',
        });
        expect(done).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        expect(done).toHaveBeenCalledOnce();
    });
});
