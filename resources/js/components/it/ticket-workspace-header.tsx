import {
    GroupPillRail,
    TabSearchPalette,
    TierTwoTabs,
    useGroupedProfileSearchShortcut,
    type GroupedProfileNavGroup,
} from '@/components/page/grouped-profile-nav';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderSearchTrigger,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { StatusVariant } from '@/components/ui/status-badge';
import { formatDateTime, formatDurationMinutes } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Activity,
    CheckCircle2,
    ClipboardCheck,
    Copy,
    Eye,
    EyeOff,
    FileText,
    GitMerge,
    Info,
    Link2,
    ListChecks,
    MessageSquare,
    MoreHorizontal,
    Paperclip,
    RotateCcw,
    ShieldCheck,
    Ticket,
    Timer,
    UserPlus,
    XCircle,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { SLA_LABELS, type SlaVerdict } from './sla-evidence';
import {
    PRIORITY_LABELS,
    type TicketWork,
    type WorkNote,
} from './ticket-work-types';

export const TICKET_WORKSPACE_GROUPS: GroupedProfileNavGroup[] = [
    {
        key: 'conversation',
        label: 'Conversation',
        icon: MessageSquare,
        tabs: [
            { key: 'messages', label: 'Messages', icon: MessageSquare },
            { key: 'files', label: 'Files', icon: Paperclip },
        ],
    },
    {
        key: 'work',
        label: 'Work',
        icon: ListChecks,
        tabs: [
            { key: 'tasks', label: 'Tasks & evidence', icon: ClipboardCheck },
            { key: 'time', label: 'Time entries', icon: Timer },
            { key: 'schedule', label: 'Technician schedule', icon: UserPlus },
            { key: 'costs', label: 'Parts & expenses', icon: ListChecks },
            { key: 'approvals', label: 'Approvals', icon: ShieldCheck },
        ],
    },
    {
        key: 'details',
        label: 'Details & links',
        icon: Info,
        tabs: [
            {
                key: 'properties',
                label: 'Classification & ownership',
                icon: FileText,
            },
            { key: 'people', label: 'People & diagnostics', icon: UserPlus },
            { key: 'links', label: 'Linked records', icon: Link2 },
            { key: 'sla', label: 'Service levels', icon: Timer },
        ],
    },
    {
        key: 'activity',
        label: 'Activity',
        icon: Activity,
        tabs: [
            { key: 'history', label: 'History', icon: Activity },
            { key: 'corrections', label: 'Work corrections', icon: FileText },
        ],
    },
];

export function ticketWorkspaceTab(
    value: string | null | undefined,
    canViewWork = true,
    canRecordWork = canViewWork,
): string {
    return TICKET_WORKSPACE_GROUPS.some(
        (group) =>
            (canViewWork || group.key !== 'work') &&
            group.tabs.some(
                (tab) =>
                    tab.key === value &&
                    (canRecordWork ||
                        ![
                            'time',
                            'schedule',
                            'costs',
                            'people',
                            'corrections',
                        ].includes(tab.key)),
            ),
    )
        ? value!
        : 'messages';
}

type HeaderAction = { label: string; run: () => void; icon: LucideIcon };

export function TicketWorkspaceHeader({
    work,
    priority,
    id,
    reference,
    title,
    status,
    statusVariant,
    subline,
    activeTab,
    onTab,
    original = false,
    sla,
    replies,
    files,
    tasks,
    related,
    primary,
    actions,
}: {
    work?: TicketWork | null;
    priority?: string;
    id: number;
    reference: string | null;
    title: string;
    status: string;
    statusVariant: StatusVariant;
    subline: ReactNode;
    activeTab: string;
    onTab: (tab: string) => void;
    original?: boolean;
    sla?: SlaVerdict;
    replies: number;
    files: number;
    tasks: {
        completed: number;
        total: number;
        needsReview: number;
        unverified: number;
    } | null;
    related: number;
    primary?: HeaderAction;
    actions: {
        resolve?: () => void;
        reopen?: () => void;
        close?: () => void;
        merge?: () => void;
        assign?: () => void;
        watch?: () => void;
        watching: boolean;
        busy: boolean;
        copyReference: () => void;
        copyLink: () => void;
    };
}) {
    const [searchOpen, setSearchOpen] = useState(false);
    const follow = work?.details?.follow_up as WorkNote['follow_up'];
    const nextVisit = work?.bookings?.find((booking) =>
        ['requested', 'accepted'].includes(booking.status),
    );
    useGroupedProfileSearchShortcut(() => setSearchOpen(true));
    const groups = TICKET_WORKSPACE_GROUPS.filter(
        (item) => tasks !== null || item.key !== 'work',
    ).map((item) => ({
        ...item,
        tabs: item.tabs.filter(
            (tab) =>
                work?.ready ||
                ![
                    'time',
                    'schedule',
                    'costs',
                    'people',
                    'corrections',
                ].includes(tab.key),
        ),
    }));
    const group =
        groups.find((item) => item.tabs.some((tab) => tab.key === activeTab)) ??
        groups[0];
    const href = (tab: string) =>
        `/it/tickets/${id}${original ? '/original' : ''}?tab=${tab}`;
    const go = (tab: string) => {
        setSearchOpen(false);
        onTab(tab);
    };
    const state = sla?.state ?? 'unmeasured';
    const tone =
        state === 'breached'
            ? 'critical'
            : state === 'at_risk' || state === 'unmeasured'
              ? 'warning'
              : state === 'met'
                ? 'success'
                : 'brand';
    return (
        <>
            <PageHeader
                className="overflow-clip!"
                variant="profile"
                icon={Ticket}
                backHref="/it"
                title={title}
                wrapTitle
                titleChip={
                    <PageHeaderStatusChip variant={statusVariant}>
                        {status}
                    </PageHeaderStatusChip>
                }
                subline={
                    <>
                        {reference ?? `Ticket ${id}`} ·{' '}
                        {priority && (
                            <>
                                <Button
                                    variant="link"
                                    type="button"
                                    className="frontline-focus rounded px-1 font-semibold underline"
                                    onClick={() => go('properties')}
                                >
                                    {PRIORITY_LABELS[priority] ?? priority}
                                </Button>{' '}
                                ·{' '}
                            </>
                        )}
                        {subline}
                    </>
                }
                actions={
                    <>
                        <PageHeaderSearchTrigger
                            placeholder="Find a ticket section…"
                            onOpen={() => setSearchOpen(true)}
                        />
                        {primary && (
                            <PageHeaderPrimaryButton
                                icon={primary.icon}
                                onClick={primary.run}
                            >
                                {primary.label}
                            </PageHeaderPrimaryButton>
                        )}
                        {work?.ready && (
                            <PageHeaderGlassButton
                                icon={UserPlus}
                                onClick={() => go('schedule')}
                            >
                                Schedule technician
                            </PageHeaderGlassButton>
                        )}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <PageHeaderGlassButton
                                    icon={MoreHorizontal}
                                    aria-label="Ticket actions"
                                    disabled={actions.busy}
                                />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {actions.resolve && (
                                    <DropdownMenuItem onClick={actions.resolve}>
                                        <CheckCircle2 />
                                        Resolve ticket
                                    </DropdownMenuItem>
                                )}
                                {actions.reopen && (
                                    <DropdownMenuItem onClick={actions.reopen}>
                                        <RotateCcw />
                                        Reopen ticket
                                    </DropdownMenuItem>
                                )}
                                {actions.close && (
                                    <DropdownMenuItem onClick={actions.close}>
                                        <XCircle />
                                        Close ticket
                                    </DropdownMenuItem>
                                )}
                                {actions.assign && (
                                    <DropdownMenuItem onClick={actions.assign}>
                                        <UserPlus />
                                        Assign to me
                                    </DropdownMenuItem>
                                )}
                                {actions.watch && (
                                    <DropdownMenuItem onClick={actions.watch}>
                                        {actions.watching ? (
                                            <EyeOff />
                                        ) : (
                                            <Eye />
                                        )}
                                        {actions.watching
                                            ? 'Stop watching'
                                            : 'Watch ticket'}
                                    </DropdownMenuItem>
                                )}
                                {actions.merge && (
                                    <DropdownMenuItem onClick={actions.merge}>
                                        <GitMerge />
                                        Merge ticket
                                    </DropdownMenuItem>
                                )}
                                <DropdownMenuSeparator />
                                {reference && (
                                    <DropdownMenuItem
                                        onClick={actions.copyReference}
                                    >
                                        <Copy />
                                        Copy reference
                                    </DropdownMenuItem>
                                )}
                                <DropdownMenuItem onClick={actions.copyLink}>
                                    <Link2 />
                                    Copy ticket link
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </>
                }
                meters={
                    <>
                        <PageHeaderMeterBlock
                            label={
                                work?.ready ? 'Next follow-up' : 'Conversation'
                            }
                            href={href(work?.ready ? 'people' : 'messages')}
                            preserveState
                            preserveScroll
                            ariaLabel="View ticket conversation"
                        >
                            <PageHeaderMeterBig>
                                {work?.ready
                                    ? follow
                                        ? 'Follow up'
                                        : 'Not set'
                                    : replies}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {work?.ready
                                    ? follow
                                        ? `${work.follow_up_owner} · ${formatDateTime(follow.due_at)}`
                                        : 'Set the next action with a note'
                                    : `${replies} replies · ${files} files`}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        {tasks !== null && (
                            <PageHeaderMeterBlock
                                label="Required work"
                                value={
                                    tasks.total > 0 ? tasks.total : undefined
                                }
                                href={href('tasks')}
                                preserveState
                                preserveScroll
                                ariaLabel="View required ticket work"
                            >
                                {tasks.total > 0 ? (
                                    <PageHeaderMeterDonut
                                        percent={Math.round(
                                            (tasks.completed / tasks.total) *
                                                100,
                                        )}
                                    />
                                ) : (
                                    <PageHeaderMeterBig>0</PageHeaderMeterBig>
                                )}
                                <PageHeaderMeterCaption>
                                    {tasks.total
                                        ? `${tasks.completed} of ${tasks.total} verified complete`
                                        : 'No required tasks recorded'}
                                    {tasks.needsReview > 0 &&
                                        ` · ${tasks.needsReview} ${tasks.needsReview === 1 ? 'completion needs' : 'completions need'} review`}
                                    {tasks.unverified > 0 &&
                                        ` · ${tasks.unverified} ${tasks.unverified === 1 ? 'completion' : 'completions'} unverified`}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        )}
                        <PageHeaderMeterBlock
                            label="Service levels"
                            tone={tone}
                            href={href('sla')}
                            preserveState
                            preserveScroll
                            ariaLabel="View response and resolution clocks"
                        >
                            <PageHeaderMeterBig>
                                {SLA_LABELS[state]}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {sla?.coverage === 'full'
                                    ? 'Both clocks measured'
                                    : 'Measurement details available'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label={
                                work?.ready ? 'Next visit' : 'Linked records'
                            }
                            href={href(work?.ready ? 'schedule' : 'links')}
                            preserveState
                            preserveScroll
                            ariaLabel="View permitted linked records"
                        >
                            <PageHeaderMeterBig>
                                {work?.ready
                                    ? nextVisit
                                        ? nextVisit.technician_name
                                        : 'No visit'
                                    : related}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {work?.ready
                                    ? nextVisit
                                        ? formatDateTime(nextVisit.starts_at)
                                        : 'Schedule additional technician time'
                                    : 'Related systems and work'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        {work?.ready && (
                            <PageHeaderMeterBlock
                                label="Time logged"
                                href={href('time')}
                                preserveState
                                preserveScroll
                                ariaLabel="View actual time entries"
                            >
                                <PageHeaderMeterBig>
                                    {formatDurationMinutes(
                                        work.totals?.minutes ?? 0,
                                    )}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {formatDurationMinutes(
                                        work.totals?.after_hours_minutes ?? 0,
                                    )}{' '}
                                    after hours
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        )}
                    </>
                }
                rail={
                    <GroupPillRail
                        groups={groups}
                        openGroup={group.key}
                        activeTab={activeTab}
                        onOpenGroup={(_group, tab) => go(tab)}
                        onSearch={() => setSearchOpen(true)}
                        testIdPrefix="it-ticket"
                        ariaLabel="Ticket workspace"
                    />
                }
            />
            <TierTwoTabs
                tabs={group.tabs.map((tab) => ({
                    ...tab,
                    href: href(tab.key),
                }))}
                activeTab={activeTab}
                onTab={go}
                testIdPrefix="it-ticket"
                ariaLabel={`${group.label} sections`}
                panelId="it-ticket-panel"
                renderLink={(tab, className, inner, accessibility) => (
                    <Link
                        key={tab.key}
                        href={href(tab.key)}
                        className={className}
                        {...accessibility}
                        onClick={(event) => {
                            if (
                                !event.ctrlKey &&
                                !event.metaKey &&
                                !event.shiftKey &&
                                !event.altKey
                            ) {
                                event.preventDefault();
                                go(tab.key);
                            }
                        }}
                    >
                        {inner}
                    </Link>
                )}
            />
            <TabSearchPalette
                open={searchOpen}
                onClose={() => setSearchOpen(false)}
                groups={groups}
                onTab={go}
                testIdPrefix="it-ticket"
                searchLabel="Find a section in this ticket"
            />
        </>
    );
}
