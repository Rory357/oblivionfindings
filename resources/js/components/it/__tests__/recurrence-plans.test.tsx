import {
    describeCron,
    ItRecurrencePlans,
    type RecurrencePlanRow,
} from '@/components/it/it-recurrence-plans';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    patch: vi.fn(),
    routerPost: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: { post: inertia.routerPost },
    useForm: (initial: Record<string, unknown>) => ({
        data: { ...initial },
        setData: vi.fn(),
        transform: vi.fn(),
        post: inertia.post,
        patch: inertia.patch,
        processing: false,
        errors: {},
    }),
}));

const plan: RecurrencePlanRow = {
    id: 3,
    name: 'Monthly printer service',
    cron_expression: '0 9 1 * *',
    timezone: 'Pacific/Auckland',
    starts_on: '2026-01-01',
    ends_on: null,
    exception_dates: [],
    owner: { id: 9, name: 'Ari Tech' },
    ticket_template: {
        title: 'Service the office printers',
        site_id: 4,
        category: 'hardware',
        work_type: 'task',
        priority: 'normal',
    },
    status: 'active',
    next_due_at: '2026-10-01T09:00:00+13:00',
    run_count: 8,
    lock_version: 5,
};

const registerProps = {
    total: 1,
    layout: 'cards' as const,
    creating: false,
    onCreatingChange: vi.fn(),
    sites: [{ id: 4, name: 'Kauri House' }],
    services: [],
    agents: [{ id: 9, name: 'Ari Tech' }],
};

describe('describeCron', () => {
    it('summarises the supported schedule shapes in plain language', () => {
        expect(describeCron('0 9 * * *')).toBe('Daily at 09:00');
        expect(describeCron('30 7 * * 1')).toBe('Every Monday at 07:30');
        expect(describeCron('0 9 1 * *')).toBe('Monthly on day 1 at 09:00');
        expect(describeCron('*/15 * * * *')).toBe('*/15 * * * *');
    });
});

describe('ItRecurrencePlans', () => {
    it('lists plans on the shared register with schedule, ownership and row actions', () => {
        render(<ItRecurrencePlans {...registerProps} plans={[plan]} />);

        expect(screen.getByText('1 of 1 shown')).toBeVisible();
        expect(screen.getByText('Monthly printer service')).toBeVisible();
        expect(
            screen.getByText(/Monthly on day 1 at 09:00 · next/),
        ).toBeVisible();
        expect(screen.getByText('8 runs')).toBeVisible();
        expect(screen.getByText('Ari Tech')).toBeVisible();
        expect(
            screen.getByRole('button', {
                name: 'Actions for Monthly printer service',
            }),
        ).toBeVisible();
    });

    it('honours the table layout with the same actions', () => {
        render(
            <ItRecurrencePlans
                {...registerProps}
                layout="table"
                plans={[plan]}
            />,
        );

        expect(screen.getByRole('table')).toBeVisible();
        expect(
            screen.getByRole('button', {
                name: 'Actions for Monthly printer service',
            }),
        ).toBeVisible();
    });

    it('pauses with the current version and retires only through the governed confirmation', () => {
        render(<ItRecurrencePlans {...registerProps} plans={[plan]} />);

        fireEvent.contextMenu(screen.getByText('Monthly printer service'));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Pause' }));
        expect(inertia.routerPost).toHaveBeenCalledWith(
            '/it/setup/recurrence-plans/3/status',
            { status: 'paused', lock_version: 5 },
            { preserveScroll: true },
        );

        fireEvent.contextMenu(screen.getByText('Monthly printer service'));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Retire' }));
        expect(screen.getByText('Retire recurrence plan?')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Retire plan' }));
        expect(inertia.routerPost).toHaveBeenCalledWith(
            '/it/setup/recurrence-plans/3/status',
            { status: 'retired', lock_version: 5 },
            { preserveScroll: true },
        );
    });

    it('never offers lifecycle actions on a retired plan', () => {
        render(
            <ItRecurrencePlans
                {...registerProps}
                plans={[{ ...plan, status: 'retired', next_due_at: null }]}
            />,
        );

        expect(screen.getByText('Retired')).toBeVisible();
        expect(
            screen.queryByRole('button', {
                name: 'Actions for Monthly printer service',
            }),
        ).not.toBeInTheDocument();
        fireEvent.contextMenu(screen.getByText('Monthly printer service'));
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    });

    it('distinguishes an empty register from an empty search', () => {
        const { rerender } = render(
            <ItRecurrencePlans {...registerProps} total={0} plans={[]} />,
        );
        expect(screen.getByText(/No recurring plans yet/)).toBeVisible();

        rerender(<ItRecurrencePlans {...registerProps} plans={[]} />);
        expect(screen.getByText('0 of 1 shown')).toBeVisible();
        expect(screen.getByText(/No matching records/)).toBeVisible();
    });
});
