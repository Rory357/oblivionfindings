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
    MapPin,
    MessageSquareText,
    Settings,
    Shield,
    Users,
} from 'lucide-react';
import AppLogo from './app-logo';

type PageProps = {
    auth: {
        user: null | {
            name: string;
            email: string;
            role?: string | null;
            organization_id?: number | null;
        };
        can?: any;
    };
    labels?: Record<string, string>;
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

function buildMainNav({ role, can, labels }: { role?: string | null; can?: any; labels: Record<string, string> }): NavItem[] {
    const items: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    const clientPlural = labels['client.plural'] ?? 'Clients';
    const sitePlural = labels['site.plural'] ?? 'Sites';
    const staffPlural = labels['staff.plural'] ?? 'Staff';
    const shiftPlural = labels['shift.plural'] ?? 'Shifts';
    const timesheetPlural = labels['timesheet.plural'] ?? 'Timesheets';

    // Support Worker nav (kept for now, but also gate via permissions)
    if (role === 'support_worker') {
        items.push(
            {
                title: 'My Shifts',
                href: '/shifts',
                icon: CalendarDays,
            },
            {
                title: timesheetPlural,
                href: '/timesheets',
                icon: ClipboardList,
            },
            {
                title: clientPlural,
                href: '/clients',
                icon: Users,
            },
            {
                title: 'Notes',
                href: '/notes',
                icon: FileText,
            },
            {
                title: 'Timeline',
                href: '/timeline',
                icon: MessageSquareText,
            },
            ...(can?.medications?.view
                ? [{ title: 'Medications', href: '/medications', icon: ClipboardList }]
                : []),
            ...(can?.medications?.breakGlass
                ? [{ title: 'Emergency Access', href: '/emergency-access', icon: Shield }]
                : []),
            ...(can?.incidents?.viewAssigned
                ? [{ title: 'Incidents', href: '/incidents', icon: FileText }]
                : []),
        );
        return items;
    }

    // Provider/Manager/Admin nav (permission gated)
    if (can?.sites?.viewAny) {
        items.push({ title: sitePlural, href: '/sites', icon: MapPin });
    }
    if (can?.clients?.viewAny) {
        items.push({ title: clientPlural, href: '/clients', icon: Users });
    }
    if (can?.medications?.view) {
        items.push({ title: 'Medications', href: '/medications', icon: ClipboardList });
    }
    if (can?.medications?.breakGlass) {
        items.push({ title: 'Emergency Access', href: '/emergency-access', icon: Shield });
    }
    if (can?.shifts?.viewAny) {
        items.push({ title: shiftPlural, href: '/shifts', icon: CalendarDays });
    }
    if (can?.timesheets?.viewAny || can?.timesheets?.viewAssigned) {
        items.push({ title: timesheetPlural, href: '/timesheets', icon: ClipboardList });
    }
    if (can?.timesheets?.approve || can?.timesheets?.manageAny) {
        items.push({ title: 'Timesheet Approvals', href: '/timesheets/approvals', icon: ClipboardList });
    }
    if (can?.staff?.viewAny) {
        items.push({ title: staffPlural, href: '/staff', icon: ClipboardList });
    }
    if (can?.reports?.viewAny) {
        items.push({ title: 'Reports', href: '/reports', icon: FileText });
    }
    if (can?.medications?.view) {
        items.push({ title: 'Medications', href: '/medications', icon: ClipboardList });
    }
    if (can?.rostering?.viewAny) {
        items.push({ title: 'Rostering', href: '/rostering', icon: Settings });
    }
    if (can?.fleet?.viewAny) {
        items.push({ title: 'Fleet Management', href: '/fleet-management', icon: Settings });
    }
    if (can?.calendar?.viewAny) {
        items.push({ title: 'Calendar', href: '/calendar', icon: CalendarDays });
    }
    if (can?.timeline?.viewAny) {
        items.push({ title: 'Timeline', href: '/timeline', icon: MessageSquareText });
    }
    if (can?.summaries?.viewAny) {
        items.push({ title: 'Summaries', href: '/summaries', icon: FileText });
    }
    if (can?.incidents?.viewAny) {
        items.push({ title: 'Incidents', href: '/incidents', icon: FileText });
    }
    if (can?.audit?.viewAny) {
        items.push({ title: 'Audit Logs', href: '/audit-logs', icon: Shield });
    }
    if (can?.unifi?.manage) {
        items.push({ title: 'UniFi', href: '/integrations/unifi', icon: Settings });
    }
    items.push({ title: 'Settings', href: '/settings', icon: Settings });

    return items;
}

export function AppSidebar() {
    const { auth } = usePage<PageProps>().props;
    const { labels } = usePage<PageProps>().props;
    const role = auth.user?.role ?? null;
    const can = auth?.can;

    const mainNavItems = buildMainNav({ role, can, labels: labels ?? {} });

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
