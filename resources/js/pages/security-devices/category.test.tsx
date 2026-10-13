import type { SecurityDevicesWorkspace } from '@/components/security-devices/security-devices-workspace-shell';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CategoryPage, {
    CategoryRegisterAction,
    CategorySearchInput,
    CategoryWorkspaceHero,
    type CategoryPageProps,
    type PageConfig,
} from './category';
const navigation = vi.hoisted(() => ({
    get: vi.fn(),
    visit: vi.fn(),
    actorId: 7,
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
            props: { auth: { user: { id: navigation.actorId } } },
        }),
        router: {
            ...actual.router,
            get: navigation.get,
            visit: navigation.visit,
        },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));
vi.mock('@/components/security-devices/add-device-dialog', () => ({
    AddDeviceDialog: () => null,
    useAddDeviceDialogState: () => ({
        open: false,
        openDialog: vi.fn(),
        closeDialog: vi.fn(),
    }),
}));
const workspace: SecurityDevicesWorkspace = {
    slug: 'network-it',
    title: 'Network & IT',
    description: 'Network and infrastructure operations.',
    canonicalHref: '/security-devices/network-it',
    activeTab: 'overview',
    activeTabState: 'available',
    tabs: [
        {
            key: 'overview',
            label: 'Overview',
            description: 'Network operations overview.',
            state: 'available',
            stateLabel: 'Available',
        },
        {
            key: 'traffic-capacity',
            label: 'Traffic & capacity',
            description: 'Retained traffic and capacity evidence.',
            state: 'not_configured',
            stateLabel: 'Not configured',
        },
    ],
    summary: { devices: 12, attention: 2, monitored: 9, unmonitored: 3 },
    freshness: {
        state: 'current',
        label: 'Latest device observation',
        observedAt: '2026-10-12T01:00:00Z',
    },
};
const config: PageConfig = {
    slug: 'network-it',
    title: 'Network & IT',
    description: 'Network and infrastructure operations.',
    emptyTitle: 'No devices',
    emptyDescription: 'No network devices are available.',
    icon: 'network-it',
    domain: 'it_infrastructure',
    categories: null,
};
function fixture(): CategoryPageProps {
    return {
        pageConfig: config,
        workspace,
        devices: {
            data: [
                {
                    id: 41,
                    device_uid: 'synthetic-switch-41',
                    name: 'Synthetic switch',
                    domain: 'it_infrastructure',
                    category: 'networking',
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
                    monitoring_state: 'unknown',
                },
            ],
            links: [],
            meta: { current_page: 1, last_page: 1, total: 1 },
        },
        stats: {
            total: 12,
            active: 9,
            offline: 1,
            attention: 2,
            bySubcategory: {},
        },
        filters: {},
        filterOptions: {
            subcategories: [],
            categories: [],
            statuses: [],
            healthStatuses: [],
            providers: [],
        },
        can: { registerDevice: false },
    };
}
beforeEach(() => {
    navigation.actorId = 7;
    navigation.get.mockReset();
    navigation.visit.mockReset();
});
afterEach(cleanup);
describe('Category page controls', () => {
    it('names the search as an inventory search and submits the entered text', () => {
        const onSubmit = vi.fn();
        render(
            <CategorySearchInput
                title="Security"
                value="camera"
                onChange={vi.fn()}
                onSubmit={onSubmit}
            />,
        );
        const search = screen.getByRole('searchbox', {
            name: 'Search security device inventory',
        });
        fireEvent.keyDown(search, { key: 'Enter' });
        expect(onSubmit).toHaveBeenCalledOnce();
    });
    it('does not offer registration to a viewer without create capability', () => {
        render(
            <CategoryRegisterAction
                canRegister={false}
                label="Register device"
                onRegister={vi.fn()}
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'Register device' }),
        ).not.toBeInTheDocument();
    });
    it('opens registration through the existing action with create capability', () => {
        const onRegister = vi.fn();
        render(
            <CategoryRegisterAction
                canRegister
                label="Register device"
                onRegister={onRegister}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Register device' }),
        );
        expect(onRegister).toHaveBeenCalledOnce();
    });
    it('uses real domain inventory destinations for counts and preserves filters when selecting a workspace view', () => {
        render(
            <CategoryWorkspaceHero
                pageConfig={config}
                workspace={workspace}
                filters={{ status: 'offline', device_id: '41', page: '3' }}
                canRegister
                registerLabel="Register device"
                onRegister={vi.fn()}
            />,
        );
        expect(
            screen.getByRole('link', { name: 'View devices' }),
        ).toHaveAttribute(
            'href',
            '/security-devices/devices?domain=it_infrastructure',
        );
        expect(
            screen.getByRole('link', { name: 'View without monitoring' }),
        ).toHaveAttribute(
            'href',
            '/security-devices/devices?domain=it_infrastructure&view=unmonitored',
        );
        expect(
            screen.getByRole('link', { name: 'View latest report' }),
        ).toHaveAttribute(
            'href',
            '/security-devices/devices?domain=it_infrastructure&sort=last_seen_at&direction=desc',
        );
        fireEvent.click(
            screen.getByRole('tab', { name: /Traffic & capacity/ }),
        );
        expect(navigation.get).toHaveBeenCalledWith(
            '/security-devices/network-it?status=offline&device_id=41&tab=traffic-capacity',
            {},
            { preserveState: true },
        );
    });
    it('hides inventory and search in a restricted view while retaining the permission explanation', () => {
        const props = fixture();
        props.workspace = {
            ...workspace,
            activeTab: 'events',
            activeTabState: 'restricted',
            tabs: [
                ...workspace.tabs,
                {
                    key: 'events',
                    label: 'Events',
                    description: 'Private event context.',
                    state: 'restricted',
                    stateLabel: 'Restricted',
                },
            ],
        };
        render(<CategoryPage {...props} />);
        expect(screen.getByText('Permission required')).toBeInTheDocument();
        expect(screen.queryByText('Synthetic switch')).not.toBeInTheDocument();
        expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
    });
    it('keeps the table view across filter updates and clears a private local query on account change', () => {
        const props = fixture();
        const view = render(<CategoryPage {...props} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        fireEvent.change(
            screen.getByRole('searchbox', {
                name: 'Search network & it device inventory',
            }),
            { target: { value: 'private query' } },
        );
        view.rerender(
            <CategoryPage {...props} filters={{ status: 'offline' }} />,
        );
        expect(screen.getByRole('radio', { name: 'Table' })).toBeChecked();
        navigation.actorId = 8;
        view.rerender(
            <CategoryPage {...props} filters={{ status: 'offline' }} />,
        );
        expect(
            screen.getByRole('searchbox', {
                name: 'Search network & it device inventory',
            }),
        ).toHaveValue('');
    });
    it('preserves the active tab and canonical device target when clearing inventory filters', () => {
        const props = fixture();
        props.filters = {
            tab: 'traffic-capacity',
            device_id: '41',
            status: 'offline',
            search: 'switch',
        };
        props.workspace = { ...workspace, activeTab: 'traffic-capacity' };
        render(<CategoryPage {...props} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Clear inventory filters' }),
        );
        expect(navigation.get).toHaveBeenCalledWith(
            '/security-devices/network-it',
            { tab: 'traffic-capacity', device_id: '41' },
            { preserveState: true },
        );
    });
});
