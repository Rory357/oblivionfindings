import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import { GovernanceTermHint } from '@/components/governance/GovernanceTermHint';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import { meetingWorkspaceUrl } from '@/components/governance/meeting-workspace-links';
import {
    EmptyValue,
    EntityChip,
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
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import AppLayout from '@/layouts/app-layout';
import {
    formatDate,
    formatDateLong,
    formatDateOnly,
    formatDurationMinutes,
    formatTime,
} from '@/lib/datetime';
import { governanceLabel, meetingTypeLabel } from '@/lib/governance-labels';
import { canDoGovernance } from '@/lib/governance-permissions';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarDays,
    CalendarRange,
    ClipboardList,
    ExternalLink,
    Lock,
    Pencil,
    Plus,
    Vote,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { MeetingWizardDialog, type MeetingFormOptions } from './_dialogs';
import { MEETING_STATUS_FILTERS, meetingStatusChip } from './_workspace';

type QuorumState = 'met' | 'not_met' | 'not_recorded' | 'upcoming' | 'cancelled';

interface Meeting {
    id: number;
    title: string;
    meeting_type: string;
    scheduled_at: string;
    duration_minutes: number;
    location: string | null;
    virtual_link?: string | null;
    status: string;
    quorum_met: boolean;
    /** What the register can truthfully say about the quorum (server-derived). */
    quorum_state?: QuorumState;
    /** The viewer runs this meeting (edits it or handles its minutes). */
    can_run?: boolean;
    committee?: { id: number; name: string } | null;
    chair?: { user: { name: string } } | null;
    secretary?: { user: { name: string } } | null;
}

type Filters = {
    status: string | null;
    meeting_type: string | null;
    from: string | null;
    to: string | null;
    search: string | null;
};

interface Props extends PageProps {
    meetings: {
        data: Meeting[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        last_page?: number;
        total?: number;
    };
    filters?: Filters;
    summary?: {
        total: number;
        upcoming: number;
        minutes_pending: number;
        held: number;
        /** Held meetings whose quorum is known (attendance was recorded). */
        held_recorded?: number;
        held_quorum_met: number;
        next_meeting: { id: number; title: string; scheduled_at: string } | null;
        today: string;
    };
    meetingTypes?: Record<string, string>;
    canCreate?: boolean;
    formOptions?: MeetingFormOptions | null;
    initialScheduledAt?: string | null;
}

const ALL = '__all';
const EMPTY_FILTERS: Filters = {
    status: null,
    meeting_type: null,
    from: null,
    to: null,
    search: null,
};

function QuorumCell({ state }: { state?: QuorumState }) {
    switch (state) {
        case 'met':
            return <EntityStatusChip variant="success">Met</EntityStatusChip>;
        case 'not_met':
            return <EntityStatusChip variant="warning">Not met</EntityStatusChip>;
        case 'not_recorded':
            return <EntityStatusChip variant="neutral">Not recorded</EntityStatusChip>;
        default:
            // Before a meeting (or for a cancelled one) there's nothing to say yet.
            return <EmptyValue />;
    }
}

export default function MeetingsIndex({
    auth,
    meetings,
    filters = EMPTY_FILTERS,
    summary,
    meetingTypes = {},
    canCreate = false,
    formOptions = null,
    initialScheduledAt = null,
}: Props) {
    const canSchedule = canCreate && formOptions !== null;
    // Members reach Meetings from Home, and have no Meetings hub in the
    // sidebar — give them the way back.
    const governancePermissions =
        (auth as { can?: { governance?: Record<string, unknown> } })?.can
            ?.governance ?? null;
    const showHomeLink = !canDoGovernance(governancePermissions, 'meetings', 'manage');
    // Retired /meetings/create deep links (and calendar slots) arrive as ?create=1.
    const [createOpen, setCreateOpen] = useDialogDeepLink('create', canSchedule);
    const [search, setSearch] = useState(filters.search ?? '');
    const [range, setRange] = useState({
        from: filters.from ?? '',
        to: filters.to ?? '',
    });
    const [rangeOpen, setRangeOpen] = useState(false);
    const ctxMenu = useEntityContextMenu<Meeting>();

    useEffect(() => {
        setSearch(filters.search ?? '');
    }, [filters.search]);

    const go = (patch: Partial<Filters>) => {
        const merged = { ...filters, ...patch };
        router.get(
            '/governance/meetings',
            Object.fromEntries(Object.entries(merged).filter(([, value]) => value)),
            { preserveState: true, preserveScroll: true, replace: true },
        );
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

    const hasFilters = Object.values(filters).some(Boolean);
    const clearFilters = () => {
        setSearch('');
        setRange({ from: '', to: '' });
        router.get('/governance/meetings', {}, { preserveScroll: true });
    };

    const open = (meeting: Meeting, tab?: string) =>
        router.visit(meetingWorkspaceUrl(meeting.id, { tab }));

    const actionsFor = (meeting: Meeting): MenuItem[] =>
        compactMenu([
            {
                label: 'Open meeting',
                icon: ExternalLink,
                onClick: () => open(meeting),
            },
            {
                label: 'Resolutions',
                icon: Vote,
                onClick: () => open(meeting, 'resolutions'),
            },
            {
                label: 'Minutes',
                icon: Pencil,
                onClick: () => open(meeting, 'minutes'),
            },
            // The checklist is the chair and secretary's; nobody else has a Workflow tab.
            meeting.can_run
                ? {
                      label: 'Workflow checklist',
                      icon: ClipboardList,
                      onClick: () => open(meeting, 'workflow'),
                  }
                : null,
        ]);

    const typeOptions = [
        { value: ALL, label: 'Any type' },
        ...Object.entries(meetingTypes).map(([value, label]) => ({ value, label })),
    ];
    const statusOptions = [
        { value: ALL, label: 'Any status' },
        { value: 'upcoming', label: governanceLabel('meeting_status', 'upcoming') },
        { value: 'minutes_pending', label: governanceLabel('meeting_status', 'minutes_pending') },
        ...MEETING_STATUS_FILTERS.map((value) => ({
            value,
            label: meetingStatusChip(value).label,
        })),
    ];
    const rangeLabel =
        filters.from || filters.to
            ? `${filters.from ? formatDateOnly(filters.from) : 'Start'} – ${filters.to ? formatDateOnly(filters.to) : 'Any'}`
            : 'Any date';

    const next = summary?.next_meeting ?? null;
    const heldRecorded = summary ? (summary.held_recorded ?? summary.held) : 0;
    const heldPct =
        summary && heldRecorded > 0
            ? Math.round((summary.held_quorum_met / heldRecorded) * 100)
            : null;

    const header = (
        <PageHeader
            icon={CalendarDays}
            title="Meetings"
            subline={`Board and committee meetings · ${summary?.upcoming ?? 0} coming up · ${summary?.total ?? meetings.total ?? meetings.data.length} on record`}
            actions={
                <>
                    {showHomeLink ? (
                        <PageHeaderGlassButton
                            icon={ArrowLeft}
                            onClick={() => router.visit('/governance/dashboard')}
                        >
                            Governance home
                        </PageHeaderGlassButton>
                    ) : null}
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search meeting titles and venues…"
                    />
                    <PageHeaderGlassButton
                        icon={CalendarRange}
                        onClick={() => router.visit('/governance/meetings/calendar')}
                    >
                        Calendar
                    </PageHeaderGlassButton>
                    {canSchedule ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                            dusk="schedule-meeting"
                        >
                            Schedule meeting
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                summary ? (
                    <>
                        <PageHeaderMeterBlock
                            label="Next meeting"
                            href={
                                next
                                    ? meetingWorkspaceUrl(next.id)
                                    : '/governance/meetings?status=upcoming'
                            }
                            ariaLabel={
                                next
                                    ? `Open the next meeting: ${next.title}`
                                    : 'Show meetings coming up'
                            }
                        >
                            <PageHeaderMeterBig>
                                {next ? formatDate(next.scheduled_at) : 'None'}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {next
                                    ? `${formatTime(next.scheduled_at)} · ${next.title}`
                                    : 'No meeting scheduled'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Coming up"
                            href="/governance/meetings?status=upcoming"
                            ariaLabel="Show meetings coming up"
                        >
                            <PageHeaderMeterBig>{summary.upcoming}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>Scheduled from today</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Minutes to finish"
                            href="/governance/meetings?status=minutes_pending"
                            ariaLabel="Show meetings whose minutes aren't finished"
                            tone={summary.minutes_pending > 0 ? 'warning' : 'brand'}
                        >
                            <PageHeaderMeterBig>{summary.minutes_pending}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Being written or waiting for approval
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Quorum met"
                            value={
                                heldPct !== null
                                    ? `${summary.held_quorum_met}/${heldRecorded}`
                                    : undefined
                            }
                            href={`/governance/meetings?to=${summary.today}`}
                            ariaLabel="Show meetings already held"
                        >
                            {heldPct !== null ? (
                                <PageHeaderMeterDonut
                                    percent={heldPct}
                                    caption={
                                        <>
                                            {summary.held_quorum_met} of {heldRecorded}
                                            <br />
                                            with attendance recorded
                                        </>
                                    }
                                />
                            ) : (
                                <>
                                    <PageHeaderMeterBig>—</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {summary.held > 0
                                            ? 'No attendance recorded yet'
                                            : 'No meetings held yet'}
                                    </PageHeaderMeterCaption>
                                </>
                            )}
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="All meetings"
                            href="/governance/meetings"
                            ariaLabel="Show every meeting"
                        >
                            <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>Scheduled and held</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                ) : undefined
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Any status"
                        value={filters.status ?? ALL}
                        allValue={ALL}
                        options={statusOptions}
                        onChange={(value) => go({ status: value === ALL ? null : value })}
                    />
                    <PageHeaderFilterSelect
                        label="Any type"
                        value={filters.meeting_type ?? ALL}
                        allValue={ALL}
                        options={typeOptions}
                        onChange={(value) => go({ meeting_type: value === ALL ? null : value })}
                    />
                    <Popover open={rangeOpen} onOpenChange={setRangeOpen}>
                        <PopoverTrigger asChild>
                            <PageHeaderFilterButton
                                icon={CalendarRange}
                                active={Boolean(filters.from || filters.to)}
                                aria-label="Filter by meeting date range"
                            >
                                {rangeLabel}
                            </PageHeaderFilterButton>
                        </PopoverTrigger>
                        <PopoverContent align="end" className="w-64">
                            <form
                                className="flex flex-col gap-3"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    setRangeOpen(false);
                                    go({ from: range.from || null, to: range.to || null });
                                }}
                            >
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="meetings-from">From</Label>
                                    <Input
                                        id="meetings-from"
                                        type="date"
                                        value={range.from}
                                        onChange={(e) => setRange((r) => ({ ...r, from: e.target.value }))}
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="meetings-to">To</Label>
                                    <Input
                                        id="meetings-to"
                                        type="date"
                                        value={range.to}
                                        onChange={(e) => setRange((r) => ({ ...r, to: e.target.value }))}
                                    />
                                </div>
                                <div className="flex justify-between gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setRange({ from: '', to: '' });
                                            setRangeOpen(false);
                                            go({ from: null, to: null });
                                        }}
                                    >
                                        Clear
                                    </Button>
                                    <Button type="submit" size="sm">
                                        Apply range
                                    </Button>
                                </div>
                            </form>
                        </PopoverContent>
                    </Popover>
                </>
            }
            rail={<GovernanceSectionRail />}
        />
    );

    const total = meetings.total ?? meetings.data.length;

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Meetings', href: '/governance/meetings' },
            ]}
        >
            <Head title="Meetings" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={filters.status === 'upcoming' ? 'Meetings coming up' : 'Meetings'}
                        caption={`${meetings.data.length} of ${total} shown`}
                        right={
                            meetings.data.length > 0 ? (
                                <span className="text-caption flex items-center gap-1">
                                    Quorum: enough members present for decisions to be valid
                                    <GovernanceTermHint term="quorum" align="end" />
                                </span>
                            ) : undefined
                        }
                    />

                    {meetings.data.length === 0 ? (
                        <EmptyState
                            icon={CalendarDays}
                            title={hasFilters ? 'No meetings match your filters' : 'No meetings yet'}
                            description={
                                hasFilters
                                    ? 'Try clearing a filter, search term or date range.'
                                    : 'Board and committee meetings appear here once they are scheduled.'
                            }
                            action={
                                hasFilters ? (
                                    <Button variant="outline" size="sm" onClick={clearFilters}>
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                ) : canSchedule ? (
                                    <Button size="sm" onClick={() => setCreateOpen(true)}>
                                        <Plus className="h-3.5 w-3.5" />
                                        Schedule meeting
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={meetings.data}
                            rowKey={(meeting) => meeting.id}
                            identityLabel="Meeting"
                            identity={(meeting) => ({
                                icon: meeting.meeting_type === 'executive_session' ? Lock : CalendarDays,
                                name: meeting.title,
                                subline: meeting.committee?.name ?? meetingTypeLabel(meeting.meeting_type),
                            })}
                            hrefFor={(meeting) => meetingWorkspaceUrl(meeting.id)}
                            onOpen={(meeting) => open(meeting)}
                            onRowContextMenu={ctxMenu.open}
                            actionsFor={actionsFor}
                            minWidth={1080}
                            columns={[
                                {
                                    key: 'when',
                                    label: 'When',
                                    width: '1.1fr',
                                    cell: (meeting) => (
                                        <span className="flex min-w-0 flex-col">
                                            <span className="truncate font-medium">
                                                {formatDateLong(meeting.scheduled_at)}
                                            </span>
                                            <span className="truncate text-xs text-muted-foreground">
                                                {formatTime(meeting.scheduled_at)} ·{' '}
                                                {formatDurationMinutes(meeting.duration_minutes)}
                                            </span>
                                        </span>
                                    ),
                                },
                                {
                                    key: 'type',
                                    label: 'Type',
                                    width: '0.9fr',
                                    cell: (meeting) => (
                                        <EntityChip
                                            icon={meeting.meeting_type === 'executive_session' ? Lock : undefined}
                                        >
                                            {meetingTypeLabel(meeting.meeting_type)}
                                        </EntityChip>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    width: '0.9fr',
                                    cell: (meeting) => {
                                        const chip = meetingStatusChip(meeting.status);
                                        return <EntityStatusChip variant={chip.variant}>{chip.label}</EntityStatusChip>;
                                    },
                                },
                                {
                                    key: 'chair',
                                    label: 'Chair',
                                    width: '1fr',
                                    cell: (meeting) => <PersonCell name={meeting.chair?.user?.name} />,
                                },
                                {
                                    key: 'location',
                                    label: 'Venue',
                                    width: '1fr',
                                    cell: (meeting) =>
                                        meeting.location ? (
                                            <span className="truncate" title={meeting.location}>
                                                {meeting.location}
                                            </span>
                                        ) : meeting.virtual_link ? (
                                            <span className="truncate">Online</span>
                                        ) : (
                                            <EmptyValue />
                                        ),
                                },
                                {
                                    key: 'quorum',
                                    label: 'Quorum',
                                    width: '0.7fr',
                                    cell: (meeting) => (
                                        <QuorumCell
                                            state={
                                                meeting.quorum_state ??
                                                (meeting.quorum_met ? 'met' : undefined)
                                            }
                                        />
                                    ),
                                },
                            ]}
                        />
                    )}

                    <LaravelPagination
                        links={meetings.links}
                        lastPage={meetings.last_page}
                        preserveScroll
                    />
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={CalendarDays}
                    title={ctxMenu.ctx.record.title}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {canSchedule && formOptions ? (
                <MeetingWizardDialog
                    isOpen={createOpen}
                    onClose={() => setCreateOpen(false)}
                    options={formOptions}
                    initialScheduledAt={initialScheduledAt}
                />
            ) : null}
        </AppLayout>
    );
}
