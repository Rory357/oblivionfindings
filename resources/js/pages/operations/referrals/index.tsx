import {
    EntityContextMenu,
    ListCaption,
    useEntityContextMenu,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
    PageLayout,
    type PageHeaderRailItem,
} from '@/components/page';
import { CaseActionDialog } from '@/components/referrals/case-action-dialog';
import {
    StaffIntakeDialog,
    type IntakeDraft,
} from '@/components/referrals/staff-intake-dialog';
import {
    label,
    referralHref,
    type Page,
    type Permissions,
    type ReferralDetail,
    type WorkspaceFilters,
    type WorkspaceFollowUpRow,
    type WorkspaceFundingRow,
    type WorkspaceOptions,
    type WorkspacePipelineLane,
    type WorkspaceReferral,
    type WorkspaceSavedView,
    type WorkspaceStats,
    type WorkspaceView,
} from '@/components/referrals/types';
import { FollowUpCollection } from '@/components/referrals/workspace/follow-up-collection';
import { FundingCollection } from '@/components/referrals/workspace/funding-collection';
import {
    IntakeDrafts,
    type IntakeDraftSummary,
} from '@/components/referrals/workspace/intake-drafts';
import { ReceivedDatesDialog } from '@/components/referrals/workspace/received-dates-dialog';
import {
    referralActions,
    type WorkspaceAction,
} from '@/components/referrals/workspace/referral-actions';
import { ReferralCollection } from '@/components/referrals/workspace/referral-collection';
import { ReferralPipeline } from '@/components/referrals/workspace/referral-pipeline';
import { SavedViews } from '@/components/referrals/workspace/saved-views';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import {
    CalendarClock,
    CalendarDays,
    Columns3,
    Inbox,
    LayoutGrid,
    List,
    Plus,
    Wallet,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Groups = { total: number; overdue: number; today: number };
type Props = {
    view: WorkspaceView;
    referrals: Page<WorkspaceReferral>;
    pipeline: WorkspacePipelineLane[];
    funding: Page<WorkspaceFundingRow>;
    followUps: Page<WorkspaceFollowUpRow>;
    followUpGroups: Groups | null;
    stats: WorkspaceStats;
    counts: { referrals: number; funding: number | null; follow_ups: number };
    savedViews: WorkspaceSavedView[];
    filters: WorkspaceFilters;
    permissions: Permissions;
    options: WorkspaceOptions;
    submissionToken: string;
    referral: ReferralDetail | null;
    drafts: IntakeDraftSummary[];
};
type RailKey = WorkspaceView | 'calendar';
type Patch = Partial<
    Record<keyof WorkspaceFilters, string | number | undefined>
>;

const WORKSPACE_URL = '/operations/referrals';
const DEFAULTS: Record<string, string> = {
    view: 'referrals',
    queue: 'open',
    layout: 'table',
};
const HEADINGS: Record<
    WorkspaceView,
    { title: string; subline: string; icon: typeof Inbox }
> = {
    referrals: {
        title: 'Referrals',
        subline: 'Enquiries, assessment and supported-living handover',
        icon: Inbox,
    },
    funding: {
        title: 'Funding assistance',
        subline: 'Applications, decisions and funding follow-ups',
        icon: Wallet,
    },
    follow_ups: {
        title: 'Follow-ups',
        subline: 'Next actions, due dates and accountable owners',
        icon: CalendarClock,
    },
};
const REFERRAL_QUEUES = [
    { value: 'open', label: 'All open' },
    { value: 'all', label: 'All records' },
    { value: 'mine', label: 'Assigned to me' },
    { value: 'unassigned', label: 'Unassigned' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'due_today', label: 'Follow up today' },
    { value: 'awaiting_contact', label: 'Awaiting human contact' },
    { value: 'waiting', label: 'Waiting for someone else' },
    { value: 'capacity_waitlist', label: 'Capacity waitlist' },
    { value: 'ready_for_handover', label: 'Ready for handover' },
    { value: 'funding_help', label: 'Funding help' },
    { value: 'central', label: 'Central intake' },
];
const FUNDING_QUEUES = [
    { value: 'open', label: 'All open cases' },
    { value: 'mine', label: 'I navigate' },
    { value: 'unassigned', label: 'No navigator' },
    { value: 'overdue', label: 'Funding work overdue' },
];
const FOLLOW_UP_QUEUES = [
    { value: 'open', label: 'All open' },
    { value: 'mine', label: 'Assigned to me' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'due_today', label: 'Due today' },
    { value: 'upcoming', label: 'Not yet due' },
];
const SOURCE_LABELS: Record<string, string> = {
    website: 'Website',
    phone: 'Phone',
    email: 'Email',
    in_person: 'In person',
    agency: 'Agency or professional',
    internal: 'Existing person',
};

/** Drop defaults and blanks so links stay short and comparable. */
function query(filters: Patch): Record<string, string> {
    const result: Record<string, string> = {};
    for (const [key, value] of Object.entries(filters)) {
        if (value === undefined || value === null || value === '') continue;
        if (DEFAULTS[key] === String(value)) continue;
        result[key] = String(value);
    }
    return result;
}

export default function Referrals({
    view,
    referrals,
    pipeline,
    funding,
    followUps,
    followUpGroups,
    stats,
    counts,
    savedViews,
    filters,
    permissions,
    options,
    submissionToken,
    drafts,
    referral,
}: Props) {
    const [search, setSearch] = useState(filters.q ?? '');
    const [creating, setCreating] = useState(false);
    const [resumeDraft, setResumeDraft] = useState<IntakeDraft | null>(null);
    const [dates, setDates] = useState(false);
    const [opening, setOpening] = useState<string | null>(null);
    const context = useEntityContextMenu<WorkspaceReferral>();
    const heading = HEADINGS[view];
    const layout = filters.layout;
    const listLayout = layout === 'pipeline' ? 'table' : layout;
    const base: Patch = {
        ...filters,
        page: undefined,
        focus: undefined,
        act: undefined,
    };

    const visit = (patch: Patch, only?: string[]) =>
        router.get(WORKSPACE_URL, query({ ...base, ...patch }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            ...(only ? { only } : {}),
        });
    /** A queue or saved view starts from a clean filter set in the Referrals view. */
    const openQueue = (queue: string) => {
        setSearch('');
        router.get(WORKSPACE_URL, query({ view: 'referrals', queue, layout }), {
            preserveState: true,
            preserveScroll: true,
        });
    };
    const clearFilters = () => {
        setSearch('');
        router.get(WORKSPACE_URL, query({ view, layout }), {
            preserveState: true,
        });
    };

    // Debounced server search: names go through the hashed index, references by prefix.
    const lastSearch = useRef(filters.q ?? '');
    useEffect(() => {
        lastSearch.current = filters.q ?? '';
        setSearch(filters.q ?? '');
    }, [filters.q]);
    useEffect(() => {
        const value = search.trim();
        if (
            value === lastSearch.current ||
            (value.length > 0 && value.length < 2)
        )
            return;
        const timer = window.setTimeout(() => {
            lastSearch.current = value;
            visit({ q: value || undefined });
        }, 400);
        return () => window.clearTimeout(timer);
        // Only the typed value should schedule a search.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    /** Assign owner / Log contact / stage review open the referral's own wizard here. */
    const act = (reference: string, action: WorkspaceAction) => {
        setOpening(reference);
        router.get(
            WORKSPACE_URL,
            query({
                ...base,
                page: filters.page,
                focus: reference,
                act: action,
            }),
            {
                preserveState: true,
                preserveScroll: true,
                only: ['referral', 'filters'],
                onFinish: () => setOpening(null),
            },
        );
    };
    const closeAction = () =>
        router.get(WORKSPACE_URL, query({ ...base, page: filters.page }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    const actionsFor = (record: WorkspaceReferral) =>
        referralActions(record, permissions, {
            open: () => router.visit(referralHref(record.reference)),
            act: (action) => {
                if (!opening) act(record.reference, action);
            },
            handover: () =>
                router.visit(`${referralHref(record.reference)}?tab=handover`),
        });

    const stages = options.stages;
    const siteOptions = [
        { value: 'all', label: 'All approved sites' },
        ...options.sites.map((site) => ({
            value: String(site.id),
            label: site.name,
        })),
    ];
    const ownerOptions = [
        {
            value: 'all',
            label: view === 'funding' ? 'All navigators' : 'All owners',
        },
        {
            value: 'unassigned',
            label: view === 'funding' ? 'No navigator' : 'Unassigned',
        },
        ...options.owners.map((owner) => ({
            value: String(owner.id),
            label: owner.name,
        })),
    ];
    const pick = (key: keyof WorkspaceFilters) => (value: string) =>
        visit({ [key]: value === 'all' ? undefined : value });
    const receivedLabel =
        filters.received_from || filters.received_to
            ? `Received ${filters.received_from ? formatDateOnly(filters.received_from) : 'any time'} – ${filters.received_to ? formatDateOnly(filters.received_to) : 'today'}`
            : 'Received dates';

    const railItems: PageHeaderRailItem<RailKey>[] = [
        {
            key: 'referrals',
            label: 'Referrals',
            icon: Inbox,
            count: counts.referrals,
        },
        ...(permissions['funding.view'] && counts.funding !== null
            ? [
                  {
                      key: 'funding' as const,
                      label: 'Funding assistance',
                      icon: Wallet,
                      count: counts.funding,
                  },
              ]
            : []),
        {
            key: 'follow_ups',
            label: 'Follow-ups',
            icon: CalendarClock,
            count: counts.follow_ups,
        },
        { key: 'calendar', label: 'Calendar', icon: CalendarDays },
    ];
    const meterPressed = (queue: string) =>
        view === 'referrals' && filters.queue === queue;
    const awaitingShare =
        stats.open > 0 ? (stats.awaiting_contact / stats.open) * 100 : 0;

    const commonFilters = (
        <PageHeaderFilterSelect
            label="Site"
            value={filters.site_id ? String(filters.site_id) : 'all'}
            options={siteOptions}
            onChange={pick('site_id')}
        />
    );
    const layoutToggle = (
        <PageHeaderViewToggle
            ariaLabel="Layout"
            value={view === 'referrals' ? layout : listLayout}
            onChange={(value) => visit({ layout: value })}
            options={[
                { value: 'cards', label: 'Cards', icon: LayoutGrid },
                { value: 'table', label: 'Table', icon: List },
                ...(view === 'referrals'
                    ? [
                          {
                              value: 'pipeline' as const,
                              label: 'Pipeline',
                              icon: Columns3,
                          },
                      ]
                    : []),
            ]}
        />
    );
    const filterRow =
        view === 'funding' ? (
            <>
                <PageHeaderFilterSelect
                    label="Queue"
                    allValue="open"
                    value={filters.queue}
                    options={FUNDING_QUEUES}
                    onChange={(value) => visit({ queue: value })}
                />
                {commonFilters}
                <PageHeaderFilterSelect
                    label="Pathway"
                    value={filters.pathway ?? 'all'}
                    options={[
                        { value: 'all', label: 'All pathways' },
                        ...options.pathways.map((pathway) => ({
                            value: pathway.id,
                            label: pathway.label,
                        })),
                    ]}
                    onChange={pick('pathway')}
                />
                <PageHeaderFilterSelect
                    label="Funding status"
                    value={filters.funding_status ?? 'all'}
                    options={[
                        { value: 'all', label: 'All open statuses' },
                        ...options.funding_statuses.map((status) => ({
                            value: status,
                            label: label(status),
                        })),
                    ]}
                    onChange={pick('funding_status')}
                />
                <PageHeaderFilterSelect
                    label="Navigator"
                    value={filters.owner ?? 'all'}
                    options={ownerOptions}
                    onChange={pick('owner')}
                />
                {layoutToggle}
            </>
        ) : view === 'follow_ups' ? (
            <>
                <PageHeaderFilterSelect
                    label="Due"
                    allValue="open"
                    value={filters.queue}
                    options={FOLLOW_UP_QUEUES}
                    onChange={(value) => visit({ queue: value })}
                />
                {commonFilters}
                <PageHeaderFilterSelect
                    label="Stage"
                    value={filters.stage ?? 'all'}
                    options={[
                        { value: 'all', label: 'All stages' },
                        ...stages.map((stage) => ({
                            value: stage.id,
                            label: stage.label,
                        })),
                    ]}
                    onChange={pick('stage')}
                />
                <PageHeaderFilterSelect
                    label="Work type"
                    value={filters.audience ?? 'all'}
                    options={[
                        { value: 'all', label: 'All permitted work' },
                        { value: 'intake', label: 'Intake follow-ups' },
                        ...(permissions['assessment.view']
                            ? [
                                  {
                                      value: 'assessment',
                                      label: 'Assessment work',
                                  },
                              ]
                            : []),
                        ...(permissions['funding.view']
                            ? [
                                  {
                                      value: 'funding',
                                      label: 'Funding follow-ups',
                                  },
                              ]
                            : []),
                    ]}
                    onChange={pick('audience')}
                />
                <PageHeaderFilterSelect
                    label="Owner"
                    value={filters.owner ?? 'all'}
                    options={ownerOptions}
                    onChange={pick('owner')}
                />
                {layoutToggle}
            </>
        ) : (
            <>
                <PageHeaderFilterSelect
                    label="Queue"
                    allValue="open"
                    value={filters.queue}
                    options={REFERRAL_QUEUES.filter(
                        (queue) =>
                            queue.value !== 'central' ||
                            permissions.centralIntake,
                    )}
                    onChange={(value) => visit({ queue: value })}
                />
                {commonFilters}
                {layout !== 'pipeline' && (
                    <PageHeaderFilterSelect
                        label="Stage"
                        value={filters.stage ?? 'all'}
                        options={[
                            { value: 'all', label: 'All stages' },
                            ...stages.map((stage) => ({
                                value: stage.id,
                                label: stage.label,
                            })),
                        ]}
                        onChange={pick('stage')}
                    />
                )}
                <PageHeaderFilterSelect
                    label="Source"
                    value={filters.source ?? 'all'}
                    options={[
                        { value: 'all', label: 'All sources' },
                        ...options.sources.map((source) => ({
                            value: source,
                            label: SOURCE_LABELS[source] ?? label(source),
                        })),
                    ]}
                    onChange={pick('source')}
                />
                <PageHeaderFilterSelect
                    label="Owner"
                    value={filters.owner ?? 'all'}
                    options={ownerOptions}
                    onChange={pick('owner')}
                />
                <PageHeaderFilterSelect
                    label="Hold reason"
                    value={filters.hold_reason ?? 'all'}
                    options={[
                        { value: 'all', label: 'Any hold reason' },
                        ...options.hold_reasons.map((reason) => ({
                            value: reason.id,
                            label: reason.label,
                        })),
                    ]}
                    onChange={pick('hold_reason')}
                />
                {options.priorities.length > 1 && (
                    <PageHeaderFilterSelect
                        label="Priority"
                        value={filters.priority ?? 'all'}
                        options={[
                            { value: 'all', label: 'All priorities' },
                            ...options.priorities.map((priority) => ({
                                value: priority,
                                label: label(priority),
                            })),
                        ]}
                        onChange={pick('priority')}
                    />
                )}
                <PageHeaderFilterButton
                    icon={CalendarDays}
                    active={!!(filters.received_from || filters.received_to)}
                    onClick={() => setDates(true)}
                >
                    {receivedLabel}
                </PageHeaderFilterButton>
                {layoutToggle}
            </>
        );

    const referralBody =
        layout === 'pipeline' ? (
            pipeline.every((lane) => lane.total === 0) ? null : (
                <div className="grid gap-5">
                    <ListCaption
                        title="Referrals"
                        caption={`${pipeline.reduce((sum, lane) => sum + lane.total, 0)} in this view · up to 8 previewed per stage`}
                    />
                    <ReferralPipeline
                        lanes={pipeline}
                        stages={stages}
                        actionsFor={actionsFor}
                        onContextMenu={(event, record) =>
                            context.open(event, record)
                        }
                        onViewAll={(stage) => visit({ stage, layout: 'table' })}
                    />
                </div>
            )
        ) : referrals.data.length === 0 ? null : (
            <div className="grid gap-5">
                <ListCaption
                    title="Referrals"
                    caption={`${referrals.data.length} of ${referrals.total} shown`}
                />
                <ReferralCollection
                    rows={referrals.data}
                    layout={listLayout}
                    stages={stages}
                    actionsFor={actionsFor}
                    onContextMenu={(event, record) =>
                        context.open(event, record)
                    }
                />
                <LaravelPagination
                    links={referrals.links}
                    lastPage={referrals.last_page}
                />
            </div>
        );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Referrals', href: WORKSPACE_URL },
            ]}
        >
            <Head title={heading.title} />
            <PageLayout
                hero={
                    <PageHeader
                        icon={heading.icon}
                        title={heading.title}
                        subline={heading.subline}
                        titleChip={
                            <PageHeaderStatusChip variant="neutral">
                                {stats.open} open in your scope
                            </PageHeaderStatusChip>
                        }
                        actions={
                            <>
                                <PageHeaderSearch
                                    value={search}
                                    onChange={setSearch}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') {
                                            lastSearch.current = search.trim();
                                            visit({
                                                q: search.trim() || undefined,
                                            });
                                        }
                                    }}
                                    placeholder="Find a person or reference"
                                />
                                <SavedViews
                                    views={savedViews}
                                    active={
                                        view === 'referrals'
                                            ? filters.queue
                                            : null
                                    }
                                    onPick={openQueue}
                                />
                                {permissions.create && (
                                    <PageHeaderPrimaryButton
                                        icon={Plus}
                                        disabled={!options.intake}
                                        title={
                                            options.intake
                                                ? undefined
                                                : 'Publish a referral configuration before recording new intake'
                                        }
                                        onClick={() => {
                                            setResumeDraft(null);
                                            setCreating(true);
                                        }}
                                    >
                                        New referral
                                    </PageHeaderPrimaryButton>
                                )}
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Open referrals"
                                    pressed={meterPressed('open')}
                                    onClick={() => openQueue('open')}
                                >
                                    <PageHeaderMeterBig>
                                        {stats.open}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        View this queue
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Unassigned"
                                    tone={
                                        stats.unassigned > 0
                                            ? 'warning'
                                            : 'brand'
                                    }
                                    pressed={meterPressed('unassigned')}
                                    onClick={() => openQueue('unassigned')}
                                >
                                    <PageHeaderMeterBig>
                                        {stats.unassigned}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        View this queue
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Follow-up overdue"
                                    tone={
                                        stats.overdue > 0 ? 'critical' : 'brand'
                                    }
                                    pressed={meterPressed('overdue')}
                                    onClick={() => openQueue('overdue')}
                                >
                                    <PageHeaderMeterBig>
                                        {stats.overdue}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Human follow-up needed
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Ready for handover"
                                    tone="success"
                                    pressed={meterPressed('ready_for_handover')}
                                    onClick={() =>
                                        openQueue('ready_for_handover')
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {stats.ready_for_handover}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        View this queue
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                {stats.open > 0 && (
                                    <PageHeaderMeterBlock
                                        label="Awaiting human contact"
                                        value={stats.awaiting_contact}
                                        pressed={meterPressed(
                                            'awaiting_contact',
                                        )}
                                        onClick={() =>
                                            openQueue('awaiting_contact')
                                        }
                                    >
                                        <PageHeaderMeterDonut
                                            percent={awaitingShare}
                                            caption={
                                                <>
                                                    {stats.awaiting_contact} of{' '}
                                                    {stats.open} open
                                                    <br />
                                                    no conversation yet
                                                </>
                                            }
                                        />
                                    </PageHeaderMeterBlock>
                                )}
                            </>
                        }
                        filters={filterRow}
                        rail={
                            <PageHeaderRail
                                items={railItems}
                                value={view}
                                ariaLabel="Referral workspace views"
                                onSelect={(key) =>
                                    key === 'calendar'
                                        ? router.visit(
                                              `${WORKSPACE_URL}/calendar`,
                                          )
                                        : router.get(
                                              WORKSPACE_URL,
                                              query({
                                                  view: key,
                                                  layout:
                                                      key === 'referrals'
                                                          ? layout
                                                          : listLayout,
                                                  site_id: filters.site_id,
                                                  q: filters.q,
                                              }),
                                              { preserveState: true },
                                          )
                                }
                            />
                        }
                    />
                }
            >
                <div className="grid gap-5">
                    {permissions.create && view === 'referrals' && (
                        <IntakeDrafts
                            drafts={drafts}
                            onResume={(draft) => {
                                setResumeDraft(draft);
                                setCreating(true);
                            }}
                        />
                    )}
                    {permissions.create && !options.intake && (
                        <Alert>
                            <AlertDescription>
                                Publish a referral configuration before
                                recording new intake.
                            </AlertDescription>
                        </Alert>
                    )}
                    {view === 'referrals' && filters.queue === 'unassigned' && (
                        <Alert>
                            <AlertDescription>
                                Central intake remains accountable until an
                                owner accepts the referral. Assignment escalates
                                to the manager after the configured target.
                            </AlertDescription>
                        </Alert>
                    )}
                    {view === 'funding' ? (
                        <FundingCollection
                            page={funding}
                            layout={listLayout}
                            permissions={permissions}
                            stages={stages}
                            onClear={clearFilters}
                        />
                    ) : view === 'follow_ups' ? (
                        <FollowUpCollection
                            page={followUps}
                            groups={followUpGroups}
                            layout={listLayout}
                            permissions={permissions}
                            stages={stages}
                            onClear={clearFilters}
                        />
                    ) : (
                        (referralBody ?? (
                            <EmptyState
                                icon={Inbox}
                                title="No referrals match this view"
                                description="Change the search, site or stage filter to see other permitted records."
                                action={
                                    <Button
                                        variant="outline"
                                        onClick={clearFilters}
                                    >
                                        Clear filters
                                    </Button>
                                }
                            />
                        ))
                    )}
                    <p className="text-caption text-muted-foreground">
                        Dates shown in NZ time · Private records outside your
                        scope are excluded.
                    </p>
                </div>
            </PageLayout>
            {context.ctx && (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    title={context.ctx.record.name}
                    items={actionsFor(context.ctx.record)}
                    onClose={context.close}
                />
            )}
            {dates && (
                <ReceivedDatesDialog
                    from={filters.received_from}
                    to={filters.received_to}
                    onClose={() => setDates(false)}
                    onApply={(from, to) => {
                        setDates(false);
                        visit({ received_from: from, received_to: to });
                    }}
                />
            )}
            {referral &&
                filters.act &&
                filters.focus === referral.reference && (
                    <CaseActionDialog
                        key={`${referral.reference}-${filters.act}`}
                        action={filters.act}
                        referral={referral}
                        options={options}
                        permissions={permissions}
                        onClose={closeAction}
                    />
                )}
            {creating && options.intake && (
                <StaffIntakeDialog
                    configuration={options.intake}
                    sites={options.sites}
                    permissions={permissions}
                    token={submissionToken}
                    draft={resumeDraft}
                    onClose={() => {
                        setCreating(false);
                        setResumeDraft(null);
                        router.reload();
                    }}
                />
            )}
        </AppLayout>
    );
}
