import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AlertsEvents, {
    type EventItem,
    type EventsProps,
} from './alerts-events';
const mocks = vi.hoisted(() => ({ get: vi.fn(), visit: vi.fn(), actor: 7 }));
vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({
            props: {
                auth: {
                    user: { id: mocks.actor },
                    can: { 'securityDevices.events.view': true },
                },
            },
        }),
        router: { ...actual.router, get: mocks.get, visit: mocks.visit },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));
const eventBase: EventItem = {
    id: 11,
    device_id: 41,
    device_name: 'Lobby camera',
    device_uid: 'CAM-0041',
    device_domain: 'security',
    device_href: '/security-devices/devices/41',
    device_access: {
        state: 'available',
        metadata_state: 'available',
        label: 'Open device profile',
    },
    event_type: 'alarm_trigger',
    severity: 'critical',
    source: 'Site collector',
    occurred_at: '2026-10-12T12:00:00Z',
    processed_at: null,
};
export function eventsFixture(): EventsProps {
    return {
        stats: {
            total24h: 124,
            critical24h: 7,
            warning24h: 9,
            unprocessed: 36,
        },
        snapshotAt: '2026-10-12T13:00:00Z',
        windowStart: '2026-10-11T13:00:00Z',
        controlRoom: { href: '/control-room/alerts' },
        events: {
            data: [
                structuredClone(eventBase),
                {
                    ...structuredClone(eventBase),
                    id: 12,
                    event_type: 'heartbeat',
                    severity: 'info',
                    occurred_at: null,
                    processed_at: '2026-10-12T12:10:00Z',
                    source: null,
                },
                {
                    ...structuredClone(eventBase),
                    id: 13,
                    event_type: 'heartbeat',
                    severity: 'warning',
                    device_id: 42,
                    device_name: 'Room sensor',
                    device_uid: 'SEN-0042',
                    device_href: null,
                    device_access: {
                        state: 'restricted',
                        metadata_state: 'available',
                        label: 'Device profile permission required',
                    },
                },
            ],
            links: [
                { url: null, label: '&laquo; Previous', active: false },
                {
                    url: '/security-devices/alerts-events?page=1',
                    label: '1',
                    active: true,
                },
                {
                    url: '/security-devices/alerts-events?page=2',
                    label: '2',
                    active: false,
                },
                {
                    url: '/security-devices/alerts-events?page=2',
                    label: 'Next &raquo;',
                    active: false,
                },
            ],
            meta: { current_page: 1, last_page: 2, total: 96 },
        },
        filters: {},
        filterOptions: {
            eventTypes: ['alarm_trigger', 'heartbeat'],
            sources: ['Site collector'],
            domains: [{ value: 'security', label: 'Security' }],
        },
    };
}
afterEach(cleanup);
beforeEach(() => {
    mocks.get.mockReset();
    mocks.visit.mockReset();
    mocks.actor = 7;
});
describe('Read-only permitted device event stream', () => {
    it('selects all or filtered history until the server filters exactly match a counted summary', () => {
        const props = eventsFixture();
        const { rerender } = render(<AlertsEvents {...props} />);
        expect(
            screen.getByRole('tab', { name: 'All history' }),
        ).toHaveAttribute('aria-selected', 'true');
        expect(
            screen.getByRole('tab', { name: /^Last 24 hours\s*124$/ }),
        ).toHaveAttribute('aria-selected', 'false');
        rerender(
            <AlertsEvents
                {...props}
                filters={{
                    window: '24h',
                    severity: 'critical',
                    search: 'camera',
                }}
            />,
        );
        expect(
            screen.getByRole('tab', { name: 'Filtered history' }),
        ).toHaveAttribute('aria-selected', 'true');
        expect(
            screen.getByRole('tab', { name: /^Critical\s*7$/ }),
        ).toHaveAttribute('aria-selected', 'false');
        rerender(
            <AlertsEvents
                {...props}
                filters={{ window: '24h', severity: 'critical' }}
            />,
        );
        expect(
            screen.getByRole('tab', { name: /^Critical\s*7$/ }),
        ).toHaveAttribute('aria-selected', 'true');
        fireEvent.click(screen.getByRole('tab', { name: 'Filtered history' }));
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/security-devices/alerts-events',
            { page: '1' },
            { preserveState: true },
        );
    });

    it('keeps owner summary counts separate from loaded results and opens the counted rolling window without unrelated filters', () => {
        const props = eventsFixture();
        props.filters = {
            search: 'unrelated',
            device_id: '99',
            from: '2026-10-01',
            source: 'other',
        };
        render(<AlertsEvents {...props} />);
        expect(
            screen.getByRole('button', { name: 'View last 24 hours events' }),
        ).toHaveTextContent('124');
        expect(
            screen.getByText(/96 matching events across all pages/),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'View critical events' }),
        );
        expect(mocks.get).toHaveBeenCalledWith(
            '/security-devices/alerts-events',
            { window: '24h', severity: 'critical', page: '1' },
            { preserveState: true },
        );
        expect(screen.getByRole('searchbox')).toHaveValue('');
        fireEvent.click(
            screen.getByRole('button', { name: 'View not processed events' }),
        );
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/security-devices/alerts-events',
            { processed: 'no', page: '1' },
            { preserveState: true },
        );
    });
    it('preserves submitted filters for search and uses the owner pagination URL', () => {
        const props = eventsFixture();
        props.filters = { severity: 'warning', source: 'Site collector' };
        render(<AlertsEvents {...props} />);
        const input = screen.getByRole('searchbox');
        fireEvent.change(input, { target: { value: 'heartbeat' } });
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(mocks.get).toHaveBeenCalledWith(
            '/security-devices/alerts-events',
            {
                severity: 'warning',
                source: 'Site collector',
                search: 'heartbeat',
                page: '1',
            },
            { preserveState: true },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/security-devices/alerts-events?page=2',
            {},
            { preserveState: true },
        );
    });
    it('retains all event facts in a small related-details viewer while the table keeps concise scan cells', async () => {
        render(<AlertsEvents {...eventsFixture()} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        const table = screen.getByRole('table');
        expect(within(table).getAllByRole('row')).toHaveLength(4);
        expect(within(table).queryByRole('link')).not.toBeInTheDocument();
        const opener = screen.getByRole('button', {
            name: 'View evidence for Alarm trigger',
        });
        fireEvent.click(opener);
        const detail = screen.getByRole('dialog');
        expect(within(detail).getByText('#11')).toBeInTheDocument();
        expect(
            within(detail).getAllByText('Alarm trigger').length,
        ).toBeGreaterThan(0);
        expect(
            within(detail).queryByText('alarm_trigger'),
        ).not.toBeInTheDocument();
        expect(within(detail).getByText('CAM-0041')).toBeInTheDocument();
        expect(
            within(detail).getByText('No processing time recorded'),
        ).toBeInTheDocument();
        expect(
            within(detail).getByRole('link', { name: 'Open device profile' }),
        ).toHaveAttribute('href', '/security-devices/devices/41');
        fireEvent.keyDown(detail, { key: 'Escape' });
        await waitFor(() => expect(opener).toHaveFocus());
    });
    it('fails closed on injected current identity from a restricted metadata scope in both layouts and details', () => {
        const props = eventsFixture();
        props.events.data = [
            {
                ...eventBase,
                device_name: 'PRIVATE-CURRENT-NAME',
                device_uid: 'PRIVATE-CURRENT-UID',
                device_domain: 'PRIVATE-DOMAIN',
                device_access: {
                    state: 'available',
                    metadata_state: 'restricted',
                    label: 'Current device details restricted',
                },
            },
        ];
        render(<AlertsEvents {...props} />);
        expect(screen.queryByText(/PRIVATE-/)).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        expect(screen.queryByText(/PRIVATE-/)).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View evidence for Alarm trigger',
            }),
        );
        expect(screen.queryByText(/PRIVATE-/)).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Open device profile' }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('dialog')).toHaveTextContent(
            'Current device details restricted',
        );
    });
    it('keeps permitted event context separate from device profile permission and Control Room permission', () => {
        const props = eventsFixture();
        props.controlRoom = { href: null };
        props.events.data = [props.events.data[2]];
        render(<AlertsEvents {...props} />);
        expect(screen.getAllByText(/Room sensor/).length).toBeGreaterThan(0);
        expect(
            screen.queryByRole('link', { name: 'Open Control Room' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Control Room access required'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'View evidence for Heartbeat' }),
        );
        expect(
            screen.queryByRole('link', { name: 'Open device profile' }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('dialog')).toHaveTextContent(
            'Device profile permission required',
        );
    });
    it('does not turn a wrong device or owner destination into a native link', () => {
        const props = eventsFixture();
        props.controlRoom = { href: 'https://outside.example.test' };
        props.events.data = [
            { ...eventBase, device_href: '/security-devices/devices/999' },
        ];
        render(<AlertsEvents {...props} />);
        expect(
            screen.queryByRole('link', { name: 'Open Control Room' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View evidence for Alarm trigger',
            }),
        );
        expect(
            screen.queryByRole('link', { name: 'Open device profile' }),
        ).not.toBeInTheDocument();
    });
    it('uses the same device destination in keyboard and context menus and retains independent duplicate event identities', async () => {
        render(<AlertsEvents {...eventsFixture()} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        fireEvent.keyDown(
            screen.getAllByRole('button', { name: 'Actions for Heartbeat' })[0],
            { key: 'ArrowDown' },
        );
        fireEvent.click(
            await screen.findByRole('menuitem', {
                name: 'Open device profile',
            }),
        );
        expect(mocks.visit).toHaveBeenCalledWith(
            '/security-devices/devices/41',
        );
        const row = screen
            .getByText('Event #12 · Lobby camera')
            .closest('[role="row"]')!;
        fireEvent.contextMenu(row, { clientX: 60, clientY: 70 });
        fireEvent.click(
            await screen.findByRole('menuitem', {
                name: 'Open device profile',
            }),
        );
        expect(mocks.visit).toHaveBeenCalledTimes(2);
        fireEvent.click(
            screen.getAllByRole('button', {
                name: 'View evidence for Heartbeat',
            })[1],
        );
        expect(screen.getByRole('dialog')).toHaveTextContent('#13');
    });
    it('clears private evidence and unsent search when actor or incoming record scope changes', () => {
        const props = eventsFixture(),
            view = render(<AlertsEvents {...props} />);
        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'private draft' },
        });
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View evidence for Alarm trigger',
            }),
        );
        mocks.actor = 8;
        const next = eventsFixture();
        next.events.data = [];
        view.rerender(<AlertsEvents {...next} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByRole('searchbox')).toHaveValue('');
        expect(
            screen.getByText('No permitted device events'),
        ).toBeInTheDocument();
    });
    it('picks New Zealand calendar days in the shared range calendar and ends a rolling window when the dates apply', () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-10-12T00:00:00Z'));
        try {
            const props = eventsFixture();
            props.filters = { window: '24h' };
            const view = render(<AlertsEvents {...props} />);
            fireEvent.click(screen.getByRole('button', { name: 'Date range' }));
            const applyDates = screen.getByRole('button', {
                name: 'Apply dates',
            });
            expect(applyDates).toBeDisabled();
            fireEvent.click(
                screen.getByRole('button', { name: 'Sat 10 October 2026' }),
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Mon 12 October 2026' }),
            );
            expect(
                screen.getByText('10 Oct 2026 – 12 Oct 2026'),
            ).toBeInTheDocument();
            fireEvent.click(applyDates);
            expect(mocks.get).toHaveBeenLastCalledWith(
                '/security-devices/alerts-events',
                {
                    window: '',
                    from: '2026-10-10',
                    to: '2026-10-12',
                    page: '1',
                },
                { preserveState: true },
            );
            const next = eventsFixture();
            next.filters = {
                from: '2026-10-10',
                to: '2026-10-12',
                window: '',
            };
            view.rerender(<AlertsEvents {...next} />);
            expect(
                screen.getByRole('button', {
                    name: '10 Oct 2026 – 12 Oct 2026',
                }),
            ).toBeInTheDocument();
            expect(screen.queryByText(/UTC/)).not.toBeInTheDocument();
        } finally {
            vi.useRealTimers();
        }
    });
    it('preserves the legacy page metadata variant and avoids inventing processing or occurrence times', () => {
        const props = eventsFixture();
        props.pageMeta = {
            title: 'Monitoring',
            description: 'Legacy event-only composition',
            href: '/security-devices/monitoring',
        };
        props.events.data = [props.events.data[1]];
        render(<AlertsEvents {...props} />);
        expect(
            screen.getByRole('heading', { name: 'Monitoring' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Time not recorded')).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'View warning events' }),
        );
        expect(mocks.get.mock.calls[0][0]).toBe('/security-devices/monitoring');
    });
});
