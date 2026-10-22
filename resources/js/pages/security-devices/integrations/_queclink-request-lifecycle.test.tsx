import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import QueclinkHub, {
    type Device,
    type Preset,
    type Props,
} from './queclink-hub';
const mocks = vi.hoisted(() => ({
    actor: 17,
    permissions: { viewAny: true, integrationsManage: true, devicesView: true },
    post: vi.fn(),
    get: vi.fn(),
    delete: vi.fn(),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => children,
}));
vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({ props: { auth: auth() } }),
        router: {
            ...actual.router,
            on: actual.router.on.bind(actual.router),
            post: mocks.post,
            get: mocks.get,
            delete: mocks.delete,
        },
    };
});
const auth = () => ({
    user: { id: mocks.actor },
    can: { securityDevices: mocks.permissions },
});
const device = (): Device => ({
    id: 2,
    canonical_device_id: 42,
    reference: 'Synthetic lifecycle tracker',
    status: 'paired',
    model_hint: 'GL30MEU',
    protocol_version: '970204',
    firmware_version: 'synthetic-1',
    connection_state: 'connected',
    first_seen_at: '2026-10-01T03:00:00Z',
    last_seen_at: '2026-10-13T03:00:00Z',
    last_frame_at: '2026-10-13T03:00:00Z',
    assignment: null,
    configuration: {
        state: 'observed',
        observed_at: '2026-10-13T03:01:00Z',
        sections: ['CFG'],
    },
    recent_commands: [],
});
const preset = (
    id = 6,
    name = 'Synthetic immutable lifecycle preset',
): Preset => ({
    id,
    name,
    slug: `synthetic-lifecycle-${id}`,
    description: 'Retained synthetic profile',
    target_category: 'personal_tracker',
    is_system: false,
    sections: ['tracking'],
    profile_uuid: `synthetic-profile-${id}`,
    profile_version: 3,
    payload_hash: `synthetic-hash-${id}`,
    created_at: '2026-10-14T03:00:00Z',
});
function fixture(overrides: Partial<Props> = {}): Props {
    return {
        actor_id: 17,
        as_of: '2026-10-14T03:00:00Z',
        listener: {
            port: 8090,
            endpoint_configured: true,
            service_state: 'active',
            connected_count: 1,
        },
        devices: {
            paired: [device()],
            pending: [],
            rejected: [],
            total: 1,
            counts: { paired: 1, pending: 0, rejected: 0 },
        },
        statistics: {
            frames_last_hour: 20,
            last_frame_at: '2026-10-13T03:00:00Z',
        },
        cloudIntegration: {
            status: 'unavailable',
            legacy_credential_stored: false,
            legacy_credential_last4: null,
        },
        siteCredentials: [],
        targets: { vehicles: [], staff: [], clients: [] },
        presets: [],
        retiredPresets: [],
        can: { manage: true },
        ...overrides,
    };
}
const settings = () =>
    fireEvent.click(screen.getByRole('tab', { name: /^Device settings/i }));
const next = () =>
    fireEvent.click(screen.getByRole('button', { name: /^Continue$/ }));
function startSave(
    name = 'Synthetic possibly committed preset',
    server = false,
) {
    fireEvent.click(
        screen.getByRole('button', { name: 'Save current as preset' }),
    );
    fireEvent.change(screen.getByLabelText('Name'), {
        target: { value: name },
    });
    if (server)
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Server connection' }),
        );
    next();
    fireEvent.click(screen.getByRole('button', { name: 'Save preset' }));
    return mocks.post.mock.calls.at(-1)![2];
}
async function application() {
    fireEvent.keyDown(
        screen.getByRole('button', {
            name: 'Actions for Synthetic immutable lifecycle preset',
        }),
        { key: 'Enter', code: 'Enter' },
    );
    fireEvent.click(
        await screen.findByRole('menuitem', { name: 'Review application' }),
    );
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
describe('Queclink request lifecycle and current resource withdrawal', () => {
    it('retains the active save form across the actual page-rerender-before-onSuccess order and confirms its returned receipt', () => {
        const data = fixture(),
            ui = render(<QueclinkHub {...data} />);
        settings();
        const name = 'Synthetic confirmed preset',
            options = startSave(name),
            cancel = vi.fn();
        options.onCancelToken({ cancel });
        const refreshed = fixture({
            as_of: '2026-10-14T03:02:00Z',
            devices: {
                ...data.devices,
                paired: [
                    { ...device(), last_frame_at: '2026-10-14T03:02:00Z' },
                ],
            },
            listener: { ...data.listener, connected_count: 2 },
            presets: [preset(8, name)],
        });
        ui.rerender(<QueclinkHub {...refreshed} />);
        expect(cancel).not.toHaveBeenCalled();
        expect(
            screen.getByRole('dialog', { name: 'Save configuration preset' }),
        ).toBeVisible();
        act(() => {
            options.onSuccess({
                component: 'security-devices/integrations/queclink-hub',
                url: '/security-devices/integrations/queclink',
                props: { ...refreshed, auth: auth() },
            });
            options.onFinish();
        });
        expect(screen.getByText('Preset saved')).toBeVisible();
        expect(
            screen.getByText(
                `${name} is available for review and application.`,
            ),
        ).toBeVisible();
        expect(mocks.post).toHaveBeenCalledTimes(1);
    });
    it('retains preset name, section choices and captured draft fields when validation replies change ordinary device/listener DTOs first', () => {
        const data = fixture(),
            ui = render(<QueclinkHub {...data} />);
        settings();
        const options = startSave('Synthetic validation draft', true);
        const refreshed = fixture({
            as_of: '2026-10-14T03:02:00Z',
            devices: {
                ...data.devices,
                paired: [
                    {
                        ...device(),
                        last_seen_at: '2026-10-14T03:02:00Z',
                        firmware_version: 'synthetic-2',
                    },
                ],
            },
            listener: { ...data.listener, port: 8091 },
        });
        ui.rerender(<QueclinkHub {...refreshed} />);
        act(() => {
            options.onError({ name: 'Choose a clearer preset name.' });
            options.onFinish();
        });
        expect(screen.getByLabelText('Name')).toHaveValue(
            'Synthetic validation draft',
        );
        expect(
            screen.getByRole('checkbox', { name: 'Server connection' }),
        ).toBeChecked();
        expect(
            screen.getByRole('checkbox', { name: 'Global tracking' }),
        ).toBeChecked();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Choose a clearer preset name.',
        );
        next();
        expect(
            within(screen.getByRole('dialog')).getAllByText('8090').length,
        ).toBe(2);
        expect(
            within(screen.getByRole('dialog')).queryByText('8091'),
        ).not.toBeInTheDocument();
        expect(mocks.post).toHaveBeenCalledTimes(1);
    });
    it('retains edited server fields and validation feedback through ordinary DTO updates without rebinding to new defaults', () => {
        const data = fixture(),
            ui = render(<QueclinkHub {...data} />);
        settings();
        fireEvent.click(
            screen.getByRole('button', { name: /^Server connection/ }),
        );
        fireEvent.change(screen.getByLabelText('Main host'), {
            target: { value: 'synthetic.example' },
        });
        next();
        next();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Continue to Device Management',
            }),
        );
        const options = mocks.post.mock.calls.at(-1)![2];
        ui.rerender(
            <QueclinkHub
                {...fixture({
                    listener: { ...data.listener, port: 8091 },
                    devices: {
                        ...data.devices,
                        paired: [
                            {
                                ...device(),
                                last_frame_at: '2026-10-14T03:02:00Z',
                            },
                        ],
                    },
                })}
            />,
        );
        act(() => {
            options.onError({ main_host: 'Confirm this desired host.' });
            options.onFinish();
        });
        expect(screen.getByLabelText('Main host')).toHaveValue(
            'synthetic.example',
        );
        expect(screen.getByLabelText('Main port')).toHaveValue(8090);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Confirm this desired host.',
        );
        expect(mocks.post).toHaveBeenCalledTimes(1);
    });
    it('keeps the possibly committed preset lock through discard, reopening, ordinary DTO updates and tab changes until a full attributed remount', () => {
        const data = fixture(),
            ui = render(<QueclinkHub {...data} />);
        settings();
        const options = startSave(),
            cancel = vi.fn();
        options.onCancelToken({ cancel });
        act(() => {
            options.onCancel();
            options.onFinish();
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(screen.getByRole('button', { name: 'Discard draft' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        ui.rerender(
            <QueclinkHub
                {...fixture({
                    devices: {
                        ...data.devices,
                        paired: [
                            {
                                ...device(),
                                last_frame_at: '2026-10-14T03:04:00Z',
                            },
                        ],
                    },
                })}
            />,
        );
        fireEvent.click(screen.getByRole('tab', { name: /^Devices/ }));
        settings();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save current as preset' }),
        );
        fireEvent.change(screen.getByLabelText('Name'), {
            target: { value: 'Synthetic possibly committed preset' },
        });
        next();
        expect(
            screen.getByRole('button', { name: 'Save preset' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Save preset' }));
        expect(mocks.post).toHaveBeenCalledTimes(1);
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh current records' }),
        );
        expect(mocks.get).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink',
            {},
            { preserveState: false, preserveScroll: true },
        );
        ui.unmount();
        render(
            <QueclinkHub
                {...fixture({
                    as_of: '2026-10-14T03:05:00Z',
                    presets: [preset(8, 'Synthetic possibly committed preset')],
                })}
            />,
        );
        settings();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save current as preset' }),
        );
        fireEvent.change(screen.getByLabelText('Name'), {
            target: { value: 'Synthetic next reviewed preset' },
        });
        next();
        expect(
            screen.getByRole('button', { name: 'Save preset' }),
        ).toBeEnabled();
        fireEvent.click(screen.getByRole('button', { name: 'Save preset' }));
        expect(mocks.post).toHaveBeenCalledTimes(2);
    });
    it.each(['missing', 'canonical'] as const)(
        'withdraws a pending save when its current target is %s and retains uncertainty after the old target returns',
        (mode) => {
            const data = fixture(),
                ui = render(<QueclinkHub {...data} />);
            settings();
            const options = startSave(),
                cancel = vi.fn();
            options.onCancelToken({ cancel });
            const changed = {
                ...data.devices,
                paired:
                    mode === 'missing'
                        ? []
                        : [{ ...device(), canonical_device_id: 99 }],
            };
            ui.rerender(<QueclinkHub {...fixture({ devices: changed })} />);
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            expect(cancel).toHaveBeenCalledTimes(1);
            act(() =>
                options.onSuccess({
                    component: 'security-devices/integrations/queclink-hub',
                    url: '/security-devices/integrations/queclink',
                    props: {
                        ...fixture({
                            presets: [
                                preset(
                                    8,
                                    'Synthetic possibly committed preset',
                                ),
                            ],
                        }),
                        auth: auth(),
                    },
                }),
            );
            expect(screen.queryByText('Preset saved')).not.toBeInTheDocument();
            ui.rerender(<QueclinkHub {...data} />);
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            fireEvent.click(
                screen.getByRole('button', { name: 'Save current as preset' }),
            );
            fireEvent.change(screen.getByLabelText('Name'), {
                target: { value: 'Synthetic possibly committed preset' },
            });
            next();
            expect(
                screen.getByRole('button', { name: 'Save preset' }),
            ).toBeDisabled();
            expect(mocks.post).toHaveBeenCalledTimes(1);
        },
    );
    it.each(['removed', 'version'] as const)(
        'withdraws the selected preset when its immutable profile is %s and requires a new explicit selection',
        async (mode) => {
            const data = fixture({ presets: [preset()] }),
                ui = render(<QueclinkHub {...data} />);
            settings();
            await application();
            expect(screen.getByText('synthetic-profile-6')).toBeVisible();
            const changed =
                mode === 'removed'
                    ? []
                    : [
                          {
                              ...preset(),
                              profile_version: 4,
                              profile_uuid: 'synthetic-profile-6-v4',
                              payload_hash: 'synthetic-hash-v4',
                          },
                      ];
            ui.rerender(<QueclinkHub {...fixture({ presets: changed })} />);
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            expect(
                screen.queryByText('synthetic-profile-6'),
            ).not.toBeInTheDocument();
            ui.rerender(<QueclinkHub {...data} />);
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            expect(mocks.post).not.toHaveBeenCalled();
        },
    );
    it('keeps the uncertain save lock across permission withdrawal/restoration and never exposes a raw failed reply', () => {
        const data = fixture(),
            ui = render(<QueclinkHub {...data} />);
        settings();
        startSave();
        const event = new CustomEvent('inertia:invalid', {
            cancelable: true,
            detail: {
                response: {
                    status: 503,
                    data: { message: 'RAW-PRIVATE-REPLY' },
                    config: {
                        method: 'POST',
                        url: 'http://localhost/security-devices/integrations/queclink/presets',
                    },
                },
            },
        });
        act(() => {
            document.dispatchEvent(event);
            mocks.post.mock.calls.at(-1)![2].onFinish();
        });
        expect(screen.queryByText('RAW-PRIVATE-REPLY')).not.toBeInTheDocument();
        mocks.permissions.integrationsManage = false;
        ui.rerender(<QueclinkHub {...data} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.getByText('Queclink access is unavailable'),
        ).toBeVisible();
        mocks.permissions.integrationsManage = true;
        ui.rerender(<QueclinkHub {...data} />);
        settings();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save current as preset' }),
        );
        fireEvent.change(screen.getByLabelText('Name'), {
            target: { value: 'Synthetic possibly committed preset' },
        });
        next();
        expect(
            screen.getByRole('button', { name: 'Save preset' }),
        ).toBeDisabled();
        expect(mocks.post).toHaveBeenCalledTimes(1);
    });
    it('locks an owned request that finishes without a receipt or validation callback, as the installed transport does after a network exception', () => {
        render(<QueclinkHub {...fixture()} />);
        settings();
        const options = startSave();
        act(() => options.onFinish());
        expect(
            screen.getByRole('button', { name: 'Save preset' }),
        ).toBeDisabled();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Refresh current records',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(screen.getByRole('button', { name: 'Discard draft' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Save current as preset' }),
        );
        fireEvent.change(screen.getByLabelText('Name'), {
            target: { value: 'Synthetic retry intent' },
        });
        next();
        expect(
            screen.getByRole('button', { name: 'Save preset' }),
        ).toBeDisabled();
        expect(mocks.post).toHaveBeenCalledTimes(1);
    });
    it('withdraws a kept draft and an open configuration menu on canonical target change without reviving them when the old target returns', () => {
        const data = fixture(),
            ui = render(<QueclinkHub {...data} />);
        settings();
        fireEvent.click(
            screen.getByRole('button', { name: /^Server connection/ }),
        );
        fireEvent.change(screen.getByLabelText('Main host'), {
            target: { value: 'PRIVATE-OLD-CANONICAL-DRAFT' },
        });
        next();
        next();
        fireEvent.click(screen.getByRole('button', { name: 'Keep draft' }));
        fireEvent.contextMenu(
            screen.getByRole('button', { name: /^Server connection/ }),
            { clientX: 80, clientY: 80 },
        );
        expect(
            screen.getByRole('menu', { name: 'Server connection' }),
        ).toBeVisible();
        ui.rerender(
            <QueclinkHub
                {...fixture({
                    devices: {
                        ...data.devices,
                        paired: [{ ...device(), canonical_device_id: 99 }],
                    },
                })}
            />,
        );
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        expect(
            screen.queryByText('PRIVATE-OLD-CANONICAL-DRAFT'),
        ).not.toBeInTheDocument();
        ui.rerender(<QueclinkHub {...data} />);
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: /^Server connection/ }),
        );
        expect(screen.getByLabelText('Main host')).toHaveValue('');
        expect(mocks.post).not.toHaveBeenCalled();
    });
});
