import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import StockHub from './StockHub';
import { stockMeters } from './__fixtures__/meters';
import type { StockCount } from './_types';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    usePage: () => ({
        url: '/emar/stock/packs?view=counts',
        props: { auth: { can: { medications: {} } } },
    }),
    router: { get: vi.fn(), reload: vi.fn(), visit: vi.fn() },
}));
vi.mock('./_dialogs', async (original) => ({
    ...(await original<typeof import('./_dialogs')>()),
    CountReview: ({ record }: { record: StockCount }) => (
        <div>Signing off count #{record.id}</div>
    ),
}));

afterEach(cleanup);

const count = (overrides: Partial<StockCount>): StockCount => ({
    id: 11,
    medication_id: 7,
    medication_name: 'Sertraline 50 mg tablet',
    client_name: 'Grace Liu',
    state: 'needs_review',
    counted_at: '2026-10-20T18:40:00Z',
    counted_by_name: 'Priya Shah',
    reason: 'Dropped or lost — two dropped at breakfast',
    review_reason: null,
    lines: [],
    counted_total: '26',
    expected_total: '28',
    reviewed_at: null,
    reviewed_by_name: null,
    ...overrides,
});
const empty = { data: [], total: 0, links: [], last_page: 1 };
const hub = (manage: boolean) =>
    render(
        <StockHub
            items={empty}
            orders={empty}
            counts={{
                data: [
                    count({}),
                    count({
                        id: 10,
                        medication_name: 'Metformin 500 mg tablet',
                        client_name: 'Aroha Ngata',
                        state: 'counted',
                        counted_total: '68',
                        expected_total: '68',
                        reason: null,
                    }),
                    count({
                        id: 9,
                        medication_name: 'Losartan 50 mg tablet',
                        client_name: 'Tama Walker',
                        state: 'reviewed',
                        reviewed_by_name: 'Jordan Tipene',
                        reviewed_at: '2026-10-19T21:00:00Z',
                        review_reason: 'Stock set to the count',
                    }),
                ],
                total: 3,
                links: [],
                last_page: 1,
            }}
            movements={empty}
            sites={[{ id: 1, name: 'Kōwhai House' }]}
            pharmacies={[]}
            filters={{ view: 'counts', search: '', site_id: null }}
            metrics={stockMeters({ counts: 1 })}
            can={{ manage, receive: true, controlled: true }}
            lots_enabled
        />,
    );
const section = (title: string) => screen.getByRole('region', { name: title });

// The approved P06 Counts view (1440-14).
describe('Stock hub › Counts (P06)', () => {
    it('puts differences to sign off first, then the recent counts', () => {
        hub(true);
        expect(
            screen
                .getAllByRole('heading', { level: 2 })
                .map((heading) => heading.textContent),
        ).toEqual(['Differences to sign off', 'Recent counts']);
        const waiting = section('Differences to sign off');
        expect(
            within(waiting).getByText(
                '1 shown · the house lead signs off every difference',
            ),
        ).toBeInTheDocument();
        expect(
            within(waiting).getByText('— counted 26, expected 28', {
                exact: false,
            }),
        ).toBeInTheDocument();
        expect(
            within(waiting).getByText(
                'Dropped or lost — two dropped at breakfast',
            ),
        ).toBeInTheDocument();
        expect(
            within(section('Recent counts')).getAllByRole('row'),
        ).toHaveLength(3);
    });

    it('says how each recent count ended', () => {
        hub(true);
        const recent = section('Recent counts');
        expect(within(recent).getByText('All matched')).toBeInTheDocument();
        expect(within(recent).getByText('Signed off')).toBeInTheDocument();
        expect(
            within(recent).getByText(
                /^Jordan Tipene .+ — Stock set to the count$/,
            ),
        ).toBeInTheDocument();
    });

    it('lets the house lead sign off, and tells others who does', async () => {
        hub(true);
        fireEvent.click(
            within(section('Differences to sign off')).getByRole('button', {
                name: 'Sign off',
            }),
        );
        expect(
            await screen.findByText('Signing off count #11'),
        ).toBeInTheDocument();
        cleanup();
        hub(false);
        expect(
            within(section('Differences to sign off')).getByText(
                'The house lead signs it off',
            ),
        ).toBeInTheDocument();
    });
});
