import { Button } from '@/components/ui/button';
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
import Dashboard from './dashboard';
import { estateFixture } from './estate-test-fixtures';
const mocks = vi.hoisted(() => ({
    actor: 7,
    can: {} as Record<string, Record<string, boolean>>,
    visit: vi.fn(),
    reload: vi.fn(),
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
            visit: mocks.visit,
            reload: mocks.reload,
        },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));
vi.mock('@/components/security-devices/add-device-dialog', async () => {
    const React = await vi.importActual<typeof import('react')>('react');
    return {
        useAddDeviceDialogState: () => {
            const [open, setOpen] = React.useState(false);
            return {
                open,
                openDialog: () => setOpen(true),
                closeDialog: () => setOpen(false),
            };
        },
        AddDeviceDialog: ({
            open,
            onClose,
        }: {
            open: boolean;
            onClose: () => void;
        }) =>
            open ? (
                <div role="dialog" aria-label="Existing device registration">
                    <Button onClick={onClose}>Close registration</Button>
                </div>
            ) : null,
    };
});
beforeEach(() => {
    mocks.actor = 7;
    mocks.can = {
        securityDevices: {
            viewAny: true,
            devicesView: true,
            eventsView: true,
            maintenanceView: true,
            devicesCreate: true,
        },
    };
    mocks.visit.mockReset();
    mocks.reload.mockReset();
});
afterEach(cleanup);
const card = (name: string) =>
    screen.getByRole('article', { name: `Estate record: ${name}` });
async function details(name: string) {
    fireEvent.keyDown(
        within(card(name)).getByRole('button', { name: `Actions for ${name}` }),
        { key: 'Enter', code: 'Enter' },
    );
    fireEvent.click(
        await screen.findByRole('menuitem', { name: 'View evidence' }),
    );
    return screen.findByRole('dialog');
}
function invalid(status: number, path = '/security-devices') {
    const event = new CustomEvent('inertia:invalid', {
        cancelable: true,
        detail: {
            response: {
                status,
                config: { method: 'get', url: path },
                data: { private: 'Private backend details' },
            },
        },
    });
    act(() => document.dispatchEvent(event));
    return event;
}
describe('Estate shared records and authority', () => {
    it('withholds retained estate records after current nested view authority is withdrawn', () => {
        const data = estateFixture();
        const ui = render(<Dashboard {...data} />);
        expect(screen.getAllByRole('article').length).toBeGreaterThan(0);
        mocks.can.securityDevices.viewAny = false;
        ui.rerender(<Dashboard {...data} />);
        expect(screen.queryByRole('article')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Register device' }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent(
            'Estate records are restricted',
        );
    });

    it('preserves work queue destinations, restricted IT and neutral zero-item states', async () => {
        const data = estateFixture();
        data.operations.action_queue[0].count = 0;
        render(<Dashboard {...data} />);
        expect(
            within(card('Failed monitors')).getByText('No items'),
        ).toBeInTheDocument();
        expect(
            within(card('Failed monitors')).queryByText('Healthy'),
        ).not.toBeInTheDocument();
        expect(
            within(card('Open IT work')).getAllByText(
                /Restricted|IT work access/,
            ).length,
        ).toBeGreaterThan(0);
        fireEvent.keyDown(
            within(card('Failed monitors')).getByRole('button', {
                name: 'Actions for Failed monitors',
            }),
            { key: 'Enter', code: 'Enter' },
        );
        fireEvent.click(
            await screen.findByRole('menuitem', { name: 'Open queue' }),
        );
        expect(mocks.visit).toHaveBeenCalledWith(
            '/security-devices/monitoring?state=failed',
        );
    });
    it('retains all original totals, configured coverage and permission-owned counters through the full summary sections', async () => {
        render(<Dashboard {...estateFixture()} />);
        const dialog = await details('Estate summary');
        expect(within(dialog).getByText('All devices')).toBeInTheDocument();
        expect(
            within(dialog).getByText('Service due within 30 days'),
        ).toBeInTheDocument();
        expect(within(dialog).getByText('Device groups')).toBeInTheDocument();
        fireEvent.click(
            within(dialog).getByRole('button', {
                name: /MonitoringRetained evidence/,
            }),
        );
        expect(
            within(dialog).getByText('With enabled monitoring'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText('Failed or degraded checks'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText('Critical findings in 24 hours'),
        ).toBeInTheDocument();
        fireEvent.click(
            within(dialog).getByRole('button', {
                name: /Sites and workRetained evidence/,
            }),
        );
        expect(within(dialog).getByText('Open IT work')).toBeInTheDocument();
        expect(
            within(dialog).getAllByText('Restricted').length,
        ).toBeGreaterThan(0);
        expect(
            within(dialog).getByText('Overdue work in operational summary'),
        ).toBeInTheDocument();
    });
    it('retains all Site/collector/nullable counters and canonical Site navigation', async () => {
        render(<Dashboard {...estateFixture()} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Affected Sites' }));
        const dialog = await details('Kauri House');
        for (const label of [
            'Site ID',
            'Location',
            'Type',
            'Active',
            'Recorded health',
            'Requires action',
            'Needing attention',
            'Offline',
            'With monitoring',
            'Without monitoring',
            'Coverage',
            'Failed checks',
            'Uncertain checks',
            'Findings',
            'Control Room alerts',
            'Open IT work',
            'Overdue work',
            'Collector',
            'Collector state',
            'Collectors',
            'Collector last contact',
            'Last change',
        ])
            expect(within(dialog).getByText(label)).toBeInTheDocument();
        expect(
            within(dialog).getByText('Supported living'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getAllByText('Restricted').length,
        ).toBeGreaterThan(0);
    });
    it('retains attention identity/domain/category/battery/dates and domain/health distributions', async () => {
        render(<Dashboard {...estateFixture()} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Devices & domains' }));
        // Domains use the sidebar's workspace names.
        expect(card('Healthcare')).toBeInTheDocument();
        expect(card('Network & IT')).toBeInTheDocument();
        expect(card('Recorded health: Unknown')).toBeInTheDocument();
        const dialog = await details('Synthetic pump');
        for (const value of [
            'DEV-2',
            'Healthcare',
            'Medical device',
            'Offline',
            'Critical',
            'Not recorded',
        ])
            expect(within(dialog).getAllByText(value).length).toBeGreaterThan(
                0,
            );
        expect(within(dialog).getByText(/22 July 2026/)).toBeInTheDocument();
        expect(
            within(dialog).getByRole('link', { name: 'Synthetic pump' }),
        ).toHaveAttribute('href', '/security-devices/devices/2');
    });
    it('retains the event source and identifiers in the permitted 24-hour findings view', async () => {
        render(<Dashboard {...estateFixture()} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Recent findings' }));
        const dialog = await details('Alarm trigger');
        for (const value of ['8', '2', 'Synthetic source', 'Critical'])
            expect(within(dialog).getAllByText(value).length).toBeGreaterThan(
                0,
            );
        expect(within(dialog).getByText(/13 October 2026/)).toBeInTheDocument();
    });
    it('keeps calendar work dates/work references and every supplied recent change', async () => {
        const data = estateFixture();
        for (let i = 0; i < 8; i++)
            data.operations.recent_changes.push({
                ...data.operations.recent_changes[0],
                key: 'extra-' + i,
                summary: 'Stored change ' + i,
            });
        render(<Dashboard {...data} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Overdue work' }));
        const dialog = await details('Overdue calibration');
        expect(within(dialog).getByText('1 Oct 2026')).toBeInTheDocument();
        expect(within(dialog).getByText('WO-42')).toBeInTheDocument();
        fireEvent.click(
            within(dialog).getAllByRole('button', { name: 'Close' })[0],
        );
        fireEvent.click(screen.getByRole('tab', { name: 'Recent changes' }));
        expect(screen.getAllByRole('article')).toHaveLength(10);
    });
    it('provides compact table/full details via context menu and returns focus', async () => {
        render(<Dashboard {...estateFixture()} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Recent findings' }));
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        const row = screen.getByRole('row', { name: /Alarm trigger/ });
        fireEvent.contextMenu(row, { clientX: 90, clientY: 100 });
        fireEvent.click(
            screen.getByRole('menuitem', { name: 'View evidence' }),
        );
        const dialog = await screen.findByRole('dialog');
        fireEvent.click(
            within(dialog).getAllByRole('button', { name: 'Close' })[0],
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(
            within(row).getByRole('button', {
                name: 'View evidence for Alarm trigger',
            }),
        ).toHaveFocus();
    });
    it('searches the current view, resets search on view selection and separates empty results', () => {
        render(<Dashboard {...estateFixture()} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Devices & domains' }));
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Search estate records' }),
            { target: { value: 'No such record' } },
        );
        expect(screen.getAllByText(/No matching/).length).toBeGreaterThan(0);
        fireEvent.click(screen.getByRole('tab', { name: 'Recent findings' }));
        expect(
            screen.getByRole('searchbox', { name: 'Search estate records' }),
        ).toHaveValue('');
        expect(card('Alarm trigger')).toBeInTheDocument();
    });
    it('keeps empty coverage not measured while missing permissions are restricted', () => {
        const data = estateFixture();
        data.operations.coverage = {
            total_devices: 0,
            monitored_devices: 0,
            unmonitored_devices: 0,
            percent: null,
        };
        render(<Dashboard {...data} />);
        expect(screen.getAllByText(/Not measured/).length).toBeGreaterThan(0);
        cleanup();
        mocks.can.securityDevices.eventsView = false;
        render(<Dashboard {...data} />);
        expect(
            screen.getByText('Monitoring access required'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('tab', { name: 'Recent findings' }));
        expect(screen.getByRole('status')).toHaveTextContent(
            'Event and device access',
        );
        expect(screen.queryByRole('article')).not.toBeInTheDocument();
    });
    it('uses current create permission and preserves the existing non-navigating registration entry', () => {
        const data = estateFixture(),
            ui = render(<Dashboard {...data} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Register device' }),
        );
        expect(
            screen.getByRole('dialog', {
                name: 'Existing device registration',
            }),
        ).toBeInTheDocument();
        expect(mocks.visit).not.toHaveBeenCalled();
        mocks.can.securityDevices.devicesCreate = false;
        ui.rerender(<Dashboard {...data} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Register device' }),
        ).not.toBeInTheDocument();
    });
    it('clears full private record context on actor replacement', async () => {
        const data = estateFixture(),
            ui = render(<Dashboard {...data} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Devices & domains' }));
        await details('Synthetic pump');
        mocks.actor = 8;
        ui.rerender(<Dashboard {...data} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByRole('article')).not.toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent(
            'Estate records are restricted',
        );
    });
    it('quarantines all private records and registration after the owned refresh is denied', () => {
        render(<Dashboard {...estateFixture()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(invalid(403).defaultPrevented).toBe(true);
        expect(screen.queryByRole('article')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Register device' }),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent(
            'Estate records are restricted',
        );
        expect(
            screen.queryByText('Private backend details'),
        ).not.toBeInTheDocument();
    });
    it('isolates another workflow error and labels a failed estate read honestly', () => {
        render(<Dashboard {...estateFixture()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(invalid(403, '/it').defaultPrevented).toBe(false);
        expect(invalid(503).defaultPrevented).toBe(true);
        expect(screen.getByRole('status')).toHaveTextContent(
            'previous records are still shown',
        );
        expect(
            screen.queryByText('Private backend details'),
        ).not.toBeInTheDocument();
    });
});

describe('dashboard received DTO ownership', () => {
    it('quarantines retained private DTOs after paired actor metadata changes', async () => {
        const original = estateFixture();
        const { rerender } = render(<Dashboard {...original} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Affected Sites' }));
        await details('Kauri House');
        mocks.actor = 8;
        // Same-page partial update: actor metadata changes, all private DTOs remain from actor 7.
        rerender(
            <Dashboard
                {...original}
                actor_id={8}
                as_of="2026-10-13T02:00:00Z"
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByRole('article')).not.toBeInTheDocument();
        expect(screen.queryByText('Synthetic pump')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Add device' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(mocks.visit).toHaveBeenCalledWith(
            '/security-devices',
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

describe('dashboard same-account reads', () => {
    it('keeps the existing same-account refresh projection and owner', () => {
        const original = estateFixture();
        const { rerender } = render(<Dashboard {...original} />);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(mocks.reload).toHaveBeenCalledTimes(1);
        expect(mocks.reload.mock.calls[0][0].only).toEqual(
            expect.arrayContaining(['actor_id', 'as_of', 'auth']),
        );
        expect(mocks.visit).not.toHaveBeenCalled();
        rerender(<Dashboard {...original} as_of="2026-10-13T02:00:00Z" />);
        expect(screen.getAllByRole('article').length).toBeGreaterThan(0);
    });
});

describe('dashboard number owners', () => {
    it('opens Monitoring, IT and Maintenance for numbers this page does not own', () => {
        mocks.can.it = { view: true };
        render(<Dashboard {...estateFixture()} />);
        expect(
            screen.getByRole('link', { name: 'Open monitoring coverage' }),
        ).toHaveAttribute('href', '/security-devices/monitoring?tab=coverage');
        expect(
            screen.getByRole('link', { name: 'Open the IT open work queue' }),
        ).toHaveAttribute('href', '/it?tab=tickets&view=all_open');
        fireEvent.click(screen.getByRole('tab', { name: /Recent findings/ }));
        expect(
            screen.getByRole('link', { name: 'View all findings' }),
        ).toHaveAttribute('href', '/security-devices/monitoring?tab=findings');
        fireEvent.click(screen.getByRole('tab', { name: /Overdue work/ }));
        expect(
            screen.getByRole('link', {
                name: 'View due or overdue maintenance',
            }),
        ).toHaveAttribute('href', '/security-devices/maintenance?tab=due');
    });

    it('keeps a restricted owner on the in-page overview instead of a link', () => {
        render(<Dashboard {...estateFixture()} />);
        expect(
            screen.queryByRole('link', { name: 'Open the IT open work queue' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getAllByRole('button', { name: 'Open overview view' })
                .length,
        ).toBeGreaterThan(0);
    });
});

describe('dashboard first-run checklist', () => {
    it('renders incomplete first-run steps on Overview, hides when complete, and withholds when restricted', () => {
        const incomplete = {
            ...estateFixture(),
            first_run_checklist: {
                completed: 1,
                total: 4,
                all_complete: false,
                steps: [
                    {
                        key: 'server_readiness',
                        title: 'Check server readiness',
                        summary:
                            'Open Server readiness to check background workers, keys, and ping access.',
                        done: false,
                        href: '/settings/server-readiness',
                        action_label: 'Open Server readiness',
                    },
                    {
                        key: 'sites',
                        title: 'Confirm your Sites',
                        summary: '1 active Site is ready for devices.',
                        done: true,
                        href: '/security-devices/sites',
                        action_label: 'View Sites',
                    },
                    {
                        key: 'devices',
                        title: 'Add or discover devices',
                        summary:
                            'Register a device manually or scan a network from Setup.',
                        done: false,
                        href: '/security-devices/discovery',
                        action_label: 'Open Setup',
                    },
                    {
                        key: 'monitoring',
                        title: 'Turn on device checks',
                        summary:
                            'Assign checks so Oblivion can watch device reachability and alert you when something goes offline.',
                        done: false,
                        href: '/security-devices/monitoring',
                        action_label: 'Open Alerts & upkeep',
                    },
                ],
            },
        };

        const ui = render(<Dashboard {...incomplete} />);
        expect(screen.getByText('First-run setup')).toBeInTheDocument();
        expect(screen.getByText('1 of 4 done')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Open Server readiness/i }),
        ).toHaveAttribute('href', '/settings/server-readiness');

        fireEvent.click(screen.getByRole('tab', { name: 'Affected Sites' }));
        expect(screen.queryByText('First-run setup')).not.toBeInTheDocument();

        ui.rerender(
            <Dashboard
                {...incomplete}
                first_run_checklist={{
                    ...incomplete.first_run_checklist,
                    completed: 4,
                    all_complete: true,
                }}
            />,
        );
        expect(screen.queryByText('First-run setup')).not.toBeInTheDocument();

        mocks.can.securityDevices.viewAny = false;
        ui.rerender(<Dashboard {...incomplete} />);
        expect(screen.queryByText('First-run setup')).not.toBeInTheDocument();
    });
});
