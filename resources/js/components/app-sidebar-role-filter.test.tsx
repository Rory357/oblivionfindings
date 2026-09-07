import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { AppHeader } from './app-header';
import { filterVisibleSidebarGroups } from './app-sidebar';

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        Link: React.forwardRef<
            HTMLAnchorElement,
            React.AnchorHTMLAttributes<HTMLAnchorElement> & { href: string }
        >(({ href, children, ...props }, ref) => (
            <a ref={ref} href={href} {...props}>
                {children}
            </a>
        )),
        usePage: () => ({
            url: '/tasks?bucket=in_progress',
            props: {
                auth: {
                    user: {
                        id: 7,
                        name: 'Novice Worker',
                        email: 'worker@demo.test',
                        role: 'Support Worker',
                    },
                    can: {},
                    portalClients: [],
                    unreadMessageCount: 0,
                },
                branding: { name: 'Oblivion Findings', logoUrl: null },
                sidebarOpen: true,
            },
        }),
    };
});

vi.mock('@/components/user-menu-content', () => ({
    UserMenuContent: () => <div role="menuitem">User actions</div>,
}));

vi.mock('@/hooks/use-initials', () => ({
    useInitials: () => () => 'NW',
}));

describe('role-filtered app sidebar', () => {
    it('removes groups that have no visible child links', () => {
        expect(
            filterVisibleSidebarGroups([
                { label: 'Restricted', items: [] },
                {
                    label: 'Available',
                    items: [{ title: 'My queue', href: '/tasks' }],
                },
            ]),
        ).toEqual([
            {
                label: 'Available',
                items: [{ title: 'My queue', href: '/tasks' }],
            },
        ]);
    });

    // The user's identity lives in the header avatar, not the sidebar footer
    // (Event Horizon shell — APP_SHELL_STYLE_GUIDE.md §2/§3).
    it('opens the user menu from the header avatar', async () => {
        render(<AppHeader />);

        const trigger = screen.getByRole('button', {
            name: 'Open user menu for Novice Worker',
        });
        expect(trigger).toHaveClass('focus-visible:ring-2');
        fireEvent.pointerDown(trigger, { button: 0, ctrlKey: false });
        fireEvent.click(trigger);

        expect(await screen.findByText('User actions')).toBeVisible();
    });
});
