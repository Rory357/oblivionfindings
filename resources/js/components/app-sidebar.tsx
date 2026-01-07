import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    CalendarDays,
    ClipboardList,
    FileText,
    Folder,
    LayoutGrid,
    Settings,
    Users,
} from 'lucide-react';
import AppLogo from './app-logo';

type Role = 'admin' | 'provider_manager' | 'support_worker';

type PageProps = {
    auth: {
        user: null | {
            name: string;
            email: string;
            role?: Role | null;
            organization_id?: number | null;
        };
    };
};

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

function buildMainNav(role?: Role | null): NavItem[] {
    const items: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    // Support Worker nav
    if (role === 'support_worker') {
        items.push(
            {
                title: 'My Shifts',
                href: '/my-shifts',
                icon: CalendarDays,
            },
            {
                title: 'Clients',
                href: '/clients',
                icon: Users,
            },
            {
                title: 'Notes',
                href: '/notes',
                icon: FileText,
            },
        );
        return items;
    }

    // Provider Manager / Admin nav (default)
    items.push(
        {
            title: 'Clients',
            href: '/clients',
            icon: Users,
        },
        {
            title: 'Shifts',
            href: '/shifts',
            icon: CalendarDays,
        },
        {
            title: 'Staff',
            href: '/staff',
            icon: ClipboardList,
        },
        {
            title: 'Reports',
            href: '/reports',
            icon: FileText,
        },
        {
            title: 'Rostering',
            href: '/rostering',
            icon: Settings,
        },
        {
            title: 'Fleet Management',
            href: '/fleet-management',
            icon: Settings,
        },
        {
            title: 'Calendar',
            href: '/calendar',
            icon: CalendarDays,
        },
        {
            title: 'Settings',
            href: '/settings',
            icon: Settings,
        },
    );

    return items;
}

export function AppSidebar() {
    const { auth } = usePage<PageProps>().props;
    const role = auth.user?.role ?? null;

    const mainNavItems = buildMainNav(role);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
