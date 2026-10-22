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
import DeviceGroupsIndex, { type GroupIndexProps } from './index';
import DeviceGroupShow, {
    type AutoRulePreview,
    type GroupShowProps,
} from './show';
const mocks = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
    visit: vi.fn(),
    preview: vi.fn(),
    actor: 7,
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
            props: {
                auth: {
                    user: { id: mocks.actor },
                    can: {
                        securityDevices: {
                            groupsManage: true,
                            monitoringManage: true,
                        },
                    },
                },
            },
        }),
        router: {
            ...actual.router,
            get: mocks.get,
            post: mocks.post,
            delete: mocks.delete,
            visit: mocks.visit,
        },
    };
});
vi.mock('axios', () => ({ default: { get: mocks.preview } }));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));
const indexFixture = (): GroupIndexProps => ({
    groups: {
        data: [
            {
                id: 1,
                name: 'House devices',
                type: 'location',
                description:
                    'Full long description for the approved Site device group.',
                devices_count: 4,
                created_at: '2026-10-11T01:00:00Z',
            },
            {
                id: 2,
                name: 'House devices',
                type: 'functional',
                description: null,
                devices_count: 3,
                created_at: null,
            },
            {
                id: 3,
                name: 'Unconfigured group',
                type: 'custom',
                description: null,
                devices_count: 0,
                created_at: null,
            },
        ],
        links: [
            { url: null, label: '&laquo; Previous', active: false },
            {
                url: '/security-devices/device-groups?page=1',
                label: '1',
                active: true,
            },
            {
                url: '/security-devices/device-groups?page=2',
                label: '2',
                active: false,
            },
            {
                url: '/security-devices/device-groups?page=2',
                label: 'Next &raquo;',
                active: false,
            },
        ],
        meta: { current_page: 1, last_page: 2, total: 45 },
    },
    filters: {},
});
const showFixture = (): GroupShowProps => ({
    actor_id: 7,
    as_of: '2026-10-14T03:00:00Z',
    can_preview_maintenance: true,
    group: {
        id: 17,
        name: 'House devices',
        type: 'location',
        description: 'Approved house equipment',
        created_at: null,
        auto_rules: {
            match: 'all',
            conditions: [{ field: 'domain', op: 'equals', value: 'security' }],
        },
        auto_rule_condition_count: 1,
    },
    members: {
        data: [
            {
                id: 41,
                name: 'Lobby camera',
                device_uid: 'CAM-0041',
                domain: 'security',
                category: 'camera',
                subcategory: null,
                manufacturer: 'Hikvision',
                model: 'Fixture model',
                status: 'active',
                health_status: 'warning',
                provider: null,
                last_seen_at: null,
                battery_level: null,
                assigned_to: 'Kauri House',
                assignment_type: 'site',
            },
            {
                id: 42,
                name: 'Lobby camera',
                device_uid: 'CAM-0042',
                domain: 'security',
                category: 'camera',
                subcategory: null,
                manufacturer: null,
                model: null,
                status: 'active',
                health_status: 'unknown',
                provider: null,
                last_seen_at: null,
                battery_level: null,
                assigned_to: null,
                assignment_type: null,
            },
        ],
        links: [],
        meta: { current_page: 1, last_page: 1, total: 32 },
    },
    availableDevices: [
        {
            id: 43,
            name: 'Door sensor',
            device_uid: 'SEN-0043',
            domain: 'security',
            category: 'sensor',
        },
    ],
});
const preview: AutoRulePreview = {
    count: 26,
    changes: { added: 2, removed: 1, kept: 24, total: 26 },
    sample: [
        {
            id: 41,
            name: 'Lobby camera',
            device_uid: 'CAM-0041',
            category: 'camera',
        },
    ],
};
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((res) => {
        resolve = res;
    });
    return { promise, resolve };
}
async function openPreview() {
    fireEvent.click(screen.getByRole('tab', { name: /Automatic membership/ }));
    fireEvent.click(
        screen.getByRole('button', { name: 'Review proposed membership' }),
    );
}
afterEach(cleanup);
beforeEach(() => {
    Object.values(mocks).forEach((value) => {
        if (typeof value === 'function') value.mockReset();
    });
    mocks.actor = 7;
});
describe('Device group list', () => {
    it('keeps cross-page totals separate from current-page counts and never labels membership sums as unique devices', () => {
        render(<DeviceGroupsIndex {...indexFixture()} />);
        expect(
            screen.getByRole('button', { name: 'View current group results' }),
        ).toHaveTextContent('45');
        // Page-local membership counts live on the rail only, not as meters.
        expect(
            screen.queryByRole('button', {
                name: 'View with devices on this page',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('tab', { name: /With devices/ }),
        ).toHaveTextContent('2');
        fireEvent.click(screen.getByRole('tab', { name: /Empty groups/ }));
        expect(
            screen.getByRole('article', { name: 'Unconfigured group' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('article', { name: 'House devices' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'View current group results' }),
        );
        expect(
            screen.getAllByRole('article', { name: 'House devices' }),
        ).toHaveLength(2);
        expect(
            screen.getByText(/Devices can belong to more than one group/),
        ).toBeInTheDocument();
    });
    it('uses compact tables, native identity links and a complete separate description viewer', async () => {
        render(<DeviceGroupsIndex {...indexFixture()} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        const table = screen.getByRole('table');
        expect(within(table).getAllByRole('row')).toHaveLength(4);
        expect(
            within(table).queryByText(/Full long description/),
        ).not.toBeInTheDocument();
        expect(
            within(table).getAllByRole('link', { name: /House devices/ })[0],
        ).toHaveAttribute('href', '/security-devices/device-groups/1');
        const opener = screen.getAllByRole('button', {
            name: 'View evidence for House devices',
        })[0];
        fireEvent.click(opener);
        expect(
            within(screen.getByRole('dialog')).getByText(
                /Full long description/,
            ),
        ).toBeInTheDocument();
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        await waitFor(() => expect(opener).toHaveFocus());
    });
    it('preserves server filters, resets their page, and passes the canonical pagination destination unchanged', () => {
        const props = indexFixture();
        props.filters = { type: 'location', search: 'House' };
        render(<DeviceGroupsIndex {...props} />);
        const search = screen.getByRole('searchbox', {
            name: 'Search all device groups',
        });
        fireEvent.change(search, { target: { value: 'Pump' } });
        fireEvent.keyDown(search, { key: 'Enter' });
        expect(mocks.get).toHaveBeenCalledWith(
            '/security-devices/device-groups',
            { type: 'location', search: 'Pump', page: '1' },
            { preserveState: true },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/security-devices/device-groups?page=2',
            {},
            { preserveState: true },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
        expect(search).toHaveValue('');
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/security-devices/device-groups',
            {},
            { preserveState: true },
        );
    });
    it('closes private evidence and resets a stale search when the actor/results change', async () => {
        const props = indexFixture();
        const view = render(<DeviceGroupsIndex {...props} />);
        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'Private draft' },
        });
        fireEvent.keyDown(
            screen.getAllByRole('button', {
                name: 'Actions for House devices',
            })[0],
            { key: 'ArrowDown' },
        );
        fireEvent.click(
            await screen.findByRole('menuitem', { name: 'View evidence' }),
        );
        mocks.actor = 8;
        const next = indexFixture();
        next.groups.data = [];
        view.rerender(<DeviceGroupsIndex {...next} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByRole('searchbox')).toHaveValue('');
    });
});
describe('Device group profile', () => {
    it('retains both repeated member identities, complete evidence, and current-page attention semantics', () => {
        render(<DeviceGroupShow {...showFixture()} />);
        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'CAM-0042' },
        });
        expect(
            screen.getAllByRole('article', { name: 'Lobby camera' }),
        ).toHaveLength(1);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View members needing attention on this page',
            }),
        );
        expect(screen.getByRole('searchbox')).toHaveValue('');
        expect(
            screen.getAllByRole('article', { name: 'Lobby camera' }),
        ).toHaveLength(1);
        expect(
            screen.getByRole('button', { name: 'View all group members' }),
        ).toHaveTextContent('32');
        fireEvent.click(
            screen.getByRole('button', { name: 'View all group members' }),
        );
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        expect(
            within(screen.getByRole('table'))
                .getAllByRole('link', { name: /Lobby camera/ })
                .map((link) => link.getAttribute('href')),
        ).toEqual([
            '/security-devices/devices/41',
            '/security-devices/devices/42',
        ]);
        fireEvent.click(
            screen.getAllByRole('button', {
                name: 'View evidence for Lobby camera',
            })[1],
        );
        expect(
            within(screen.getByRole('dialog')).getByText('CAM-0042'),
        ).toBeInTheDocument();
        expect(
            within(screen.getByRole('dialog')).getByText('Unassigned'),
        ).toBeInTheDocument();
        expect(
            within(screen.getByRole('dialog')).getByText('Not reported'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Retained evidence/ }),
        ).not.toBeInTheDocument();
    });
    it('requires explicit member removal confirmation and preserves the exact selected identity while pending', async () => {
        render(<DeviceGroupShow {...showFixture()} />);
        fireEvent.keyDown(
            screen.getAllByRole('button', {
                name: 'Actions for Lobby camera',
            })[1],
            { key: 'ArrowDown' },
        );
        fireEvent.click(
            await screen.findByRole('menuitem', { name: 'Remove from group' }),
        );
        expect(mocks.delete).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Remove device' }));
        expect(mocks.delete.mock.calls[0][0]).toBe(
            '/security-devices/device-groups/17/members/42',
        );
        expect(
            screen.getByRole('button', { name: 'Remove device' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Remove device' }));
        expect(mocks.delete).toHaveBeenCalledTimes(1);
    });
    it('does not apply automatic rules before a preview and does not treat an incomplete sample as the whole match set', async () => {
        const request = deferred<{ data: AutoRulePreview }>();
        mocks.preview.mockReturnValue(request.promise);
        render(<DeviceGroupShow {...showFixture()} />);
        await openPreview();
        expect(
            screen.getByRole('button', { name: 'Apply membership changes' }),
        ).toBeDisabled();
        expect(mocks.post).not.toHaveBeenCalled();
        request.resolve({ data: preview });
        await waitFor(() =>
            expect(
                screen.getByRole('button', {
                    name: 'Apply membership changes',
                }),
            ).toBeEnabled(),
        );
        expect(
            screen.getByText(/server rechecks approved devices/),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: /Matching devices.*A sample/ }),
        );
        expect(
            screen.getByText('Showing the first 1 match.'),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Apply membership changes' }),
        );
        expect(mocks.post.mock.calls[0].slice(0, 2)).toEqual([
            '/security-devices/device-groups/17/auto-rules/sync',
            {},
        ]);
        expect(
            screen.getByRole('button', { name: 'Applying…' }),
        ).toBeDisabled();
    });
    it('aborts closed previews, ignores late responses, and permits a fresh read after a failed preview', async () => {
        const request = deferred<{ data: AutoRulePreview }>();
        mocks.preview
            .mockReturnValueOnce(request.promise)
            .mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValueOnce({ data: preview });
        render(<DeviceGroupShow {...showFixture()} />);
        await openPreview();
        const signal = mocks.preview.mock.calls[0][1].signal as AbortSignal;
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(signal.aborted).toBe(true);
        request.resolve({ data: preview });
        fireEvent.click(
            screen.getByRole('button', { name: 'Review proposed membership' }),
        );
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'No devices were changed',
            ),
        );
        expect(
            screen.getByRole('button', { name: 'Apply membership changes' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Review again' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', {
                    name: 'Apply membership changes',
                }),
            ).toBeEnabled(),
        );
    });
    it('guards a selected add-device draft and keeps the owner request disabled while it is being sent', async () => {
        render(<DeviceGroupShow {...showFixture()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add device' }));
        fireEvent.click(screen.getByRole('combobox'));
        fireEvent.click(
            await screen.findByRole('option', { name: /Door sensor/ }),
        );
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );
        expect(screen.getByRole('alertdialog')).toHaveTextContent(
            'has not been added',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Keep selection' }));
        fireEvent.click(screen.getByRole('button', { name: 'Add to group' }));
        expect(mocks.post.mock.calls[0].slice(0, 2)).toEqual([
            '/security-devices/device-groups/17/members',
            { device_id: '43' },
        ]);
        expect(screen.getByRole('button', { name: 'Adding…' })).toBeDisabled();
    });
    it('keeps an open add-device dialog and its selection through an ordinary page response', async () => {
        const view = render(<DeviceGroupShow {...showFixture()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add device' }));
        fireEvent.click(screen.getByRole('combobox'));
        fireEvent.click(
            await screen.findByRole('option', { name: /Door sensor/ }),
        );
        // A validation error or reload returns fresh props for the same group and actor.
        const next = showFixture();
        next.as_of = '2026-10-14T03:05:00Z';
        next.members = { ...next.members, data: [...next.members.data] };
        view.rerender(<DeviceGroupShow {...next} />);
        const dialog = screen.getByRole('dialog', {
            name: 'Add device to group',
        });
        expect(within(dialog).getByRole('combobox')).toHaveTextContent(
            /Door sensor/,
        );
        expect(
            within(dialog).getByRole('button', { name: 'Add to group' }),
        ).toBeEnabled();
    });
    it('offers the maintenance preview only when the server says the read is permitted', async () => {
        const data = showFixture();
        data.can_preview_maintenance = false;
        render(<DeviceGroupShow {...data} />);
        fireEvent.keyDown(
            screen.getAllByRole('button', {
                name: 'Actions for Lobby camera',
            })[0],
            { key: 'ArrowDown' },
        );
        expect(
            await screen.findByRole('menuitem', { name: 'Open device' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('menuitem', {
                name: 'Preview maintenance policy',
            }),
        ).not.toBeInTheDocument();
    });
    it('shows the unavailable group inside the shell with a Home trail and a full-read recovery', () => {
        mocks.actor = 8;
        render(<DeviceGroupShow {...showFixture()} />);
        expect(
            screen.getByRole('heading', { name: 'Group access unavailable' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent(
            /isn’t available to you right now/,
        );
        expect(screen.queryByText('Lobby camera')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Refresh group' }));
        expect(mocks.get).toHaveBeenCalledWith(
            '/security-devices/device-groups/17',
            {},
            { preserveState: false, preserveScroll: true },
        );
    });
    it('returns keyboard focus to the exact member action after the maintenance reader closes', async () => {
        mocks.preview.mockResolvedValue({ data: {} });
        render(<DeviceGroupShow {...showFixture()} />);
        const opener = screen.getAllByRole('button', {
            name: 'Actions for Lobby camera',
        })[0];
        fireEvent.keyDown(opener, { key: 'ArrowDown' });
        fireEvent.click(
            await screen.findByRole('menuitem', {
                name: 'Preview maintenance policy',
            }),
        );
        const dialog = await screen.findByRole('dialog');
        fireEvent.click(
            within(dialog).getAllByRole('button', { name: /^Close$/ })[1],
        );
        await waitFor(() => expect(opener).toHaveFocus());
    });
    it('does not let a preview or pending selection cross to a replacement group or actor', async () => {
        const request = deferred<{ data: AutoRulePreview }>();
        mocks.preview.mockReturnValue(request.promise);
        const view = render(<DeviceGroupShow {...showFixture()} />);
        await openPreview();
        const signal = mocks.preview.mock.calls[0][1].signal as AbortSignal;
        mocks.actor = 8;
        const next = showFixture();
        next.group.id = 18;
        next.group.name = 'Different group';
        view.rerender(<DeviceGroupShow {...next} />);
        request.resolve({ data: preview });
        expect(signal.aborted).toBe(true);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Group access unavailable' }),
        ).toBeInTheDocument();
        expect(mocks.post).not.toHaveBeenCalled();
    });
    it('invalidates a failed apply and asks for a new preview instead of confirming success', async () => {
        mocks.preview.mockResolvedValue({ data: preview });
        render(<DeviceGroupShow {...showFixture()} />);
        await openPreview();
        await waitFor(() =>
            expect(
                screen.getByRole('button', {
                    name: 'Apply membership changes',
                }),
            ).toBeEnabled(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Apply membership changes' }),
        );
        const callbacks = mocks.post.mock.calls[0][2];
        fireEvent.click(screen.getByRole('button', { name: 'Applying…' }));
        expect(mocks.post).toHaveBeenCalledTimes(1);
        act(() => {
            callbacks.onError({ message: 'Unknown' });
            callbacks.onFinish();
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'could not be confirmed',
        );
        expect(
            screen.getByRole('button', { name: 'Apply membership changes' }),
        ).toBeDisabled();
    });
});
