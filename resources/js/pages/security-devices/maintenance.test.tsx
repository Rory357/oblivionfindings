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
import Maintenance, {
    type MaintenanceRow,
    type MaintenanceWorkspace,
} from './maintenance';
const mocks = vi.hoisted(() => ({
    actor: 7,
    can: {} as Record<string, Record<string, boolean>>,
    get: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
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
            get: mocks.get,
            post: mocks.post,
            reload: mocks.reload,
            visit: mocks.visit,
        },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));
export function maintenanceFixture(): MaintenanceWorkspace {
    const row: MaintenanceRow = {
        id: 8,
        type: 'calibration',
        status: 'scheduled',
        schedule_state: 'due_soon',
        description: 'Calibrate synthetic pump',
        scheduled_for: '2026-07-22',
        completed_at: null,
        performed_by: null,
        vendor_reference: 'WO-42',
        device: {
            id: 2,
            name: 'Synthetic infusion pump',
            href: '/security-devices/devices/2',
            domain: 'iot_healthcare',
            category: 'medical_device',
        },
        site: {
            id: 1,
            name: 'Clinical Site',
            href: '/security-devices/sites/1',
        },
    };
    return {
        actor_id: 7,
        tabs: [
            { key: 'overview', label: 'Overview' },
            { key: 'due', label: 'Due & overdue' },
            { key: 'planned', label: 'Planned' },
            { key: 'in-progress', label: 'In progress' },
            { key: 'completed', label: 'Completed' },
            { key: 'calibration', label: 'Calibration' },
            {
                key: 'firmware-configuration',
                label: 'Firmware & configuration',
            },
        ],
        active_tab: 'overview',
        boundary: {
            title: 'One operational maintenance record',
            description:
                'Servicing and technical work remain attached to the canonical device and Site.',
            finance_note: 'Finance remains the authoritative financial record.',
        },
        summary: {
            total: 204,
            overdue: 2,
            due_soon: 3,
            planned: 20,
            in_progress: 4,
            completed: 175,
            calibration: 16,
            firmware_configuration: 9,
        },
        records: [
            row,
            {
                ...structuredClone(row),
                id: 9,
                description: 'Retained battery replacement',
                type: 'battery_replacement',
                status: 'completed',
                schedule_state: 'completed',
                scheduled_for: null,
                completed_at: '2026-07-21T23:30:00Z',
                performed_by: 'Synthetic technician',
                vendor_reference: null,
            },
        ],
        inventory: { total: 204, shown: 2, truncated: true },
        filters: {
            search: null,
            status: null,
            type: null,
            site_id: null,
            device_id: null,
            domain: null,
        },
        filter_options: {
            statuses: [
                'open',
                'overdue',
                'scheduled',
                'in_progress',
                'completed',
                'cancelled',
            ],
            types: [
                'calibration',
                'firmware_update',
                'configuration_change',
                'battery_replacement',
            ],
            sites: [{ value: 1, label: 'Clinical Site' }],
            devices: [{ value: 2, label: 'Synthetic infusion pump' }],
        },
        permissions: { manage: true },
    };
}
const card = () =>
    screen.getByRole('article', {
        name: 'Maintenance work 8: Calibrate synthetic pump',
    });
async function openReview() {
    fireEvent.keyDown(
        within(card()).getByRole('button', {
            name: 'Actions for Calibrate synthetic pump',
        }),
        { key: 'Enter', code: 'Enter' },
    );
    fireEvent.click(
        await screen.findByRole('menuitem', { name: 'Mark complete' }),
    );
    return screen.findByRole('dialog', { name: 'Record work completion' });
}
const checked = () =>
    fireEvent.click(
        screen.getByRole('checkbox', {
            name: 'I have checked that this work is complete.',
        }),
    );
type Callbacks = {
    onSuccess: (page: {
        component: string;
        props: {
            auth?: { user: { id: number } };
            flash?: { success?: string };
        };
    }) => void;
    onError: (errors: Record<string, string>) => void;
    onFinish: () => void;
    onCancel: () => void;
    onCancelToken: (token: { cancel: () => void }) => void;
};
const callbacks = () => mocks.post.mock.calls[0][2] as Callbacks;
const ownerPage = () => ({
    component: 'security-devices/maintenance',
    props: {
        auth: { user: { id: 7 } },
        flash: { success: 'Maintenance marked as complete.' },
    },
});
beforeEach(() => {
    mocks.actor = 7;
    mocks.can = {
        securityDevices: {
            maintenanceView: true,
            maintenanceManage: true,
            devicesView: true,
        },
    };
    for (const name of ['get', 'post', 'reload', 'visit'] as const)
        mocks[name].mockReset();
});
afterEach(() => {
    cleanup();
    vi.useRealTimers();
});
describe('Maintenance shared work records and completion review', () => {
    it('withholds retained work after current nested maintenance-view authority is withdrawn', () => {
        const workspace = maintenanceFixture();
        const ui = render(<Maintenance workspace={workspace} />);
        expect(screen.getAllByRole('article').length).toBeGreaterThan(0);
        mocks.can.securityDevices.maintenanceView = false;
        ui.rerender(<Maintenance workspace={workspace} />);
        expect(screen.queryByRole('article')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Calibrate synthetic pump'),
        ).not.toBeInTheDocument();
    });

    it('does not rebind retained private work when auth and response actor metadata change together', async () => {
        const workspace = maintenanceFixture();
        workspace.filters.search = 'WO-42';
        const ui = render(<Maintenance workspace={workspace} />);
        await openReview();
        checked();
        fireEvent.click(
            screen.getByRole('button', { name: 'Record completion' }),
        );
        const cancel = vi.fn();
        callbacks().onCancelToken({ cancel });
        mocks.actor = 8;
        ui.rerender(<Maintenance workspace={{ ...workspace, actor_id: 8 }} />);
        expect(cancel).toHaveBeenCalledTimes(1);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryAllByRole('article')).toHaveLength(0);
        expect(
            screen.queryByText('Calibrate synthetic pump'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('Clinical Site')).not.toBeInTheDocument();
        expect(screen.getByRole('searchbox')).toHaveValue('');
        act(() => callbacks().onSuccess(ownerPage()));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getAllByText('Restricted').length).toBeGreaterThan(0);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(mocks.get).toHaveBeenCalledWith(
            '/security-devices/maintenance',
            {},
            { preserveState: false, preserveScroll: true },
        );
        expect(mocks.reload).not.toHaveBeenCalled();
        expect(mocks.post).toHaveBeenCalledTimes(1);
        ui.unmount();
        const current = maintenanceFixture();
        current.actor_id = 8;
        current.records = [
            {
                ...current.records[0],
                description: 'Current actor permitted work',
            },
        ];
        render(<Maintenance workspace={current} />);
        expect(
            screen.getByText('Current actor permitted work'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Calibrate synthetic pump'),
        ).not.toBeInTheDocument();
    });

    it('uses a complete read after current maintenance-view authority is withdrawn', () => {
        const workspace = maintenanceFixture();
        workspace.filters.search = 'WO-42';
        const ui = render(<Maintenance workspace={workspace} />);
        mocks.can.securityDevices.maintenanceView = false;
        ui.rerender(<Maintenance workspace={workspace} />);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(mocks.get).toHaveBeenCalledWith(
            '/security-devices/maintenance',
            {},
            { preserveState: false, preserveScroll: true },
        );
        expect(mocks.reload).not.toHaveBeenCalled();
        expect(mocks.post).not.toHaveBeenCalled();
    });

    it('keeps an ordinary same-actor header refresh as a partial read', () => {
        render(<Maintenance workspace={maintenanceFixture()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(mocks.reload).toHaveBeenCalledWith({
            only: ['workspace', 'auth'],
            onSuccess: expect.any(Function),
        });
        expect(mocks.get).not.toHaveBeenCalled();
        expect(mocks.post).not.toHaveBeenCalled();
    });

    it('retains exact identifiers, all original safe fields and NZ calendar/completion dates in full details', async () => {
        render(<Maintenance workspace={maintenanceFixture()} />);
        expect(screen.getByText('Work reference WO-42')).toBeInTheDocument();
        expect(
            screen.getAllByRole('link', { name: 'Synthetic infusion pump' })[0],
        ).toHaveAttribute('href', '/security-devices/devices/2');
        fireEvent.click(
            within(card()).getByRole('button', {
                name: /^Calibrate synthetic pump/,
            }),
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'Evidence for Calibrate synthetic pump',
        });
        for (const value of [
            '#8',
            'Calibrate synthetic pump',
            'Calibration',
            'Scheduled',
            'Due soon',
            'Synthetic infusion pump',
            '2',
            'IoT healthcare',
            'Medical device',
            'Clinical Site',
            '22 Jul 2026',
            'WO-42',
        ])
            expect(within(dialog).getAllByText(value).length).toBeGreaterThan(
                0,
            );
        expect(within(dialog).getByText('Completed')).toBeInTheDocument();
        expect(
            within(dialog).getAllByText('Not recorded').length,
        ).toBeGreaterThan(0);
        expect(
            within(dialog).queryByText(/cost|private notes/i),
        ).not.toBeInTheDocument();
    });
    it('keeps compact rows and complete evidence through table and context actions with focus return', async () => {
        render(<Maintenance workspace={maintenanceFixture()} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        expect(screen.getByRole('table')).toBeInTheDocument();
        const row = screen.getByRole('row', {
            name: /Calibrate synthetic pump/,
        });
        fireEvent.contextMenu(row, { clientX: 80, clientY: 100 });
        fireEvent.click(
            screen.getByRole('menuitem', { name: 'View evidence' }),
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'Evidence for Calibrate synthetic pump',
        });
        fireEvent.click(
            within(dialog).getAllByRole('button', { name: 'Close' })[0],
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(
            within(row).getByRole('button', {
                name: 'View evidence for Calibrate synthetic pump',
            }),
        ).toHaveFocus();
    });
    it('preserves server filter combinations and makes summary selection show its full permitted scope', async () => {
        const workspace = maintenanceFixture();
        workspace.filters.domain = 'iot_healthcare';
        render(<Maintenance workspace={workspace} />);
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Search maintenance work' }),
            { target: { value: 'WO-42' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/security-devices/maintenance',
            { ...workspace.filters, tab: 'overview', search: 'WO-42' },
            { preserveState: true, preserveScroll: true },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Site' }));
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Clinical Site',
            }),
        );
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/security-devices/maintenance',
            { ...workspace.filters, tab: 'overview', site_id: 1 },
            { preserveState: true, preserveScroll: true },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Device' }));
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Synthetic infusion pump',
            }),
        );
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/security-devices/maintenance',
            { ...workspace.filters, tab: 'overview', device_id: 2 },
            { preserveState: true, preserveScroll: true },
        );
        fireEvent.click(screen.getByRole('tab', { name: 'Completed' }));
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/security-devices/maintenance',
            { ...workspace.filters, tab: 'completed' },
            { preserveState: true, preserveScroll: true },
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View due or overdue',
            }),
        );
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/security-devices/maintenance',
            {
                tab: 'due',
                search: null,
                status: null,
                type: null,
                site_id: null,
                device_id: null,
                domain: null,
            },
            { preserveState: true, preserveScroll: true },
        );
        expect(
            screen.getByText(
                'Showing the first 2 of 204 matching records. Narrow the filters to reconcile the full set.',
            ),
        ).toBeInTheDocument();
    });
    it('requires an explicit reviewed completion and prevents duplicate or pending close actions', async () => {
        render(<Maintenance workspace={maintenanceFixture()} />);
        await openReview();
        expect(
            screen.getByRole('button', {
                name: 'Record completion',
            }),
        ).toBeDisabled();
        checked();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Record completion',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Recording…' }));
        expect(mocks.post).toHaveBeenCalledTimes(1);
        expect(mocks.post.mock.calls[0][0]).toBe(
            '/security-devices/maintenance/8/complete',
        );
        expect(mocks.post.mock.calls[0][1]).toEqual({});
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        expect(
            screen.getByRole('dialog', { name: 'Record work completion' }),
        ).toBeInTheDocument();
        act(() => callbacks().onSuccess(ownerPage()));
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
    });
    it('keeps the reviewed choice through shared Keep/Discard without submitting a completion', async () => {
        render(<Maintenance workspace={maintenanceFixture()} />);
        await openReview();
        checked();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        const prompt = await screen.findByRole('alertdialog');
        expect(
            within(prompt).getByText('Discard this completion review?'),
        ).toBeInTheDocument();
        fireEvent.click(within(prompt).getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByRole('checkbox', {
                name: 'I have checked that this work is complete.',
            }),
        ).toBeChecked();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Discard and continue',
            }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(mocks.post).not.toHaveBeenCalled();
    });
    it('rejects an unconfirmed or different-actor completion reply and offers a read-only refresh without repeating the action', async () => {
        render(<Maintenance workspace={maintenanceFixture()} />);
        await openReview();
        checked();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Record completion',
            }),
        );
        const page = ownerPage();
        page.props.auth.user.id = 8;
        act(() => callbacks().onSuccess(page));
        expect(screen.getByRole('alert')).toHaveTextContent(
            'The response did not confirm this completion',
        );
        expect(
            screen.getByRole('button', {
                name: 'Record completion',
            }),
        ).toBeDisabled();
        expect(
            screen.getByRole('checkbox', {
                name: 'I have checked that this work is complete.',
            }),
        ).toBeChecked();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Refresh retained work',
            }),
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Discard and continue',
            }),
        );
        expect(mocks.reload).toHaveBeenCalledWith({
            only: ['workspace', 'auth'],
        });
        expect(mocks.post).toHaveBeenCalledTimes(1);
    });
    it('retains a rejected completion for review and blocks another write until the current record is refreshed', async () => {
        render(<Maintenance workspace={maintenanceFixture()} />);
        await openReview();
        checked();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Record completion',
            }),
        );
        act(() =>
            callbacks().onError({
                status: 'A completed maintenance record is retained history.',
            }),
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'A completed maintenance record is retained history.',
        );
        expect(
            screen.getByRole('button', {
                name: 'Record completion',
            }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', {
                name: 'Refresh retained work',
            }),
        ).toBeInTheDocument();
        expect(mocks.post).toHaveBeenCalledTimes(1);
    });
    it('clears records, old search, review and late callbacks when actor provenance no longer matches', async () => {
        const workspace = maintenanceFixture();
        workspace.filters.search = 'WO-42';
        const view = render(<Maintenance workspace={workspace} />);
        await openReview();
        checked();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Record completion',
            }),
        );
        const cancel = vi.fn();
        callbacks().onCancelToken({ cancel });
        mocks.actor = 8;
        view.rerender(<Maintenance workspace={workspace} />);
        expect(cancel).toHaveBeenCalledTimes(1);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Calibrate synthetic pump'),
        ).not.toBeInTheDocument();
        expect(screen.getByRole('searchbox')).toHaveValue('');
        act(() => callbacks().onSuccess(ownerPage()));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getAllByText('Restricted').length).toBeGreaterThan(0);
    });
    it('retains authorized facts while removing profile and completion actions after current permission changes', async () => {
        const workspace = maintenanceFixture();
        const view = render(<Maintenance workspace={workspace} />);
        await openReview();
        mocks.can.securityDevices.maintenanceManage = false;
        mocks.can.securityDevices.devicesView = false;
        view.rerender(<Maintenance workspace={workspace} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Synthetic infusion pump' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getAllByText('Synthetic infusion pump').length,
        ).toBeGreaterThan(0);
        fireEvent.keyDown(
            within(card()).getByRole('button', {
                name: 'Actions for Calibrate synthetic pump',
            }),
            { key: 'Enter', code: 'Enter' },
        );
        expect(
            await screen.findByRole('menuitem', { name: 'View evidence' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('menuitem', { name: 'Mark complete' }),
        ).not.toBeInTheDocument();
    });
    it.each([403, 404])(
        'quarantines private records on current completion HTTP denial %i',
        async (status) => {
            const workspace = maintenanceFixture();
            workspace.filters.search = 'WO-42';
            render(<Maintenance workspace={workspace} />);
            await openReview();
            checked();
            fireEvent.click(
                screen.getByRole('button', { name: 'Record completion' }),
            );
            const event = new CustomEvent('inertia:invalid', {
                cancelable: true,
                detail: {
                    response: {
                        status,
                        config: {
                            method: 'post',
                            url: '/security-devices/maintenance/8/complete',
                        },
                    },
                },
            });
            act(() => {
                document.dispatchEvent(event);
            });
            expect(event.defaultPrevented).toBe(true);
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            expect(
                screen.queryByText('Calibrate synthetic pump'),
            ).not.toBeInTheDocument();
            expect(screen.getByRole('searchbox')).toHaveValue('');
            expect(
                screen.getByText(
                    'Completion is no longer permitted in this scope. Refresh Maintenance for current access.',
                ),
            ).toBeInTheDocument();
            expect(mocks.post).toHaveBeenCalledTimes(1);
            fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
            expect(mocks.get).toHaveBeenCalledWith(
                '/security-devices/maintenance',
                {},
                { preserveState: false, preserveScroll: true },
            );
            expect(mocks.reload).not.toHaveBeenCalled();
            expect(mocks.post).toHaveBeenCalledTimes(1);
        },
    );
    it('handles only the current completion response as uncertain without publishing its raw error body', async () => {
        render(<Maintenance workspace={maintenanceFixture()} />);
        await openReview();
        checked();
        fireEvent.click(
            screen.getByRole('button', { name: 'Record completion' }),
        );
        const event = new CustomEvent('inertia:invalid', {
            cancelable: true,
            detail: {
                response: {
                    status: 504,
                    data: { message: 'RAW-ERROR-NOT-FOR-UI' },
                    config: {
                        method: 'POST',
                        url: '/security-devices/maintenance/8/complete',
                    },
                },
            },
        });
        act(() => {
            document.dispatchEvent(event);
        });
        expect(event.defaultPrevented).toBe(true);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Completion was not confirmed',
        );
        expect(
            screen.queryByText('RAW-ERROR-NOT-FOR-UI'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Record completion' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Refresh retained work' }),
        ).toBeInTheDocument();
        expect(mocks.post).toHaveBeenCalledTimes(1);
    });
    it('leaves unrelated response errors with their owning workflow', async () => {
        render(<Maintenance workspace={maintenanceFixture()} />);
        await openReview();
        checked();
        fireEvent.click(
            screen.getByRole('button', { name: 'Record completion' }),
        );
        const event = new CustomEvent('inertia:invalid', {
            cancelable: true,
            detail: {
                response: {
                    status: 504,
                    config: {
                        method: 'post',
                        url: '/security-devices/maintenance/9/complete',
                    },
                },
            },
        });
        act(() => {
            document.dispatchEvent(event);
        });
        expect(event.defaultPrevented).toBe(false);
        expect(
            screen.getByRole('button', { name: 'Recording…' }),
        ).toBeDisabled();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });
    it('keeps missing values and the empty filtered inventory distinct from a completed or healthy estate', () => {
        const workspace = maintenanceFixture();
        workspace.records = [];
        workspace.inventory = { total: 0, shown: 0, truncated: false };
        render(<Maintenance workspace={workspace} />);
        expect(
            screen.getByText('No maintenance work matches this view'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('204 retained records across permitted devices'),
        ).toBeInTheDocument();
        expect(screen.queryByText('All healthy')).not.toBeInTheDocument();
    });
});
