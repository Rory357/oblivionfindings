import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import { VotingSwitchedOffBanner } from '@/components/governance/VotingSwitchedOffBanner';
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
import { refSuffix, resolutionChip } from '@/lib/governance-labels';
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
    type WizardVotingRules,
} from './_dialogs';
import { howItPassesLabel, resolutionWorkspaceHref } from './_helpers';

interface ResolutionRow {
    id: number;
    resolution_reference: string;
    title: string;
    status: string;
    purpose?: string | null;
    voting_threshold: string;
    applied_threshold?: string | null;
    deadline: string | null;
    outcome: string | null;
    governance_meeting_id?: number | null;
    meeting: { id: number; title: string; scheduled_at?: string | null } | null;
    committee?: { id: number; name: string } | null;
    proposed_by?: { name: string } | null;
}

interface PendingVote {
    id: number;
    resolution_reference: string;
    title: string;
    deadline?: string | null;
    meeting_id?: number | null;
    vote_href?: string;
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
    summary: {
        total: number;
        draft: number;
        open: number;
        carried: number;
        decided?: number;
    };
    filters: Filters;
    can_create: boolean;
    can_publish?: boolean;
    voting_rules?: { switched_on: boolean; can_switch_on: boolean };
    committees?: CommitteeOption[];
    users?: UserOption[];
    authoritySubjects?: AuthoritySubjects | null;
    authoritySubjectGroups?: AuthoritySubjectGroup[];
    votingRules?: WizardVotingRules | null;
}

const ALL = '__all';

const STATUS_OPTIONS = [
    { value: ALL, label: 'Any status' },
    { value: 'draft', label: 'Draft' },
    { value: 'proposed', label: 'Waiting for the board' },
    { value: 'open', label: 'Open for voting' },
    { value: 'closed', label: 'Voting closed' },
    { value: 'implemented', label: 'Done' },
    { value: 'archived', label: 'Archived' },
];

const OUTCOME_OPTIONS = [
    { value: ALL, label: 'Any result' },
    { value: 'carried', label: 'Passed' },
    { value: 'defeated', label: 'Not passed' },
    { value: 'no_quorum', label: 'No decision — not enough members took part' },
];

export default function ResolutionsIndex({
    resolutions,
    my_pending_votes,
    meetings,
    summary,
    filters,
    can_create,
    can_publish = false,
    voting_rules,
    committees = [],
    users = [],
    authoritySubjects = null,
    authoritySubjectGroups = [],
    votingRules = null,
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

    const pendingById = useMemo(
        () => new Map(my_pending_votes.map((vote) => [vote.id, vote])),
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

    const recordHref = (row: { id: number }) =>
        `/governance/resolutions/${row.id}`;
    const open = (row: ResolutionRow) => router.visit(recordHref(row));

    const actionsFor = (row: ResolutionRow): MenuItem[] => {
        const pending = pendingById.get(row.id);
        return compactMenu([
            {
                label: 'Open resolution',
                icon: FileText,
                onClick: () => open(row),
            },
            pending && {
                label: 'Vote now',
                icon: Vote,
                onClick: () =>
                    router.visit(pending.vote_href ?? resolutionWorkspaceHref(row)),
            },
            row.meeting && { separator: true },
            row.meeting && {
                label: 'Open in meeting',
                icon: CalendarDays,
                onClick: () => router.visit(resolutionWorkspaceHref(row)),
            },
        ]);
    };

    const meetingOptions = [
        { value: ALL, label: 'Any meeting' },
        ...meetings.map((m) => ({
            value: String(m.id),
            label: m.scheduled_at
                ? `${m.title} · ${formatDateLong(m.scheduled_at)}`
                : m.title,
        })),
    ];

    const decided = summary.decided ?? summary.carried;
    const votingSwitchedOff = voting_rules ? !voting_rules.switched_on : false;

    const header = (
        <PageHeader
            icon={Gavel}
            title="Resolutions"
            subline={`Matters the board decides, discusses or notes · ${summary.total} resolution${summary.total === 1 ? '' : 's'}`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search titles, wording or references…"
                    />
                    {can_create ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                            dusk="new-resolution-button"
                        >
                            New resolution
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
                            Being written
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Open for voting"
                        href="/governance/resolutions?status=open"
                        tone={summary.open > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>{summary.open}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Votes happening now
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Waiting for your vote"
                        href="/governance/my-work?kind=vote"
                        tone={
                            my_pending_votes.length > 0 ? 'critical' : 'brand'
                        }
                    >
                        <PageHeaderMeterBig>
                            {my_pending_votes.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {my_pending_votes.length > 0
                                ? 'Open in My work'
                                : 'Nothing to vote on'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Passed"
                        href="/governance/resolutions?outcome=carried"
                        tone={summary.carried > 0 ? 'success' : 'brand'}
                    >
                        <PageHeaderMeterBig>
                            {summary.carried}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {`of ${decided} decided`}
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
                        label="Result"
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
                    {votingSwitchedOff ? (
                        <VotingSwitchedOffBanner
                            canSwitchOn={Boolean(voting_rules?.can_switch_on)}
                        />
                    ) : null}

                    {my_pending_votes.length > 0 ? (
                        <Card className="border-status-warning/40">
                            <CardHeader>
                                <CardTitle className="text-section-title flex items-center gap-2">
                                    <Vote className="size-4 text-status-warning" />
                                    {`Waiting for your vote (${my_pending_votes.length})`}
                                </CardTitle>
                                <CardDescription>
                                    Resolutions open for voting that you can
                                    vote on and haven’t yet.
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
                                                {[
                                                    vote.deadline
                                                        ? `Voting closes ${formatDateTimeLong(vote.deadline)}`
                                                        : vote.meeting_id
                                                          ? 'Voting closes at the meeting'
                                                          : 'No voting deadline set',
                                                    refSuffix(
                                                        vote.resolution_reference,
                                                    ),
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </div>
                                        <Button size="sm" asChild>
                                            <Link
                                                href={
                                                    vote.vote_href ??
                                                    resolutionWorkspaceHref({
                                                        id: vote.id,
                                                        governance_meeting_id:
                                                            vote.meeting_id,
                                                    })
                                                }
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
                        title="Resolutions"
                        caption={`${resolutions.data.length} of ${resolutions.total ?? resolutions.data.length} shown`}
                    />

                    {resolutions.data.length === 0 ? (
                        <EmptyState
                            icon={Gavel}
                            title={
                                hasFilters
                                    ? 'No resolutions match your filters'
                                    : 'No resolutions yet'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : 'A resolution sets out a matter for the board: the exact wording, the options and what it would mean.'
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
                                        New resolution
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={resolutions.data}
                            rowKey={(row) => row.id}
                            identityLabel="Resolution"
                            identity={(row) => ({
                                icon: Gavel,
                                name: row.title,
                                subline: [
                                    row.committee?.name,
                                    refSuffix(row.resolution_reference),
                                ]
                                    .filter(Boolean)
                                    .join(' · '),
                            })}
                            hrefFor={recordHref}
                            onOpen={open}
                            onRowContextMenu={ctxMenu.open}
                            actionsFor={actionsFor}
                            columns={[
                                {
                                    key: 'status',
                                    label: 'Status',
                                    width: '1fr',
                                    cell: (row) => {
                                        const chip = resolutionChip(
                                            row.status,
                                            row.outcome,
                                        );
                                        return (
                                            <EntityStatusChip
                                                variant={chip.variant}
                                            >
                                                {chip.label}
                                            </EntityStatusChip>
                                        );
                                    },
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
                                            <span className="truncate text-muted-foreground">
                                                Vote outside a meeting
                                            </span>
                                        ),
                                },
                                {
                                    key: 'threshold',
                                    label: 'How it passes',
                                    width: '1fr',
                                    cell: (row) => (
                                        <span className="truncate">
                                            {howItPassesLabel(row)}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'deadline',
                                    label: 'Voting closes',
                                    width: '1fr',
                                    cell: (row) =>
                                        row.deadline ? (
                                            formatDateTimeLong(row.deadline)
                                        ) : (
                                            <EmptyValue />
                                        ),
                                },
                                {
                                    key: 'proposer',
                                    label: 'Written by',
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
                    votingRules={votingRules}
                />
            ) : null}
        </AppLayout>
    );
}
