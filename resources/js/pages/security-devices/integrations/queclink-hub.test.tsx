import { act, fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import QueclinkHub, { ClaimDialog, type Props } from './queclink-hub';

const mocks = vi.hoisted(() => ({
    auth: {
        user: { id: 17 },
        can: {
            securityDevices: {
                viewAny: true,
                integrationsManage: true,
                devicesView: true,
            },
        },
    },
    get: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
    formPost: vi.fn(),
    invalid: undefined as undefined | ((event: any) => void),
    formFailure: null as null | Record<string, string>,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({
        children,
        breadcrumbs,
    }: {
        children: ReactNode;
        breadcrumbs: { title: string; href: string }[];
    }) => (
        <main>
            <nav aria-label="Breadcrumbs">
                {breadcrumbs.map((crumb) => (
                    <a key={crumb.href} href={crumb.href}>
                        {crumb.title}
                    </a>
                ))}
            </nav>
            {children}
        </main>
    ),
}));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        Head: () => null,
        Link: ({
            href,
            children,
            preserveState: _state,
            preserveScroll: _scroll,
            ...props
        }: any) => (
            <a href={href} {...props}>
                {children}
            </a>
        ),
        usePage: () => ({ props: { auth: mocks.auth } }),
        router: {
            get: mocks.get,
            post: mocks.post,
            delete: mocks.delete,
            on: (_name: string, callback: (event: any) => void) => {
                mocks.invalid = callback;
                return () => {
                    mocks.invalid = undefined;
                };
            },
        },
        useForm: (initial: Record<string, unknown>) => {
            const [data, setData] = React.useState(initial);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );
            return {
                data,
                errors,
                processing: false,
                isDirty: JSON.stringify(data) !== JSON.stringify(initial),
                setData: (
                    key: string | Record<string, unknown>,
                    value?: unknown,
                ) =>
                    setData((current) =>
                        typeof key === 'string'
                            ? { ...current, [key]: value }
                            : key,
                    ),
                post: (url: string, options: any) => {
                    mocks.formPost(url, data, options);
                    if (mocks.formFailure) {
                        setErrors(mocks.formFailure);
                        options.onError?.(mocks.formFailure);
                    }
                },
                reset: () => {
                    setData(initial);
                    setErrors({});
                },
            };
        },
    };
});
class FrameSource {
    static instances: FrameSource[] = [];
    static CLOSED = 2;
    readyState = 0;
    onopen: null | (() => void) = null;
    onmessage: null | ((event: MessageEvent) => void) = null;
    onerror: null | (() => void) = null;
    close = vi.fn();
    constructor(public url: string) {
        FrameSource.instances.push(this);
    }
}
const paired: Props['devices']['paired'][number] = {
    id: 2,
    canonical_device_id: 202,
    reference: 'Tracker ending 6998',
    status: 'paired',
    model_hint: 'GL30MEU',
    protocol_version: '970204',
    firmware_version: 'F4.2',
    connection_state: 'disconnected',
    first_seen_at: '2026-05-17T02:00:00Z',
    last_seen_at: '2026-05-18T02:07:01Z',
    last_frame_at: '2026-05-18T02:06:58Z',
    assignment: {
        type: 'client',
        label: 'Private client name with a long approved Site label',
        assigned_at: '2026-05-18T02:00:00Z',
    },
    configuration: {
        state: 'observed',
        observed_at: '2026-05-18T02:03:00Z',
        sections: ['tracking', 'power'],
    },
    recent_commands: [
        {
            id: 51,
            command_word: 'GTFRI',
            status: 'acked',
            created_at: '2026-05-18T01:00:00Z',
            sent_at: '2026-05-18T01:01:00Z',
            acked_at: '2026-05-18T01:02:00Z',
            fulfilled_at: null,
            expires_at: '2026-05-18T01:30:00Z',
            governed: true,
            failure_category: null,
        },
    ],
};
const pending = {
    ...paired,
    id: 3,
    canonical_device_id: null,
    reference: 'Tracker ending 7000',
    status: 'pending' as const,
    assignment: null,
};
const rejected = {
    ...paired,
    id: 4,
    reference: 'Tracker ending 8000',
    status: 'rejected' as const,
    assignment: null,
};
function fixture(overrides: Partial<Props> = {}): Props {
    return {
        actor_id: 17,
        as_of: '2026-10-13T03:00:00Z',
        can: { manage: true },
        listener: {
            port: 8090,
            endpoint_configured: true,
            service_state: 'active',
            connected_count: 4,
        },
        devices: {
            paired: [paired],
            pending: [pending],
            rejected: [rejected],
            total: 32,
            counts: { paired: 30, pending: 1, rejected: 1 },
        },
        statistics: {
            frames_last_hour: 120,
            last_frame_at: '2026-10-13T02:59:00Z',
        },
        cloudIntegration: {
            status: 'unavailable',
            legacy_credential_stored: true,
            legacy_credential_last4: '4567',
        },
        siteCredentials: [],
        targets: {
            vehicles: [{ id: 401, label: 'Fleet van · Harbour House' }],
            staff: [],
            clients: [{ id: 501, label: 'Current client · Harbour House' }],
        },
        presets: [],
        retiredPresets: [],
        ...overrides,
    };
}
function chooseView(name: RegExp) {
    fireEvent.click(screen.getByRole('tab', { name }));
}
async function chooseTarget(name: string, label: string) {
    fireEvent.keyDown(screen.getByRole('combobox', { name }), {
        key: 'Enter',
        code: 'Enter',
    });
    fireEvent.click(await screen.findByRole('option', { name: label }));
}
function openRow(reference: string) {
    fireEvent.click(screen.getByText(reference).closest('[role="row"]')!);
}
function contextMenu(reference: string) {
    fireEvent.contextMenu(screen.getByText(reference).closest('[role="row"]')!);
}
beforeEach(() => {
    vi.clearAllMocks();
    mocks.formFailure = null;
    mocks.invalid = undefined;
    mocks.auth = {
        user: { id: 17 },
        can: {
            securityDevices: {
                viewAny: true,
                integrationsManage: true,
                devicesView: true,
            },
        },
    };
    FrameSource.instances = [];
    vi.stubGlobal('EventSource', FrameSource);
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ frames: [] }),
        }),
    );
});

describe('Queclink shared page and record views', () => {
    it('roots breadcrumbs at Home and links honest scoped meters to their useful views', () => {
        render(<QueclinkHub {...fixture()} />);
        expect(
            within(
                screen.getByRole('navigation', { name: 'Breadcrumbs' }),
            ).getByRole('link', { name: 'Home' }),
        ).toHaveAttribute('href', '/dashboard');
        expect(
            screen.getByText('30 of 32 matching trackers paired'),
        ).toBeVisible();
        expect(screen.getByText(/Read started/)).toHaveTextContent('NZ time');
        expect(screen.getByRole('tab', { name: /^Pending/ })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'View paired devices' }),
        );
        expect(screen.getByText('1 of 30 shown')).toBeVisible();
        expect(screen.getByText(paired.assignment!.label)).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'View connected now' }),
        );
        expect(
            screen.getByRole('region', { name: 'Listener observation' }),
        ).toHaveTextContent('4 connected');
        expect(
            screen.getByText(/does not confirm a tracker location/),
        ).toBeVisible();
    });
    it('uses both shared list layouts and keeps release actions in both menus', async () => {
        render(<QueclinkHub {...fixture()} />);
        chooseView(/^Devices/);
        contextMenu(paired.reference);
        expect(screen.getByRole('menuitem', { name: 'Release' })).toBeVisible();
        fireEvent.keyDown(screen.getByRole('menu'), { key: 'Escape' });
        fireEvent.click(screen.getByRole('radio', { name: 'Cards' }));
        const card = screen
            .getByText(paired.reference)
            .closest('[role="button"]')!;
        expect(card).toHaveClass('rounded-[14px]');
        fireEvent.contextMenu(card);
        fireEvent.click(screen.getByRole('menuitem', { name: 'Release' }));
        expect(
            screen.getByRole('heading', {
                name: 'Release tracker assignment?',
            }),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.keyDown(
            screen.getByRole('button', {
                name: 'Actions for ' + paired.reference,
            }),
            { key: 'Enter', code: 'Enter' },
        );
        expect(
            await screen.findByRole('menuitem', { name: 'Release' }),
        ).toBeVisible();
    });
    it('keeps source times, assignment and command observations in the shared reader', () => {
        render(<QueclinkHub {...fixture()} />);
        chooseView(/^Devices/);
        openRow(paired.reference);
        expect(
            screen.getByRole('dialog', {
                name: 'Tracker details · ' + paired.reference,
            }),
        ).toBeVisible();
        expect(
            screen.getByText(
                'Current assignment does not establish who carried the tracker at an earlier event.',
                { exact: false },
            ),
        ).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Open device record' }),
        ).toHaveAttribute('href', '/security-devices/devices/202');
        fireEvent.click(
            screen.getByRole('button', { name: /Technical observations/ }),
        );
        expect(screen.getByText('970204')).toBeVisible();
        expect(screen.getByText('F4.2')).toBeVisible();
        expect(screen.getByText('tracking, power')).toBeVisible();
        expect(screen.getByText('Acknowledged')).toBeVisible();
        expect(
            screen.getByText(
                'Transport acknowledgement does not confirm that the intended device change took effect.',
            ),
        ).toBeVisible();
    });
    it('shows plain listener, pairing, connection and assignment labels instead of returned keys', () => {
        render(
            <QueclinkHub
                {...fixture({
                    listener: {
                        ...fixture().listener,
                        service_state: 'inactive',
                    },
                })}
            />,
        );
        expect(screen.getByText('Listener stopped')).toBeVisible();
        chooseView(/^Devices/);
        expect(screen.getByText('Client')).toBeVisible();
        expect(screen.queryByText('client')).not.toBeInTheDocument();
        openRow(paired.reference);
        const reader = screen.getByRole('dialog');
        expect(within(reader).getByText('Paired')).toBeVisible();
        expect(within(reader).getByText('Disconnected')).toBeVisible();
        expect(within(reader).queryByText('paired')).not.toBeInTheDocument();
        expect(
            within(reader).queryByText('disconnected'),
        ).not.toBeInTheDocument();
    });
    it('keeps canonical record navigation hidden without current device permission', () => {
        mocks.auth.can.securityDevices.devicesView = false;
        render(<QueclinkHub {...fixture()} />);
        chooseView(/^Devices/);
        openRow(paired.reference);
        expect(
            screen.queryByRole('link', { name: 'Open device record' }),
        ).not.toBeInTheDocument();
    });
    it('shows useful empty views and keeps the cloud capability unavailable', () => {
        render(
            <QueclinkHub
                {...fixture({
                    devices: {
                        paired: [],
                        pending: [],
                        rejected: [],
                        total: 0,
                        counts: { paired: 0, pending: 0, rejected: 0 },
                    },
                })}
            />,
        );
        chooseView(/^Pending/);
        expect(
            screen.getByText('No devices waiting for pairing'),
        ).toBeVisible();
        chooseView(/^Devices/);
        expect(screen.getByText('No devices paired yet')).toBeVisible();
        chooseView(/^Rejected/);
        expect(screen.getByText('No rejected devices')).toBeVisible();
        chooseView(/^Cloud API/);
        expect(screen.getByText('Cloud API unavailable')).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /Test cloud/ }),
        ).not.toBeInTheDocument();
    });
    it('keeps the active view through new search data and requests current authority alongside partial records', () => {
        const data = fixture();
        const result = render(<QueclinkHub {...data} />);
        chooseView(/^Devices/);
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Search devices' }),
            { target: { value: 'GL30' } },
        );
        fireEvent.keyDown(
            screen.getByRole('searchbox', { name: 'Search devices' }),
            { key: 'Enter' },
        );
        expect(mocks.get).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink',
            { device_search: 'GL30' },
            expect.objectContaining({
                // The header summary is read with the list, so the new
                // "Read started" time never labels older header numbers.
                only: [
                    'devices',
                    'actor_id',
                    'as_of',
                    'auth',
                    'can',
                    'listener',
                    'statistics',
                ],
            }),
        );
        result.rerender(
            <QueclinkHub
                {...data}
                devices={{ ...data.devices, paired: [], search: 'GL30' }}
            />,
        );
        expect(screen.getByRole('tab', { name: /^Devices/ })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(screen.getByText('No devices paired yet')).toBeVisible();
        expect(
            screen.queryByText(paired.assignment!.label),
        ).not.toBeInTheDocument();
    });
});

describe('Queclink current authority', () => {
    it.each(['actor', 'view', 'manage', 'payload'] as const)(
        'hides private records and open readers after %s authority loss',
        (loss) => {
            const data = fixture();
            const result = render(<QueclinkHub {...data} />);
            chooseView(/^Devices/);
            openRow(paired.reference);
            if (loss === 'actor') mocks.auth.user.id = 18;
            if (loss === 'view') mocks.auth.can.securityDevices.viewAny = false;
            if (loss === 'manage')
                mocks.auth.can.securityDevices.integrationsManage = false;
            result.rerender(
                <QueclinkHub {...data} can={{ manage: loss !== 'payload' }} />,
            );
            expect(
                screen.getByText('Queclink access is unavailable'),
            ).toBeVisible();
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            expect(
                screen.queryByText(paired.assignment!.label),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('searchbox', { name: 'Search devices' }),
            ).not.toBeInTheDocument();
            expect(mocks.post).not.toHaveBeenCalled();
        },
    );
    it('does not reauthorise retained private props when both actor metadata and auth change in a partial visit', () => {
        const data = fixture();
        const result = render(<QueclinkHub {...data} />);
        mocks.auth.user.id = 18;
        result.rerender(<QueclinkHub {...data} actor_id={18} />);
        expect(
            screen.getByText('Queclink access is unavailable'),
        ).toBeVisible();
        expect(screen.queryByText(pending.reference)).not.toBeInTheDocument();
        expect(
            screen.queryByRole('tab', { name: /^Cloud API/ }),
        ).not.toBeInTheDocument();
    });
    it('starts denied when actor metadata is absent or stale, without opening background transports', () => {
        render(<QueclinkHub {...fixture({ actor_id: 0 })} />);
        expect(
            screen.getByText('Queclink access is unavailable'),
        ).toBeVisible();
        expect(fetch).not.toHaveBeenCalled();
        expect(screen.queryByText(pending.reference)).not.toBeInTheDocument();
    });
    it('closes an open assignment form when an owned route denies current access', () => {
        render(<QueclinkHub {...fixture()} />);
        contextMenu(pending.reference);
        fireEvent.click(screen.getByRole('menuitem', { name: 'Claim device' }));
        expect(
            screen.getByRole('dialog', { name: 'Claim device' }),
        ).toBeVisible();
        act(() =>
            mocks.invalid?.({
                preventDefault: vi.fn(),
                detail: {
                    response: {
                        status: 403,
                        config: {
                            url: '/security-devices/integrations/queclink/devices/3/claim',
                        },
                    },
                },
            }),
        );
        expect(
            screen.getByText('Queclink access is unavailable'),
        ).toBeVisible();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('reads a refused write as not saved, keeping current records instead of claiming lost access', () => {
        render(<QueclinkHub {...fixture()} />);
        const preventDefault = vi.fn();
        act(() =>
            mocks.invalid?.({
                preventDefault,
                detail: {
                    response: {
                        status: 404,
                        config: {
                            method: 'post',
                            url: '/security-devices/integrations/queclink/devices/3/reject',
                        },
                    },
                },
            }),
        );
        expect(preventDefault).toHaveBeenCalled();
        expect(screen.getByText(/This change was not saved/)).toBeVisible();
        expect(
            screen.queryByText('Queclink access is unavailable'),
        ).not.toBeInTheDocument();
        expect(screen.getByText(pending.reference)).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));
        expect(
            screen.queryByText(/This change was not saved/),
        ).not.toBeInTheDocument();
    });
    it('explains a refused 422 hub action instead of opening a raw error page', () => {
        render(<QueclinkHub {...fixture()} />);
        const preventDefault = vi.fn();
        act(() =>
            mocks.invalid?.({
                preventDefault,
                detail: {
                    response: {
                        status: 422,
                        data: '<html>RAW-ERROR-PAGE</html>',
                        config: {
                            method: 'post',
                            url: '/security-devices/integrations/queclink/devices/2/release',
                        },
                    },
                },
            }),
        );
        expect(preventDefault).toHaveBeenCalled();
        expect(screen.getByText(/This change was not accepted/)).toBeVisible();
        expect(screen.queryByText(/RAW-ERROR-PAGE/)).not.toBeInTheDocument();
        expect(screen.getByText(pending.reference)).toBeVisible();
    });
    it('still clears private records when the hub page read itself is refused', () => {
        render(<QueclinkHub {...fixture()} />);
        act(() =>
            mocks.invalid?.({
                preventDefault: vi.fn(),
                detail: {
                    response: {
                        status: 404,
                        config: {
                            method: 'get',
                            url: '/security-devices/integrations/queclink',
                        },
                    },
                },
            }),
        );
        expect(
            screen.getByText('Queclink access is unavailable'),
        ).toBeVisible();
        expect(screen.queryByText(pending.reference)).not.toBeInTheDocument();
    });
    it('clears old frames and closes the stream when a background history read is denied', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: false,
                status: 403,
                json: async () => ({}),
            }),
        );
        render(<QueclinkHub {...fixture()} />);
        chooseView(/^Debug console/);
        expect(
            await screen.findByText('Queclink access is unavailable'),
        ).toBeVisible();
        expect(FrameSource.instances[0]?.close).toHaveBeenCalled();
        expect(
            screen.queryByText(
                'Private client name with a long approved Site label',
            ),
        ).not.toBeInTheDocument();
    });
});

describe('Queclink assignment drafts and review', () => {
    it('reviews bulk choices before the canonical management handoff and preserves them on failure', () => {
        render(<QueclinkHub {...fixture()} />);
        chooseView(/^Devices/);
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'Select ' + paired.reference,
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Bulk apply (1)' }));
        expect(
            screen.getByRole('dialog', { name: 'Review bulk management' }),
        ).toBeVisible();
        expect(mocks.post).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Continue to review' }),
        );
        expect(
            screen.getByText(
                /does not send a command or confirm a completed device action/,
            ),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review 1 device' }),
        );
        expect(mocks.post).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink/bulk',
            {
                device_ids: [2],
                action: 'read_configuration',
                section: 'all',
                preset_id: undefined,
            },
            expect.objectContaining({ preserveScroll: true }),
        );
        const options = mocks.post.mock.calls[0][2];
        act(() => {
            options.onError({ device_ids: 'Current Site access changed.' });
            options.onFinish();
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Your choices remain here',
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Current Site access changed.',
        );
        expect(screen.getByText('Read full configuration')).toBeVisible();
        expect(
            screen.queryByText('read configuration'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Commands completed'),
        ).not.toBeInTheDocument();
    });
    it('removes a bulk-management draft when the returned device scope changes', () => {
        const data = fixture();
        const result = render(<QueclinkHub {...data} />);
        chooseView(/^Devices/);
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'Select ' + paired.reference,
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Bulk apply (1)' }));
        result.rerender(
            <QueclinkHub
                {...data}
                devices={{
                    ...data.devices,
                    paired: [],
                    counts: { paired: 0, pending: 1, rejected: 1 },
                }}
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByText('No devices paired yet')).toBeVisible();
        expect(mocks.post).not.toHaveBeenCalled();
    });
    it('preserves selected ownership through Review and Back and sends the canonical claim payload', async () => {
        render(
            <ClaimDialog
                device={pending}
                targets={fixture().targets}
                onClose={vi.fn()}
            />,
        );
        await chooseTarget('Vehicle', 'Fleet van · Harbour House');
        fireEvent.click(
            screen.getByRole('button', { name: 'Continue to review' }),
        );
        expect(screen.getByText('Fleet van · Harbour House')).toBeVisible();
        expect(mocks.formPost).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(
            screen.getByRole('combobox', { name: 'Vehicle' }),
        ).toHaveTextContent('Fleet van · Harbour House');
        fireEvent.click(
            screen.getByRole('button', { name: 'Continue to review' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Claim device' }));
        expect(mocks.formPost).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink/devices/3/claim',
            { pairing_type: 'vehicle', target_id: '401', consent_id: '' },
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(screen.queryByText('Device paired')).not.toBeInTheDocument();
    });
    it('requires explicit draft discard and keeps current consent errors and choices recoverable', async () => {
        const close = vi.fn();
        mocks.formFailure = {
            consent_id:
                'Client tracker pairing requires an active location tracking consent.',
        };
        render(
            <ClaimDialog
                device={{ ...pending, pending_pairing_type: 'client' }}
                targets={fixture().targets}
                onClose={close}
            />,
        );
        await chooseTarget('Client', 'Current client · Harbour House');
        fireEvent.change(
            screen.getByRole('spinbutton', { name: /Consent record ID/ }),
            { target: { value: '42' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByRole('heading', { name: 'Discard this draft?' }),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(
            screen.getByRole('spinbutton', { name: /Consent record ID/ }),
        ).toHaveValue(42);
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Continue to review' }),
        );
        expect(
            screen.getByText('Record #42 · validation required'),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Claim device' }));
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Client tracker pairing requires an active location tracking consent.',
        );
        expect(
            screen.getByRole('spinbutton', { name: /Consent record ID/ }),
        ).toHaveValue(42);
        expect(
            screen.getByRole('combobox', { name: 'Client' }),
        ).toHaveTextContent('Current client · Harbour House');
        expect(close).not.toHaveBeenCalled();
    });
});
