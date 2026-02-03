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
    Lock,
    MapPin,
    MessageSquareText,
    Settings,
    Shield,
    ShieldAlert,
    Users,
    Package,
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
        {
            title: 'Today',
            href: '/today',
            icon: ClipboardList,
        },
    ];

    const clientPlural = labels['client.plural'] ?? 'Clients';
    const sitePlural = labels['site.plural'] ?? 'Sites';
    const staffPlural = labels['staff.plural'] ?? 'Staff';
    const shiftPlural = labels['shift.plural'] ?? 'Shifts';
    const timesheetPlural = labels['timesheet.plural'] ?? 'Timesheets';
    const assetPlural = labels['asset.plural'] ?? 'Assets';
    const medicationPlural = labels['medication.plural'] ?? 'Medications';
    const incidentPlural = labels['incident.plural'] ?? 'Incidents';
    const notePlural = labels['note.plural'] ?? 'Notes';
    const timelineLabel = labels['timeline.singular'] ?? 'Timeline';
    const emergencyLabel = labels['emergency_access.singular'] ?? 'Emergency Access';
    const respitePlural = labels['respite.plural'] ?? 'Respite';

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
                title: notePlural,
                href: '/notes',
                icon: FileText,
            },
            {
                title: timelineLabel,
                href: '/timeline',
                icon: MessageSquareText,
            },
            ...(can?.medications?.view
                ? [{ title: medicationPlural, href: '/medications', icon: ClipboardList }]
                : []),
            ...(can?.medications?.breakGlass
                ? [{ title: emergencyLabel, href: '/emergency-access', icon: Shield }]
                : []),
            ...(can?.assets?.viewAssigned || can?.assets?.viewAny
                ? [{ title: assetPlural, href: '/assets', icon: Package }]
                : []),
            ...(can?.assets?.alertsView
                ? [{ title: 'Asset Alerts', href: '/assets/alerts', icon: ShieldAlert }]
                : []),
            ...(can?.incidents?.viewAssigned
                ? [{ title: incidentPlural, href: '/incidents', icon: FileText }]
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
    if (can?.assets?.viewAny || can?.assets?.viewAssigned) {
        items.push({ title: assetPlural, href: '/assets', icon: Package });
    }
    if (can?.assets?.alertsView) {
        items.push({ title: 'Asset Alerts', href: '/assets/alerts', icon: ShieldAlert });
    }

    if (can?.medications?.view) {
        items.push({ title: medicationPlural, href: '/medications', icon: ClipboardList });
    }
    if (can?.medications?.breakGlass) {
        items.push({ title: emergencyLabel, href: '/emergency-access', icon: Shield });
    }
    if (can?.shifts?.viewAny) {
        items.push({ title: shiftPlural, href: '/shifts', icon: CalendarDays });
    }
    if (can?.respite?.viewAny) {
        items.push({ title: respitePlural, href: '/respite', icon: CalendarDays });
    }
    if (can?.timesheets?.viewAny || can?.timesheets?.viewAssigned) {
        items.push({ title: timesheetPlural, href: '/timesheets', icon: ClipboardList });
    }
    // Approval queue is now part of Timesheets module.
    if (can?.staff?.viewAny) {
        items.push({ title: staffPlural, href: '/staff', icon: ClipboardList });
    }
    if (can?.reports?.viewAny) {
        items.push({ title: 'Reports', href: '/reports', icon: FileText });
    }
    if (can?.rostering?.viewAny) {
        items.push({ title: 'Rostering', href: '/rostering', icon: Settings });
    }
    if (can?.fleet?.viewAny) {
        items.push({ title: 'Fleet Management', href: '/fleet-management', icon: Settings });
    }
    if (can?.controlRoom?.viewAny) {
        items.push({ title: 'Control Room', href: '/control-room', icon: ShieldAlert });
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
        items.push({ title: incidentPlural, href: '/incidents', icon: FileText });
    }
    if (can?.compliance?.view) {
        items.push({ title: 'Compliance', href: '/compliance', icon: Shield });
    }
    if (can?.audit?.viewAny) {
        items.push({ title: 'Audit Logs', href: '/audit-logs', icon: Shield });
        items.push({ title: 'QA Checklist', href: '/quality/checklist', icon: Shield });
    }
    if (can?.safeguarding?.viewAny || can?.safeguarding?.create) {
        items.push({ title: 'Safeguarding', href: '/safeguarding', icon: ShieldAlert });
    }
    if (can?.privacy?.viewRequests) {
        items.push({ title: 'Privacy & GDPR', href: '/privacy/dashboard', icon: Lock });
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
