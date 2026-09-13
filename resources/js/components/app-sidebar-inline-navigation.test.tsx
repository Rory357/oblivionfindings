import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { AppSidebar } from './app-sidebar';

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    const page = {
        url: '/security-devices/devices',
        props: {
            auth: {
                user: {
                    id: 8,
                    name: 'IT Manager',
                    email: 'it-manager@demo.test',
                    role: 'IT Manager',
                },
                can: {
                    securityDevices: {
                        viewAny: true,
                        devicesView: true,
                        groupsManage: true,
                        eventsView: true,
                        maintenanceView: true,
                        integrationsView: true,
                        monitoringManage: true,
                        reportsView: true,
                    },
                },
                portalClients: [],
                unreadMessageCount: 0,
            },
            branding: { name: 'Oblivion Findings', logoUrl: null },
            sidebarOpen: true,
        },
    };

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
        usePage: vi.fn(() => page),
    };
});

vi.mock('@/components/user-menu-content', () => ({
    UserMenuContent: () => <div role="menuitem">User actions</div>,
}));

vi.mock('@/hooks/use-initials', () => ({
    useInitials: () => () => 'IM',
}));

describe('inline application navigation', () => {
    it('discovers register-only access in IT and selects filtered credential deep links without activating Sites', async () => {
        const original = usePage<SharedData>();
        try {
            for (const [canVendors, canCredentials, currentUrl] of [
                [
                    false,
                    true,
                    '/vendors?tab=credentials&credential_id=17&site_id=3',
                ],
                [true, false, '/vendors/17?return_to=%2Fit'],
                [true, true, '/vendors?tab=credentials&site_id=3'],
            ] as const) {
                vi.mocked(usePage).mockReturnValue({
                    ...original,
                    url: currentUrl,
                    props: {
                        ...original.props,
                        auth: {
                            ...original.props.auth,
                            can: {
                                sites: { viewAny: true },
                                vendors: { view: canVendors },
                                credentials: { view: canCredentials },
                            },
                        },
                    },
                } as never);
                const view = render(<AppSidebar collapsed={false} />);
                const trigger = screen.getByRole('button', {
                    name: 'IT & Support menu',
                });
                await waitFor(() =>
                    expect(trigger).toHaveAttribute('aria-expanded', 'true'),
                );
                const group = within(
                    screen.getByRole('group', {
                        name: 'IT & Support navigation',
                    }),
                );
                const registerLink = group.getByRole('link', {
                    name: 'Vendors & Credentials',
                });
                expect(registerLink).toHaveAttribute('aria-current', 'page');
                expect(registerLink).toHaveAttribute(
                    'href',
                    canVendors
                        ? '/vendors?tab=vendors'
                        : '/vendors?tab=credentials',
                );
                expect(group.getAllByRole('link')).toHaveLength(1);
                expect(
                    group.queryByRole('link', {
                        name: /Knowledge|Guides|Service desk/,
                    }),
                ).toBeNull();
                expect(
                    screen.getByRole('button', {
                        name: 'Sites & Locations menu',
                    }),
                ).toHaveAttribute('aria-expanded', 'false');
                view.unmount();
            }
        } finally {
            vi.mocked(usePage).mockReturnValue(original);
        }
    });
    it('keeps the active Security & Devices tree inside the expanded primary sidebar', async () => {
        render(<AppSidebar collapsed={false} />);

        const trigger = screen.getByRole('button', {
            name: 'Security & Devices menu',
        });

        await waitFor(() =>
            expect(trigger).toHaveAttribute('aria-expanded', 'true'),
        );

        const navigation = screen.getByRole('group', {
            name: 'Security & Devices navigation',
        });
        expect(
            within(navigation).getByRole('link', { name: 'All devices' }),
        ).toHaveAttribute('aria-current', 'page');
        expect(
            within(navigation).getByRole('link', { name: 'Network & IT' }),
        ).toHaveAttribute('href', '/security-devices/network-it');
        expect(
            screen.queryByRole('button', { name: 'Close menu' }),
        ).not.toBeInTheDocument();

        fireEvent.click(trigger);

        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        expect(
            screen.queryByRole('group', {
                name: 'Security & Devices navigation',
            }),
        ).not.toBeInTheDocument();
    });

    it('uses the flyout only as a fallback for the collapsed icon rail', () => {
        render(<AppSidebar collapsed />);

        const trigger = screen.getByRole('button', {
            name: 'Security & Devices menu',
        });
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        expect(
            screen.queryByRole('group', {
                name: 'Security & Devices navigation',
            }),
        ).not.toBeInTheDocument();

        fireEvent.click(trigger);

        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        expect(
            screen.getByRole('button', { name: 'Close menu' }),
        ).toBeVisible();
    });
});
