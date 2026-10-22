import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    DeviceSettingsTab,
    type Device,
    type Preset,
    type RecentCommand,
} from './queclink-hub';
const mocks = vi.hoisted(() => ({
    actor: 17,
    permissions: { viewAny: true, integrationsManage: true, devicesView: true },
    post: vi.fn(),
    get: vi.fn(),
    delete: vi.fn(),
}));
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
                    can: { securityDevices: mocks.permissions },
                },
            },
        }),
        router: {
            ...actual.router,
            on: actual.router.on.bind(actual.router),
            post: mocks.post,
            get: mocks.get,
            delete: mocks.delete,
        },
    };
});
const listener = {
    port: 8090,
    endpoint_configured: true,
    service_state: 'active' as const,
    connected_count: 1,
};
const device = (id = 2): Device => ({
    id,
    canonical_device_id: id + 40,
    reference: `Tracker ending ${id}998`,
    status: 'paired',
    model_hint: 'GL30MEU',
    protocol_version: '970204',
    firmware_version: 'synthetic-1',
    connection_state: 'connected',
    first_seen_at: '2026-10-01T03:00:00Z',
    last_seen_at: '2026-10-13T03:00:00Z',
    last_frame_at: '2026-10-13T03:01:00Z',
    assignment: {
        type: 'client',
        label: 'Synthetic client',
        assigned_at: '2026-10-02T04:00:00Z',
    },
    configuration: {
        state: 'observed',
        observed_at: '2026-10-13T03:02:00Z',
        sections: ['SRI', 'CFG', 'DOG'],
    },
    recent_commands: [],
});
const preset = (): Preset => ({
    id: 6,
    name: 'Synthetic safety baseline',
    slug: 'synthetic-safety-baseline',
    description: 'Prepared and reviewed profile',
    target_category: 'personal_tracker',
    is_system: false,
    sections: ['tracking', 'server'],
    profile_uuid: 'profile-synthetic-6',
    profile_version: 3,
    payload_hash: 'synthetic-profile-hash',
    created_at: '2026-10-01T03:00:00Z',
});
const command = (
    id: number,
    status: RecentCommand['status'],
    governed = false,
): RecentCommand => ({
    id,
    command_word: `SYN${id}`,
    status,
    governed,
    created_at: '2026-10-13T03:00:00Z',
    sent_at: status === 'queued' ? null : '2026-10-13T03:01:00Z',
    acked_at: status === 'acked' ? '2026-10-13T03:02:00Z' : null,
    fulfilled_at: status === 'acked' ? '2026-10-13T03:03:00Z' : null,
    cancelled_at: status === 'cancelled' ? '2026-10-13T03:04:00Z' : null,
    expires_at: '2026-10-13T03:10:00Z',
    failure_category: status === 'failed' ? 'provider_failure' : null,
});
function workspace(
    overrides: Partial<React.ComponentProps<typeof DeviceSettingsTab>> = {},
) {
    return (
        <DeviceSettingsTab
            devices={[device()]}
            listener={listener}
            presets={[]}
            can={{ manage: true }}
            {...overrides}
        />
    );
}
const serverPayload = {
    report_mode: '3',
    manual_netreg: '0',
    buffer_mode: '1',
    main_host: 'synthetic.example',
    main_port: '8090',
    backup_host: '',
    backup_port: '8090',
    sms_gateway: '',
    heartbeat_interval_minutes: '5',
    sack_enable: '1',
    sms_ack_enable: '0',
    psm_network_hold_time_seconds: '30',
    protocol_format: '0',
};
const globalPayload = {
    device_name: 'GL30MEU',
    gnss_timeout_seconds: '150',
    event_mask: '08E3',
    report_item_mask: '006F',
    mode_selection: '1',
    continuous_send_interval_seconds: '30',
    start_mode: '0',
    specified_time_of_day: '1200',
    wakeup_interval_hours: '1',
    gnss_enable: '1',
    agps_mode: '1',
    gsm_report: '0000',
    battery_low_percentage: '10',
    function_button_mode: '1',
    sos_report_mode: '1',
    wifi_report: '2',
    led_on: '1',
    charge_standby_mode: '0',
};
const next = () =>
    fireEvent.click(screen.getByRole('button', { name: /^Continue$/ }));
const callbacks = () => mocks.post.mock.calls.at(-1)![2];
const currentAuth = () => ({
    user: { id: 17 },
    can: {
        securityDevices: {
            viewAny: true,
            integrationsManage: true,
            devicesView: true,
        },
    },
});
async function menu(name: string, action: string) {
    fireEvent.keyDown(
        screen.getByRole('button', { name: `Actions for ${name}` }),
        { key: 'Enter', code: 'Enter' },
    );
    fireEvent.click(await screen.findByRole('menuitem', { name: action }));
}
beforeEach(() => {
    mocks.actor = 17;
    mocks.permissions = {
        viewAny: true,
        integrationsManage: true,
        devicesView: true,
    };
    mocks.post.mockReset();
    mocks.get.mockReset();
    mocks.delete.mockReset();
});
afterEach(cleanup);
describe('Queclink advanced settings workflows', () => {
    it('keeps the paired-device prerequisite honest and opens no transport for an empty result', () => {
        render(workspace({ devices: [] }));
        expect(screen.getByText(/Pair a Queclink device/)).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /Edit/ }),
        ).not.toBeInTheDocument();
        expect(mocks.post).not.toHaveBeenCalled();
    });
    it('reviews all thirteen server fields, retains the exact payload and locks a pending request', () => {
        render(workspace());
        fireEvent.click(
            screen.getByRole('button', { name: /^Server connection/ }),
        );
        next();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Main host is required.',
        );
        expect(mocks.post).not.toHaveBeenCalled();
        fireEvent.change(screen.getByLabelText('Main host'), {
            target: { value: 'synthetic.example' },
        });
        next();
        expect(screen.getByLabelText('SMS acknowledgement')).toBeVisible();
        expect(screen.getByLabelText('Protocol format')).toHaveValue(0);
        next();
        const review = screen.getByRole('dialog');
        for (const name of [
            'Main host',
            'Backup host',
            'SMS gateway',
            'Heartbeat minutes',
            'Report mode',
            'Manual network',
            'SACK',
            'SMS acknowledgement',
            'PSM hold seconds',
            'Protocol format',
        ])
            expect(within(review).getByText(name)).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Continue to Device Management',
            }),
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Continue to Device Management',
            }),
        );
        expect(mocks.post).toHaveBeenCalledTimes(1);
        expect(mocks.post.mock.calls[0].slice(0, 2)).toEqual([
            '/security-devices/integrations/queclink/devices/2/configuration/server',
            serverPayload,
        ]);
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Back' })).toBeDisabled();
        expect(screen.queryByText('Settings applied')).not.toBeInTheDocument();
    });
    it('retains every global field, uppercase masks and the canonical global profile route', () => {
        render(workspace());
        fireEvent.click(
            screen.getByRole('button', { name: /^Global tracking/ }),
        );
        fireEvent.change(screen.getByLabelText('Event mask'), {
            target: { value: 'abcd' },
        });
        next();
        fireEvent.change(screen.getByLabelText('Function button mode'), {
            target: { value: '2' },
        });
        fireEvent.change(screen.getByLabelText('SOS report mode'), {
            target: { value: '2' },
        });
        next();
        for (const label of [
            'Specified time',
            'Function button mode',
            'SOS report mode',
            'GSM report mask',
            'Charge standby',
        ])
            expect(screen.getByText(label)).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Continue to Device Management',
            }),
        );
        expect(mocks.post.mock.calls[0].slice(0, 2)).toEqual([
            '/security-devices/integrations/queclink/devices/2/configuration/global',
            {
                ...globalPayload,
                event_mask: 'ABCD',
                function_button_mode: '2',
                sos_report_mode: '2',
            },
        ]);
    });
    it('keeps the exact advanced command identity and normalizes allow-list input only at submission', () => {
        render(workspace());
        fireEvent.click(
            screen.getByRole('button', { name: /^Advanced GL30 sections/ }),
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: /^Phone allow-list$/,
            }),
        );
        next();
        fireEvent.change(screen.getByLabelText('Phone numbers'), {
            target: { value: '+64001, +64002\n\n+64003' },
        });
        next();
        expect(
            screen.getByText('+64001, +64002 +64003', { exact: false }),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Continue to Device Management',
            }),
        );
        expect(mocks.post.mock.calls[0][0]).toBe(
            '/security-devices/integrations/queclink/devices/2/configuration/alarms',
        );
        expect(mocks.post.mock.calls[0][1]).toEqual(
            expect.objectContaining({
                command: 'allowlist',
                phone_numbers: ['+64001', '+64002', '+64003'],
            }),
        );
        expect(
            screen.queryByRole('button', { name: 'Keep draft' }),
        ).not.toBeInTheDocument();
    });
    it('preserves settings through Cancel and Back and requires explicit discard before changing section identity', () => {
        render(workspace());
        fireEvent.click(
            screen.getByRole('button', { name: /^Advanced GL30 sections/ }),
        );
        next();
        fireEvent.change(screen.getByLabelText('Reboot time'), {
            target: { value: '0300' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(screen.getByLabelText('Reboot time')).toHaveValue('0300');
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        fireEvent.click(screen.getByRole('button', { name: /^SIM PIN$/ }));
        expect(screen.getByRole('alertdialog')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Discard draft' }));
        next();
        expect(screen.getByLabelText('PIN')).toHaveValue('');
        expect(screen.queryByLabelText('Reboot time')).not.toBeInTheDocument();
        expect(mocks.post).not.toHaveBeenCalled();
    });
    it('keeps a per-device draft and saves every selected preset section with its original shape', () => {
        render(workspace());
        fireEvent.click(
            screen.getByRole('button', { name: /^Server connection/ }),
        );
        fireEvent.change(screen.getByLabelText('Main host'), {
            target: { value: 'synthetic.example' },
        });
        next();
        next();
        fireEvent.click(screen.getByRole('button', { name: 'Keep draft' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Save current as preset' }),
        );
        fireEvent.change(screen.getByLabelText('Name'), {
            target: { value: 'Synthetic saved preset' },
        });
        fireEvent.change(screen.getByLabelText('Description'), {
            target: { value: 'Synthetic retained description' },
        });
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Server connection' }),
        );
        next();
        expect(screen.getByText('synthetic.example')).toBeVisible();
        expect(screen.getByText('Function button mode')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Save preset' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save preset' }));
        expect(mocks.post).toHaveBeenCalledTimes(1);
        expect(mocks.post.mock.calls[0].slice(0, 2)).toEqual([
            '/security-devices/integrations/queclink/presets',
            {
                name: 'Synthetic saved preset',
                description: 'Synthetic retained description',
                sections: { tracking: globalPayload, server: serverPayload },
            },
        ]);
        expect(screen.queryByText('Preset saved')).not.toBeInTheDocument();
        const saved = { ...preset(), id: 8, name: 'Synthetic saved preset' };
        act(() =>
            callbacks().onSuccess({
                component: 'security-devices/integrations/queclink-hub',
                url: '/security-devices/integrations/queclink',
                props: { actor_id: 17, auth: currentAuth(), presets: [saved] },
            }),
        );
        expect(screen.getByText('Preset saved')).toBeVisible();
    });
    it('keeps a preset draft recoverable through a validation response and rejects an unconfirmed receipt', () => {
        render(workspace());
        fireEvent.click(
            screen.getByRole('button', { name: 'Save current as preset' }),
        );
        fireEvent.change(screen.getByLabelText('Name'), {
            target: { value: 'Synthetic saved preset' },
        });
        next();
        fireEvent.click(screen.getByRole('button', { name: 'Save preset' }));
        act(() => {
            callbacks().onError({ name: 'This name needs correction.' });
            callbacks().onFinish();
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'This name needs correction.',
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: /Name & sections.*Name this reusable profile/,
            }),
        );
        expect(screen.getByLabelText('Name')).toHaveValue(
            'Synthetic saved preset',
        );
        next();
        fireEvent.click(screen.getByRole('button', { name: 'Save preset' }));
        act(() => {
            callbacks().onSuccess({
                component: 'security-devices/integrations/queclink-hub',
                url: '/security-devices/integrations/queclink',
                props: { actor_id: 17, auth: currentAuth(), presets: [] },
            });
            callbacks().onFinish();
        });
        expect(screen.queryByText('Preset saved')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Save preset' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh current records' }),
        );
        expect(mocks.get).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink',
            {},
            { preserveState: false, preserveScroll: true },
        );
        expect(mocks.post).toHaveBeenCalledTimes(2);
    });
    it('sends and confirms a trimmed preset name, so a trailing space does not lock the page', () => {
        render(workspace());
        fireEvent.click(
            screen.getByRole('button', { name: 'Save current as preset' }),
        );
        fireEvent.change(screen.getByLabelText('Name'), {
            target: { value: '  Synthetic spaced preset  ' },
        });
        next();
        fireEvent.click(screen.getByRole('button', { name: 'Save preset' }));
        expect(mocks.post.mock.calls[0][1]).toEqual(
            expect.objectContaining({ name: 'Synthetic spaced preset' }),
        );
        // The server's TrimStrings middleware stores the trimmed name.
        const saved = {
            ...preset(),
            id: 9,
            name: 'Synthetic spaced preset',
            sections: ['tracking'],
        };
        act(() => {
            callbacks().onSuccess({
                component: 'security-devices/integrations/queclink-hub',
                url: '/security-devices/integrations/queclink',
                props: { actor_id: 17, auth: currentAuth(), presets: [saved] },
            });
            callbacks().onFinish();
        });
        expect(screen.getByText('Preset saved')).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Refresh current records' }),
        ).not.toBeInTheDocument();
    });
    it.each([
        [404, /This change was not saved/],
        [422, /This change was not accepted/],
    ] as const)(
        'explains a refused %s settings write without locking the page or claiming lost access',
        (status, message) => {
            render(workspace());
            fireEvent.click(
                screen.getByRole('button', { name: 'Read full config' }),
            );
            const reply = new CustomEvent('inertia:invalid', {
                cancelable: true,
                detail: {
                    response: {
                        status,
                        data: '<html>RAW-REFUSAL-PAGE</html>',
                        config: {
                            method: 'post',
                            url: '/security-devices/integrations/queclink/devices/2/configuration/read',
                        },
                    },
                },
            });
            act(() => {
                document.dispatchEvent(reply);
                callbacks().onFinish();
            });
            expect(reply.defaultPrevented).toBe(true);
            expect(screen.getByRole('alert')).toHaveTextContent(message);
            expect(
                screen.queryByText(/RAW-REFUSAL-PAGE/),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByText(/settings are restricted/),
            ).not.toBeInTheDocument();
            expect(
                screen.getByRole('button', { name: 'Read full config' }),
            ).toBeEnabled();
            fireEvent.click(
                screen.getByRole('button', { name: 'Read full config' }),
            );
            expect(mocks.post).toHaveBeenCalledTimes(2);
        },
    );
    it.each(['actor', 'viewAny', 'integrationsManage'] as const)(
        'removes private settings, readers and pending drafts after current %s authority loss',
        async (kind) => {
            const ui = render(workspace({ presets: [preset()] }));
            fireEvent.click(
                screen.getByRole('button', { name: /^Global tracking/ }),
            );
            fireEvent.change(screen.getByLabelText('Device name'), {
                target: { value: 'PRIVATE-DRAFT' },
            });
            next();
            next();
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Continue to Device Management',
                }),
            );
            const cancel = vi.fn();
            callbacks().onCancelToken({ cancel });
            if (kind === 'actor') mocks.actor = 18;
            else mocks.permissions[kind] = false;
            ui.rerender(workspace({ presets: [preset()] }));
            expect(cancel).toHaveBeenCalledTimes(1);
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            expect(screen.queryByText('PRIVATE-DRAFT')).not.toBeInTheDocument();
            expect(
                screen.queryByText('Synthetic safety baseline'),
            ).not.toBeInTheDocument();
            expect(screen.getByText(/settings are restricted/)).toBeVisible();
            act(() =>
                callbacks().onSuccess({
                    component: 'security-devices/integrations/queclink-hub',
                    props: { auth: currentAuth(), presets: [preset()] },
                }),
            );
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        },
    );
    it('handles an owned absolute-URL denial while leaving unrelated response errors with their owner', () => {
        render(workspace());
        fireEvent.click(
            screen.getByRole('button', { name: 'Read full config' }),
        );
        act(() =>
            document.dispatchEvent(
                new CustomEvent('inertia:invalid', {
                    cancelable: true,
                    detail: {
                        response: {
                            status: 403,
                            config: {
                                method: 'post',
                                url: 'https://local.invalid/unrelated',
                            },
                        },
                    },
                }),
            ),
        );
        expect(screen.getByText('Device settings')).toBeVisible();
        const denial = new CustomEvent('inertia:invalid', {
            cancelable: true,
            detail: {
                response: {
                    status: 403,
                    config: {
                        method: 'POST',
                        url: 'https://local.invalid/security-devices/integrations/queclink/devices/2/configuration/read',
                    },
                },
            },
        });
        act(() => document.dispatchEvent(denial));
        expect(denial.defaultPrevented).toBe(true);
        expect(screen.queryByText('Device settings')).not.toBeInTheDocument();
        expect(screen.getByText(/settings are restricted/)).toBeVisible();
    });
    it('blocks a repeated write after an interrupted settings response and preserves the draft for review', () => {
        render(workspace());
        fireEvent.click(
            screen.getByRole('button', { name: /^Global tracking/ }),
        );
        next();
        next();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Continue to Device Management',
            }),
        );
        act(() => {
            callbacks().onCancel();
            callbacks().onFinish();
        });
        expect(screen.getByRole('alert')).toHaveTextContent('interrupted');
        expect(
            screen.getByRole('button', {
                name: 'Continue to Device Management',
            }),
        ).toBeDisabled();
        expect(
            within(screen.getByRole('dialog')).getByText('GL30MEU'),
        ).toBeVisible();
        expect(mocks.post).toHaveBeenCalledTimes(1);
    });
    it('retains preset identity and recorded command timestamps in shared table/context readers with truthful queued counts', async () => {
        const target = device();
        target.recent_commands = [
            command(4, 'queued'),
            command(5, 'failed'),
            command(6, 'acked', true),
        ];
        render(
            workspace({
                devices: [target],
                presets: [preset()],
                layout: 'table',
            }),
        );
        expect(screen.getAllByRole('table')).toHaveLength(2);
        const queued = screen.getByText('Queued commands').parentElement!;
        expect(within(queued).getByText('1')).toBeVisible();
        fireEvent.contextMenu(
            screen.getByRole('row', { name: /Synthetic safety baseline/ }),
            { clientX: 80, clientY: 80 },
        );
        expect(
            screen.getByRole('menu', { name: 'Synthetic safety baseline' }),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('menuitem', { name: 'View preset details' }),
        );
        const reader = screen.getByRole('dialog');
        for (const text of [
            'profile-synthetic-6',
            'Version 3',
            'Personal trackers',
            'Global tracking, Server connection',
        ])
            expect(within(reader).getByText(text)).toBeVisible();
        // Internal keys and integrity hashes are not shown as preset facts.
        for (const text of [
            'personal_tracker',
            'synthetic-safety-baseline',
            'synthetic-profile-hash',
            'tracking, server',
        ])
            expect(within(reader).queryByText(text)).not.toBeInTheDocument();
        fireEvent.click(screen.getAllByRole('button', { name: /^Close$/ })[0]);
        fireEvent.contextMenu(screen.getByRole('row', { name: /SYN6/ }), {
            clientX: 80,
            clientY: 80,
        });
        expect(screen.getByRole('menu', { name: 'SYN6 #6' })).toBeVisible();
        fireEvent.click(
            screen.getByRole('menuitem', { name: 'Inspect command status' }),
        );
        expect(
            within(screen.getByRole('dialog')).getByText('Acknowledged'),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: /Times.*Complete details/ }),
        );
        for (const label of [
            'Created',
            'Sent',
            'Acknowledged',
            'Fulfilled',
            'Cancelled',
            'Expires',
        ])
            expect(
                within(screen.getByRole('dialog')).getByText(label),
            ).toBeVisible();
        expect(
            within(screen.getByRole('dialog')).getAllByText(/13 October 2026/)
                .length,
        ).toBeGreaterThan(0);
        expect(
            screen.queryByRole('button', { name: 'Retry' }),
        ).not.toBeInTheDocument();
    });
    it('reviews immutable preset and target identity before using the exact governed application action', async () => {
        render(workspace({ presets: [preset()] }));
        await menu('Synthetic safety baseline', 'Review application');
        expect(screen.getByText('profile-synthetic-6')).toBeVisible();
        expect(mocks.post).not.toHaveBeenCalled();
        next();
        expect(screen.getByText('Synthetic client')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Continue to governed review' }),
        );
        expect(mocks.post.mock.calls[0].slice(0, 2)).toEqual([
            '/security-devices/integrations/queclink/devices/2/presets/6/apply',
            {},
        ]);
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
    });
    it('preserves the cancellation-only legacy action and excludes governed or delivered records', async () => {
        const target = device();
        target.recent_commands = [
            command(4, 'queued'),
            command(5, 'failed'),
            command(6, 'queued', true),
        ];
        render(workspace({ devices: [target] }));
        await menu('SYN4', 'Cancel queued command');
        // Cancelling is irreversible, so it waits for the shared confirmation.
        expect(mocks.post).not.toHaveBeenCalled();
        const confirm = screen.getByRole('alertdialog');
        expect(
            within(confirm).getByText('Cancel this queued command?'),
        ).toBeVisible();
        expect(confirm).toHaveTextContent('Tracker ending 2998');
        fireEvent.click(
            within(confirm).getByRole('button', { name: 'Keep command' }),
        );
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(mocks.post).not.toHaveBeenCalled();
        await menu('SYN4', 'Cancel queued command');
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Cancel command',
            }),
        );
        expect(mocks.post).toHaveBeenCalledTimes(1);
        expect(mocks.post.mock.calls[0].slice(0, 2)).toEqual([
            '/security-devices/integrations/queclink/commands/4/cancel',
            {},
        ]);
        act(() => callbacks().onFinish());
        fireEvent.keyDown(
            screen.getByRole('button', { name: 'Actions for SYN6' }),
            { key: 'Enter', code: 'Enter' },
        );
        expect(
            screen.queryByRole('menuitem', { name: 'Cancel queued command' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('menuitem', { name: 'Inspect command status' }),
        ).toBeVisible();
    });
    it('retains the exact reasoned retirement action and recovers validation without repeating a pending delete', async () => {
        render(workspace({ presets: [preset()] }));
        await menu('Synthetic safety baseline', 'Retire preset');
        fireEvent.change(
            screen.getByLabelText('Reason for retiring this preset'),
            { target: { value: '  Replaced by approved baseline.  ' } },
        );
        next();
        expect(screen.getByText('profile-synthetic-6')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Retire preset' }));
        fireEvent.click(screen.getByRole('button', { name: 'Retire preset' }));
        expect(mocks.delete).toHaveBeenCalledTimes(1);
        expect(mocks.delete).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink/presets/6',
            expect.objectContaining({
                data: { reason: 'Replaced by approved baseline.' },
                preserveScroll: true,
            }),
        );
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        const options = mocks.delete.mock.calls[0][1];
        act(() => {
            options.onError({
                reason: 'A clearer retirement reason is required.',
            });
            options.onFinish();
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'A clearer retirement reason is required.',
        );
        expect(
            screen.getByText('Replaced by approved baseline.'),
        ).toBeVisible();
        expect(mocks.delete).toHaveBeenCalledTimes(1);
    });
    it('discards old draft ownership when the refreshed canonical Device changes and ignores its late reply', () => {
        const target = device(),
            ui = render(workspace({ devices: [target] }));
        fireEvent.click(
            screen.getByRole('button', { name: /^Global tracking/ }),
        );
        fireEvent.change(screen.getByLabelText('Device name'), {
            target: { value: 'PRIVATE-CANONICAL-DRAFT' },
        });
        next();
        next();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Continue to Device Management',
            }),
        );
        const cancel = vi.fn();
        callbacks().onCancelToken({ cancel });
        ui.rerender(
            workspace({ devices: [{ ...target, canonical_device_id: 99 }] }),
        );
        expect(cancel).toHaveBeenCalledTimes(1);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByText('PRIVATE-CANONICAL-DRAFT'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Open Device Management' }),
        ).toHaveAttribute(
            'href',
            '/security-devices/devices/99?section=management',
        );
        act(() =>
            callbacks().onSuccess({
                component: 'security-devices/devices/show',
                url: '/security-devices/devices/42?section=management&action=configuration.apply',
                props: { auth: currentAuth() },
            }),
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('keeps prepared server values on their selected tracker and starts a clean draft for another tracker', async () => {
        render(workspace({ devices: [device(2), device(3)] }));
        fireEvent.click(
            screen.getByRole('button', { name: /^Server connection/ }),
        );
        fireEvent.change(screen.getByLabelText('Main host'), {
            target: { value: 'synthetic.example' },
        });
        next();
        next();
        fireEvent.click(screen.getByRole('button', { name: 'Keep draft' }));
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Target device' }),
            { key: 'Enter', code: 'Enter' },
        );
        fireEvent.click(
            await screen.findByRole('option', { name: /Tracker ending 3998/ }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: /^Server connection/ }),
        );
        expect(screen.getByLabelText('Main host')).toHaveValue('');
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Target device' }),
            { key: 'Enter', code: 'Enter' },
        );
        fireEvent.click(
            await screen.findByRole('option', { name: /Tracker ending 2998/ }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: /^Server connection/ }),
        );
        expect(screen.getByLabelText('Main host')).toHaveValue(
            'synthetic.example',
        );
        expect(mocks.post).not.toHaveBeenCalled();
    });
    it('clears an open settings form when the returned manage permission is withdrawn and hides separate profile navigation', () => {
        const ui = render(workspace());
        fireEvent.click(
            screen.getByRole('button', { name: /^Global tracking/ }),
        );
        fireEvent.change(screen.getByLabelText('Device name'), {
            target: { value: 'PRIVATE-PERMISSION-DRAFT' },
        });
        mocks.permissions.devicesView = false;
        ui.rerender(workspace({ can: { manage: false } }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByText('PRIVATE-PERMISSION-DRAFT'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Open Device Management' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Save current as preset' }),
        ).toBeDisabled();
        expect(mocks.post).not.toHaveBeenCalled();
    });
    it('offers complete advanced draft readers without claiming protected readback values', async () => {
        render(workspace({ can: { manage: false } }));
        fireEvent.contextMenu(
            screen.getByRole('button', { name: /^Advanced GL30 sections/ }),
            { clientX: 80, clientY: 80 },
        );
        expect(
            screen.getByRole('menu', { name: 'Advanced GL30 sections' }),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('menuitem', { name: 'View draft values' }),
        );
        const reader = screen.getByRole('dialog');
        expect(within(reader).getByText('Reboot interval')).toBeVisible();
        fireEvent.click(
            within(reader).getByRole('button', {
                name: /Firmware update.*Complete details/,
            }),
        );
        for (const label of [
            'Max retries',
            'Download timeout minutes',
            'Download URL',
            'Extended status',
            'Identifier number',
        ])
            expect(within(reader).getByText(label)).toBeVisible();
        expect(mocks.post).not.toHaveBeenCalled();
    });
    it('retains existing Bluetooth section guidance before edits, through review and in the full reader', () => {
        const guidance =
            'Uses GTBTS; GL30 v2.04 does not define a separate GTBT write.';
        const ui = render(workspace());
        fireEvent.click(
            screen.getByRole('button', { name: /^Advanced GL30 sections/ }),
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: /^Bluetooth settings$/,
            }),
        );
        expect(
            within(screen.getByRole('dialog')).getByText(guidance),
        ).toBeVisible();
        next();
        expect(
            within(screen.getByRole('dialog')).getByText(guidance),
        ).toBeVisible();
        fireEvent.change(screen.getByLabelText('Bluetooth name'), {
            target: { value: 'Synthetic_BT' },
        });
        next();
        expect(
            within(screen.getByRole('dialog')).getByText(guidance),
        ).toBeVisible();
        expect(
            within(screen.getByRole('dialog')).getByText('Synthetic_BT'),
        ).toBeVisible();
        ui.unmount();
        render(workspace({ can: { manage: false } }));
        fireEvent.click(
            screen.getByRole('button', { name: /^Advanced GL30 sections/ }),
        );
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: /Bluetooth settings.*Complete details/,
            }),
        );
        expect(
            within(screen.getByRole('dialog')).getByText(guidance),
        ).toBeVisible();
        expect(
            within(screen.getByRole('dialog')).getByText('Bluetooth name'),
        ).toBeVisible();
        expect(mocks.post).not.toHaveBeenCalled();
    });
});
