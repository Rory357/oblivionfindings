import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SitesIndex, { type SitesIndexProps } from './index';

const inertia = vi.hoisted(() => ({ get: vi.fn(), visit: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
        router: { ...actual.router, ...inertia, on: () => () => undefined },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

function fixture(overrides: Partial<SitesIndexProps> = {}): SitesIndexProps {
    return {
        tab: 'all',
        filters: { q: null, region: null },
        regions: [],
        tabs: { all: 2, attention: 1, not_set_up: 1 },
        meters: {
            set_up: { count: 1, of: 2, caption: 'Synthetic Lodge has no hub yet' },
            hubs: { online: 1, of: 1, caption: 'Answering' },
            attention: { count: 1, caption: 'Synthetic Lodge' },
        },
        cards: [
            {
                id: 4,
                name: 'Synthetic House',
                address: '1 Test Street, Testville',
                region: null,
                device_count: 3,
                flag: { tone: 'success', text: 'All online' },
                facts: [
                    { tone: 'success', text: '**3 of 3** online · checked {ago}', at: new Date(Date.now() - 60_000).toISOString() },
                    { tone: 'brand', text: 'Own hub: **Synthetic House hub**' },
                ],
                needs_attention: false,
                set_up: true,
                href: '/security-devices/sites/4',
            },
            {
                id: 5,
                name: 'Synthetic Lodge',
                address: '',
                region: null,
                device_count: 1,
                flag: { tone: 'critical', text: 'Router offline' },
                facts: [{ tone: 'none', text: 'No hub watches this Site yet' }],
                needs_attention: true,
                set_up: false,
                href: '/security-devices/sites/5',
            },
        ],
        can: { add_device: true, scan: true },
        ...overrides,
    };
}

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

describe('Sites', () => {
    it('shows one card per Site with its flag, its facts in plain words and the hub that watches it', () => {
        render(<SitesIndex {...fixture()} />);

        expect(screen.getByRole('heading', { name: 'Sites' })).toBeInTheDocument();
        expect(screen.getByText('Synthetic House')).toBeInTheDocument();
        expect(screen.getByText('All online')).toBeInTheDocument();
        expect(screen.getByText('Router offline')).toBeInTheDocument();
        expect(screen.getByText('3 of 3')).toBeInTheDocument();
        expect(screen.getByText('Synthetic House hub')).toBeInTheDocument();
        expect(screen.getByText('No hub watches this Site yet')).toBeInTheDocument();
        expect(screen.queryByText(/\{ago\}|\*\*/)).not.toBeInTheDocument();
    });

    it('never offers a hub button before the Add a hub pop-up exists', () => {
        render(<SitesIndex {...fixture()} />);

        expect(screen.queryByRole('button', { name: /add a hub/i })).not.toBeInTheDocument();
    });

    it('opens the Sites without a hub from its header number', () => {
        render(<SitesIndex {...fixture()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Show Sites without a hub' }));

        expect(inertia.get).toHaveBeenCalledWith('/security-devices/sites', { tab: 'not_set_up' }, expect.anything());
    });

    it('says so in one line when nothing matches', () => {
        render(<SitesIndex {...fixture({ cards: [], tab: 'attention', tabs: { all: 0, attention: 0, not_set_up: 0 } })} />);

        expect(screen.getByText('Nothing needs attention at any Site.')).toBeInTheDocument();
    });
});
