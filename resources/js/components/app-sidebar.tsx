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
import { type NavItem, type NavGroup } from '@/types';
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
    Package,
    Settings,
    Shield,
    ShieldAlert,
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
        title: 'Documentation',
        href: '/docs',
        icon: BookOpen,
    },
];

function buildNavigationGroups({
    role,
    can,
    labels,
}: {
    role?: string | null;
    can?: any;
    labels: Record<string, string>;
}): NavGroup[] {
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

    // Main navigation group (always visible)
    const mainGroup: NavGroup = {
        id: 'main',
        label: 'Main',
        items: [
            { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
            { title: 'Today', href: '/today', icon: ClipboardList },
        ],
    };

    // Operations group
    const operationsGroup: NavGroup = {
        id: 'operations',
        label: 'Operations',
        items: [],
    };

    // Resources group
    const resourcesGroup: NavGroup = {
        id: 'resources',
        label: 'Resources',
        items: [],
    };

    // Compliance group
    const complianceGroup: NavGroup = {
        id: 'compliance',
        label: 'Compliance & Safety',
        items: [],
    };

    // System group
    const systemGroup: NavGroup = {
        id: 'system',
        label: 'System',
        items: [],
    };

    // Support Worker specific nav
    if (role === 'support_worker') {
        operationsGroup.items.push(
            { title: 'My Shifts', href: '/shifts', icon: CalendarDays },
            { title: timesheetPlural, href: '/timesheets', icon: ClipboardList },
            { title: clientPlural, href: '/clients', icon: Users },
            { title: notePlural, href: '/notes', icon: FileText },
            { title: timelineLabel, href: '/timeline', icon: MessageSquareText }
        );

        if (can?.medications?.view) {
            operationsGroup.items.push({
                title: medicationPlural,
                href: '/medications',
                icon: ClipboardList,
            });
        }

        if (can?.medications?.breakGlass) {
            operationsGroup.items.push({
                title: emergencyLabel,
                href: '/emergency-access',
                icon: Shield,
            });
        }

        if (can?.assets?.viewAssigned || can?.assets?.viewAny) {
            resourcesGroup.items.push({
                title: assetPlural,
                href: '/assets',
                icon: Package,
            });
        }

        if (can?.assets?.alertsView) {
            resourcesGroup.items.push({
                title: 'Asset Alerts',
                href: '/assets/alerts',
                icon: ShieldAlert,
            });
        }

        if (can?.incidents?.viewAssigned) {
            complianceGroup.items.push({
                title: incidentPlural,
                href: '/incidents',
                icon: FileText,
            });
        }

        return [
            mainGroup,
            ...(operationsGroup.items.length > 0 ? [operationsGroup] : []),
            ...(resourcesGroup.items.length > 0 ? [resourcesGroup] : []),
            ...(complianceGroup.items.length > 0 ? [complianceGroup] : []),
        ];
    }

    // Provider/Manager/Admin nav - Operations
    if (can?.sites?.viewAny) {
        operationsGroup.items.push({ title: sitePlural, href: '/sites', icon: MapPin });
    }
    if (can?.clients?.viewAny) {
        operationsGroup.items.push({ title: clientPlural, href: '/clients', icon: Users });
    }
    if (can?.shifts?.viewAny) {
        operationsGroup.items.push({ title: shiftPlural, href: '/shifts', icon: CalendarDays });
    }
    if (can?.timesheets?.viewAny || can?.timesheets?.viewAssigned) {
        operationsGroup.items.push({
            title: timesheetPlural,
            href: '/timesheets',
            icon: ClipboardList,
        });
    }
    if (can?.respite?.viewAny) {
        operationsGroup.items.push({
            title: respitePlural,
            href: '/respite',
            icon: CalendarDays,
        });
    }
    if (can?.medications?.view) {
        operationsGroup.items.push({
            title: medicationPlural,
            href: '/medications',
            icon: ClipboardList,
        });
    }
    if (can?.medications?.breakGlass) {
        operationsGroup.items.push({
            title: emergencyLabel,
            href: '/emergency-access',
            icon: Shield,
        });
    }

    // Resources
    if (can?.assets?.viewAny || can?.assets?.viewAssigned) {
        resourcesGroup.items.push({ title: assetPlural, href: '/assets', icon: Package });
    }
    if (can?.assets?.alertsView) {
        resourcesGroup.items.push({
            title: 'Asset Alerts',
            href: '/assets/alerts',
            icon: ShieldAlert,
        });
    }
    if (can?.staff?.viewAny) {
        resourcesGroup.items.push({ title: staffPlural, href: '/staff', icon: Users });
    }
    if (can?.fleet?.viewAny) {
        resourcesGroup.items.push({
            title: 'Fleet Management',
            href: '/fleet-management',
            icon: MapPin,
        });
    }
    if (can?.rostering?.viewAny) {
        resourcesGroup.items.push({
            title: 'Rostering',
            href: '/rostering',
            icon: Settings,
        });
    }

    // Compliance & Safety
    if (can?.incidents?.viewAny) {
        complianceGroup.items.push({
            title: incidentPlural,
            href: '/incidents',
            icon: FileText,
        });
    }
    if (can?.safeguarding?.viewAny || can?.safeguarding?.create) {
        complianceGroup.items.push({
            title: 'Safeguarding',
            href: '/safeguarding',
            icon: ShieldAlert,
        });
    }
    if (can?.privacy?.viewRequests) {
        complianceGroup.items.push({
            title: 'Privacy & GDPR',
            href: '/privacy/dashboard',
            icon: Lock,
        });
    }
    if (can?.compliance?.view) {
        complianceGroup.items.push({
            title: 'Compliance',
            href: '/compliance',
            icon: Shield,
        });
    }

    // System
    if (can?.reports?.viewAny) {
        systemGroup.items.push({ title: 'Reports', href: '/reports', icon: FileText });
    }
    if (can?.calendar?.viewAny) {
        systemGroup.items.push({ title: 'Calendar', href: '/calendar', icon: CalendarDays });
    }
    if (can?.timeline?.viewAny) {
        systemGroup.items.push({ title: 'Timeline', href: '/timeline', icon: MessageSquareText });
    }
    if (can?.summaries?.viewAny) {
        systemGroup.items.push({ title: 'Summaries', href: '/summaries', icon: FileText });
    }
    if (can?.audit?.viewAny) {
        systemGroup.items.push(
            { title: 'Audit Logs', href: '/audit-logs', icon: Shield },
            { title: 'QA Checklist', href: '/quality/checklist', icon: ClipboardList }
        );
    }
    if (can?.controlRoom?.viewAny) {
        systemGroup.items.push({
            title: 'Control Room',
            href: '/control-room',
            icon: ShieldAlert,
        });
    }
    if (can?.unifi?.manage) {
        systemGroup.items.push({
            title: 'UniFi',
            href: '/integrations/unifi',
            icon: Settings,
        });
    }
    systemGroup.items.push({ title: 'Settings', href: '/settings', icon: Settings });

    return [
        mainGroup,
        ...(operationsGroup.items.length > 0 ? [operationsGroup] : []),
        ...(resourcesGroup.items.length > 0 ? [resourcesGroup] : []),
        ...(complianceGroup.items.length > 0 ? [complianceGroup] : []),
        ...(systemGroup.items.length > 0 ? [systemGroup] : []),
    ];
}

export function AppSidebar() {
    const { auth } = usePage<PageProps>().props;
    const { labels } = usePage<PageProps>().props;
    const role = auth.user?.role ?? null;
    const can = auth?.can;

    const navigationGroups = buildNavigationGroups({ role, can, labels: labels ?? {} });

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
                <NavMain groups={navigationGroups} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
