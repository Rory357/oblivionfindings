/**
 * Safeguarding (approved H&S drawings pg-hsn-21, 21b — Stephan 23 Oct 2026):
 * one header with four number boxes and the register filters, the register
 * views as a tier-two strip (like Incidents, its neighbour in Incidents &
 * concerns), then every concern with where it is up to as six stage dots, or
 * the reviews that are due. A row opens the concern's own page; its kebab and
 * right-click carry the same actions, under the same permission and lifecycle
 * gates as the concern itself. Restricted rows stay need-to-know: no detail,
 * no actions.
 */
import {
    SafetyDateFilter,
    SafetyRegisterSearch,
    SafetyWorkspaceRail,
} from '@/components/health-safety/workspace-header';
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
    PageHeaderPrimaryButton,
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
import { EntityFilter } from '@/components/rostering';
import {
    SafeguardingConcernDialog,
    type ActionKey,
    type ConcernDetail,
    type SectionKey,
} from '@/components/safeguarding/concern-dialog';
import { concernRecordHref } from '@/components/safeguarding/concern-record';
import {
    SEVERITY_LABELS,
    SEVERITY_VARIANT,
    concernKind,
    concernRowStage,
    isTerminal,
    titleCase,
    type ConcernRowFlags,
} from '@/components/safeguarding/concern-stages';
import { SafeguardingRaiseWizard } from '@/components/safeguarding/raise-wizard';
import { Note, StageDots } from '@/components/signature/blocks';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import { SelectInput } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatTime } from '@/lib/datetime';
import { incidentWorkspaceHref } from '@/lib/incidents/navigation';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Activity,
    BadgeCheck,
    CheckCircle2,
    ClipboardCheck,
    ClipboardList,
    Clock,
    Eye,
    Landmark,
    LayoutList,
    ListTodo,
    Lock,
    Paperclip,
    Plus,
    Search,
    Shield,
    ShieldAlert,
    SlidersHorizontal,
    UserCheck,
    UserCog,
    User as UserIcon,
    X,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

/* ------------------------------------------------------------------ */
/*  Types                                                               */
/* ------------------------------------------------------------------ */

type ConcernRow = {
    id: number;
    reference_number: string;
    occurred_at: string | null;
    reported_at: string | null;
    concern_type: string;
    abuse_category: string | null;
    description: string | null;
    severity: string;
    status: string;
    current_risk_level: string | null;
    restricted: boolean;
    attachments_count: number;
    subject: {
        name: string | null;
        site: string | null;
        href: string | null;
    } | null;
    subject_informed: boolean;
    assigned_to: { name: string } | null;
    flags: ConcernRowFlags;
    related_incident_id: number | null;
    related_incident_ref: string | null;
    control_room_alert_id: number | null;
    can: { update: boolean; investigate: boolean; report_external: boolean };
};

type ReviewRow = {
    id: number;
    reference_number: string;
    restricted: boolean;
    subject: string | null;
    owner: string | null;
    kind: 'risk' | 'ack';
    detail: string;
    open_section?: SectionKey;
    due_at: string | null;
    overdue: boolean;
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    last_page: number;
    total?: number;
    from?: number | null;
    to?: number | null;
};

type Filters = {
    q: string | null;
    tab: string;
    severity: string | null;
    category: string | null;
    site_id: number | null;
    subject_id: number | null;
    from: string | null;
    to: string | null;
};

type Props = {
    filters: Filters;
    tab: string;
    tabCounts: Record<string, number>;
    rows: Paginated<ConcernRow | ReviewRow>;
    rowsKind: 'concerns' | 'reviews';
    hero: {
        openWork: {
            open: { value: number };
            awaitingTriage: { value: number };
            investigating: { value: number };
            referred: { value: number };
        };
        attention: {
            overdueActions: { value: number };
            reviewsDue: { value: number };
            acksAwaited: { value: number };
            criticalOpen: { value: number };
        };
    };
    referralOverdueCount: number;
    sites: Array<{ id: number; name: string }>;
    subjects: Array<{ id: number; name: string }>;
    staff: Array<{ id: number; name: string }>;
    raise: boolean;
    can: { create: boolean };
    detail: ConcernDetail | null;
};

/* ------------------------------------------------------------------ */
/*  Views and filters                                                  */
/* ------------------------------------------------------------------ */

/** The register's views. The drawn ones sit on the strip; the rest stay one step away in More filters. */
const VIEWS: {
    key: string;
    label: string;
    icon: GroupedProfileNavTab['icon'];
    drawn: boolean;
}[] = [
    { key: 'all', label: 'All', icon: LayoutList, drawn: true },
    { key: 'open', label: 'Open concerns', icon: ClipboardList, drawn: true },
    { key: 'triage', label: 'Awaiting triage', icon: ClipboardList, drawn: true },
    { key: 'investigation', label: 'Under investigation', icon: Search, drawn: true },
    { key: 'action_plan', label: 'Action plan', icon: ListTodo, drawn: true },
    { key: 'monitoring', label: 'Monitoring', icon: Activity, drawn: true },
    { key: 'reviews', label: 'Reviews due', icon: Clock, drawn: true },
    { key: 'referrals', label: 'External referrals', icon: Landmark, drawn: false },
    { key: 'closed', label: 'Closed', icon: CheckCircle2, drawn: false },
    { key: 'mine', label: 'Assigned to me', icon: UserCheck, drawn: false },
];

const CATEGORY_OPTIONS = [
    'physical',
    'sexual',
    'emotional',
    'psychological',
    'financial',
    'discriminatory',
    'institutional',
    'neglect',
    'self-neglect',
    'domestic_violence',
    'modern_slavery',
    'other',
];

const SEV_ORDER = ['critical', 'high', 'medium', 'low'];

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function SafeguardingIndex({
    filters,
    tab,
    tabCounts,
    rows,
    rowsKind,
    hero,
    referralOverdueCount,
    sites,
    subjects,
    staff,
    raise,
    can,
    detail,
}: Props) {
    const registerUrl = usePage().url;
    const [raiseOpen, setRaiseOpen] = useState(raise);

    const go = (next: Partial<Filters>) =>
        router.get(
            '/safeguarding',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    const setTab = (id: string) => {
        if (id === tab) return;
        router.get('/safeguarding', { ...filters, tab: id }, { preserveScroll: true });
    };
    const clearFilters = () =>
        router.get('/safeguarding', { tab }, { preserveState: true, preserveScroll: true, replace: true });

    /** A concern opens on its own page; Back returns here with these filters. */
    const openConcern = (
        id: number,
        options: { section?: SectionKey; action?: ActionKey } = {},
    ) => router.visit(concernRecordHref(id, { ...options, returnTo: registerUrl }));

    // Old ?concern= links still open the concern over the list; closing drops the param.
    const closeDetail = () =>
        router.get('/safeguarding', { ...filters }, { preserveState: true, preserveScroll: true, only: ['detail'] });

    const hasFilters = !!(
        filters.q ||
        filters.severity ||
        filters.category ||
        filters.subject_id ||
        filters.site_id ||
        filters.from ||
        filters.to
    );
    const moreActive = [filters.severity, filters.category].filter(Boolean).length;
    const at = hero.attention;

    const views: GroupedProfileNavTab[] = VIEWS.filter((view) => view.drawn || view.key === tab).map((view) => ({
        key: view.key,
        label: view.label,
        icon: view.icon,
        count: tabCounts[view.key],
    }));

    const concernActions = (c: ConcernRow): MenuItem[] => {
        if (c.restricted) return []; // need-to-know: no actions on a restricted row
        const gate = c.can;
        const terminal = isTerminal(c.status);
        const reported = c.status === 'reported';
        return compactMenu([
            { label: 'View concern', icon: Eye, onClick: () => openConcern(c.id) },
            c.subject?.href && {
                label: 'View subject',
                icon: UserIcon,
                onClick: () => router.visit(c.subject!.href!),
            },
            c.related_incident_id && {
                label: 'View linked incident',
                icon: ShieldAlert,
                onClick: () => router.visit(incidentWorkspaceHref(c.related_incident_id!)),
            },
            { separator: true },
            gate.update && reported && { label: 'Triage', icon: ClipboardCheck, onClick: () => openConcern(c.id, { action: 'triage' }) },
            gate.update && !terminal && { label: 'Assign lead', icon: UserCog, onClick: () => openConcern(c.id, { action: 'assign' }) },
            gate.update && !terminal && { label: 'Add risk assessment', icon: Activity, onClick: () => openConcern(c.id, { action: 'risk' }) },
            gate.investigate && !terminal && !reported && { label: 'Start investigation', icon: Search, onClick: () => openConcern(c.id, { action: 'investigation' }) },
            gate.report_external && !terminal && !reported && { label: 'Log external referral', icon: Landmark, onClick: () => openConcern(c.id, { action: 'report' }) },
            gate.update && !terminal && !reported && { label: 'Add action', icon: ListTodo, onClick: () => openConcern(c.id, { action: 'action' }) },
            gate.update && !terminal && { label: 'Add evidence', icon: Paperclip, onClick: () => openConcern(c.id, { section: 'evidence' }) },
            gate.update &&
                !terminal &&
                !c.subject_informed && {
                    label: 'Mark subject informed',
                    icon: BadgeCheck,
                    onClick: () => router.post(`/safeguarding/${c.id}/subject-informed`, {}, { preserveScroll: true }),
                },
            gate.update &&
                c.status === 'action_plan' && {
                    label: 'Move to monitoring',
                    icon: Activity,
                    onClick: () =>
                        router.patch(`/safeguarding/${c.id}/status`, { status: 'monitoring' }, { preserveScroll: true }),
                },
            gate.update && !terminal && !reported && { separator: true },
            gate.update && !terminal && !reported && { label: 'Close concern', icon: CheckCircle2, danger: true, onClick: () => openConcern(c.id, { action: 'close' }) },
        ]);
    };

    const pager = (
        <>
            <p className="text-subtle">
                {rows.total !== undefined
                    ? rows.from && rows.to
                        ? `Showing ${rows.from}–${rows.to} of ${rows.total}`
                        : `Showing ${rows.data.length} of ${rows.total}`
                    : `Showing ${rows.data.length}`}
            </p>
            {rows.last_page > 1 ? <LaravelPagination links={rows.links} /> : null}
        </>
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & safety', href: '/health-safety' },
                { title: 'Safeguarding', href: '/safeguarding' },
            ]}
        >
            <Head title="Safeguarding" />
            <PageLayout
                hero={
                    <PageHeader
                        title="Safeguarding"
                        icon={ShieldAlert}
                        wrapTitle
                        subline="Protecting people at risk of abuse, neglect or harm"
                        actions={
                            <>
                                <SafetyRegisterSearch
                                    key={filters.q}
                                    query={filters.q ?? ''}
                                    onSearch={(q) => go({ q: q || null })}
                                    placeholder="Search concerns"
                                />
                                {can.create ? (
                                    <PageHeaderPrimaryButton icon={Plus} onClick={() => setRaiseOpen(true)}>
                                        Raise concern
                                    </PageHeaderPrimaryButton>
                                ) : null}
                            </>
                        }
                        strip={
                            <PageHeaderNumbers>
                                <PageHeaderNumber
                                    label="Overdue actions"
                                    value={at.overdueActions.value}
                                    caption={at.overdueActions.value > 0 ? 'Past due' : 'All on track'}
                                    tone={at.overdueActions.value > 0 ? 'critical' : undefined}
                                    onClick={() => setTab('action_plan')}
                                    pressed={tab === 'action_plan'}
                                />
                                <PageHeaderNumber
                                    label="Risk reviews due"
                                    value={at.reviewsDue.value}
                                    caption={at.reviewsDue.value > 0 ? 'Review now' : 'None due'}
                                    tone={at.reviewsDue.value > 0 ? 'warning' : undefined}
                                    onClick={() => setTab('reviews')}
                                    pressed={tab === 'reviews'}
                                />
                                <PageHeaderNumber
                                    label="Replies awaited"
                                    value={at.acksAwaited.value}
                                    caption="From authorities"
                                    tone={at.acksAwaited.value > 0 ? 'warning' : undefined}
                                    onClick={() => setTab('reviews')}
                                />
                                <PageHeaderNumber
                                    label="Critical concerns"
                                    value={at.criticalOpen.value}
                                    caption="Still open"
                                    tone={at.criticalOpen.value > 0 ? 'critical' : undefined}
                                    onClick={() => go({ tab: 'open', severity: 'critical' })}
                                    pressed={tab === 'open' && filters.severity === 'critical'}
                                />
                            </PageHeaderNumbers>
                        }
                        filters={
                            <>
                                {sites?.length ? (
                                    <EntityFilter
                                        label="Site"
                                        allLabel="All sites"
                                        items={sites}
                                        value={filters.site_id}
                                        onChange={(id) => go({ site_id: id })}
                                        onDark
                                    />
                                ) : null}
                                <SafetyDateFilter from={filters.from} to={filters.to} onChange={go} />
                                {subjects?.length ? (
                                    <EntityFilter
                                        label="Subject"
                                        allLabel="All subjects"
                                        items={subjects}
                                        value={filters.subject_id}
                                        onChange={(id) => go({ subject_id: id })}
                                        onDark
                                    />
                                ) : null}
                                <MoreFilters
                                    active={moreActive}
                                    filters={filters}
                                    tab={tab}
                                    tabCounts={tabCounts}
                                    onTab={setTab}
                                    onChange={go}
                                />
                                {hasFilters ? (
                                    <PageHeaderFilterButton icon={X} onClick={clearFilters}>
                                        Clear filters
                                    </PageHeaderFilterButton>
                                ) : null}
                            </>
                        }
                        rail={<SafetyWorkspaceRail hub="concerns" current="safeguarding" />}
                    />
                }
                tabs={
                    <TierTwoTabs
                        tabs={views}
                        activeTab={tab}
                        onTab={setTab}
                        testIdPrefix="safeguarding-register"
                        ariaLabel="Safeguarding views"
                        panelId="safeguarding-register-panel"
                        // Views carry no href, so the strip renders its own buttons.
                        renderLink={() => null}
                    />
                }
            >
                <div
                    id="safeguarding-register-panel"
                    role="tabpanel"
                    aria-label={VIEWS.find((view) => view.key === tab)?.label ?? 'Safeguarding'}
                    className="grid min-w-0 gap-5"
                >
                    {referralOverdueCount > 0 && (tab === 'all' || tab === 'referrals') ? (
                        <Note tone="warning" icon={Landmark}>
                            <b>
                                {referralOverdueCount} concern
                                {referralOverdueCount === 1 ? '' : 's'} flagged for external referral with no report
                                logged.
                            </b>{' '}
                            A referral was indicated at triage but no authority has been notified — log the report to
                            advance the concern.
                        </Note>
                    ) : null}

                    {rowsKind === 'concerns' ? (
                        <ConcernTable
                            rows={rows.data as ConcernRow[]}
                            actionsFor={concernActions}
                            onOpen={(row) => openConcern(row.id)}
                            footer={pager}
                        />
                    ) : (
                        <ReviewTable
                            rows={rows.data as ReviewRow[]}
                            onOpen={(row) => openConcern(row.id, row.open_section ? { section: row.open_section } : {})}
                            footer={pager}
                        />
                    )}
                </div>
            </PageLayout>

            {detail ? (
                <SafeguardingConcernDialog key={detail.id} detail={detail} open onClose={closeDetail} />
            ) : null}

            {raiseOpen && can.create ? (
                <SafeguardingRaiseWizard
                    open
                    clients={subjects}
                    staff={staff}
                    sites={sites}
                    onClose={() => setRaiseOpen(false)}
                    onOpenConcern={(id) => {
                        setRaiseOpen(false);
                        openConcern(id);
                    }}
                />
            ) : null}
        </AppLayout>
    );
}

/** The two quieter filters, plus every register view, in one place. */
function MoreFilters({
    active,
    filters,
    tab,
    tabCounts,
    onTab,
    onChange,
}: {
    active: number;
    filters: Filters;
    tab: string;
    tabCounts: Record<string, number>;
    onTab: (tab: string) => void;
    onChange: (next: Partial<Filters>) => void;
}) {
    const [open, setOpen] = useState(false);
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <PageHeaderFilterButton icon={SlidersHorizontal} active={active > 0}>
                    {active > 0 ? `More filters · ${active}` : 'More filters'}
                </PageHeaderFilterButton>
            </PopoverTrigger>
            <PopoverContent align="start" className="grid w-80 max-w-[calc(100vw-2rem)] gap-3">
                <FilterField label="Safeguarding view">
                    <SelectInput
                        ariaLabel="Safeguarding view"
                        placeholder="Safeguarding view"
                        value={tab}
                        onChange={(value) => {
                            setOpen(false);
                            onTab(value);
                        }}
                        options={VIEWS.map((view) => ({
                            value: view.key,
                            label: `${view.label} (${tabCounts[view.key] ?? 0})`,
                        }))}
                    />
                </FilterField>
                <FilterField label="Severity">
                    <SelectInput
                        ariaLabel="Severity"
                        placeholder="All severities"
                        value={filters.severity ?? 'all'}
                        onChange={(value) => onChange({ severity: value === 'all' ? null : value })}
                        options={[
                            { value: 'all', label: 'All severities' },
                            ...SEV_ORDER.map((value) => ({ value, label: SEVERITY_LABELS[value] })),
                        ]}
                    />
                </FilterField>
                <FilterField label="Category">
                    <SelectInput
                        ariaLabel="Category"
                        placeholder="All categories"
                        value={filters.category ?? 'all'}
                        onChange={(value) => onChange({ category: value === 'all' ? null : value })}
                        options={[
                            { value: 'all', label: 'All categories' },
                            ...CATEGORY_OPTIONS.map((value) => ({ value, label: titleCase(value) })),
                        ]}
                    />
                </FilterField>
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

/* ------------------------------------------------------------------ */
/*  Concern table                                                      */
/* ------------------------------------------------------------------ */

function ConcernTable({
    rows,
    actionsFor,
    onOpen,
    footer,
}: {
    rows: ConcernRow[];
    actionsFor: (row: ConcernRow) => MenuItem[];
    onOpen: (row: ConcernRow) => void;
    footer: ReactNode;
}) {
    const columns: RecordTableColumn<ConcernRow>[] = [
        {
            key: 'reference',
            label: 'Reference',
            nowrap: true,
            cell: (c) => (
                <RecordIdentity
                    title={c.reference_number}
                    sub={c.occurred_at ? `${formatDate(c.occurred_at)}, ${formatTime(c.occurred_at)}` : 'Time not recorded'}
                />
            ),
        },
        {
            key: 'concern',
            label: 'Concern',
            className: 'min-w-[16rem] max-w-[24rem]',
            cell: (c) =>
                c.restricted ? (
                    <div className="flex min-w-0 items-center gap-2.5">
                        <span className="flex size-[30px] shrink-0 items-center justify-center rounded-[8px] bg-muted text-muted-foreground">
                            <Lock aria-hidden="true" className="size-4" />
                        </span>
                        <div className="min-w-0">
                            <b className="block font-semibold text-foreground">Restricted · need-to-know</b>
                            <span className="text-subtle block">Visible to the assigned lead and reporter only.</span>
                        </div>
                    </div>
                ) : (
                    <RecordIdentity
                        title={concernKind(c.concern_type, c.abuse_category)}
                        sub={c.description ? <span className="line-clamp-2">{c.description}</span> : undefined}
                    />
                ),
        },
        {
            key: 'subject',
            label: 'Subject',
            nowrap: true,
            cell: (c) =>
                !c.restricted && c.subject?.name ? (
                    <RecordIdentity title={c.subject.name} sub={c.subject.site ?? undefined} />
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'severity',
            label: 'Severity',
            nowrap: true,
            cell: (c) => (
                <StatusBadge variant={SEVERITY_VARIANT[c.severity] ?? 'neutral'}>
                    {SEVERITY_LABELS[c.severity] ?? titleCase(c.severity)}
                </StatusBadge>
            ),
        },
        {
            key: 'stage',
            label: 'Where it’s up to',
            nowrap: true,
            cell: (c) => {
                const stage = concernRowStage(c);
                return <StageDots stage={stage.stage} of={6} label={stage.label} note={stage.note} tone={stage.tone} />;
            },
        },
        {
            key: 'assigned',
            label: 'Assigned',
            nowrap: true,
            cell: (c) =>
                c.assigned_to ? <RecordPerson name={c.assigned_to.name} /> : <span className="text-muted-foreground">Unassigned</span>,
        },
    ];
    return (
        <RecordTable
            title="Concerns"
            sub="Every concern is triaged, risk-assessed and worked through to a safe close — confidential, blame-free, and visible only to those who need to know."
            columns={columns}
            rows={rows}
            rowKey={(c) => c.id}
            onOpen={onOpen}
            canOpen={(c) => !c.restricted}
            actionsFor={actionsFor}
            menuTitle={(c) => c.reference_number}
            menuIcon={Shield}
            empty={
                <p className="sg-quiet px-4 py-6">
                    <span>
                        <b>No concerns here.</b> Nothing matches this view and these filters.
                    </span>
                </p>
            }
            footer={footer}
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Reviews due                                                        */
/* ------------------------------------------------------------------ */

function ReviewTable({
    rows,
    onOpen,
    footer,
}: {
    rows: ReviewRow[];
    onOpen: (row: ReviewRow) => void;
    footer: ReactNode;
}) {
    const columns: RecordTableColumn<ReviewRow>[] = [
        { key: 'reference', label: 'Reference', nowrap: true, cell: (r) => <b className="font-semibold">{r.reference_number}</b> },
        {
            key: 'due-what',
            label: 'What’s due',
            className: 'min-w-[14rem]',
            cell: (r) => (
                <RecordIdentity
                    leading={r.kind === 'risk' ? <Clock aria-hidden="true" className="size-4" /> : <Landmark aria-hidden="true" className="size-4" />}
                    title={r.kind === 'risk' ? 'Risk review' : 'Reply from the authority'}
                    sub={r.kind === 'risk' ? undefined : r.detail.replace(/^Acknowledgement awaited · /, '')}
                />
            ),
        },
        {
            key: 'subject',
            label: 'Subject',
            nowrap: true,
            cell: (r) =>
                r.restricted ? (
                    <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                        <Lock aria-hidden="true" className="size-3.5" /> Restricted
                    </span>
                ) : (
                    (r.subject ?? <span className="text-muted-foreground">—</span>)
                ),
        },
        {
            key: 'owner',
            label: 'Owner',
            nowrap: true,
            cell: (r) => (r.owner ? <RecordPerson name={r.owner} /> : <span className="text-muted-foreground">Unassigned</span>),
        },
        {
            key: 'due',
            label: 'Due',
            nowrap: true,
            cell: (r) =>
                !r.due_at ? (
                    <span className="text-muted-foreground">—</span>
                ) : r.overdue ? (
                    <StatusBadge variant="critical">{formatDate(r.due_at)} · overdue</StatusBadge>
                ) : (
                    formatDate(r.due_at)
                ),
        },
    ];
    return (
        <RecordTable
            title="Reviews due"
            sub="Risk reviews, and replies due from authorities · click a row to review it or record the reply"
            columns={columns}
            rows={rows}
            rowKey={(r) => `${r.id}-${r.kind}-${r.due_at ?? ''}`}
            onOpen={onOpen}
            actionsFor={(r) => [
                {
                    label: r.kind === 'risk' ? 'Review' : 'Record the reply',
                    icon: r.kind === 'risk' ? Clock : Landmark,
                    onClick: () => onOpen(r),
                },
            ]}
            menuTitle={(r) => r.reference_number}
            menuIcon={Clock}
            empty={
                <p className="sg-quiet px-4 py-6">
                    <span>
                        <b>Nothing to review.</b> No risk reviews are due and every authority has acknowledged.
                    </span>
                </p>
            }
            footer={footer}
        />
    );
}
