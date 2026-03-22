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
    Briefcase,
    Building2,
    CalendarDays,
    ClipboardCheck,
    ClipboardList,
    Clock,
    DollarSign,
    FileQuestion,
    FileText,
    Folder,
    Gavel,
    GitBranch,
    Home,
    Landmark,
    LayoutGrid,
    Lock,
    MapPin,
    MessageSquareText,
    Package,
    Scale,
    Settings,
    Shield,
    ShieldAlert,
    Target,
    Truck,
    Users,
    Vote,
    Warehouse,
    Wrench,
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
    activeSiteId,
}: {
    role?: string | null;
    can?: any;
    labels: Record<string, string>;
    activeSiteId?: number | null;
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

    // Governance group
    const governanceGroup: NavGroup = {
        id: 'governance',
        label: 'Governance',
        items: [],
    };

    // System group
    const systemGroup: NavGroup = {
        id: 'system',
        label: 'System',
        items: [],
    };

    // Sites/Locations group (for all authenticated users with access)
    const sitesGroup: NavGroup = {
        id: 'sites',
        label: 'Sites / Locations',
        items: [],
    };

    if (can?.sites?.viewAny) {
        sitesGroup.items.push({ title: 'All Sites', href: '/sites', icon: MapPin });
        sitesGroup.items.push({ title: 'Head Office', href: '/sites?type=head_office', icon: Building2 });
        sitesGroup.items.push({ title: 'Houses', href: '/sites?type=house', icon: Home });
        sitesGroup.items.push({ title: 'Facilities', href: '/sites?type=facility', icon: Warehouse });
    }

    if (can?.calendar?.view) {
        sitesGroup.items.push({ title: 'Calendars', href: '/sites/calendar', icon: CalendarDays });
    }

    if (can?.checklists?.manageTemplates) {
        sitesGroup.items.push({ title: 'Checklist Templates', href: '/sites/checklists/templates', icon: FileQuestion });
    } else if (can?.checklists?.view) {
        sitesGroup.items.push({ title: 'Checklists & Walkthroughs', href: '/sites/checklists/templates', icon: ClipboardCheck });
    }

    if (can?.hazards?.view) {
        sitesGroup.items.push({ title: 'Hazards', href: '/compliance/hazards', icon: ShieldAlert });
    }

    sitesGroup.items.push({ title: 'Documents & Notes', href: '/sites?tab=documents', icon: FileText });

    if (can?.checklists?.view) {
        sitesGroup.items.push({ title: 'Inspections & Maintenance', href: '/sites?tab=inspections', icon: Wrench });
    }

    if (can?.vendors?.view || can?.credentials?.view) {
        const href = activeSiteId
            ? can?.vendors?.view
                ? `/sites/${activeSiteId}/vendors`
                : `/sites/${activeSiteId}/credentials`
            : '/sites';

        sitesGroup.items.push({ title: 'Vendors & Credentials', href, icon: Truck });
    }

    if (can?.assets?.viewAny) {
        sitesGroup.items.push({ title: 'Assets', href: '/assets', icon: Package });
    }

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
            ...(sitesGroup.items.length > 0 ? [sitesGroup] : []),
            ...(operationsGroup.items.length > 0 ? [operationsGroup] : []),
            ...(resourcesGroup.items.length > 0 ? [resourcesGroup] : []),
            ...(complianceGroup.items.length > 0 ? [complianceGroup] : []),
        ];
    }

    // Provider/Manager/Admin nav - Operations
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

    // HR
    const hrGroup: NavGroup = {
        id: 'hr',
        label: 'HR',
        items: [],
    };

    // My HR is always visible to any authenticated user
    hrGroup.items.push({ title: 'My HR', href: '/hr/my', icon: Home });
    hrGroup.items.push({ title: 'My Training', href: '/hr/my/training', icon: Target });

    const hasAnyHr = can?.hr?.recruitment?.view || can?.hr?.employees?.viewAny || can?.hr?.compliance?.view
        || can?.hr?.leave?.viewAny || can?.hr?.performance?.view || can?.hr?.reports?.view
        || can?.hr?.policies?.view || can?.hr?.positions?.view || can?.hr?.time?.view
        || can?.hr?.compensation?.view;

    // Directory is visible to all authenticated users
    hrGroup.items.push({ title: 'Directory', href: '/hr/directory', icon: Users });

    if (hasAnyHr) {
        if (can?.hr?.recruitment?.view) {
            hrGroup.items.push({ title: 'Recruitment', href: '/hr/recruitment', icon: Users });
        }
        if (can?.hr?.employees?.viewAny) {
            hrGroup.items.push({ title: 'People', href: '/hr/people', icon: Users });
        }
        if (can?.hr?.positions?.view) {
            hrGroup.items.push({ title: 'Positions', href: '/hr/positions', icon: Briefcase });
        }
        hrGroup.items.push({ title: 'Org Chart', href: '/hr/orgchart', icon: GitBranch });
        if (can?.hr?.compliance?.view) {
            hrGroup.items.push({ title: 'Compliance', href: '/hr/compliance', icon: Shield });
        }
        if (can?.hr?.leave?.viewAny) {
            hrGroup.items.push({ title: 'Leave & Rosters', href: '/hr/leave', icon: CalendarDays });
        }
        if (can?.hr?.time?.view) {
            hrGroup.items.push({ title: 'Time Tracking', href: '/hr/time', icon: Clock });
        }
        if (can?.hr?.performance?.view) {
            hrGroup.items.push({ title: 'Performance', href: '/hr/performance', icon: ClipboardCheck });
            hrGroup.items.push({ title: '360 Feedback', href: '/hr/feedback', icon: Users });
        }
        if (can?.hr?.compensation?.view) {
            hrGroup.items.push({ title: 'Compensation', href: '/hr/compensation/bands', icon: DollarSign });
        }
        if (can?.hr?.benefits?.view) {
            hrGroup.items.push({ title: 'Benefits', href: '/hr/benefits', icon: Shield });
        }
        if (can?.hr?.goals?.view) {
            hrGroup.items.push({ title: 'Goals', href: '/hr/goals', icon: Target });
        }
        if (can?.hr?.training?.view) {
            hrGroup.items.push({ title: 'Training', href: '/hr/training/catalog', icon: BookOpen });
        }
        if (can?.hr?.assets?.view) {
            hrGroup.items.push({ title: 'Assets', href: '/hr/assets', icon: Package });
        }
        hrGroup.items.push({ title: 'Community Feed', href: '/hr/feed', icon: MessageSquareText });
        hrGroup.items.push({ title: 'Time Off Calendar', href: '/hr/calendar/time-off', icon: CalendarDays });
        if (can?.hr?.analytics?.view) {
            hrGroup.items.push({ title: 'Analytics', href: '/hr/analytics', icon: LayoutGrid });
            hrGroup.items.push({ title: 'Wellbeing', href: '/hr/wellbeing', icon: Target });
        }
        if (can?.hr?.surveys?.view) {
            hrGroup.items.push({ title: 'Surveys', href: '/hr/surveys', icon: ClipboardList });
        }
        if (can?.hr?.expenses?.view) {
            hrGroup.items.push({ title: 'Expenses', href: '/hr/expenses', icon: DollarSign });
        }
        if (can?.hr?.skills?.view) {
            hrGroup.items.push({ title: 'Skills', href: '/hr/skills', icon: Target });
        }
        if (can?.hr?.calendar?.view) {
            hrGroup.items.push({ title: 'Calendar', href: '/hr/calendar', icon: CalendarDays });
        }
        if (can?.hr?.recruitment?.view) {
            hrGroup.items.push({ title: 'Job Postings', href: '/hr/job-postings', icon: Briefcase });
        }
        if (can?.hr?.announcements?.view) {
            hrGroup.items.push({ title: 'Announcements', href: '/hr/announcements', icon: MessageSquareText });
        }
        hrGroup.items.push({ title: 'Approvals', href: '/hr/approvals/pending', icon: ClipboardCheck });
        hrGroup.items.push({ title: 'Signatures', href: '/hr/signatures/pending', icon: FileText });
        if (can?.hr?.policies?.view) {
            hrGroup.items.push({ title: 'Policies', href: '/hr/policies', icon: FileText });
        }
        if (can?.hr?.reports?.view) {
            hrGroup.items.push({ title: 'Reports', href: '/hr/reports', icon: FileText });
        }
    }

    // Governance
    if (can?.governance?.view) {
        governanceGroup.items.push(
            { title: 'Dashboard', href: '/governance/dashboard', icon: Landmark },
            { title: 'Meetings', href: '/governance/meetings', icon: CalendarDays },
            ...(can?.governance?.meetings?.manage
                ? [{ title: 'Admin', href: '/governance/admin/board-members', icon: Users }]
                : []),
            { title: 'Risks', href: '/governance/risks', icon: Scale },
            { title: 'Resolutions', href: '/governance/resolutions', icon: Vote },
            { title: 'Compliance', href: '/governance/compliance', icon: Shield },
            { title: 'Strategy', href: '/governance/strategy', icon: Target },
            { title: 'Performance', href: '/governance/performance', icon: Gavel },
            { title: 'Budgets', href: '/governance/budgets', icon: Folder },
            { title: 'Action Items', href: '/governance/actions', icon: ClipboardList },
        );
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
    systemGroup.items.push({ title: 'Settings', href: '/settings', icon: Settings });

    return [
        mainGroup,
        ...(sitesGroup.items.length > 0 ? [sitesGroup] : []),
        ...(operationsGroup.items.length > 0 ? [operationsGroup] : []),
        ...(resourcesGroup.items.length > 0 ? [resourcesGroup] : []),
        ...(complianceGroup.items.length > 0 ? [complianceGroup] : []),
        ...(hrGroup.items.length > 0 ? [hrGroup] : []),
        ...(governanceGroup.items.length > 0 ? [governanceGroup] : []),
        ...(systemGroup.items.length > 0 ? [systemGroup] : []),
    ];
}

export function AppSidebar() {
    const page = usePage<PageProps & Record<string, any>>();
    const { auth } = page.props;
    const { labels } = page.props;
    const role = auth.user?.role ?? null;
    const can = auth?.can;

    const activeSiteId = page.props?.site?.id ?? null;
    const navigationGroups = buildNavigationGroups({ role, can, labels: labels ?? {}, activeSiteId });

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
