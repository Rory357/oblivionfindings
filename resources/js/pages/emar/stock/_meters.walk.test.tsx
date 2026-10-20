import { router } from '@inertiajs/react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes, ComponentProps, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import StockHub from './StockHub';

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

type Metrics = ComponentProps<typeof StockHub>['metrics'];
const empty = { data: [], total: 0, links: [], last_page: 1 };
const metrics: Metrics = {
    tracked: 18,
    out: 1,
    expiring: 3,
    orders: 6,
    counts: 1,
    running_low: 2,
    running_low_first: 'Losartan',
    expired: 1,
    to_receive: 3,
    to_receive_due: 2,
    to_receive_part: 1,
    pharmacy_orders: 3,
    drafts: 1,
    people: 7,
    controlled_hidden: 0,
};
const hub = (overrides: Partial<Metrics> = {}, controlledLeftOut = false) =>
    render(
        <StockHub
            items={empty}
            orders={empty}
            counts={empty}
            movements={empty}
            sites={[{ id: 1, name: 'Kōwhai House' }]}
            pharmacies={[]}
            filters={{ view: 'stock', search: '', site_id: null }}
            metrics={{ ...metrics, ...overrides }}
            controlled_left_out={controlledLeftOut}
            can={{ manage: true, receive: true, controlled: true }}
            lots_enabled
        />,
    );
const meter = (label: string) =>
    screen.getByRole('button', { name: `View ${label.toLowerCase()}` });

// The approved P06 meter row (1440-06-stock-manager-two-houses, README §2).
describe('Stock hub meters (P06)', () => {
    it('shows the six approved meters in order with their captions', () => {
        hub();
        const labels = [
            'Running low',
            'Expiring',
            'To receive',
            'Counts to sign off',
            'Pharmacy orders',
            'Medicines in stock',
        ];
        const buttons = labels.map(meter);
        buttons.forEach((button) => expect(button).not.toBeNull());
        const positions = buttons.map((button) =>
            Array.from(document.querySelectorAll('button')).indexOf(button),
        );
        expect(positions).toEqual([...positions].sort((a, b) => a - b));
        expect(meter('Running low')).toHaveTextContent('2');
        expect(meter('Running low')).toHaveTextContent('First: Losartan');
        expect(meter('Expiring')).toHaveTextContent('1 expired · remove');
        expect(meter('To receive')).toHaveTextContent('2 due by today');
        expect(meter('Counts to sign off')).toHaveTextContent(
            'The house lead signs them off',
        );
        expect(meter('Pharmacy orders')).toHaveTextContent('1 draft not sent');
        expect(meter('Medicines in stock')).toHaveTextContent('18');
        expect(meter('Medicines in stock')).toHaveTextContent('7 people');
        expect(screen.queryByText('Tracked')).toBeNull();
        expect(screen.queryByText('Out of stock')).toBeNull();
    });

    it('says when nothing is waiting', () => {
        hub({
            running_low: 0,
            running_low_first: null,
            expiring: 0,
            expired: 0,
            to_receive: 0,
            to_receive_due: 0,
            to_receive_part: 0,
            counts: 0,
            pharmacy_orders: 0,
            drafts: 0,
            people: 1,
        });
        expect(meter('Running low')).toHaveTextContent('Nothing low');
        expect(meter('Expiring')).toHaveTextContent('None within 30 days');
        expect(meter('To receive')).toHaveTextContent('Nothing due');
        expect(meter('Counts to sign off')).toHaveTextContent('None waiting');
        expect(meter('Pharmacy orders')).toHaveTextContent('None open');
        expect(meter('Medicines in stock')).toHaveTextContent('1 person');
    });

    it('opens each meter’s view', () => {
        hub();
        fireEvent.click(meter('Running low'));
        expect(router.get).toHaveBeenLastCalledWith(
            '/emar/stock/packs',
            expect.objectContaining({ view: 'stock', show: 'low' }),
            expect.anything(),
        );
        fireEvent.click(meter('Pharmacy orders'));
        expect(router.get).toHaveBeenLastCalledWith(
            '/emar/stock/packs',
            expect.objectContaining({ view: 'orders' }),
            expect.anything(),
        );
    });

    it('counts controlled medicines it leaves out, and says so as on the MAR board', () => {
        hub({ controlled_hidden: 2 }, true);
        expect(meter('Medicines in stock')).toHaveTextContent(
            '+2 controlled hidden',
        );
        expect(
            screen.getByText(
                'Controlled medicines are left out of these counts and this list. The counts cover visible medicines.',
            ),
        ).toBeInTheDocument();
    });

    it('puts to-receive and sign-off counts on the rail, hiding zeros', () => {
        hub({ to_receive: 3, counts: 0 });
        const rail = screen.getByRole('tab', { name: /Deliveries & orders/ });
        expect(rail).toHaveTextContent('3');
        expect(screen.getByRole('tab', { name: 'Counts' })).toBeInTheDocument();
    });
});
