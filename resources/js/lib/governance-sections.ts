import {
    BookOpen,
    CalendarDays,
    ClipboardCheck,
    ClipboardList,
    DollarSign,
    FileSearch,
    FileText,
    Gavel,
    HeartPulse,
    Landmark,
    type LucideIcon,
    Map,
    ScrollText,
    Settings,
    Shield,
    ShieldCheck,
    Target,
    TrendingUp,
    UserCheck,
    Users,
    Wallet,
} from 'lucide-react';

/**
 * Governance hubs — the single source of truth for how Governance registers
 * are grouped. The sidebar shows ONE entry per hub; each hub's registers are
 * the connected-tab rail in the page header (<GovernanceSectionRail>). Every
 * register keeps its own canonical URL, so deep links are unchanged and the
 * server still authorises each page independently — hiding a tab is never
 * the security boundary.
 */

type Can = Record<string, any> | null | undefined;

export interface GovernanceSectionTab {
    key: string;
    label: string;
    href: string;
    icon: LucideIcon;
    /** Paths (and their sub-paths) that belong to this tab. */
    prefixes: string[];
    visible: (can: Can) => boolean;
}

export interface GovernanceSection {
    key: string;
    label: string;
    icon: LucideIcon;
    group: 'board' | 'oversight' | 'admin';
    tabs: GovernanceSectionTab[];
}

const gov = (can: Can) => (can?.governance ?? {}) as Record<string, any>;

export const GOVERNANCE_SECTIONS: GovernanceSection[] = [
    {
        key: 'meetings',
        label: 'Meetings',
        icon: CalendarDays,
        group: 'board',
        tabs: [
            {
                key: 'meetings',
                label: 'Meetings',
                href: '/governance/meetings',
                icon: CalendarDays,
                prefixes: ['/governance/meetings'],
                visible: (can) =>
                    Boolean(gov(can).meetings?.view || gov(can).view),
            },
            {
                key: 'packs',
                label: 'Board packs',
                href: '/governance/packs',
                icon: FileText,
                prefixes: ['/governance/packs'],
                visible: (can) => Boolean(gov(can).packs?.view),
            },
            {
                key: 'ceo-reports',
                label: 'CEO reports',
                href: '/governance/ceo-reports',
                icon: ScrollText,
                prefixes: ['/governance/ceo-reports'],
                visible: (can) => Boolean(gov(can)['ceo-reports']?.view),
            },
        ],
    },
    {
        key: 'decisions',
        label: 'Decisions & actions',
        icon: Gavel,
        group: 'board',
        tabs: [
            {
                key: 'resolutions',
                label: 'Resolutions',
                href: '/governance/resolutions',
                icon: Gavel,
                prefixes: ['/governance/resolutions'],
                visible: (can) => Boolean(gov(can).resolutions?.view),
            },
            {
                key: 'actions',
                label: 'Action items',
                href: '/governance/actions',
                icon: ClipboardList,
                prefixes: ['/governance/actions'],
                visible: (can) => Boolean(gov(can).actions?.view),
            },
        ],
    },
    {
        key: 'assurance',
        label: 'Risk & assurance',
        icon: ShieldCheck,
        group: 'oversight',
        tabs: [
            {
                key: 'risks',
                label: 'Risk register',
                href: '/governance/risks',
                icon: Target,
                prefixes: ['/governance/risks'],
                visible: (can) => Boolean(gov(can).risks?.view),
            },
            {
                key: 'compliance',
                label: 'Compliance',
                href: '/governance/compliance',
                icon: Shield,
                prefixes: ['/governance/compliance'],
                visible: (can) => Boolean(gov(can).compliance?.view),
            },
            {
                key: 'clinical',
                label: 'Clinical',
                href: '/governance/clinical',
                icon: HeartPulse,
                prefixes: ['/governance/clinical'],
                visible: (can) => Boolean(gov(can).clinical?.view),
            },
            {
                key: 'te-tiriti',
                label: 'Te Tiriti',
                href: '/governance/te-tiriti',
                icon: Landmark,
                prefixes: ['/governance/te-tiriti'],
                visible: (can) => Boolean(gov(can)['te-tiriti']?.view),
            },
        ],
    },
    {
        key: 'finance',
        label: 'Finance',
        icon: DollarSign,
        group: 'oversight',
        tabs: [
            {
                key: 'budgets',
                label: 'Budgets',
                href: '/governance/budgets',
                icon: Wallet,
                prefixes: ['/governance/budgets'],
                visible: (can) => Boolean(gov(can).budgets?.view),
            },
            {
                key: 'spend-approvals',
                label: 'Spend approvals',
                href: '/governance/spend-approvals',
                icon: DollarSign,
                prefixes: ['/governance/spend-approvals'],
                visible: (can) => Boolean(gov(can).spend?.view),
            },
        ],
    },
    {
        key: 'strategy',
        label: 'Strategy & performance',
        icon: TrendingUp,
        group: 'oversight',
        tabs: [
            {
                key: 'strategy',
                label: 'Strategic plan',
                href: '/governance/strategy',
                icon: Target,
                prefixes: ['/governance/strategy'],
                visible: (can) => Boolean(gov(can).strategy?.view),
            },
            {
                key: 'performance',
                label: 'CEO performance',
                href: '/governance/performance',
                icon: UserCheck,
                prefixes: ['/governance/performance'],
                visible: (can) => Boolean(gov(can).performance?.view),
            },
            {
                // Roadmap is its own module with no other sidebar entry; it
                // is reached from this hub rather than a separate link.
                key: 'roadmap',
                label: 'Roadmap',
                href: '/roadmap/dashboard',
                icon: Map,
                prefixes: ['/roadmap'],
                visible: (can) => Boolean(can?.roadmap?.view),
            },
        ],
    },
    {
        key: 'records',
        label: 'Policies & records',
        icon: BookOpen,
        group: 'oversight',
        tabs: [
            {
                key: 'policies',
                label: 'Policies',
                href: '/governance/policies',
                icon: BookOpen,
                prefixes: ['/governance/policies'],
                visible: (can) => Boolean(gov(can).policies?.view),
            },
            {
                key: 'documents',
                label: 'Documents',
                href: '/governance/documents',
                icon: FileText,
                prefixes: ['/governance/documents'],
                visible: (can) => Boolean(gov(can).documents?.view),
            },
            {
                key: 'records',
                label: 'Records search',
                href: '/governance/records',
                icon: FileSearch,
                prefixes: ['/governance/records'],
                visible: (can) => Boolean(gov(can).view),
            },
        ],
    },
    {
        key: 'board',
        label: 'Board & members',
        icon: Users,
        group: 'admin',
        tabs: [
            {
                key: 'members',
                label: 'Members',
                href: '/governance/admin/board-members',
                icon: Users,
                prefixes: ['/governance/admin/board-members'],
                visible: (can) => Boolean(gov(can).meetings?.manage),
            },
            {
                key: 'interests',
                label: 'Interests',
                href: '/governance/interests',
                icon: ClipboardList,
                prefixes: ['/governance/interests'],
                visible: (can) => Boolean(gov(can).interests?.view),
            },
            {
                key: 'evaluations',
                label: 'Evaluations',
                href: '/governance/evaluations',
                icon: ClipboardCheck,
                prefixes: ['/governance/evaluations'],
                visible: (can) => Boolean(gov(can).evaluations?.view),
            },
        ],
    },
    {
        key: 'settings',
        label: 'Settings & audit',
        icon: Settings,
        group: 'admin',
        tabs: [
            {
                key: 'settings',
                label: 'Settings',
                href: '/governance/settings',
                icon: Settings,
                prefixes: ['/governance/settings'],
                visible: (can) => Boolean(gov(can).settings?.view),
            },
            {
                key: 'audit-log',
                label: 'Audit log',
                href: '/governance/audit-log',
                icon: ClipboardCheck,
                prefixes: ['/governance/audit-log'],
                visible: (can) => Boolean(gov(can).audit?.view),
            },
        ],
    },
];

export const GOVERNANCE_SECTION_GROUP_LABELS: Record<
    GovernanceSection['group'],
    string
> = {
    board: 'Board business',
    oversight: 'Oversight',
    admin: 'Board admin',
};

function normalisePath(url: string): string {
    let path = url.split(/[?#]/)[0] ?? '/';
    if (/^https?:\/\//.test(path)) {
        try {
            path = new URL(path).pathname;
        } catch {
            // keep the raw path
        }
    }
    const trimmed = path.replace(/\/+$/, '');
    return trimmed.length > 0 ? trimmed : '/';
}

function tabMatches(tab: GovernanceSectionTab, path: string): boolean {
    return tab.prefixes.some(
        (prefix) => path === prefix || path.startsWith(`${prefix}/`),
    );
}

export function visibleSectionTabs(
    section: GovernanceSection,
    can: Can,
): GovernanceSectionTab[] {
    return section.tabs.filter((tab) => tab.visible(can));
}

/** The hub (and tab) a URL belongs to, or null outside the hubs. */
export function governanceSectionForUrl(
    url: string,
): { section: GovernanceSection; tab: GovernanceSectionTab } | null {
    const path = normalisePath(url);
    for (const section of GOVERNANCE_SECTIONS) {
        const tab = section.tabs.find((candidate) =>
            tabMatches(candidate, path),
        );
        if (tab) return { section, tab };
    }
    return null;
}

/**
 * Sidebar matching: a nav item whose href is one of a hub's tabs is active
 * anywhere inside that hub (e.g. "Meetings" stays lit on Board packs).
 */
export function governanceHubContainsUrl(
    itemHref: string,
    currentUrl: string,
): boolean {
    const itemPath = normalisePath(itemHref);
    const hub = GOVERNANCE_SECTIONS.find((section) =>
        section.tabs.some((tab) => tab.href === itemPath),
    );
    if (!hub) return false;
    const path = normalisePath(currentUrl);
    return hub.tabs.some((tab) => tabMatches(tab, path));
}
