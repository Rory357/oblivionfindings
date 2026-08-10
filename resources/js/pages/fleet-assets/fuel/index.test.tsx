import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock('@/components/page-shell', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock('@/components/fleet-charts', () => ({
    FLEET_COLORS: { primary: 'currentColor' },
    HorizontalBarChart: () => null,
}));
vi.mock('@inertiajs/react', async () => {
    const ReactActual = await vi.importActual<typeof import('react')>('react');

    return {
        Head: () => null,
        Link: ({
            href,
            children,
        }: {
            href: string;
            children: React.ReactNode;
        }) => <a href={href}>{children}</a>,
        router: { get: vi.fn() },
        useForm: (initial: Record<string, unknown>) => {
            const [data, setDataState] = ReactActual.useState({
                ...initial,
                asset_id: '7',
                logged_at: '2026-07-13',
                quantity_litres: '42.5',
                total_cost: '118.40',
            });

            return {
                data,
                setData: (key: string, value: unknown) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                errors: {},
                processing: false,
                post: vi.fn(),
                reset: vi.fn(),
            };
        },
    };
});

import FuelIndex from './index';

describe('Log fuel workflow', () => {
    it('opens an accessible wizard, reviews the purchase, and cancels safely', () => {
        render(
            <FuelIndex
                fuel_logs={{
                    data: [],
                    links: [],
                    meta: { current_page: 1, last_page: 1, total: 0 },
                }}
                vehicles={[{ id: 7, name: 'Community van' }]}
                filters={{}}
                summary={{
                    total_fill_ups: 0,
                    total_litres: 0,
                    total_cost: 0,
                    avg_cost_per_litre: 0,
                    best_efficiency: null,
                    worst_efficiency: null,
                }}
                efficiency={[]}
                can={{ log_fuel: true }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Log fuel' }));

        expect(
            screen.getByRole('dialog', { name: 'Log fuel purchase' }),
        ).toHaveAccessibleDescription(
            'Record a Fleet fuel purchase and review it before saving.',
        );
        expect(screen.getByLabelText('Vehicle *')).toBeVisible();
        expect(screen.getByLabelText('Litres *')).toBeVisible();

        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByText('Community van')).toBeVisible();
        expect(screen.getByText('42.5 L')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Save fuel log' }),
        ).toBeVisible();

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            screen.queryByRole('dialog', { name: 'Log fuel purchase' }),
        ).toBeNull();
    });
});
