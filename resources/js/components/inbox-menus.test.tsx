import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';

import InboxMenus from './inbox-menus';

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
    router: {
        post: vi.fn(),
        reload: vi.fn(),
    },
    usePage: () => ({
        props: {
            inbox: {
                notifications: {
                    unread_count: 2,
                    items: [],
                },
                announcements: {
                    unread_count: 0,
                    items: [],
                },
            },
        },
    }),
}));

describe('InboxMenus', () => {
    it('exposes a labelled mark-all-read action for notifications', () => {
        render(<InboxMenus />);

        fireEvent.pointerDown(
            screen.getByRole('button', { name: 'Notifications' }),
        );

        expect(
            screen.getByRole('button', { name: 'Mark all notifications read' }),
        ).toHaveAttribute('aria-label', 'Mark all notifications read');
    });
});
