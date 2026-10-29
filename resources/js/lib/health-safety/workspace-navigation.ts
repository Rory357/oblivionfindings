import {
    Activity,
    BarChart3,
    ClipboardCheck,
    FileText,
    FlaskConical,
    HardHat,
    HeartPulse,
    ListChecks,
    PersonStanding,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Siren,
    Target,
    Truck,
    Users,
    type LucideIcon,
} from 'lucide-react';

/** Discovery only. Each destination still enforces its own server permission. */
export interface SafetyNavigationPermissions {
    hazards?: { view?: boolean };
    incidents?: { viewAny?: boolean; viewAssigned?: boolean };
    safeguarding?: { viewAny?: boolean; create?: boolean };
    fleet?: { viewAny?: boolean };
    assets?: { viewAny?: boolean };
    restraints?: { view?: boolean };
    procedures?: { view?: boolean };
    hr?: { wellbeing?: { view?: boolean } };
    governance?: { view?: boolean };
    compliance?: { view?: boolean };
    privacy?: { viewRequests?: boolean };
}
type Can = SafetyNavigationPermissions | null | undefined;
type Visibility = (can: Can) => boolean;
const safetyReader: Visibility = (can) => !!can?.hazards?.view;
const incidentReader: Visibility = (can) =>
    !!(can?.incidents?.viewAny || can?.incidents?.viewAssigned);
const assuranceReader: Visibility = (can) => !!can?.governance?.view;

export type SafetyHubKey =
    | 'overview'
    | 'concerns'
    | 'care'
    | 'prevention'
    | 'participation'
    | 'reports';
export interface SafetyView {
    key: string;
    label: string;
    href: string;
    icon: LucideIcon;
    description: string;
    visible: Visibility;
    keywords?: string[];
    /** Search/filter shortcuts don't add a third navigation tier. */
    shortcut?: boolean;
    exact?: boolean;
}
export interface SafetyHub {
    key: SafetyHubKey;
    label: string;
    icon: LucideIcon;
    href?: string;
    views: SafetyView[];
}

/** The hubs that used to sit on the "Safety registers" card page (its address now lists them). */
export const SAFETY_REGISTER_HUBS: SafetyHubKey[] = [
    'care',
    'prevention',
    'participation',
];

/**
 * Six hubs in the sidebar (approved drawing D4, MASTER for Stephan, 30 Oct). Inside a hub the sibling lists are tabs
 * on the header rail, so people move sideways without going back. Every former destination keeps its address, its
 * own permission check and its search name.
 */
export const SAFETY_HUBS: SafetyHub[] = [
    {
        key: 'overview',
        label: 'Overview',
        icon: ShieldCheck,
        views: [
            {
                key: 'overview',
                label: 'Overview',
                href: '/health-safety',
                icon: ShieldCheck,
                description:
                    'Safety work needing attention across your approved sites.',
                visible: safetyReader,
                exact: true,
                keywords: ['H&S Dashboard'],
            },
        ],
    },
    {
        key: 'concerns',
        label: 'Incidents and safety reviews',
        icon: ShieldAlert,
        views: [
            {
                key: 'incidents',
                label: 'Incidents',
                href: '/incidents',
                icon: ShieldAlert,
                description:
                    'Report and follow up incidents involving clients and workers.',
                visible: incidentReader,
            },
            {
                key: 'near-misses',
                label: 'Near misses',
                href: '/incidents?tab=near_misses',
                icon: ShieldAlert,
                description: 'Learn from events where harm was avoided.',
                visible: incidentReader,
                shortcut: true,
            },
            {
                key: 'reviews',
                label: 'Safety reviews',
                href: '/health-safety/events',
                icon: ClipboardCheck,
                description:
                    'Investigate events and decide what needs to change.',
                visible: safetyReader,
                keywords: ['Events'],
            },
            {
                key: 'investigations',
                label: 'Investigations',
                href: '/health-safety/events?tab=investigating',
                icon: ClipboardCheck,
                description: 'Safety reviews currently under investigation.',
                visible: safetyReader,
                shortcut: true,
            },
            {
                key: 'actions',
                label: 'Actions',
                href: '/health-safety/corrective-actions',
                icon: ListChecks,
                description:
                    'Assign, complete and independently verify corrective actions.',
                visible: safetyReader,
                keywords: ['Corrective Actions'],
            },
            {
                key: 'notifications',
                label: 'WorkSafe notifications',
                href: '/health-safety/reports?view=notifications',
                icon: FileText,
                description:
                    'Review recorded WorkSafe notification decisions and progress.',
                visible: assuranceReader,
            },
            {
                key: 'safeguarding',
                label: 'Safeguarding concerns',
                href: '/safeguarding',
                icon: Shield,
                description: 'Record and respond to safeguarding concerns.',
                visible: (can) => !!can?.safeguarding?.viewAny,
                keywords: ['Safeguarding'],
            },
            {
                key: 'report-safeguarding',
                label: 'Raise a safeguarding concern',
                href: '/safeguarding/create',
                icon: Shield,
                description: 'Raise a concern with the safeguarding team.',
                visible: (can) =>
                    !!can?.safeguarding?.create && !can?.safeguarding?.viewAny,
                keywords: ['Safeguarding'],
            },
            {
                key: 'fleet-incidents',
                label: 'Fleet incidents',
                href: '/fleet-assets/incidents',
                icon: Truck,
                description:
                    'Follow up incidents involving vehicles and assets.',
                visible: (can) =>
                    !!(can?.fleet?.viewAny || can?.assets?.viewAny),
            },
        ],
    },
    {
        key: 'care',
        label: 'Injuries and care',
        icon: HeartPulse,
        views: [
            {
                key: 'injuries',
                label: 'Workplace injuries',
                href: '/health-safety/injuries',
                icon: Activity,
                description: 'Support recovery and safe returns to work.',
                visible: (can) =>
                    !!(can?.hazards?.view || can?.hr?.wellbeing?.view),
                keywords: ['Workplace Injuries', 'Injuries & recovery'],
            },
            {
                key: 'first-aid',
                label: 'First aid',
                href: '/health-safety/first-aid',
                icon: HeartPulse,
                description:
                    'Record first aid and check kits and trained responders.',
                visible: safetyReader,
                keywords: ['First Aid Register'],
            },
            {
                key: 'restraints',
                label: 'Restraints',
                href: '/health-safety/restraints',
                icon: Shield,
                description:
                    'Review restraint events and behaviour support safeguards.',
                visible: (can) => !!can?.restraints?.view,
                keywords: ['Restraints & Behaviour Support'],
            },
            {
                key: 'chemical-exposures',
                label: 'Chemical exposures',
                href: '/health-safety/chemical-exposures',
                icon: FlaskConical,
                description:
                    'Review chemical exposures and follow-up work. Identifiable records need wellbeing access.',
                visible: safetyReader,
            },
        ],
    },
    {
        key: 'prevention',
        label: 'Risks and prevention',
        icon: Target,
        views: [
            {
                key: 'hazards',
                label: 'Hazards',
                href: '/compliance/hazards',
                icon: ShieldAlert,
                description:
                    'Identify hazards and keep their controls current.',
                visible: safetyReader,
            },
            {
                key: 'risks',
                label: 'Risk assessments',
                href: '/health-safety/risk-assessments',
                icon: Target,
                description: 'Assess risks and review control effectiveness.',
                visible: safetyReader,
                keywords: ['Risks'],
            },
            {
                key: 'chemicals',
                label: 'Chemicals',
                href: '/health-safety/substances',
                icon: FlaskConical,
                description:
                    'Manage hazardous substances and safety data sheets.',
                visible: safetyReader,
                keywords: ['Chemical Register'],
            },
            {
                key: 'ppe',
                label: 'Protective gear',
                href: '/health-safety/ppe',
                icon: HardHat,
                description:
                    'Manage protective equipment (PPE), allocations and inspections.',
                visible: safetyReader,
                keywords: ['PPE & equipment'],
            },
            {
                key: 'procedures',
                label: 'Procedures',
                href: '/health-safety/procedures',
                icon: FileText,
                description:
                    'Find approved safe work procedures and acknowledgements.',
                visible: (can) => !!can?.procedures?.view,
                keywords: ['Safe Work Procedures'],
            },
            {
                key: 'drills',
                label: 'Drills',
                href: '/health-safety/drills',
                icon: Siren,
                description: 'Plan, record and learn from emergency exercises.',
                visible: safetyReader,
                keywords: ['Emergency drills'],
            },
            {
                key: 'lone-workers',
                label: 'Working alone',
                href: '/health-safety/lone-workers',
                icon: PersonStanding,
                description:
                    'Manage lone worker sessions, check-ins and safety devices.',
                visible: safetyReader,
                keywords: ['Lone worker safety'],
            },
        ],
    },
    {
        key: 'participation',
        label: 'People and participation',
        icon: Users,
        views: [
            {
                key: 'representatives',
                label: 'Health and safety representatives',
                href: '/health-safety/worker-participation?tab=representatives',
                icon: Users,
                description:
                    'Find health and safety representatives and their coverage.',
                visible: safetyReader,
                keywords: ['Worker Participation', 'Representatives'],
            },
            {
                key: 'meetings',
                label: 'Committees',
                href: '/health-safety/worker-participation?tab=meetings',
                icon: Users,
                description:
                    'Record committee meetings, minutes and agreed actions.',
                visible: safetyReader,
                keywords: ['Worker Participation', 'Committees & meetings'],
            },
            {
                key: 'consultations',
                label: 'Consultations',
                href: '/health-safety/worker-participation?tab=consultations',
                icon: Users,
                description:
                    'Involve workers in decisions that affect their safety.',
                visible: safetyReader,
                keywords: ['Worker Participation'],
            },
        ],
    },
    {
        key: 'reports',
        label: 'Reports',
        icon: BarChart3,
        href: '/health-safety/reports',
        views: [
            {
                key: 'trends',
                label: 'Trends',
                href: '/health-safety/analytics',
                icon: BarChart3,
                description:
                    'Explore incident patterns and safety performance.',
                visible: safetyReader,
                keywords: ['Analytics'],
            },
            {
                key: 'board',
                label: 'Board reports',
                href: '/health-safety/reports?view=board',
                icon: ShieldCheck,
                description:
                    'How we’re doing on health and safety, for the board.',
                visible: assuranceReader,
                keywords: ['Board safety summary'],
            },
            {
                key: 'investigation-outcomes',
                label: 'Investigation outcomes',
                href: '/health-safety/reports?view=investigation-outcomes',
                icon: ClipboardCheck,
                description: 'Review investigation findings and completion.',
                visible: assuranceReader,
            },
            {
                key: 'action-traceability',
                label: 'Actions and their safety reviews',
                href: '/health-safety/reports?view=action-traceability',
                icon: ListChecks,
                description: 'Each action and the safety review it came from.',
                visible: assuranceReader,
            },
            {
                key: 'risk-register',
                label: 'Risk assessment report',
                href: '/health-safety/reports?view=risk-register',
                icon: Target,
                description: 'Review risk assessments for assurance.',
                visible: assuranceReader,
            },
            {
                key: 'compliance',
                label: 'Compliance',
                href: '/compliance',
                icon: ShieldCheck,
                description: 'Open the organisation’s compliance workspace.',
                visible: (can) => !!can?.compliance?.view,
                exact: true,
            },
            {
                key: 'privacy',
                label: 'Privacy',
                href: '/privacy/dashboard',
                icon: Shield,
                description: 'Open privacy requests, breaches and reporting.',
                visible: (can) => !!can?.privacy?.viewRequests,
                exact: true,
            },
        ],
    },
];

export function visibleSafetyViews(hub: SafetyHub, can: Can): SafetyView[] {
    return hub.views.filter((view) => view.visible(can));
}
export function safetySidebar(can: Can) {
    return SAFETY_HUBS.flatMap((hub) => {
        const views = visibleSafetyViews(hub, can);
        return views.length
            ? [
                  {
                      key: hub.key,
                      title: hub.label,
                      icon: hub.icon,
                      href: hub.href ?? views[0].href,
                  },
              ]
            : [];
    });
}
export function safetyHubForUrl(url: string): SafetyHub | undefined {
    const path =
        new URL(url, 'https://safety.local').pathname.replace(/\/+$/, '') ||
        '/';
    if (
        /^\/hazards\/[1-9]\d*$/.test(path) ||
        /^\/sites\/[1-9]\d*\/hazards$/.test(path)
    ) {
        return SAFETY_HUBS.find((hub) => hub.key === 'prevention');
    }
    // A hub that owns the address wins (the Reports page also holds the WorkSafe notifications view).
    const owner = SAFETY_HUBS.find((hub) => hub.href === path);
    if (owner) return owner;
    return SAFETY_HUBS.find((hub) =>
        hub.views.some((view) => {
            const destination = view.href.split('?')[0];
            return (
                path === destination ||
                (!view.exact && path.startsWith(`${destination}/`))
            );
        }),
    );
}
/** Only H&S-owned sidebar links opt in; shared Fleet/Compliance rails keep their matcher. */
export function safetyHubLinkActive(
    url: string,
    href: string,
): boolean | undefined {
    const path = href.split(/[?#]/)[0];
    if (
        !path.startsWith('/health-safety') &&
        ![
            '/incidents',
            '/safeguarding',
            '/safeguarding/create',
            '/compliance/hazards',
        ].includes(path)
    )
        return undefined;
    const hub = safetyHubForUrl(href);
    return hub ? safetyHubForUrl(url)?.key === hub.key : undefined;
}
export function safetySearchEntries(can: Can) {
    return SAFETY_HUBS.flatMap((hub) =>
        visibleSafetyViews(hub, can).map((view) => ({
            id: `safety:${hub.key}:${view.key}`,
            label: view.label,
            href: view.href,
            group: hub.label,
            icon: view.icon,
            keywords: ['Health & Safety', 'H&S', ...(view.keywords ?? [])],
        })),
    );
}
