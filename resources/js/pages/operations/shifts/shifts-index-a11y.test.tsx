import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';

import ShiftsIndex from './index';

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title?: string }) => <title>{title}</title>,
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: {
        get: vi.fn(),
        post: vi.fn(),
        visit: vi.fn(),
        delete: vi.fn(),
    },
    usePage: () => ({
        props: {
            auth: {
                user: { name: 'Sheila Worker' },
                can: { shifts: { update: true } },
            },
            labels: {},
        },
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/components/page-shell', () => ({
    default: ({ children }: { children: React.ReactNode }) => <main>{children}</main>,
}));

vi.mock('./components/create-shift-dialog', () => ({
    CreateShiftDialog: () => null,
}));

vi.mock('./components/shift-detail-dialog', () => ({
    ShiftDetailDialog: () => null,
}));

const stats = {
    total: 5,
    open: 1,
    today: 2,
    in_progress: 0,
    scheduled: 3,
    completed: 1,
    draft: 1,
    cancelled: 0,
    unassigned: 1,
    hours: 24,
    sites: 2,
    staff: 4,
};

describe('ShiftsIndex tab strip accessibility', () => {
    it('exposes shift view tabs with keyboard navigation', () => {
        render(
            <ShiftsIndex
                shifts={{ data: [] }}
                filters={{
                    from: '2026-05-25',
                    to: '2026-05-31',
                    statuses: [],
                    site_ids: [],
                    user_ids: [],
                    client_ids: [],
                    q: '',
                }}
                clients={[]}
                staff={[]}
                sites={[]}
                serviceContexts={[]}
                defaultServiceContextId={null}
                stats={stats}
                canCreate={false}
            />,
        );

        const tablist = screen.getByRole('tablist', { name: 'Shift views' });
        const tabs = screen.getAllByRole('tab');

        expect(tablist).toBeVisible();
        expect(tabs).toHaveLength(5);
        expect(tabs[0]).toHaveAttribute('aria-selected', 'true');

        fireEvent.keyDown(tabs[0], { key: 'ArrowRight' });

        expect(screen.getByRole('tab', { name: /Open/i })).toHaveAttribute(
            'aria-selected',
            'true',
        );

        fireEvent.keyDown(screen.getByRole('tab', { name: /Open/i }), {
            key: 'End',
        });

        expect(screen.getByRole('tab', { name: /Completed/i })).toHaveAttribute(
            'aria-selected',
            'true',
        );
    });
});
