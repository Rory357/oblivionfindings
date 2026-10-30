import { router } from '@inertiajs/react';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import ShiftReports from './Shifts';

vi.mock('@inertiajs/react', () => ({
    router: { get: vi.fn() },
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    usePage: () => ({ props: { auth: { can: {} } } }),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

it('applies a custom calendar period while retaining the selected site and staff', () => {
    render(
        <ShiftReports
            filters={{
                date_from: '2026-04-02',
                date_to: '2026-04-29',
                site_id: 17,
                staff_id: 29,
            }}
            sites={[{ id: 17, name: 'Preview house' }]}
            staff={[{ id: 29, name: 'Preview worker' }]}
            export_url="/operations/reports/shifts/export"
            report={{}}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Custom dates' }));
    const period = screen.getByRole('dialog', { name: 'Custom report period' });
    fireEvent.click(
        within(period).getByRole('button', { name: 'Wed 1 April 2026' }),
    );
    expect(
        within(period).getByRole('button', { name: 'Apply period' }),
    ).toBeDisabled();
    fireEvent.click(
        within(period).getByRole('button', { name: 'Thu 30 April 2026' }),
    );
    fireEvent.click(
        within(period).getByRole('button', { name: 'Apply period' }),
    );
    expect(router.get).toHaveBeenCalledOnce();
    expect(router.get).toHaveBeenCalledWith(
        '/operations/reports/shifts',
        {
            date_from: '2026-04-01',
            date_to: '2026-04-30',
            site_id: '17',
            staff_id: '29',
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
});
