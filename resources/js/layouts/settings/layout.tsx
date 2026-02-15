import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { cn, isSameUrl, resolveUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { show } from '@/routes/two-factor';
import { edit as editPassword } from '@/routes/user-password';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: edit(),
        icon: null,
    },
    {
        title: 'Password',
        href: editPassword(),
        icon: null,
    },
    {
        title: 'Two-Factor Auth',
        href: show(),
        icon: null,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: null,
    },
    {
        title: 'Notifications',
        href: '/settings/notifications',
        icon: null,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    // When server-side rendering, we only render the layout on the client...
    if (typeof window === 'undefined') {
        return null;
    }

    const currentPath = window.location.pathname;
    const { auth } = usePage().props as any;
    const can = auth?.can;

    const extraItems: NavItem[] = [];
    if (can?.settings?.manageTerminology) {
        extraItems.push({
            title: 'Terminology',
            href: '/settings/terminology',
            icon: null,
        });
    }
    if (can?.settings?.manageBranding) {
        extraItems.push({
            title: 'Branding',
            href: '/settings/branding',
            icon: null,
        });
    }
    if (can?.settings?.manageServiceContexts) {
        extraItems.push({
            title: 'Service contexts',
            href: '/settings/service-contexts',
            icon: null,
        });
    }
    if (can?.settings?.manageAccess) {
        extraItems.push({
            title: 'Notification defaults',
            href: '/settings/notifications/roles',
            icon: null,
        });
        extraItems.push({
            title: 'Notification escalations',
            href: '/settings/notifications/escalations',
            icon: null,
        });
    }
    if (can?.integrations?.view) {
        extraItems.push({
            title: 'Integrations',
            href: '/settings/integrations',
            icon: null,
        });
    }

    const allItems = [...sidebarNavItems, ...extraItems];

    return (
        <div className="px-4 py-6">
            <Heading
                title="Settings"
                description="Manage your profile and account settings"
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full lg:w-48">
                    <nav className="flex flex-col space-y-1 space-x-0">
                        {allItems.map((item, index) => (
                            <Button
                                key={`${resolveUrl(item.href)}-${index}`}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted': isSameUrl(
                                        currentPath,
                                        item.href,
                                    ),
                                })}
                            >
                                <Link href={item.href}>
                                    {item.icon && (
                                        <item.icon className="h-4 w-4" />
                                    )}
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="space-y-12">{children}</section>
                </div>
            </div>
        </div>
    );
}
