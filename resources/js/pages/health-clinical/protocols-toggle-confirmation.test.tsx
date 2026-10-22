import { fireEvent, render, screen, within } from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Protocols from './Protocols';

// Activate / Deactivate must ask first: nothing reaches the server until the
// confirm button, and the request is the same toggle-active PATCH as before.

type VisitOptions = { onStart?: () => void; onFinish?: () => void };

const router = vi.hoisted(() => ({
    get: vi.fn(),
    patch: vi.fn((_url: string, _data: unknown, options?: VisitOptions) => {
        options?.onStart?.();
        options?.onFinish?.();
    }),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: PropsWithChildren<{ href: string }>) => (
        <a href={href}>{children}</a>
    ),
    router,
}));
vi.mock('@/pages/health-clinical/components/health-clinical-shell', () => ({
    HealthClinicalShell: ({ children }: PropsWithChildren) => <>{children}</>,
    RegisterStatStrip: () => null,
}));

const protocol = (overrides: Record<string, unknown> = {}) => ({
    id: 31,
    client_id: 4,
    name: 'Blood pressure watch',
    observation_type: 'blood_pressure',
    observation_type_label: 'Blood pressure',
    frequency: 'daily',
    frequency_label: 'Daily',
    custom_frequency_hours: null,
    instructions: null,
    alert_if_missed_hours: 4,
    is_active: true,
    starts_at: null,
    ends_at: null,
    created_at: null,
    updated_at: null,
    client: { id: 4, first_name: 'Mere', last_name: 'Tane' },
    creator: null,
    schedule_counts: { total: 0, pending: 0, overdue: 0, completed_30d: 0 },
    has_schedule_history: true,
    ...overrides,
});

const renderPage = (rows: ReturnType<typeof protocol>[]) =>
    render(
        <Protocols
            protocols={{
                data: rows,
                links: [],
                current_page: 1,
                last_page: 1,
                total: rows.length,
            }}
            stats={{
                active_protocols: 1,
                inactive_protocols: 0,
                schedules_due: 0,
                schedules_overdue: 0,
                compliance_rate_30d: 100,
            }}
            filters={{}}
            filter_options={{
                clients: [],
                observation_types: [],
                frequencies: [],
                statuses: [],
            }}
            can_manage
            kpis={{} as never}
        />,
    );

const dialog = () => screen.getByRole('alertdialog');

beforeEach(() => {
    router.patch.mockClear();
});

describe('Health & clinical › Protocols: activate / deactivate', () => {
    it('asks before deactivating, and Cancel sends nothing', () => {
        renderPage([protocol()]);

        fireEvent.click(screen.getByRole('button', { name: 'Deactivate' }));

        expect(router.patch).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Deactivate this protocol?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent('Blood pressure watch for Mere Tane');
        expect(dialog()).toHaveTextContent(
            'Upcoming checks are taken off the schedule',
        );

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(router.patch).not.toHaveBeenCalled();
    });

    it('deactivates the chosen protocol once confirmed', () => {
        renderPage([protocol()]);

        fireEvent.click(screen.getByRole('button', { name: 'Deactivate' }));
        fireEvent.click(
            within(dialog()).getByRole('button', {
                name: 'Deactivate protocol',
            }),
        );

        expect(router.patch).toHaveBeenCalledTimes(1);
        expect(router.patch).toHaveBeenCalledWith(
            '/health-clinical/protocols/31/toggle-active',
            { is_active: false, idempotency_key: expect.any(String) },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('asks before activating with a non-destructive confirm, then activates', () => {
        renderPage([protocol({ id: 32, is_active: false })]);

        fireEvent.click(screen.getByRole('button', { name: 'Activate' }));

        expect(router.patch).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Activate this protocol?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            'New checks are scheduled from now, on its usual frequency (Daily).',
        );

        fireEvent.click(
            within(dialog()).getByRole('button', {
                name: 'Activate protocol',
            }),
        );

        expect(router.patch).toHaveBeenCalledWith(
            '/health-clinical/protocols/32/toggle-active',
            { is_active: true, idempotency_key: expect.any(String) },
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
