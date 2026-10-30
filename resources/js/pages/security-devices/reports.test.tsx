import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Reports from './reports';
import { reportFixture } from './reports-test-fixtures';
const mocks = vi.hoisted(() => ({
    actor: 7,
    can: {} as Record<string, Record<string, boolean>>,
    reload: vi.fn(),
    download: vi.fn(),
    visit: vi.fn(),
}));
vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({
            props: { auth: { user: { id: mocks.actor }, can: mocks.can } },
        }),
        router: {
            ...actual.router,
            on: actual.router.on.bind(actual.router),
            reload: mocks.reload,
            visit: mocks.visit,
        },
    };
});
vi.mock('@/lib/security-devices-reports', async () => ({
    ...(await vi.importActual<typeof import('@/lib/security-devices-reports')>(
        '@/lib/security-devices-reports',
    )),
    downloadReport: mocks.download,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));
beforeEach(() => {
    mocks.actor = 7;
    mocks.can = { securityDevices: { reportsView: true } };
    mocks.reload.mockReset();
    mocks.download.mockReset();
    mocks.visit.mockReset();
});
afterEach(cleanup);
const card = (name: string) =>
    screen.getByRole('article', { name: `Report: ${name}` });
async function open(name: string) {
    fireEvent.click(
        within(card(name)).getByRole('button', {
            name: new RegExp('^' + name),
        }),
    );
    return screen.findByRole('dialog', { name: `Evidence for ${name}` });
}
function invalid(status: number, path = '/security-devices/reports') {
    const event = new CustomEvent('inertia:invalid', {
        cancelable: true,
        detail: {
            response: {
                status,
                config: { method: 'get', url: path },
                data: { private_server_message: 'Secret SQL message' },
            },
        },
    });
    act(() => document.dispatchEvent(event));
    return event;
}
type Reload = {
    onFinish: () => void;
    onSuccess: (page: {
        component: string;
        props: {
            actor_id: number;
            auth: {
                user: { id: number };
                can: Record<string, Record<string, boolean>>;
            };
        };
    }) => void;
};
describe('Security and Devices report catalogue', () => {
    it('retains exact three canonical native downloads and complete inventory details', async () => {
        render(<Reports {...reportFixture()} />);
        for (const [name, path] of [
            ['device inventory', 'devices'],
            ['device events', 'events'],
            ['maintenance log', 'maintenance'],
        ])
            expect(
                screen.getByRole('link', { name: `Download ${name} CSV` }),
            ).toHaveAttribute('href', `/security-devices/reports/${path}.csv`);
        const dialog = await open('Device inventory');
        expect(within(dialog).getByText('2,010')).toBeInTheDocument();
        expect(within(dialog).getByText(/13 October 2026/)).toBeInTheDocument();
        expect(
            within(dialog).getByText(
                /Record ID, Device ID, Name, Domain, Category, Subcategory, Manufacturer, Model, Serial number, MAC address, IMEI, Asset tag, Firmware version, Status, Health, Provider, Battery level, Last seen, Last signal, Next service due, Created/,
            ),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText(
                /including devices with any recorded status/,
            ),
        ).toBeInTheDocument();
        expect(
            within(dialog).queryByText(
                /403|cursor|securityDevices.reports.view|dedicated Reporting module/,
            ),
        ).not.toBeInTheDocument();
    });
    it('keeps event window/occurrence scope and all maintenance export facts', async () => {
        render(<Reports {...reportFixture()} />);
        let dialog = await open('Device events');
        expect(
            within(dialog).getByText('Past 90 days through the request time'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText(
                /where the device was assigned when it happened/,
            ),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText(
                'Event ID, Device record ID, Device name, Event type, Severity, Source, Occurred, Processed',
            ),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText(
                'Included only for devices in your current permitted scope.',
            ),
        ).toBeInTheDocument();
        fireEvent.click(
            within(dialog).getAllByRole('button', { name: 'Close' })[0],
        );
        dialog = await open('Maintenance log');
        expect(
            within(dialog).getByText(
                'Work ID, Device record ID, Device name, Work type, Status, Description, Scheduled, Completed, Recorded by, Work reference, Recorded cost',
            ),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText(
                'Recorded maintenance cost; use Finance for accounts.',
            ),
        ).toBeInTheDocument();
    });
    it('searches human field names and separates a matching catalogue from empty search', () => {
        render(<Reports {...reportFixture()} />);
        const search = screen.getByRole('searchbox', {
            name: 'Search reports',
        });
        fireEvent.change(search, { target: { value: 'IMEI' } });
        expect(screen.getAllByRole('article')).toHaveLength(1);
        expect(card('Device inventory')).toBeInTheDocument();
        fireEvent.change(search, { target: { value: 'No such report' } });
        expect(screen.getByText('No matching reports')).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Clear report filters' }),
        );
        expect(screen.getAllByRole('article')).toHaveLength(3);
    });
    it('selects the corresponding summary/rail view and clears old search filters', () => {
        render(<Reports {...reportFixture()} />);
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Search reports' }),
            { target: { value: 'IMEI' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Open device events report' }),
        );
        expect(
            screen.getByRole('searchbox', { name: 'Search reports' }),
        ).toHaveValue('');
        expect(screen.getAllByRole('article')).toHaveLength(1);
        expect(card('Device events')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('tab', { name: 'Maintenance log' }));
        expect(card('Maintenance log')).toBeInTheDocument();
    });
    it('keeps table/context download parity and complete details with focus return', async () => {
        render(<Reports {...reportFixture()} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        const row = screen.getByRole('row', { name: /Device events/ });
        fireEvent.contextMenu(row, { clientX: 90, clientY: 100 });
        fireEvent.click(screen.getByRole('menuitem', { name: 'Download CSV' }));
        expect(mocks.download).toHaveBeenCalledWith('events');
        expect(mocks.visit).not.toHaveBeenCalled();
        fireEvent.contextMenu(row, { clientX: 90, clientY: 100 });
        fireEvent.click(
            screen.getByRole('menuitem', { name: 'View evidence' }),
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'Evidence for Device events',
        });
        fireEvent.click(
            within(dialog).getAllByRole('button', { name: 'Close' })[0],
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(
            within(row).getByRole('button', {
                name: 'View evidence for Device events',
            }),
        ).toHaveFocus();
    });
    it('native download links avoid triggering the row details action', () => {
        render(<Reports {...reportFixture()} />);
        const link = screen.getByRole('link', {
            name: 'Download device inventory CSV',
        });
        link.addEventListener('click', (event) => event.preventDefault(), {
            once: true,
        });
        fireEvent.click(link);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('clears private counts, open facts and search on account replacement', async () => {
        const data = reportFixture(),
            ui = render(<Reports {...data} />);
        await open('Device inventory');
        mocks.actor = 8;
        ui.rerender(<Reports {...data} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByRole('article')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /Download/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('searchbox', { name: 'Search reports' }),
        ).toHaveValue('');
        expect(screen.getByText(/Reports are restricted/)).toBeInTheDocument();
    });
    it('requires current report view permission independently of other manager permissions', () => {
        mocks.can = { securityDevices: { integrationsManage: true } };
        render(<Reports {...reportFixture()} />);
        expect(screen.queryByRole('article')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /Download/ }),
        ).not.toBeInTheDocument();
        expect(screen.getByText(/Reports are restricted/)).toBeInTheDocument();
    });
    it('allows a zero-row CSV while unavailable counts remain visibly blocked', async () => {
        const data = reportFixture();
        data.stats.devices = 0;
        data.stats.maintenance = Number.NaN;
        render(<Reports {...data} />);
        expect(
            screen.getByRole('link', { name: 'Download device inventory CSV' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('link', {
                name: 'Download maintenance log CSV',
            }),
        ).not.toBeInTheDocument();
        fireEvent.keyDown(
            within(card('Maintenance log')).getByRole('button', {
                name: 'Actions for Maintenance log',
            }),
            { key: 'Enter', code: 'Enter' },
        );
        const item = await screen.findByRole('menuitem', {
            name: 'Download CSV',
        });
        expect(item).toHaveAttribute('aria-disabled', 'true');
        fireEvent.click(item);
        expect(mocks.download).not.toHaveBeenCalled();
    });
    it('does not export or display a plausible count under an unavailable event period', async () => {
        const data = reportFixture();
        data.windowDays = 0;
        render(<Reports {...data} />);
        expect(
            screen.queryByRole('link', { name: 'Download device events CSV' }),
        ).not.toBeInTheDocument();
        const dialog = await open('Device events');
        expect(within(dialog).queryByText('230')).not.toBeInTheDocument();
        expect(
            within(dialog).getAllByText('Not available').length,
        ).toBeGreaterThan(0);
    });
    it('quarantines old private catalogue/search/downloads after an owned refresh denial', () => {
        render(<Reports {...reportFixture()} />);
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Search reports' }),
            { target: { value: 'IMEI' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(invalid(403).defaultPrevented).toBe(true);
        expect(screen.queryByRole('article')).not.toBeInTheDocument();
        expect(
            screen.getByRole('searchbox', { name: 'Search reports' }),
        ).toHaveValue('');
        expect(
            screen.queryByRole('link', { name: /Download/ }),
        ).not.toBeInTheDocument();
        expect(screen.getByText(/Reports are restricted/)).toBeInTheDocument();
        expect(
            screen.queryByText('Secret SQL message'),
        ).not.toBeInTheDocument();
    });
    it('does not intercept another route and labels failed refresh counts as previous', () => {
        render(<Reports {...reportFixture()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(invalid(403, '/security-devices/devices').defaultPrevented).toBe(
            false,
        );
        expect(screen.getAllByRole('article')).toHaveLength(3);
        expect(invalid(503).defaultPrevented).toBe(true);
        expect(
            screen.getByText(/previous counts are still shown/),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Secret SQL message'),
        ).not.toBeInTheDocument();
    });
    it('requires a complete page remount to recover a denied catalogue for the current owner', () => {
        const data = reportFixture();
        const { unmount } = render(<Reports {...data} />);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        const partial = mocks.reload.mock.calls[0][0] as Reload;
        act(() =>
            partial.onSuccess({
                component: 'security-devices/reports',
                props: {
                    actor_id: 8,
                    auth: {
                        user: { id: 8 },
                        can: { securityDevices: { reportsView: true } },
                    },
                },
            }),
        );
        expect(screen.queryByRole('article')).not.toBeInTheDocument();
        act(() => partial.onFinish());
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(mocks.visit).toHaveBeenCalledWith(
            '/security-devices/reports',
            expect.objectContaining({
                method: 'get',
                preserveState: false,
                preserveScroll: true,
            }),
        );
        expect(mocks.visit.mock.calls[0][1]).not.toHaveProperty('only');
        expect(mocks.reload).toHaveBeenCalledTimes(1);
        expect(screen.queryByRole('article')).not.toBeInTheDocument();
        unmount();
        mocks.actor = 8;
        render(
            <Reports
                {...data}
                actor_id={8}
                stats={{ ...data.stats, devices: 99 }}
            />,
        );
        expect(screen.getAllByRole('article')).toHaveLength(3);
        expect(screen.queryAllByText('2010')).toHaveLength(0);
        expect(screen.getAllByText('99').length).toBeGreaterThan(0);
    });
});

describe('reports received DTO ownership', () => {
    it('quarantines retained private DTOs after paired actor metadata changes', async () => {
        const original = reportFixture();
        const { rerender } = render(<Reports {...original} />);
        await open('Device inventory');
        mocks.actor = 8;
        // Same-page partial update: actor metadata changes, all private DTOs remain from actor 7.
        rerender(
            <Reports {...original} actor_id={8} as_of="2026-10-13T02:00:00Z" />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByRole('article')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /Download/ }),
        ).not.toBeInTheDocument();
        expect(screen.queryAllByText('2010')).toHaveLength(0);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(mocks.visit).toHaveBeenCalledWith(
            '/security-devices/reports',
            expect.objectContaining({
                method: 'get',
                preserveState: false,
                preserveScroll: true,
            }),
        );
        expect(mocks.visit.mock.calls.at(-1)![1]).not.toHaveProperty('only');
        expect(mocks.reload).not.toHaveBeenCalled();
    });
});

describe('reports same-account reads', () => {
    it('keeps the existing same-account refresh projection and owner', () => {
        const original = reportFixture();
        const { rerender } = render(<Reports {...original} />);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(mocks.reload).toHaveBeenCalledTimes(1);
        expect(mocks.reload.mock.calls[0][0].only).toEqual(
            expect.arrayContaining(['actor_id', 'as_of', 'auth']),
        );
        expect(mocks.visit).not.toHaveBeenCalled();
        rerender(<Reports {...original} as_of="2026-10-13T02:00:00Z" />);
        expect(screen.getAllByRole('article').length).toBeGreaterThan(0);
    });
});
