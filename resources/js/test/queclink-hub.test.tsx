import { act, fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    ClaimDialog,
    CloudIntegrationTab,
    DebugConsoleTab,
    DeviceSettingsTab,
    GovernedCommandHandoff,
    OverviewTab,
    default as QueclinkHub,
    RejectedTab,
} from '@/pages/security-devices/integrations/queclink-hub';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <main>{children}</main>
    ),
}));

const inertiaMocks = vi.hoisted(() => ({
    router: {
        post: vi.fn(),
        get: vi.fn(),
        delete: vi.fn(),
    },
    formPost: vi.fn(),
    formErrors: {} as Record<string, string>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: inertiaMocks.router,
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        post: inertiaMocks.formPost,
        processing: false,
        errors: inertiaMocks.formErrors,
        reset: vi.fn(),
    }),
}));

beforeEach(() => {
    inertiaMocks.formErrors = {};
    inertiaMocks.formPost.mockReset();
});

const recentFrame = {
    id: 101,
    direction: 'inbound',
    frame_type: 'RESP',
    command_word: 'GTFRI',
    parse_ok: true,
    failure_category: null,
    created_at: '2026-05-18T02:07:01Z',
};

class EventSourceStub {
    static instances: EventSourceStub[] = [];
    static CLOSED = 2;

    onopen: (() => void) | null = null;
    onmessage: ((event: MessageEvent) => void) | null = null;
    onerror: (() => void) | null = null;
    close = vi.fn();
    readyState = 0;

    constructor(public url: string) {
        EventSourceStub.instances.push(this);
    }
}

function renderHub() {
    return render(
        <DebugConsoleTab
            devices={[
                {
                    id: 2,
                    reference: 'Tracker ending 6998',
                    status: 'paired',
                    model_hint: null,
                    protocol_version: '970204',
                    firmware_version: null,
                    connection_state: 'connected',
                    first_seen_at: null,
                    last_seen_at: '2026-05-18T02:07:01Z',
                    last_frame_at: '2026-05-18T02:07:01Z',
                    assignment: {
                        type: 'client',
                        assigned_at: '2026-05-18T02:00:00Z',
                        label: 'Amelia Wilson',
                    },
                },
            ]}
            can={{ manage: true }}
        />,
    );
}

describe('QueclinkHub debug console', () => {
    beforeEach(() => {
        EventSourceStub.instances = [];
        vi.stubGlobal('EventSource', EventSourceStub);
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ frames: [recentFrame] }),
            }),
        );
    });

    it('loads recent frames when the debug console opens instead of showing an empty stream', async () => {
        renderHub();

        expect(screen.getByRole('status')).toHaveTextContent('Connecting');
        expect(await screen.findByText('RESP frame received')).toBeVisible();
        expect(fetch).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink/frames',
            expect.objectContaining({
                headers: expect.objectContaining({
                    Accept: 'application/json',
                }),
            }),
        );

        act(() => {
            const source = EventSourceStub.instances[0];
            source.readyState = 1;
            source.onopen?.();
        });
        expect(screen.getByRole('status')).toHaveTextContent('Live');

        fireEvent.click(screen.getByRole('button', { name: 'Pause' }));
        expect(screen.getByRole('status')).toHaveTextContent('Paused');
    });

    it('never labels failed or reconnecting frame transports as live', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));

        renderHub();

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Recent frames could not be loaded',
        );

        act(() => {
            const source = EventSourceStub.instances[0];
            source.readyState = 0;
            source.onerror?.();
        });
        expect(screen.getByRole('status')).toHaveTextContent('Reconnecting');
        expect(screen.getByRole('alert')).toHaveTextContent(
            'reconnecting automatically',
        );
        expect(screen.getByRole('status')).not.toHaveTextContent('Live');

        act(() => {
            const source = EventSourceStub.instances[0];
            source.readyState = EventSourceStub.CLOSED;
            source.onerror?.();
        });
        expect(screen.getByRole('status')).toHaveTextContent('Unavailable');
        expect(screen.getByRole('button', { name: 'Reconnect' })).toBeVisible();
        expect(screen.getByText('Live frames are unavailable.')).toBeVisible();
    });

    it('keeps the debug console usable when no tracker is paired yet', () => {
        render(<DebugConsoleTab devices={[]} can={{ manage: true }} />);

        expect(screen.getByRole('status')).toHaveTextContent('Connecting');
        expect(screen.getByText('Pick a paired device…')).toBeVisible();
        expect(
            screen.getByRole('button', {
                name: 'Review governed location refresh',
            }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Review governed restart' }),
        ).toBeDisabled();
    });
});

describe('QueclinkHub page chrome', () => {
    const overviewProps: React.ComponentProps<typeof OverviewTab> = {
        listener: {
            port: 8090,
            endpoint_configured: true,
            service_state: 'active',
            connected_count: 0,
        },
        devices: {
            paired: [],
            pending: [],
            rejected: [],
            total: 0,
        },
        statistics: { frames_last_hour: 0, last_frame_at: null },
        can: { manage: true },
    };

    it('describes protected provisioning readiness without promising a raw command', () => {
        render(<OverviewTab {...overviewProps} />);

        expect(
            screen.getByRole('heading', { name: 'Provisioning readiness' }),
        ).toBeVisible();
        expect(
            screen.getByText(/protected server configuration/i),
        ).toBeVisible();
        expect(
            screen.getByText(/existing hostname remains protected/i),
        ).toBeVisible();
        expect(
            screen.queryByText('Provisioning string'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText(/AT\+GTSRI/i)).not.toBeInTheDocument();
    });

    it('shows readiness failures inline without a browser alert', async () => {
        const browserAlert = vi.fn();
        vi.stubGlobal('alert', browserAlert);
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: false,
                status: 422,
                json: async () => ({
                    error: 'Set the public hostname under Listener settings first.',
                }),
            }),
        );

        render(<OverviewTab {...overviewProps} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Check readiness' }),
        );

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Set the public hostname under Listener settings first.',
        );
        expect(browserAlert).not.toHaveBeenCalled();
    });

    it('presents a successful readiness result in plain language', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                status: 200,
                json: async () => ({
                    state: 'ready_for_secure_provisioning',
                    family: 'vehicle_tracker',
                    instructions: [
                        'Use the approved secure device-management process.',
                    ],
                }),
            }),
        );

        render(<OverviewTab {...overviewProps} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Check readiness' }),
        );

        expect(
            await screen.findByText('Ready for secure provisioning'),
        ).toBeVisible();
        expect(
            screen.queryByText('ready_for_secure_provisioning'),
        ).not.toBeInTheDocument();
    });

    it('shows the native-only cloud boundary and offers only legacy credential removal', () => {
        inertiaMocks.router.delete.mockClear();
        vi.stubGlobal(
            'confirm',
            vi.fn(() => true),
        );

        render(
            <CloudIntegrationTab
                cloudIntegration={{
                    status: 'unavailable',
                    legacy_credential_stored: true,
                    legacy_credential_last4: '0042',
                }}
                can={{ manage: true }}
            />,
        );

        expect(screen.getByText('Cloud API unavailable')).toBeVisible();
        expect(
            screen.getByText(/Direct TCP intake remains the primary path/i),
        ).toBeVisible();
        expect(
            screen.getByText(/Legacy cloud credential ending in 0042/i),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /Save IMS credentials/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Test connection/i }),
        ).not.toBeInTheDocument();
        expect(screen.queryByLabelText(/API key/i)).not.toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Remove legacy cloud credential',
            }),
        );
        expect(
            screen.getByRole('heading', {
                name: 'Remove legacy Queclink cloud credential?',
            }),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove legacy credential' }),
        );
        expect(inertiaMocks.router.delete).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink/key',
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('renders rejected devices with restore and rejected pagination controls', () => {
        inertiaMocks.router.post.mockClear();
        inertiaMocks.router.get.mockClear();
        vi.stubGlobal(
            'confirm',
            vi.fn(() => true),
        );

        render(
            <RejectedTab
                rejected={[
                    {
                        id: 91,
                        reference: 'Tracker ending 0091',
                        status: 'rejected',
                        model_hint: 'GL30MEU',
                        protocol_version: null,
                        firmware_version: null,
                        connection_state: 'disconnected',
                        first_seen_at: null,
                        last_seen_at: null,
                        last_frame_at: null,
                        assignment: null,
                    },
                ]}
                pagination={{
                    current_page: 1,
                    last_page: 2,
                    per_page: 25,
                    total: 26,
                    prev_page_url: null,
                    next_page_url:
                        '/security-devices/integrations/queclink?rejected_page=2',
                }}
                can={{ manage: true }}
            />,
        );

        expect(screen.getByText('Rejected devices')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Restore' }));
        expect(
            screen.getByRole('heading', {
                name: 'Restore tracker to pending?',
            }),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Restore tracker' }),
        );
        expect(inertiaMocks.router.post).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink/devices/91/restore',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Next rejected' }));
        expect(inertiaMocks.router.get).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink?rejected_page=2',
            {},
            expect.objectContaining({ only: ['devices'] }),
        );
    });

    it('explains canonical site prerequisites in the claim flow', () => {
        render(
            <ClaimDialog
                device={{
                    id: 44,
                    reference: 'Tracker ending 0044',
                    status: 'pending',
                    pending_pairing_type: 'staff',
                    model_hint: 'GL30MEU',
                    protocol_version: null,
                    firmware_version: null,
                    connection_state: 'disconnected',
                    first_seen_at: null,
                    last_seen_at: null,
                    last_frame_at: null,
                    assignment: null,
                }}
                targets={{ vehicles: [], staff: [], clients: [] }}
                onClose={vi.fn()}
            />,
        );

        expect(
            screen.getByText(/active HR profile with a primary site/i),
        ).toBeVisible();
    });

    it('announces backend claim errors and links them to the affected fields', () => {
        inertiaMocks.formErrors = {
            pairing_type: 'Choose what this device is tracking.',
            target_id: 'Choose a client to track.',
            consent_id:
                'The selected tracking consent is not active for this client.',
        };

        const props = {
            device: {
                id: 45,
                reference: 'Tracker ending 0045',
                status: 'pending' as const,
                pending_pairing_type: 'client' as const,
                model_hint: 'GL30MEU',
                protocol_version: null,
                firmware_version: null,
                connection_state: 'disconnected' as const,
                first_seen_at: null,
                last_seen_at: null,
                last_frame_at: null,
                assignment: null,
            },
            targets: { vehicles: [], staff: [], clients: [] },
            onClose: vi.fn(),
        };
        const { rerender } = render(<ClaimDialog {...props} />);

        const summary = screen.getByRole('alert');
        expect(summary).toHaveTextContent("We couldn't claim this device");
        expect(summary).toHaveTextContent(
            'Choose what this device is tracking.',
        );
        expect(summary).toHaveTextContent('Choose a client to track.');
        expect(summary).toHaveTextContent(
            'The selected tracking consent is not active for this client.',
        );
        expect(summary).toHaveAttribute('tabindex', '-1');
        expect(summary).toHaveFocus();

        const pairingType = screen.getByRole('combobox', {
            name: 'What is this device?',
        });
        expect(pairingType).toHaveAttribute('aria-invalid', 'true');
        expect(pairingType).toHaveAccessibleDescription(
            'Choose what this device is tracking.',
        );

        const clientPicker = screen.getByRole('combobox', { name: 'Client' });
        expect(clientPicker).toHaveAttribute('aria-invalid', 'true');
        expect(clientPicker).toHaveAccessibleDescription(
            'Choose a client to track.',
        );

        const consentInput = screen.getByRole('spinbutton', {
            name: /Consent record ID/i,
        });
        expect(consentInput).toHaveAttribute('aria-invalid', 'true');
        expect(consentInput).toHaveAccessibleDescription(
            /The selected tracking consent is not active for this client\./,
        );

        consentInput.focus();
        inertiaMocks.formErrors = { ...inertiaMocks.formErrors };
        rerender(<ClaimDialog {...props} />);
        expect(summary).toHaveFocus();
    });

    it('explains that current consent is required and tells the operator how to proceed', () => {
        inertiaMocks.formErrors = {
            consent_id:
                'Client tracker pairing requires an active location tracking consent.',
        };

        render(
            <ClaimDialog
                device={{
                    id: 46,
                    reference: 'Tracker ending 0046',
                    status: 'pending',
                    pending_pairing_type: 'client',
                    model_hint: 'GL30MEU',
                    protocol_version: null,
                    firmware_version: null,
                    connection_state: 'disconnected',
                    first_seen_at: null,
                    last_seen_at: null,
                    last_frame_at: null,
                    assignment: null,
                }}
                targets={{ vehicles: [], staff: [], clients: [] }}
                onClose={vi.fn()}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            'Client tracker pairing requires an active location tracking consent.',
        );
        expect(
            screen.getByRole('spinbutton', {
                name: /optional when current consent exists/i,
            }),
        ).toBeVisible();
        expect(
            screen.getByText(
                /A current location-tracking consent is required before you can claim this device/i,
            ),
        ).toBeVisible();
        expect(
            screen.getByText(
                /If there is no current consent, record it in the client's profile first/i,
            ),
        ).toBeVisible();
        expect(
            screen.queryByText(/device will connect/i),
        ).not.toBeInTheDocument();
    });

    it('requests server-filtered vehicle targets so the picker cap is not an authorization boundary', () => {
        inertiaMocks.router.get.mockClear();
        render(
            <ClaimDialog
                device={{
                    id: 42,
                    reference: 'Tracker ending 0042',
                    status: 'pending',
                    model_hint: 'GV500CG',
                    protocol_version: null,
                    firmware_version: null,
                    connection_state: 'disconnected',
                    first_seen_at: null,
                    last_seen_at: null,
                    last_frame_at: null,
                    assignment: null,
                }}
                targets={{ vehicles: [], staff: [], clients: [] }}
                onClose={vi.fn()}
            />,
        );

        fireEvent.change(screen.getByPlaceholderText('Search vehicles…'), {
            target: { value: 'Vehicle 0501' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));

        expect(inertiaMocks.router.get).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink',
            expect.objectContaining({
                target_type: 'vehicle',
                target_search: 'Vehicle 0501',
            }),
            expect.objectContaining({ only: ['targets'], preserveState: true }),
        );
    });

    it('requests server-filtered staff targets beyond the first picker page', () => {
        inertiaMocks.router.get.mockClear();
        render(
            <ClaimDialog
                device={{
                    id: 43,
                    reference: 'Tracker ending 0043',
                    status: 'pending',
                    pending_pairing_type: 'staff',
                    model_hint: 'GL30MEU',
                    protocol_version: null,
                    firmware_version: null,
                    connection_state: 'disconnected',
                    first_seen_at: null,
                    last_seen_at: null,
                    last_frame_at: null,
                    assignment: null,
                }}
                targets={{ vehicles: [], staff: [], clients: [] }}
                onClose={vi.fn()}
            />,
        );

        fireEvent.change(screen.getByPlaceholderText('Search staff…'), {
            target: { value: 'Worker 0501' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));

        expect(inertiaMocks.router.get).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink',
            expect.objectContaining({
                target_type: 'staff',
                target_search: 'Worker 0501',
            }),
            expect.objectContaining({ only: ['targets'], preserveState: true }),
        );
    });

    it('server-searches devices and follows exposed pagination links', () => {
        inertiaMocks.router.get.mockClear();
        render(
            <QueclinkHub
                listener={{
                    port: 8090,
                    endpoint_configured: true,
                    service_state: 'active',
                    connected_count: 0,
                }}
                devices={{
                    paired: [],
                    pending: [],
                    rejected: [],
                    total: 65,
                    counts: { paired: 65, pending: 0, rejected: 0 },
                    search: '',
                    pagination: {
                        paired: {
                            current_page: 1,
                            last_page: 3,
                            per_page: 25,
                            total: 65,
                            prev_page_url: null,
                            next_page_url:
                                '/security-devices/integrations/queclink?paired_page=2',
                        },
                        pending: {
                            current_page: 1,
                            last_page: 1,
                            per_page: 25,
                            total: 0,
                            prev_page_url: null,
                            next_page_url: null,
                        },
                        rejected: {
                            current_page: 1,
                            last_page: 1,
                            per_page: 25,
                            total: 0,
                            prev_page_url: null,
                            next_page_url: null,
                        },
                    },
                }}
                statistics={{ frames_last_hour: 0, last_frame_at: null }}
                cloudIntegration={{
                    status: 'unavailable',
                    legacy_credential_stored: false,
                    legacy_credential_last4: null,
                }}
                siteCredentials={[]}
                targets={{ vehicles: [], staff: [], clients: [] }}
                presets={[]}
                can={{ manage: true }}
            />,
        );

        fireEvent.change(screen.getByPlaceholderText('Search devices…'), {
            target: { value: 'NeedleModel' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Search devices' }));
        expect(inertiaMocks.router.get).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink',
            { device_search: 'NeedleModel' },
            expect.objectContaining({ only: ['devices'] }),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Next devices' }));
        expect(inertiaMocks.router.get).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink?paired_page=2',
            {},
            expect.objectContaining({ only: ['devices'] }),
        );
    });

    it('uses the full-width Sites-style hero and tab rail', () => {
        render(
            <QueclinkHub
                listener={{
                    port: 8090,
                    endpoint_configured: true,
                    service_state: 'active',
                    connected_count: 1,
                }}
                devices={{
                    paired: [
                        {
                            id: 2,
                            reference: 'Tracker ending 6998',
                            status: 'paired',
                            model_hint: 'GL30MEU',
                            protocol_version: '970204',
                            firmware_version: null,
                            connection_state: 'connected',
                            first_seen_at: null,
                            last_seen_at: '2026-05-18T02:07:01Z',
                            last_frame_at: '2026-05-18T02:07:01Z',
                            assignment: {
                                type: 'client',
                                assigned_at: '2026-05-18T02:00:00Z',
                                label: 'Amelia Wilson',
                            },
                            configuration: null,
                            recent_commands: [],
                        },
                    ],
                    pending: [],
                    rejected: [],
                    total: 1,
                }}
                statistics={{
                    frames_last_hour: 12,
                    last_frame_at: '2026-05-18T02:07:01Z',
                }}
                cloudIntegration={{
                    status: 'unavailable',
                    legacy_credential_stored: false,
                    legacy_credential_last4: null,
                }}
                siteCredentials={[]}
                targets={{
                    vehicles: [],
                    staff: [],
                    clients: [{ id: 9012, label: 'Amelia Wilson' }],
                }}
                presets={[]}
                can={{ manage: true }}
            />,
        );

        expect(screen.getByTestId('queclink-page-shell')).toHaveClass('w-full');
        // Unified compact PageHero (post hero-unification): back link + title,
        // not the former bespoke gradient banner.
        expect(screen.getByText('Back to APIs & Integrations')).toBeVisible();
        expect(screen.getByText('Paired devices')).toBeVisible();
        const tabList = screen.getByTestId('queclink-tab-list');
        expect(tabList).toHaveClass('border-b', 'flex-wrap');
        expect(tabList).not.toHaveClass('overflow-x-auto');
    });
});

describe('QueclinkHub device settings', () => {
    it('shows immutable retired preset actor, reason and profile version', () => {
        render(
            <DeviceSettingsTab
                can={{ manage: true }}
                presets={[]}
                retiredPresets={[
                    {
                        id: 91,
                        name: 'Previous resident baseline',
                        slug: 'previous-resident-baseline',
                        description: null,
                        target_category: 'personal_tracker',
                        is_system: false,
                        sections: ['tracking'],
                        profile_version: 3,
                        payload_hash: null,
                        created_at: '2026-08-01T01:00:00Z',
                        retired_at: '2026-08-06T02:00:00Z',
                        retired_by: 'Moana Jones',
                        retirement_reason:
                            'Replaced by the approved current resident baseline.',
                    },
                ]}
                listener={{
                    port: 8090,
                    endpoint_configured: true,
                    service_state: 'active',
                    connected_count: 0,
                }}
                devices={[
                    {
                        id: 2,
                        canonical_device_id: 42,
                        reference: 'Tracker ending 6998',
                        status: 'paired',
                        model_hint: 'GL30MEU',
                        protocol_version: '970204',
                        firmware_version: null,
                        connection_state: 'connected',
                        first_seen_at: null,
                        last_seen_at: '2026-08-06T01:00:00Z',
                        last_frame_at: '2026-08-06T01:00:00Z',
                        assignment: null,
                        configuration: null,
                        recent_commands: [],
                    },
                ]}
            />,
        );

        expect(screen.getByText('Retired preset history')).toBeVisible();
        expect(screen.getByText(/Moana Jones/)).toBeVisible();
        expect(screen.getByText(/Profile v3/)).toBeVisible();
        expect(screen.getByText(/Replaced by the approved/)).toBeVisible();
    });

    it('renders the latest config snapshot and safe GL30 controls', () => {
        inertiaMocks.router.post.mockClear();

        render(
            <DeviceSettingsTab
                can={{ manage: true }}
                presets={[]}
                listener={{
                    port: 8090,
                    endpoint_configured: true,
                    service_state: 'active',
                    connected_count: 1,
                }}
                devices={[
                    {
                        id: 2,
                        canonical_device_id: 42,
                        reference: 'Tracker ending 6998',
                        status: 'paired',
                        model_hint: 'GL30MEU',
                        protocol_version: '970204',
                        firmware_version: null,
                        connection_state: 'connected',
                        first_seen_at: null,
                        last_seen_at: '2026-05-18T02:07:01Z',
                        last_frame_at: '2026-05-18T02:07:01Z',
                        assignment: {
                            type: 'client',
                            assigned_at: '2026-05-18T02:00:00Z',
                            label: 'Amelia Wilson',
                        },
                        configuration: {
                            state: 'observed',
                            observed_at: '2026-05-18T03:15:00Z',
                            sections: ['SRI', 'CFG', 'DOG'],
                        },
                        recent_commands: [
                            {
                                id: 44,
                                command_word: 'GTDOG',
                                status: 'queued',
                                created_at: '2026-05-18T03:16:00Z',
                                sent_at: null,
                                acked_at: null,
                                fulfilled_at: null,
                                cancelled_at: null,
                                expires_at: '2026-05-18T03:21:00Z',
                                governed: false,
                                failure_category: null,
                            },
                            {
                                id: 45,
                                command_word: 'GTWFI',
                                status: 'failed',
                                created_at: '2026-05-18T03:17:00Z',
                                sent_at: '2026-05-18T03:17:10Z',
                                acked_at: null,
                                fulfilled_at: null,
                                cancelled_at: null,
                                expires_at: '2026-05-18T03:22:00Z',
                                governed: false,
                                failure_category: 'provider_failure',
                            },
                        ],
                    },
                ]}
            />,
        );

        expect(screen.getByText('Device settings')).toBeVisible();
        expect(screen.getByText('Connection health')).toBeVisible();
        expect(screen.getByText(/Amelia Wilson/)).toBeVisible();
        expect(screen.getByText(/Values are protected/)).toBeVisible();
        expect(
            screen.queryByText(/oblivionfindings\.com/),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Read full config')).toBeVisible();
        expect(screen.getByText('Read one section')).toBeVisible();
        expect(screen.getByText('Advanced GL30 sections')).toBeVisible();
        expect(screen.getByText('Resident safety profile')).toBeVisible();
        expect(
            screen.getByText(
                /configuration profiles governed through Device Management/i,
            ),
        ).toBeVisible();
        expect(
            screen.getByText(
                'Legacy provider-console command · cancellation only · content protected',
            ),
        ).toBeVisible();
        expect(
            screen.getByText(
                'Legacy provider-console history · read only · content protected',
            ),
        ).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Open Device Management' }),
        ).toHaveAttribute(
            'href',
            '/security-devices/devices/42?section=management',
        );
        expect(
            screen.queryByRole('button', { name: 'Retry' }),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Server' }));

        expect(inertiaMocks.router.post).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink/devices/2/configuration/server/read',
            { command: 'SRI' },
            { preserveScroll: true },
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Review Watchdog auto-reboot',
            }),
        );

        expect(inertiaMocks.router.post).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink/devices/2/configuration/server',
            expect.objectContaining({
                command: 'dog',
            }),
            { preserveScroll: true },
        );

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(inertiaMocks.router.post).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink/commands/44/cancel',
            {},
            { preserveScroll: true },
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Resident safety profile',
            }),
        );

        expect(inertiaMocks.router.post).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink/devices/2/configuration/resident-safety-profile',
            {},
            { preserveScroll: true },
        );
    });
});

describe('QueclinkHub governed command handoff', () => {
    it('offers governed location and restart handoffs without raw commands', () => {
        render(
            <GovernedCommandHandoff
                can={{ manage: true }}
                devices={[
                    {
                        id: 2,
                        reference: 'Tracker ending 6998',
                        status: 'paired',
                        model_hint: 'GL30MEU',
                        protocol_version: '970204',
                        firmware_version: null,
                        connection_state: 'connected',
                        first_seen_at: null,
                        last_seen_at: '2026-05-18T02:07:01Z',
                        last_frame_at: '2026-05-18T02:07:01Z',
                        assignment: {
                            type: 'client',
                            assigned_at: '2026-05-18T02:00:00Z',
                            label: 'Amelia Wilson',
                        },
                    },
                ]}
            />,
        );

        expect(screen.getByText('Governed device actions')).toBeVisible();
        expect(
            screen
                .getByText('Governed device actions')
                .closest('[data-slot="card"]'),
        ).toHaveClass('order-first', '2xl:order-last');
        expect(screen.getByText(/Raw and configuration-change/)).toBeVisible();
        expect(screen.queryByText(/Raw AT/i)).not.toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Review governed location refresh',
            }),
        );

        expect(inertiaMocks.formPost).toHaveBeenCalledWith(
            '/security-devices/integrations/queclink/devices/2/command',
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Review governed restart',
            }),
        );
        expect(inertiaMocks.formPost).toHaveBeenCalledTimes(2);
        expect(inertiaMocks.formPost).toHaveBeenLastCalledWith(
            '/security-devices/integrations/queclink/devices/2/command',
        );
    });
});
