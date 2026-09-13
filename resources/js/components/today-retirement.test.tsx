import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    AppSidebar,
    AppSidebarMobile,
    buildNavSearchCatalog,
} from './app-sidebar';
import { Sheet } from './ui/sheet';

const currentUser = vi.hoisted(() => ({
    role: 'support_worker',
    can: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        Link: React.forwardRef<
            HTMLAnchorElement,
            React.AnchorHTMLAttributes<HTMLAnchorElement> & {
                href: string;
                prefetch?: boolean;
                preserveScroll?: boolean;
            }
        >(({ href, children, prefetch, preserveScroll, ...props }, ref) => (
            <a ref={ref} href={href} {...props}>
                {children}
            </a>
        )),
        usePage: () => ({
            url: '/my-day',
            props: {
                auth: {
                    user: { id: 7, name: 'Test User', role: currentUser.role },
                    can: currentUser.can,
                    portalClients: [],
                    unreadMessageCount: 0,
                },
                branding: { name: 'Oblivion Findings', logoUrl: null },
                sidebarOpen: true,
            },
        }),
    };
});

const roles: {
    role: string;
    can: Record<string, unknown>;
    overview: boolean;
}[] = [
    { role: 'support_worker', can: {}, overview: false },
    {
        role: 'provider_manager',
        can: { shifts: { manageAny: true } },
        overview: true,
    },
    { role: 'admin', can: { timesheets: { manageAny: true } }, overview: true },
    {
        role: 'hr_admin',
        can: { hr: { analytics: { view: true } } },
        overview: true,
    },
];

describe.each(roles)(
    'Today retirement for $role',
    ({ role, can, overview }) => {
        it.each(['desktop', 'mobile'])(
            'keeps Today out of %s navigation and search',
            (surface) => {
                Object.assign(currentUser, { role, can });
                render(
                    surface === 'desktop' ? (
                        <AppSidebar collapsed={false} />
                    ) : (
                        <Sheet open>
                            <AppSidebarMobile onClose={() => undefined} />
                        </Sheet>
                    ),
                );

                expect(
                    screen.getByRole('link', { name: 'My Day', exact: true }),
                ).toHaveAttribute('href', '/my-day');
                expect(
                    screen.queryByRole('link', { name: 'Today', exact: true }),
                ).not.toBeInTheDocument();
                expect(document.querySelector('a[href="/today"]')).toBeNull();
                expect(
                    !!screen.queryByRole('link', {
                        name: 'Overview',
                        exact: true,
                    }),
                ).toBe(overview);

                const catalog = buildNavSearchCatalog({ role, can });
                expect(catalog.some((item) => item.href === '/my-day')).toBe(
                    true,
                );
                expect(
                    catalog.some(
                        (item) =>
                            item.href === '/today' || item.label === 'Today',
                    ),
                ).toBe(false);
                expect(catalog.some((item) => item.href === '/dashboard')).toBe(
                    overview,
                );
            },
        );
    },
);
