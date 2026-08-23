import { cleanup, render, screen, within } from '@testing-library/react';
import { Users } from 'lucide-react';
import type React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { StaffHeader } from '@/components/staff-header';

vi.mock('@inertiajs/react', () => ({
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
}));

afterEach(() => cleanup());

describe('StaffHeader responsive actions', () => {
    it('wraps the extended action group in keyboard order on narrow screens', () => {
        render(
            <StaffHeader
                title="Today"
                subtitle="Sunday, 23 August 2026"
                onTitleClick={vi.fn()}
                globalLinks={[
                    { icon: Users, label: 'Clients', href: '/clients' },
                ]}
                search={{ placeholder: 'Search staff tools' }}
                action={<button type="button">Report incident</button>}
                liveIndicator={{
                    lastUpdatedAt: null,
                    onRefresh: vi.fn(),
                }}
                notifications={{ count: 3, href: '/notifications' }}
            />,
        );

        const group = screen
            .getByRole('button', { name: 'Report incident' })
            .closest('[data-staff-header-actions]');
        expect(group).not.toBeNull();
        expect(group).toHaveClass(
            'basis-full',
            'w-full',
            'shrink-0',
            'lg:basis-auto',
            '[&>button]:min-h-11',
            '[&>button]:min-w-11',
        );
        expect(group?.closest('header')).toHaveClass(
            'flex-wrap',
            'lg:flex-nowrap',
        );

        expect(screen.getByText('Sunday, 23 August 2026')).toHaveClass(
            'whitespace-normal',
            'lg:truncate',
        );
        expect(
            screen.getByRole('button', {
                name: /^Today Sunday, 23 August 2026$/,
            }),
        ).toHaveClass('frontline-focus', 'min-h-11');
        expect(screen.getByRole('link', { name: 'Clients' })).toHaveClass(
            'frontline-focus',
            'h-11',
            'w-11',
        );
        const searchbox = screen.getByRole('searchbox', {
            name: 'Search staff tools',
        });
        expect(searchbox).toHaveClass('h-full');
        expect(searchbox.parentElement).toHaveClass(
            'h-11',
            'focus-within:ring-2',
        );
        expect(screen.getByRole('button', { name: 'Refresh now' })).toHaveClass(
            'frontline-focus',
            'frontline-tap',
        );
        expect(
            screen.getByRole('link', { name: 'Notifications (3)' }),
        ).toHaveClass('frontline-focus', 'h-11', 'w-11');

        const controls = within(group as HTMLElement).getAllByRole(
            /button|link/,
        );
        expect(
            controls.map(
                (control) =>
                    control.getAttribute('aria-label') ?? control.textContent,
            ),
        ).toEqual(['Report incident', 'Refresh now', 'Notifications (3)']);
    });

    it('keeps the original one-row action group for compact callers', () => {
        render(
            <StaffHeader
                title="My roster"
                subtitle="This week"
                action={<button type="button">Add availability</button>}
            />,
        );

        const group = screen
            .getByRole('button', { name: 'Add availability' })
            .closest('[data-staff-header-actions]');
        expect(group).not.toBeNull();
        expect(group).toHaveClass('ml-auto', 'shrink-0');
        expect(group).not.toHaveClass('basis-full', 'w-full');
    });
});
