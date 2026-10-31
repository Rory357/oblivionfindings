/**
 * Restraints list (approved H&S drawings pg-hsn-100,
 * 100b — Stephan 23 Oct 2026): one header with the two numbers that need
 * someone, the filters (the quieter ones under More filters) and the
 * "Injuries and care" hub tabs on the rail (drawing D4); then the Restraints /
 * Behaviour support plans switch and that list's status views as tier-two
 * tabs, then the table. Every row opens its record; its kebab and right-click carry Open and,
 * for reviewers, Review. Restraint wording — reported plan use, injury, the
 * current source revision and its review — stays exactly as the records say it.
 */
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { type SharedData } from '@/types';
import { safetyHubCrumbs } from '@/lib/health-safety/workspace-navigation';
import { SafetyWorkspaceRail } from '@/components/health-safety/workspace-header';
import {
    RecordIdentity,
    RecordPerson,
    RecordTable,
    type RecordTableColumn,
} from '@/components/incidents/record-table';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import {
    TierTwoTabs,
    type GroupedProfileNavTab,
} from '@/components/page/grouped-profile-nav';
import {
    PageHeaderNumber,
    PageHeaderNumbers,
} from '@/components/page/page-header-strip';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import { SelectInput } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateOnly, formatTime } from '@/lib/datetime';
import {
    RESTRAINT_REGISTER,
    restraintHref,
    restraintReturn,
} from '@/lib/health-safety/restraint-navigation';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Archive,
    CalendarClock,
    CalendarDays,
    CircleCheck,
    ClipboardCheck,
    Download,
    Eye,
    FileText,
    FileWarning,
    HeartPulse,
    List,
    PencilLine,
    Plus,
    ShieldAlert,
    SlidersHorizontal,
    X,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { hsBadge, hsLabel } from '@/lib/health-safety/hs-labels';
import {
    PLAN_STATUS_META,
    RESTRAINT_TYPE_OPTIONS,
    SEVERITY_OPTIONS,
    type RestraintFilters,
    type RestraintHero,
    type RestraintTabCounts,
} from './shared';

type EventRow = {
    id: number;
    reference: string;
    client: { id: number; name: string };
    site: { id: number; name: string } | null;
    restraint_type: string;
    severity: string;
    started_at: string | null;
    duration_minutes: number | null;
    within_support_plan: boolean;
    injury_occurred: boolean;
    needs_review: boolean;
    source_revision: number;
};
type PlanRow = {
    id: number;
    reference: string;
    title: string;
    client: { id: number; name: string };
    status: string;
    restrictive_practice_type: string | null;
    review_date: string | null;
    review_state: string;
    published_version_id: number | null;
    authorization_state: string;
};
type Page<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    last_page: number;
    current_page: number;
    total: number;
};
type Props = {
    lens: 'events' | 'plans';
    tab: string;
    events: Page<EventRow>;
    plans: Page<PlanRow>;
    tabCounts: RestraintTabCounts;
    hero: RestraintHero;
    filters: RestraintFilters;
    clients: { id: number; name: string }[];
    sites: { id: number; name: string }[];
    can: { create: boolean; review: boolean; manage: boolean };
};
type Next = Record<string, string | number | null>;

const EVENT_TABS: GroupedProfileNavTab[] = [
    { key: 'all', label: 'All restraints', icon: List },
    { key: 'unreviewed', label: 'Needs review', icon: ClipboardCheck },
    { key: 'out_of_plan', label: 'Outside the plan', icon: FileWarning },
    { key: 'injury', label: 'Injury recorded', icon: HeartPulse },
    { key: 'critical', label: 'Critical', icon: AlertTriangle },
    { key: '30d', label: 'Last 30 days', icon: CalendarDays },
];
const PLAN_TABS: GroupedProfileNavTab[] = [
    { key: 'all', label: 'Current plans', icon: FileText },
    { key: 'active', label: 'In use', icon: CircleCheck },
    { key: 'draft', label: 'Drafts', icon: PencilLine },
    { key: 'review_due', label: 'Review due', icon: CalendarClock },
    { key: 'under_review', label: 'Being reviewed', icon: ClipboardCheck },
    { key: 'archived', label: 'Archived', icon: Archive },
];
const LENSES: GroupedProfileNavTab[] = [
    { key: 'events', label: 'Restraints', icon: ShieldAlert },
    { key: 'plans', label: 'Behaviour support plans', icon: FileText },
];
/** A header number opens its view with every narrowing filter cleared. */
const CLEAR: Next = {
    client_id: null,
    site_id: null,
    q: null,
    restraint_type: null,
    severity: null,
    within_plan: null,
    review_state: null,
    from: null,
    to: null,
};

export default function RestraintWorkspaceIndex({
    lens,
    tab,
    events,
    plans,
    tabCounts,
    hero,
    filters,
    clients,
    sites,
    can,
}: Props) {
    const { url } = usePage();
    const safetyCan = usePage<SharedData>().props.auth.can;
    const origin = restraintReturn(url);
    const [search, setSearch] = useState(filters.q ?? '');
    useEffect(() => setSearch(filters.q ?? ''), [filters.q]);
    const params = (next: Next = {}) =>
        Object.fromEntries(
            Object.entries({ ...filters, lens, tab, ...next }).filter(
                ([, value]) => value !== null && value !== '',
            ),
        );
    const query = (next: Next = {}) =>
        new URLSearchParams(
            Object.entries(params(next)).map(([key, value]) => [key, String(value)]),
        );
    const href = (next: Next = {}) => `${RESTRAINT_REGISTER}?${query(next)}`;
    const go = (next: Next) =>
        router.get(RESTRAINT_REGISTER, params(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    const report = (kind: 'event' | 'plan') =>
        `${RESTRAINT_REGISTER}/report?${new URLSearchParams({ kind, return_to: origin, ...(filters.client_id ? { client_id: String(filters.client_id) } : {}), ...(filters.site_id ? { site_id: String(filters.site_id) } : {}) })}`;
    const tabs = lens === 'events' ? EVENT_TABS : PLAN_TABS;
    const rows = lens === 'events' ? events : plans;
    const moreActive = [
        filters.restraint_type,
        lens === 'events' ? filters.severity : null,
        lens === 'events' ? filters.within_plan : null,
        filters.review_state,
        filters.from,
        filters.to,
    ].filter(Boolean).length;
    const narrowed = !!(
        filters.q ||
        filters.client_id ||
        filters.site_id ||
        moreActive ||
        (filters.period && filters.period !== '30d') ||
        tab !== 'all'
    );
    const pager = (
        <>
            <p className="text-subtle">
                Showing {rows.data.length} of {rows.total}
            </p>
            {rows.last_page > 1 ? <LaravelPagination links={rows.links} /> : null}
        </>
    );
    const open = (kind: 'events' | 'plans', id: number) =>
        router.visit(restraintHref(kind, id, { returnTo: origin }));
    const review = (kind: 'events' | 'plans', id: number) =>
        router.visit(
            restraintHref(kind, id, { workspace: 'review', section: 'review', returnTo: origin }),
        );
    const menuFor = (kind: 'events' | 'plans', id: number): MenuItem[] =>
        compactMenu([
            { label: kind === 'events' ? 'Open restraint' : 'Open plan', icon: Eye, onClick: () => open(kind, id) },
            can.review && {
                label: kind === 'events' ? 'Review restraint' : 'Review plan',
                icon: ClipboardCheck,
                onClick: () => review(kind, id),
            },
        ]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                ...safetyHubCrumbs('care', safetyCan),
                { title: 'Restraints', href: RESTRAINT_REGISTER },
            ]}
        >
            <Head title="Restraints and behaviour support plans" />
            <PageLayout
                hero={
                    <PageHeader
                        wrapTitle
                        icon={ShieldAlert}
                        title="Restraints"
                        subline="Restraints, behaviour support plans and their reviews"
                        actions={
                            <>
                                <PageHeaderSearch
                                    value={search}
                                    onChange={setSearch}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') go({ q: search.trim() || null });
                                    }}
                                    placeholder="Search by person or words in the record"
                                    ariaLabel="Search restraints and plans"
                                />
                                <PageHeaderGlassButton asChild>
                                    <a href={`${RESTRAINT_REGISTER}/export?${query()}`}>
                                        <Download className="size-4" />
                                        Export these records (CSV)
                                    </a>
                                </PageHeaderGlassButton>
                                {can.create && (
                                    <PageHeaderPrimaryButton asChild>
                                        <Link href={report(lens === 'plans' ? 'plan' : 'event')}>
                                            <Plus className="size-4" />
                                            {lens === 'plans' ? 'Add behaviour support plan' : 'Record a restraint'}
                                        </Link>
                                    </PageHeaderPrimaryButton>
                                )}
                            </>
                        }
                        strip={
                            <PageHeaderNumbers>
                                <PageHeaderNumber
                                    label="Needs review"
                                    value={hero.attention.unreviewed}
                                    caption="New or changed since the last review"
                                    tone={hero.attention.unreviewed > 0 ? 'warning' : undefined}
                                    href={href({ ...CLEAR, lens: 'events', tab: 'unreviewed', period: 'all' })}
                                />
                                <PageHeaderNumber
                                    label="Plan reviews due"
                                    value={hero.attention.plans_review_due}
                                    caption="Plans in use that are due for review"
                                    tone={hero.attention.plans_review_due > 0 ? 'warning' : undefined}
                                    href={href({ ...CLEAR, lens: 'plans', tab: 'review_due', period: 'all' })}
                                />
                            </PageHeaderNumbers>
                        }
                        filters={
                            <>
                                <PageHeaderFilterSelect
                                    label="Site"
                                    value={String(filters.site_id ?? 'all')}
                                    options={[
                                        { value: 'all', label: 'All sites' },
                                        ...sites.map((item) => ({ value: String(item.id), label: item.name })),
                                    ]}
                                    onChange={(value) => go({ site_id: value === 'all' ? null : value })}
                                />
                                <PageHeaderFilterSelect
                                    label="Period"
                                    value={filters.period ?? '30d'}
                                    options={[
                                        { value: 'week', label: 'This week' },
                                        { value: '30d', label: 'Last 30 days' },
                                        { value: 'quarter', label: 'Last 3 months' },
                                        { value: 'all', label: 'All time' },
                                    ]}
                                    onChange={(value) => go({ period: value })}
                                />
                                <PageHeaderFilterSelect
                                    label="Person"
                                    value={String(filters.client_id ?? 'all')}
                                    options={[
                                        { value: 'all', label: 'All people' },
                                        ...clients.map((item) => ({ value: String(item.id), label: item.name })),
                                    ]}
                                    onChange={(value) => go({ client_id: value === 'all' ? null : value })}
                                />
                                <MoreFilters lens={lens} filters={filters} active={moreActive} onChange={go} />
                                {narrowed ? (
                                    <PageHeaderFilterButton
                                        icon={X}
                                        onClick={() => go({ ...CLEAR, period: '30d', tab: 'all' })}
                                    >
                                        Clear filters
                                    </PageHeaderFilterButton>
                                ) : null}
                            </>
                        }
                        rail={<SafetyWorkspaceRail hub="care" current="restraints" />}
                    />
                }
                tabs={
                    <div className="grid gap-3">
                        <TierTwoTabs
                            tabs={LENSES}
                            activeTab={lens}
                            onTab={(value) => go({ lens: value, tab: 'all', review_state: null })}
                            testIdPrefix="restraint-register"
                            ariaLabel="Show restraints or plans"
                            panelId="restraint-register-panel"
                            renderLink={() => null}
                        />
                        <TierTwoTabs
                            tabs={tabs.map((item) => ({
                                ...item,
                                count: (tabCounts[lens] as Record<string, number>)[item.key],
                            }))}
                            activeTab={tab}
                            onTab={(value) => go({ tab: value })}
                            testIdPrefix="restraint-view"
                            ariaLabel={lens === 'events' ? 'Restraint views' : 'Plan views'}
                            panelId="restraint-register-panel"
                            // Views carry no href, so the strip renders its own buttons.
                            renderLink={() => null}
                        />
                    </div>
                }
            >
                <div id="restraint-register-panel" role="tabpanel" className="grid min-w-0 gap-5">
                    {lens === 'events' ? (
                        <EventTable
                            rows={events.data}
                            onOpen={(row) => open('events', row.id)}
                            menuFor={(row) => menuFor('events', row.id)}
                            footer={pager}
                        />
                    ) : (
                        <PlanTable
                            rows={plans.data}
                            onOpen={(row) => open('plans', row.id)}
                            menuFor={(row) => menuFor('plans', row.id)}
                            footer={pager}
                        />
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}

/** Type, severity, whether it was in the plan, review and an exact date range — the quieter filters. */
function MoreFilters({
    lens,
    filters,
    active,
    onChange,
}: {
    lens: 'events' | 'plans';
    filters: RestraintFilters;
    active: number;
    onChange: (next: Next) => void;
}) {
    const select = (key: keyof RestraintFilters, label: string, all: string, options: { value: string; label: string }[]) => (
        <FilterField label={label}>
            <SelectInput
                ariaLabel={label}
                placeholder={all}
                value={String(filters[key] ?? 'all')}
                onChange={(value) => onChange({ [key]: value === 'all' ? null : value })}
                options={[{ value: 'all', label: all }, ...options]}
            />
        </FilterField>
    );
    return (
        <Popover>
            <PopoverTrigger asChild>
                <PageHeaderFilterButton icon={SlidersHorizontal} active={active > 0}>
                    {active > 0 ? `More filters · ${active}` : 'More filters'}
                </PageHeaderFilterButton>
            </PopoverTrigger>
            <PopoverContent align="start" className="grid w-80 max-w-[calc(100vw-2rem)] gap-3">
                {select('restraint_type', 'Type', 'All types', RESTRAINT_TYPE_OPTIONS)}
                {lens === 'events' ? (
                    <>
                        {select('severity', 'Severity', 'All severities', SEVERITY_OPTIONS)}
                        {select('within_plan', 'In the plan? (as reported)', 'All', [
                            { value: 'yes', label: 'In the plan' },
                            { value: 'no', label: 'Outside the plan' },
                        ])}
                        {select('review_state', 'Review', 'All', [
                            { value: 'reviewed', label: 'Reviewed' },
                            { value: 'unreviewed', label: 'Needs review (new or changed)' },
                        ])}
                    </>
                ) : (
                    select('review_state', 'Review date', 'All', [
                        { value: 'due', label: 'Due within 30 days' },
                        { value: 'overdue', label: 'Overdue' },
                    ])
                )}
                <div className="grid grid-cols-2 gap-3">
                    <DatePicker
                        compact
                        id="restraint-register-from"
                        label="From"
                        value={filters.from ?? ''}
                        onChange={(value) => onChange({ from: value || null })}
                        allowClear
                    />
                    <DatePicker
                        compact
                        id="restraint-register-to"
                        label="To"
                        value={filters.to ?? ''}
                        onChange={(value) => onChange({ to: value || null })}
                        allowClear
                    />
                </div>
            </PopoverContent>
        </Popover>
    );
}

function FilterField({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <span className="text-caption font-semibold">{label}</span>
            {children}
        </div>
    );
}

const EMPTY = (
    <p role="status" className="sg-quiet px-4 py-6">
        <span>No records match these filters.</span>
    </p>
);

function EventTable({
    rows,
    onOpen,
    menuFor,
    footer,
}: {
    rows: EventRow[];
    onOpen: (row: EventRow) => void;
    menuFor: (row: EventRow) => MenuItem[];
    footer: ReactNode;
}) {
    const columns: RecordTableColumn<EventRow>[] = [
        {
            key: 'person',
            label: 'Person',
            className: 'min-w-[14rem]',
            cell: (row) => (
                <RecordIdentity
                    title={row.client.name}
                    sub={[hsLabel('restraint_type', row.restraint_type), row.site?.name ?? 'Site not recorded', row.reference].join(' · ')}
                />
            ),
        },
        {
            key: 'when',
            label: 'When',
            nowrap: true,
            cell: (row) =>
                row.started_at ? (
                    <RecordIdentity
                        title={formatDate(row.started_at)}
                        sub={`${formatTime(row.started_at)} · ${row.duration_minutes !== null ? `${row.duration_minutes} minutes` : 'duration not recorded'}`}
                    />
                ) : (
                    <span className="text-muted-foreground">Time not recorded</span>
                ),
        },
        {
            key: 'plan',
            label: 'In the plan? (as reported)',
            nowrap: true,
            cell: (row) =>
                row.within_support_plan ? (
                    'In the plan'
                ) : (
                    <StatusBadge variant="critical">Outside the plan</StatusBadge>
                ),
        },
        {
            key: 'injury',
            label: 'Injury',
            nowrap: true,
            cell: (row) =>
                row.injury_occurred ? (
                    <StatusBadge variant="warning">Injury recorded</StatusBadge>
                ) : (
                    'No injury recorded'
                ),
        },
        {
            key: 'severity',
            label: 'Severity',
            nowrap: true,
            cell: (row) => {
                const badge = hsBadge('severity', row.severity);
                return <StatusBadge variant={badge.variant}>{badge.label}</StatusBadge>;
            },
        },
        {
            key: 'review',
            label: 'Review',
            nowrap: true,
            cell: (row) =>
                row.needs_review ? (
                    <StatusBadge variant="warning">Needs review</StatusBadge>
                ) : (
                    <StatusBadge variant="success">Reviewed</StatusBadge>
                ),
        },
    ];
    return (
        <RecordTable
            title="Restraints"
            sub="Newest first · click a row to open it, right-click for more"
            columns={columns}
            rows={rows}
            rowKey={(row) => row.id}
            onOpen={onOpen}
            actionsFor={menuFor}
            menuTitle={(row) => `${row.client.name} · ${row.reference}`}
            menuIcon={ShieldAlert}
            empty={EMPTY}
            footer={footer}
        />
    );
}

function PlanTable({
    rows,
    onOpen,
    menuFor,
    footer,
}: {
    rows: PlanRow[];
    onOpen: (row: PlanRow) => void;
    menuFor: (row: PlanRow) => MenuItem[];
    footer: ReactNode;
}) {
    const columns: RecordTableColumn<PlanRow>[] = [
        {
            key: 'plan',
            label: 'Plan',
            className: 'min-w-[16rem] max-w-[24rem]',
            cell: (row) => <RecordIdentity title={row.title} sub={row.reference} />,
        },
        { key: 'person', label: 'Person', nowrap: true, cell: (row) => <RecordPerson name={row.client.name} /> },
        {
            key: 'practice',
            label: 'Restraint type',
            nowrap: true,
            cell: (row) =>
                row.restrictive_practice_type ? (
                    hsLabel('restraint_type', row.restrictive_practice_type)
                ) : (
                    <span className="text-muted-foreground">Not recorded</span>
                ),
        },
        {
            key: 'next',
            label: 'Next review',
            nowrap: true,
            cell: (row) =>
                row.review_date ? (
                    formatDateOnly(row.review_date)
                ) : (
                    <span className="text-muted-foreground">Review date not recorded</span>
                ),
        },
        {
            key: 'review',
            label: 'Review',
            nowrap: true,
            cell: (row) =>
                row.review_state === 'overdue' ? (
                    <StatusBadge variant="critical">Review overdue</StatusBadge>
                ) : row.review_state === 'due' ? (
                    <StatusBadge variant="warning">Review due</StatusBadge>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'version',
            label: 'Approved version in use',
            nowrap: true,
            cell: (row) =>
                row.published_version_id ? (
                    'Yes'
                ) : (
                    <span className="text-muted-foreground">None in use yet</span>
                ),
        },
        {
            key: 'status',
            label: 'Status',
            nowrap: true,
            cell: (row) => {
                const meta = PLAN_STATUS_META[row.status];
                return (
                    <StatusBadge
                        variant={
                            meta?.tone === 'success' || meta?.tone === 'warning' || meta?.tone === 'critical'
                                ? meta.tone
                                : 'neutral'
                        }
                    >
                        {meta?.label ?? hsLabel('plan_status', row.status)}
                    </StatusBadge>
                );
            },
        },
    ];
    return (
        <RecordTable
            title="Behaviour support plans"
            sub="Open a plan to see who approved it and when"
            columns={columns}
            rows={rows}
            rowKey={(row) => row.id}
            onOpen={onOpen}
            actionsFor={menuFor}
            menuTitle={(row) => `${row.title} · ${row.reference}`}
            menuIcon={FileText}
            empty={EMPTY}
            footer={footer}
        />
    );
}
