import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import {
    EmptyValue,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    PersonCell,
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong, formatDateTimeLong } from '@/lib/datetime';
import { show as showResolution } from '@/routes/governance/resolutions';
import { PageProps } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarDays, FileText, Gavel, Plus, Vote, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import {
    ResolutionWizardDialog,
    type AuthoritySubjectGroup,
    type AuthoritySubjects,
    type CommitteeOption,
    type MeetingOption,
    type UserOption,
} from './_dialogs';
import {
    formatThreshold,
    resolutionOutcomeLabel,
    resolutionOutcomeVariant,
    resolutionStatusLabel,
    resolutionStatusVariant,
} from './_helpers';

interface ResolutionRow {
    id: number;
    resolution_reference: string;
    title: string;
    status: string;
    voting_threshold: string;
    deadline: string | null;
    outcome: string | null;
    meeting: { id: number; title: string; scheduled_at?: string | null } | null;
    committee?: { id: number; name: string } | null;
    proposed_by?: { name: string } | null;
}

interface PendingVote {
    id: number;
    resolution_reference: string;
    title: string;
    deadline?: string | null;
}

interface Filters {
    status: string | null;
    outcome: string | null;
    meeting: string | null;
    search: string | null;
}

interface Props extends PageProps {
    resolutions: {
        data: ResolutionRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        total?: number;
        last_page?: number;
    };
    my_pending_votes: PendingVote[];
    meetings: MeetingOption[];
    summary: { total: number; draft: number; open: number; carried: number };
    filters: Filters;
    can_create: boolean;
    can_publish?: boolean;
    committees?: CommitteeOption[];
    users?: UserOption[];
    authoritySubjects?: AuthoritySubjects | null;
    authoritySubjectGroups?: AuthoritySubjectGroup[];
}

const ALL = '__all';

const STATUS_OPTIONS = [
    { value: ALL, label: 'Any status' },
    { value: 'draft', label: 'Draft' },
    { value: 'open', label: 'Open for voting' },
    { value: 'closed', label: 'Voting closed' },
    { value: 'implemented', label: 'Implemented' },
    { value: 'archived', label: 'Archived' },
];

const OUTCOME_OPTIONS = [
    { value: ALL, label: 'Any outcome' },
    { value: 'carried', label: 'Carried' },
    { value: 'defeated', label: 'Defeated' },
    { value: 'no_quorum', label: 'No quorum' },
];

export default function ResolutionsIndex({
    resolutions,
    my_pending_votes,
    meetings,
    summary,
    filters,
    can_create,
    can_publish = false,
    committees = [],
    users = [],
    authoritySubjects = null,
    authoritySubjectGroups = [],
}: Props) {
    const page = usePage();
    const [search, setSearch] = useState(filters.search ?? '');
    const [createOpen, setCreateOpen] = useDialogDeepLink('create', can_create);
    // `/governance/resolutions/create?meeting_id=…` preselects that meeting.
    const [createMeetingId] = useState(() => {
        const id = new URLSearchParams(page.url.split('?')[1] ?? '').get(
            'meeting_id',
        );
        return id && meetings.some((m) => String(m.id) === id) ? id : null;
    });
    const ctxMenu = useEntityContextMenu<ResolutionRow>();

    const pendingIds = useMemo(
        () => new Set(my_pending_votes.map((vote) => vote.id)),
        [my_pending_votes],
    );

    useEffect(() => {
        setSearch(filters.search ?? '');
    }, [filters.search]);

    const go = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        const query = Object.fromEntries(
            Object.entries(merged).filter(([, value]) => value),
        );
        router.get('/governance/resolutions', query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    useEffect(() => {
        const handle = setTimeout(() => {
            if ((filters.search ?? '') !== search) {
                go({ search: search.trim() || null });
            }
        }, 350);
        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const hasFilters = Boolean(
        filters.status || filters.outcome || filters.meeting || filters.search,
    );

    const open = (row: ResolutionRow) =>
        router.visit(showResolution.url({ resolution: row.id }));

    const actionsFor = (row: ResolutionRow): MenuItem[] =>
        compactMenu([
            {
                label: 'Open paper',
                icon: FileText,
                onClick: () => open(row),
            },
            pendingIds.has(row.id) && {
                label: 'Vote now',
                icon: Vote,
                onClick: () => open(row),
            },
            row.meeting && { separator: true },
            row.meeting && {
                label: 'Open in meeting',
                icon: CalendarDays,
                onClick: () =>
                    router.visit(
                        `/governance/meetings/${row.meeting!.id}?tab=resolutions&paper=${row.id}`,
                    ),
            },
        ]);

    const meetingOptions = [
        { value: ALL, label: 'Any meeting' },
        ...meetings.map((m) => ({
            value: String(m.id),
            label: m.scheduled_at
                ? `${m.title} · ${formatDateLong(m.scheduled_at)}`
                : m.title,
        })),
    ];

    const header = (
        <PageHeader
            icon={Gavel}
            title="Resolutions"
            subline={`Decision papers, board votes and outcomes · ${summary.total} paper${summary.total === 1 ? '' : 's'}`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search titles, references or motions…"
                    />
                    {can_create ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                            dusk="new-resolution-button"
                        >
                            New decision paper
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Drafts"
                        href="/governance/resolutions?status=draft"
                    >
                        <PageHeaderMeterBig>{summary.draft}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Papers being prepared
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Open for voting"
                        href="/governance/resolutions?status=open"
                        tone={summary.open > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>{summary.open}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Votes in progress
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Awaiting your vote"
                        href="/governance/my-work?kind=vote"
                        tone={
                            my_pending_votes.length > 0 ? 'critical' : 'brand'
                        }
                    >
                        <PageHeaderMeterBig>
                            {my_pending_votes.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Your outstanding ballots
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Carried"
                        href="/governance/resolutions?outcome=carried"
                        tone={summary.carried > 0 ? 'success' : 'brand'}
                    >
                        <PageHeaderMeterBig>
                            {summary.carried}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            of {summary.total} papers
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status ?? ALL}
                        allValue={ALL}
                        options={STATUS_OPTIONS}
                        onChange={(value) =>
                            go({ status: value === ALL ? null : value })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Outcome"
                        value={filters.outcome ?? ALL}
                        allValue={ALL}
                        options={OUTCOME_OPTIONS}
                        onChange={(value) =>
                            go({ outcome: value === ALL ? null : value })
                        }
                    />
                    <PageHeaderFilterSelect
                        icon={CalendarDays}
                        label="Meeting"
                        value={filters.meeting ?? ALL}
                        allValue={ALL}
                        options={meetingOptions}
                        onChange={(value) =>
                            go({ meeting: value === ALL ? null : value })
                        }
                    />
                </>
            }
            rail={<GovernanceSectionRail />}
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Resolutions', href: '/governance/resolutions' },
            ]}
        >
            <Head title="Resolutions" />
            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {my_pending_votes.length > 0 ? (
                        <Card className="border-status-warning/40">
                            <CardHeader>
                                <CardTitle className="text-section-title flex items-center gap-2">
                                    <Vote className="size-4 text-status-warning" />
                                    Your vote is required (
                                    {my_pending_votes.length})
                                </CardTitle>
                                <CardDescription>
                                    Papers open for voting where you are an
                                    eligible voter and have not yet voted.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col divide-y divide-border">
                                {my_pending_votes.map((vote) => (
                                    <div
                                        key={vote.id}
                                        className="flex items-center justify-between gap-4 py-2.5 first:pt-0 last:pb-0"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium">
                                                {vote.title}
                                            </p>
                                            <p className="text-caption">
                                                {vote.resolution_reference}
                                                {vote.deadline
                                                    ? ` · Closes ${formatDateTimeLong(vote.deadline)}`
                                                    : ''}
                                            </p>
                                        </div>
                                        <Button size="sm" asChild>
                                            <Link
                                                href={showResolution.url({
                                                    resolution: vote.id,
                                                })}
                                            >
                                                Vote now
                                            </Link>
                                        </Button>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    ) : null}

                    <ListCaption
                        title="Decision papers"
                        caption={`${resolutions.data.length} of ${resolutions.total ?? resolutions.data.length} shown`}
                    />

                    {resolutions.data.length === 0 ? (
                        <EmptyState
                            icon={Gavel}
                            title={
                                hasFilters
                                    ? 'No decision papers match your filters'
                                    : 'No decision papers yet'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : 'Decision papers put a motion, its options and implications before the board.'
                            }
                            action={
                                hasFilters ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setSearch('');
                                            go({
                                                status: null,
                                                outcome: null,
                                                meeting: null,
                                                search: null,
                                            });
                                        }}
                                    >
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                ) : can_create ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setCreateOpen(true)}
                                    >
                                        <Plus className="h-3.5 w-3.5" />
                                        New decision paper
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={resolutions.data}
                            rowKey={(row) => row.id}
                            identityLabel="Paper"
                            identity={(row) => ({
                                icon: Gavel,
                                name: row.title,
                                subline: [
                                    row.resolution_reference,
                                    row.committee?.name,
                                ]
                                    .filter(Boolean)
                                    .join(' · '),
                            })}
                            hrefFor={(row) =>
                                showResolution.url({ resolution: row.id })
                            }
                            onOpen={open}
                            onRowContextMenu={ctxMenu.open}
                            actionsFor={actionsFor}
                            columns={[
                                {
                                    key: 'status',
                                    label: 'Status',
                                    width: '0.9fr',
                                    cell: (row) => (
                                        <EntityStatusChip
                                            variant={resolutionStatusVariant(
                                                row.status,
                                            )}
                                        >
                                            {resolutionStatusLabel(row.status)}
                                        </EntityStatusChip>
                                    ),
                                },
                                {
                                    key: 'outcome',
                                    label: 'Outcome',
                                    width: '0.8fr',
                                    cell: (row) =>
                                        row.outcome ? (
                                            <EntityStatusChip
                                                variant={resolutionOutcomeVariant(
                                                    row.outcome,
                                                )}
                                            >
                                                {row.outcome === 'no_quorum'
                                                    ? 'No quorum'
                                                    : resolutionOutcomeLabel(
                                                          row.outcome,
                                                      )}
                                            </EntityStatusChip>
                                        ) : (
                                            <EmptyValue />
                                        ),
                                },
                                {
                                    key: 'meeting',
                                    label: 'Meeting',
                                    width: '1.2fr',
                                    cell: (row) =>
                                        row.meeting ? (
                                            <span className="truncate">
                                                {row.meeting.title}
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Standalone
                                            </span>
                                        ),
                                },
                                {
                                    key: 'threshold',
                                    label: 'Threshold',
                                    width: '1fr',
                                    cell: (row) => (
                                        <span className="truncate">
                                            {formatThreshold(
                                                row.voting_threshold,
                                            )}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'deadline',
                                    label: 'Voting deadline',
                                    width: '0.9fr',
                                    cell: (row) =>
                                        row.deadline ? (
                                            formatDateLong(row.deadline)
                                        ) : (
                                            <EmptyValue />
                                        ),
                                },
                                {
                                    key: 'proposer',
                                    label: 'Proposed by',
                                    width: '1fr',
                                    cell: (row) => (
                                        <PersonCell
                                            name={row.proposed_by?.name}
                                        />
                                    ),
                                },
                            ]}
                        />
                    )}

                    <LaravelPagination
                        links={resolutions.links}
                        lastPage={resolutions.last_page}
                        preserveScroll
                    />
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={Gavel}
                    title={ctxMenu.ctx.record.title}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {can_create ? (
                <ResolutionWizardDialog
                    isOpen={createOpen}
                    onClose={() => setCreateOpen(false)}
                    meetings={meetings}
                    committees={committees}
                    users={users}
                    meetingId={createMeetingId}
                    authoritySubjects={authoritySubjects}
                    authoritySubjectGroups={authoritySubjectGroups}
                    canPublish={can_publish}
                />
            ) : null}
        </AppLayout>
    );
}
