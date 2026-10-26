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
    | 'actions'
    | 'prevention'
    | 'registers'
    | 'reports';
export type SafetyRegisterGroup = 'people' | 'equipment' | 'participation';
export interface SafetyView {
    key: string;
    label: string;
    href: string;
    icon: LucideIcon;
    description: string;
    visible: Visibility;
    keywords?: string[];
    group?: SafetyRegisterGroup;
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

export const SAFETY_REGISTER_GROUPS = [
    { key: 'people', label: 'People safety' },
    { key: 'equipment', label: 'Equipment & substances' },
    { key: 'participation', label: 'Participation' },
] as const;

/** All 21 former rail destinations retain their canonical URLs and search names. */
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
        label: 'Incidents & concerns',
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
                key: 'safeguarding',
                label: 'Safeguarding',
                href: '/safeguarding',
                icon: Shield,
                description: 'Record and respond to safeguarding concerns.',
                visible: (can) => !!can?.safeguarding?.viewAny,
            },
            {
                key: 'report-safeguarding',
                label: 'Report a safeguarding concern',
                href: '/safeguarding/create',
                icon: Shield,
                description: 'Raise a concern with the safeguarding team.',
                visible: (can) =>
                    !!can?.safeguarding?.create && !can?.safeguarding?.viewAny,
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
        key: 'actions',
        label: 'Actions & reviews',
        icon: ListChecks,
        views: [
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
        ],
    },
    {
        key: 'prevention',
        label: 'Risks & prevention',
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
                label: 'Emergency drills',
                href: '/health-safety/drills',
                icon: Siren,
                description: 'Plan, record and learn from emergency exercises.',
                visible: safetyReader,
            },
        ],
    },
    {
        key: 'registers',
        label: 'Safety registers',
        icon: ClipboardCheck,
        href: '/health-safety/registers',
        views: [
            {
                key: 'lone-workers',
                label: 'Lone worker safety',
                href: '/health-safety/lone-workers',
                icon: PersonStanding,
                description:
                    'Manage lone worker sessions, check-ins and safety devices.',
                visible: safetyReader,
                group: 'people',
            },
            {
                key: 'injuries',
                label: 'Injuries & recovery',
                href: '/health-safety/injuries',
                icon: Activity,
                description: 'Support recovery and safe returns to work.',
                visible: (can) =>
                    !!(can?.hazards?.view || can?.hr?.wellbeing?.view),
                group: 'people',
                keywords: ['Workplace Injuries'],
            },
            {
                key: 'first-aid',
                label: 'First aid',
                href: '/health-safety/first-aid',
                icon: HeartPulse,
                description:
                    'Record first aid and check kits and trained responders.',
                visible: safetyReader,
                group: 'people',
                keywords: ['First Aid Register'],
            },
            {
                key: 'restraints',
                label: 'Restraint & behaviour support',
                href: '/health-safety/restraints',
                icon: Shield,
                description:
                    'Review restraint events and behaviour support safeguards.',
                visible: (can) => !!can?.restraints?.view,
                group: 'people',
                keywords: ['Restraints & Behaviour Support'],
            },
            {
                key: 'ppe',
                label: 'PPE & equipment',
                href: '/health-safety/ppe',
                icon: HardHat,
                description:
                    'Manage protective equipment, allocations and inspections.',
                visible: safetyReader,
                group: 'equipment',
            },
            {
                key: 'chemicals',
                label: 'Chemicals',
                href: '/health-safety/substances',
                icon: FlaskConical,
                description:
                    'Manage hazardous substances and safety data sheets.',
                visible: safetyReader,
                group: 'equipment',
                keywords: ['Chemical Register'],
            },
            {
                key: 'chemical-exposures',
                label: 'Chemical exposures',
                href: '/health-safety/chemical-exposures',
                icon: FlaskConical,
                description:
                    'Review chemical exposures and follow-up work. Identifiable records need wellbeing access.',
                visible: safetyReader,
                group: 'equipment',
            },
            {
                key: 'representatives',
                label: 'Representatives',
                href: '/health-safety/worker-participation?tab=representatives',
                icon: Users,
                description:
                    'Find health and safety representatives and their coverage.',
                visible: safetyReader,
                group: 'participation',
                keywords: ['Worker Participation'],
            },
            {
                key: 'meetings',
                label: 'Committees & meetings',
                href: '/health-safety/worker-participation?tab=meetings',
                icon: Users,
                description:
                    'Record committee meetings, minutes and agreed actions.',
                visible: safetyReader,
                group: 'participation',
                keywords: ['Worker Participation'],
            },
            {
                key: 'consultations',
                label: 'Consultations',
                href: '/health-safety/worker-participation?tab=consultations',
                icon: Users,
                description:
                    'Involve workers in decisions that affect their safety.',
                visible: safetyReader,
                group: 'participation',
                keywords: ['Worker Participation'],
            },
        ],
    },
    {
        key: 'reports',
        label: 'Reports & assurance',
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
                key: 'notifications',
                label: 'WorkSafe notifications',
                href: '/health-safety/reports?view=notifications',
                icon: FileText,
                description:
                    'Review recorded WorkSafe notification decisions and progress.',
                visible: assuranceReader,
            },
            {
                key: 'board',
                label: 'Board assurance',
                href: '/health-safety/reports?view=board',
                icon: ShieldCheck,
                description:
                    'Review the safety summary prepared for governance.',
                visible: assuranceReader,
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
                label: 'Corrective action traceability',
                href: '/health-safety/reports?view=action-traceability',
                icon: ListChecks,
                description:
                    'Trace actions back to the safety events they address.',
                visible: assuranceReader,
            },
            {
                key: 'risk-register',
                label: 'Risk assessment register',
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
    return SAFETY_HUBS.find(
        (hub) =>
            hub.href === path ||
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
