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
import { healthFixture } from '../../../../.endpoint-tools/sd-ui-maintenance-health-20261013/fixtures';
import MaintenanceHealth from './maintenance-health';
const mocks = vi.hoisted(() => ({
    actor: 7,
    can: {} as Record<string, boolean>,
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
beforeEach(() => {
    mocks.actor = 7;
    mocks.can = {
        'securityDevices.maintenance.view': true,
        'securityDevices.maintenance.manage': true,
        'securityDevices.devices.view': true,
    };
    for (const key of ['get', 'post', 'reload', 'visit'] as const)
        mocks[key].mockReset();
});
afterEach(cleanup);
const workCard = () =>
    screen.getByRole('article', {
        name: 'Maintenance work 8: Calibrate synthetic pump',
    });
async function openReview() {
    fireEvent.keyDown(
        within(workCard()).getByRole('button', {
            name: 'Actions for Calibrate synthetic pump',
        }),
        { key: 'Enter', code: 'Enter' },
    );
    fireEvent.click(
        await screen.findByRole('menuitem', { name: 'Mark complete' }),
    );
    return screen.findByRole('dialog', { name: 'Record work completion' });
}
function send() {
    fireEvent.click(
        screen.getByRole('checkbox', {
            name: 'I have checked that this work is complete.',
        }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Record completion' }));
}
type Callbacks = {
    onSuccess: (page: {
        component: string;
        props: { auth: { user: { id: number } }; flash: { success: string } };
    }) => void;
    onCancel: () => void;
    onFinish: () => void;
};
const reply = (component = 'security-devices/maintenance-health') => ({
    component,
    props: {
        auth: { user: { id: 7 } },
        flash: { success: 'Maintenance marked as complete.' },
    },
});
describe('Maintenance and health shared legacy workspace', () => {
    it('retains full work, cost and notes facts with NZ dates and canonical device links', async () => {
        render(<MaintenanceHealth {...healthFixture()} />);
        fireEvent.click(
            within(workCard()).getByRole('button', {
                name: /^Calibrate synthetic pump/,
            }),
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'Evidence for Calibrate synthetic pump',
        });
        for (const value of [
            '8',
            '2',
            'DEV-2',
            'WO-42',
            'Calibration',
            'Scheduled',
            '20 Oct 2026',
            '$45.00',
            'Private retained work note',
        ])
            expect(within(dialog).getAllByText(value).length).toBeGreaterThan(
                0,
            );
        expect(
            within(dialog).getByRole('link', { name: 'Synthetic pump' }),
        ).toHaveAttribute('href', '/security-devices/devices/2');
        expect(
            within(dialog).getAllByText('Not recorded').length,
        ).toBeGreaterThan(0);
    });
    it('keeps all attention facts and exact last-seen time without claiming live or unique totals', async () => {
        render(<MaintenanceHealth {...healthFixture()} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Health attention' }));
        fireEvent.click(
            within(
                screen.getByRole('article', {
                    name: 'Attention device 2: Synthetic pump',
                }),
            ).getByRole('button', { name: /^Synthetic pump/ }),
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'Evidence for Synthetic pump',
        });
        for (const value of [
            'DEV-2',
            'IoT healthcare',
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
            screen.queryByText(/devices require attention|All devices healthy/),
        ).not.toBeInTheDocument();
    });
    it('retains old battery-reading timestamps and distinguishes a bounded empty result', async () => {
        const props = healthFixture();
        const ui = render(<MaintenanceHealth {...props} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Low battery' }));
        expect(
            screen.getByText(/Up to 15 devices are shown/),
        ).toBeInTheDocument();
        fireEvent.click(
            within(
                screen.getByRole('article', {
                    name: 'Battery device 3: Synthetic door sensor',
                }),
            ).getByRole('button', { name: /^Synthetic door sensor/ }),
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'Evidence for Synthetic door sensor',
        });
        expect(within(dialog).getByText('15%')).toBeInTheDocument();
        expect(within(dialog).getByText(/1 July 2026/)).toBeInTheDocument();
        ui.rerender(
            <MaintenanceHealth {...{ ...props, lowBatteryDevices: [] }} />,
        );
        expect(
            screen.getByText('No low battery devices in this result'),
        ).toBeInTheDocument();
    });
    it('preserves sort and direction through search and canonical pagination without HTML injection', () => {
        render(<MaintenanceHealth {...healthFixture()} />);
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Search maintenance work' }),
            { target: { value: 'pump' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/security-devices/maintenance-health',
            {
                search: 'pump',
                sort: 'created_at',
                direction: 'desc',
                page: '1',
            },
            { preserveState: true, preserveScroll: true },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/security-devices/maintenance-health?page=2&search=WO-42',
            {},
            { preserveState: true, preserveScroll: true },
        );
    });
    it('provides full details from compact table context menus with focus return', async () => {
        render(<MaintenanceHealth {...healthFixture()} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        const row = screen.getByRole('row', {
            name: /Calibrate synthetic pump/,
        });
        fireEvent.contextMenu(row, { clientX: 90, clientY: 100 });
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
    it('clears open private details, search and old rows when the response actor changes', async () => {
        const props = healthFixture(),
            ui = render(<MaintenanceHealth {...props} />);
        fireEvent.click(
            within(workCard()).getByRole('button', {
                name: /^Calibrate synthetic pump/,
            }),
        );
        await screen.findByRole('dialog');
        mocks.actor = 8;
        ui.rerender(<MaintenanceHealth {...props} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Private retained work note'),
        ).not.toBeInTheDocument();
        expect(screen.queryByRole('article')).not.toBeInTheDocument();
        expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
        expect(
            screen.getByText(/Maintenance records are restricted/),
        ).toBeInTheDocument();
    });
    it('requires current manage and separate profile access', async () => {
        mocks.can['securityDevices.maintenance.manage'] = false;
        mocks.can['securityDevices.devices.view'] = false;
        render(<MaintenanceHealth {...healthFixture()} />);
        expect(
            screen.queryByRole('link', { name: 'Synthetic pump' }),
        ).not.toBeInTheDocument();
        fireEvent.keyDown(
            within(workCard()).getByRole('button', {
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
    it('accepts only the current legacy owner reply and prevents repeated pending writes', async () => {
        render(<MaintenanceHealth {...healthFixture()} />);
        const dialog = await openReview();
        expect(
            within(dialog).queryByText('No Site assigned'),
        ).not.toBeInTheDocument();
        send();
        fireEvent.click(screen.getByRole('button', { name: 'Recording…' }));
        expect(mocks.post).toHaveBeenCalledTimes(1);
        expect(mocks.post.mock.calls[0].slice(0, 2)).toEqual([
            '/security-devices/maintenance/8/complete',
            {},
        ]);
        act(() =>
            (mocks.post.mock.calls[0][2] as Callbacks).onSuccess(reply()),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
    });
    it('treats a success from another page as uncertain', async () => {
        render(<MaintenanceHealth {...healthFixture()} />);
        await openReview();
        send();
        act(() =>
            (mocks.post.mock.calls[0][2] as Callbacks).onSuccess(
                reply('security-devices/maintenance'),
            ),
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'The response did not confirm',
        );
        expect(
            screen.getByRole('button', { name: 'Record completion' }),
        ).toBeDisabled();
    });
    it('recovers a lost reply through legacy read-only properties after explicit discard', async () => {
        render(<MaintenanceHealth {...healthFixture()} />);
        await openReview();
        send();
        act(() => (mocks.post.mock.calls[0][2] as Callbacks).onCancel());
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Refresh retained work',
            }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Discard and continue' }),
        );
        expect(mocks.post).toHaveBeenCalledTimes(1);
        expect(mocks.reload).toHaveBeenCalledWith({
            only: [
                'actor_id',
                'stats',
                'records',
                'attentionDevices',
                'lowBatteryDevices',
                'filters',
                'can',
                'auth',
            ],
        });
    });
    it('quarantines every old health and work record after an owned completion denial', async () => {
        render(<MaintenanceHealth {...healthFixture()} />);
        await openReview();
        send();
        act(() => {
            document.dispatchEvent(
                new CustomEvent('inertia:invalid', {
                    cancelable: true,
                    detail: {
                        response: {
                            status: 403,
                            config: {
                                method: 'post',
                                url: '/security-devices/maintenance/8/complete',
                            },
                        },
                    },
                }),
            );
        });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByRole('article')).not.toBeInTheDocument();
        expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
        expect(
            screen.getByText(/Maintenance records are restricted/),
        ).toBeInTheDocument();
        expect(mocks.post).toHaveBeenCalledTimes(1);
    });
});
