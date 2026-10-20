import { router } from '@inertiajs/react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import StockHub from './StockHub';
import { stockMeters } from './__fixtures__/meters';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    usePage: () => ({
        url: '/emar/stock/packs',
        props: { auth: { can: { medications: {} } } },
    }),
    router: { get: vi.fn(), reload: vi.fn(), visit: vi.fn() },
}));

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

const empty = { data: [], total: 0, links: [], last_page: 1 };
const hub = (
    sites: { id: number; name: string }[],
    siteId: number | null = null,
) =>
    render(
        <StockHub
            items={empty}
            orders={empty}
            counts={empty}
            movements={empty}
            sites={sites}
            pharmacies={[]}
            filters={{ view: 'stock', search: '', site_id: siteId }}
            metrics={stockMeters()}
            can={{ manage: false, receive: false, controlled: false }}
            lots_enabled
        />,
    );

// The approved P06 header (1440-06-stock-manager-two-houses): a house-count chip,
// a subline naming the houses with the time zone, and the "As at" chip.
describe('Stock hub header (P06, 1440 walk)', () => {
    it('counts the houses and names them in the subline', () => {
        hub([
            { id: 1, name: 'Kōwhai House' },
            { id: 2, name: 'Rimu House' },
        ]);
        expect(screen.getByText('2 houses')).toBeInTheDocument();
        expect(screen.queryByText('Supply and pack records')).toBeNull();
        expect(
            screen.getByText(
                'Medicines held for people at Kōwhai House and Rimu House · times in Pacific/Auckland',
            ),
        ).toBeInTheDocument();
    });

    it('follows the House filter: one house, named', () => {
        hub(
            [
                { id: 1, name: 'Kōwhai House' },
                { id: 2, name: 'Rimu House' },
            ],
            2,
        );
        expect(screen.getByText('1 house')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Medicines held for people at Rimu House · times in Pacific/Auckland',
            ),
        ).toBeInTheDocument();
    });

    it('names one house, and shortens a long list', () => {
        hub([{ id: 1, name: 'Kōwhai House' }]);
        expect(screen.getByText('1 house')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Medicines held for people at Kōwhai House · times in Pacific/Auckland',
            ),
        ).toBeInTheDocument();
        cleanup();
        hub(
            ['Kōwhai', 'Rimu', 'Tōtara', 'Mānuka', 'Harakeke'].map(
                (name, index) => ({ id: index + 1, name }),
            ),
        );
        expect(
            screen.getByText(
                'Medicines held for people at Kōwhai, Rimu, Tōtara and 2 more · times in Pacific/Auckland',
            ),
        ).toBeInTheDocument();
    });

    it('shows when the stock was read and refreshes it from the As at chip', () => {
        hub([{ id: 1, name: 'Kōwhai House' }]);
        const asAt = screen.getByRole('button', {
            name: /^As at .+ · Pacific\/Auckland$/,
        });
        fireEvent.click(asAt);
        expect(router.reload).toHaveBeenCalledTimes(1);
    });
});
