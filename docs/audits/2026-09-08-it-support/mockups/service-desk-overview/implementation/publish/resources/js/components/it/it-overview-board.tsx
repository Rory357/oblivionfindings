import {
    EntityContextMenu,
    EntityKebab,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUpRight,
    Check,
    ChevronDown,
    CircleHelp,
    Clock,
    Eye,
    Inbox,
    MessageSquareText,
    Ticket,
    UserRound,
} from 'lucide-react';
import {
    useEffect,
    useRef,
    useState,
    type MouseEvent,
    type ReactNode,
} from 'react';
import {
    overviewClockDetail,
    overviewConversation,
    overviewQueueHref,
    overviewQueues,
    type OverviewQueue,
    type OverviewTicket,
    type OverviewWorkboard,
} from './it-overview-workboard';
import { SLA_LABELS, SLA_VARIANTS, type SlaClock } from './sla-evidence';

const priorities: Record<string, StatusVariant> = {
    urgent: 'critical',
    high: 'critical',
    normal: 'info',
    low: 'neutral',
};
const label = (value: string) => value.charAt(0).toUpperCase() + value.slice(1);
function ClockFact({
    name,
    clock,
    compact = false,
}: {
    name: string;
    clock?: SlaClock;
    compact?: boolean;
}) {
    return (
        <div className="min-w-0 space-y-1">
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-caption">{name}</span>
                <StatusBadge
                    size="sm"
                    variant={SLA_VARIANTS[clock?.state ?? 'unmeasured']}
                >
                    {SLA_LABELS[clock?.state ?? 'unmeasured']}
                </StatusBadge>
            </div>
            <p className={compact ? 'text-caption' : 'text-sm font-medium'}>
                {overviewClockDetail(clock)}
            </p>
            {clock?.ever_breached && clock.state !== 'breached' && (
                <p className="text-caption">Earlier breach retained</p>
            )}
        </div>
    );
}
function TicketMeta({ ticket }: { ticket: OverviewTicket }) {
    return (
        <div className="text-caption flex flex-wrap items-center gap-2">
            <span>{ticket.reference ?? `IT-${ticket.id}`}</span>
            <StatusBadge
                size="sm"
                variant={priorities[ticket.priority] ?? 'neutral'}
            >
                {label(ticket.priority)}
            </StatusBadge>
            {ticket.site && <span>{ticket.site}</span>}
        </div>
    );
}

export function ItOverviewBoard({
    board,
    priority,
    conversationReady,
    canManage,
    actorId,
    onOpenTicket,
    onAssign,
    assignmentBusy = false,
    onClearPriority,
    activity,
    average,
}: {
    board: OverviewWorkboard;
    priority?: string | null;
    conversationReady: boolean;
    canManage?: boolean;
    actorId?: number;
    onOpenTicket: (id: number) => void;
    onAssign?: (ticket: OverviewTicket) => void;
    assignmentBusy?: boolean;
    onClearPriority?: () => void;
    activity: ReactNode;
    average: number | null;
}) {
    const [selection, setSelection] = useState<{
        queue: OverviewQueue;
        expanded: boolean;
        priority?: string | null;
    }>({ queue: 'attention', expanded: false, priority });
    const [assignmentIntent, setAssignmentIntent] = useState<{
        id: number;
        actorId?: number;
        priority?: string | null;
    } | null>(null);
    const [context, setContext] = useState<{
        id: number;
        x: number;
        y: number;
    } | null>(null);
    const remainingRef = useRef<HTMLButtonElement>(null);
    const scope = board.scopes[priority ?? 'all'];
    const view =
        (selection.queue === 'awaiting_it' && !conversationReady) ||
        (selection.queue === 'waiting_requester' && !canManage)
            ? 'attention'
            : selection.queue;
    const choose = (queue: OverviewQueue) =>
        setSelection({ queue, expanded: false, priority });
    const response = scope?.actions.response
        ? board.tickets[scope.actions.response]
        : undefined;
    const intentTicket =
        assignmentIntent &&
        assignmentIntent.actorId === actorId &&
        assignmentIntent.priority === priority
            ? board.tickets[assignmentIntent.id]
            : undefined;
    const assigned =
        intentTicket &&
        actorId !== undefined &&
        intentTicket.assigned_to_user_id === actorId &&
        intentTicket.can_manage &&
        intentTicket.id !== response?.id;
    const assignment = assigned
        ? intentTicket
        : scope?.actions.assignment
          ? board.tickets[scope.actions.assignment]
          : undefined;
    const selectedFollowup = scope?.actions.follow_up
        ? board.tickets[scope.actions.follow_up]
        : undefined;
    const otherAgeing = (scope?.queues.aging.ids ?? [])
        .map((id) => board.tickets[id])
        .filter(
            (ticket) =>
                ticket &&
                ticket.id !== response?.id &&
                ticket.id !== assignment?.id,
        );
    const followup =
        selectedFollowup &&
        selectedFollowup.id !== assignment?.id &&
        selectedFollowup.id !== response?.id
            ? selectedFollowup
            : (otherAgeing.find(
                  (ticket) =>
                      conversationReady &&
                      ticket.conversation?.state === 'awaiting_it',
              ) ?? otherAgeing[0]);
    useEffect(() => {
        if (assigned) remainingRef.current?.focus();
    }, [assigned]);
    if (!scope)
        return (
            <EmptyState
                title="Overview could not be loaded"
                description="Refresh to check the current permitted work."
                action={
                    <Button
                        onClick={() => router.reload({ preserveScroll: true })}
                    >
                        Refresh Overview
                    </Button>
                }
            />
        );
    const featured = [response, assignment, followup].filter(
        (ticket): ticket is OverviewTicket => !!ticket,
    );
    const queue = scope.queues[view];
    const excluded =
        view === 'attention'
            ? featured
                  .filter((ticket) =>
                      ['breached', 'at_risk'].includes(ticket.sla.state),
                  )
                  .map((ticket) => ticket.id)
            : [];
    const rows = queue.ids
        .filter((id) => !excluded.includes(id))
        .map((id) => board.tickets[id])
        .filter(Boolean);
    const total = Math.max(0, queue.total - new Set(excluded).size);
    const expanded = selection.expanded && selection.priority === priority;
    const shown = expanded ? rows : rows.slice(0, 3);
    const queueKeys = (Object.keys(overviewQueues) as OverviewQueue[]).filter(
        (key) =>
            (key !== 'awaiting_it' || conversationReady) &&
            (key !== 'waiting_requester' || canManage),
    );
    const assign = (ticket: OverviewTicket) => {
        if (!ticket.can_manage || !onAssign || assignmentBusy) return;
        setAssignmentIntent({ id: ticket.id, actorId, priority });
        onAssign(ticket);
    };
    const actionsFor = (ticket: OverviewTicket): MenuItem[] => [
        {
            label: 'Quick preview',
            icon: Eye,
            onClick: () => onOpenTicket(ticket.id),
        },
        {
            label: 'Open ticket',
            icon: ArrowUpRight,
            onClick: () => router.visit(`/it/tickets/${ticket.id}`),
        },
        ...(ticket.can_manage &&
        ticket.assigned_to_user_id === null &&
        onAssign &&
        !assignmentBusy
            ? [
                  {
                      label: 'Assign to me',
                      icon: UserRound,
                      onClick: () => assign(ticket),
                  },
              ]
            : []),
    ];
    const openContext = (event: MouseEvent, ticket: OverviewTicket) => {
        event.preventDefault();
        setContext({ id: ticket.id, x: event.clientX, y: event.clientY });
    };
    const fullQueueLabel =
        view === 'attention'
            ? 'Browse all tickets'
            : view === 'aging'
              ? 'All open · oldest first'
              : 'Open full queue';
    return (
        <section
            className="min-w-0 space-y-5"
            aria-label="Service Desk Overview"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="text-section-title">What needs you now</h2>
                    <p className="text-subtle">
                        {priority
                            ? `Actions for ${priority}-priority tickets.`
                            : 'First responses, assignment and follow-up.'}
                    </p>
                </div>
                <span className="text-caption">
                    Checked {formatDateTime(board.evaluated_at)}
                </span>
            </div>
            <div
                className="grid min-w-0 gap-5 lg:grid-cols-2"
                aria-label="Prioritised ticket actions"
            >
                <Card
                    className={cn(
                        'min-w-0 gap-4 p-5',
                        response?.sla.clocks.first_response.state ===
                            'breached' && 'border-l-4 border-l-status-critical',
                    )}
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <MessageSquareText className="size-4" aria-hidden />
                        <h3 className="text-sm font-medium">First response</h3>
                        {response && (
                            <StatusBadge
                                size="sm"
                                variant={
                                    SLA_VARIANTS[
                                        response.sla.clocks.first_response.state
                                    ]
                                }
                            >
                                {response.sla.clocks.first_response.state ===
                                'unmeasured'
                                    ? 'Reply needed'
                                    : `SLA ${SLA_LABELS[response.sla.clocks.first_response.state].toLowerCase()}`}
                            </StatusBadge>
                        )}
                    </div>
                    {response ? (
                        <>
                            <h3 className="text-section-title break-words">
                                {response.title}
                            </h3>
                            <TicketMeta ticket={response} />
                            <p className="text-sm text-muted-foreground">
                                No first response recorded.{' '}
                                {response.assignee
                                    ? `Assigned to ${response.assigned_to_user_id === actorId ? 'you' : response.assignee}.`
                                    : 'No technician assigned yet.'}
                            </p>
                            <div className="grid gap-4 border-t pt-4 sm:grid-cols-2">
                                <ClockFact
                                    name="Response SLA"
                                    clock={response.sla.clocks.first_response}
                                />
                                <ClockFact
                                    name="Resolution SLA"
                                    clock={response.sla.clocks.resolution}
                                />
                            </div>
                            <div className="mt-auto flex flex-wrap gap-2">
                                <Button
                                    onClick={() => onOpenTicket(response.id)}
                                >
                                    {response.can_manage
                                        ? 'Draft first reply'
                                        : 'View conversation'}
                                    <ArrowRight />
                                </Button>
                                <Button variant="ghost" asChild>
                                    <Link href={`/it/tickets/${response.id}`}>
                                        View ticket
                                        <ArrowUpRight />
                                    </Link>
                                </Button>
                            </div>
                        </>
                    ) : (
                        <EmptyState
                            variant="compact"
                            icon={Inbox}
                            title="No first replies in this selection"
                            description="Choose a queue below to review other permitted work."
                        />
                    )}
                </Card>
                <div className="grid min-w-0 gap-5">
                    <Card
                        className="min-w-0 gap-3 p-4"
                        aria-label="Assignment action"
                    >
                        <div className="flex items-center gap-2">
                            <UserRound className="size-4" aria-hidden />
                            <h3 className="text-sm font-medium">
                                {assigned
                                    ? 'Assigned to you'
                                    : assignment?.priority === 'urgent'
                                      ? 'Assign the next urgent ticket'
                                      : 'Unassigned work'}
                            </h3>
                            <Popover>
                                <PopoverTrigger asChild>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        className="ml-auto"
                                        aria-label="Browse unassigned tickets by priority"
                                    >
                                        <ChevronDown />
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent
                                    align="end"
                                    className="w-64 space-y-1"
                                >
                                    <p className="text-caption mb-2">
                                        Unassigned · full queues
                                    </p>
                                    {['urgent', 'high', 'normal', 'low'].map(
                                        (pri) => (
                                            <Button
                                                key={pri}
                                                variant="ghost"
                                                className="w-full justify-between"
                                                asChild
                                            >
                                                <Link
                                                    href={overviewQueueHref(
                                                        'unassigned',
                                                        pri,
                                                    )}
                                                >
                                                    {label(pri)}
                                                    <span>
                                                        {board.scopes[pri]
                                                            ?.queues.unassigned
                                                            .total ?? 0}
                                                    </span>
                                                    <ArrowUpRight />
                                                </Link>
                                            </Button>
                                        ),
                                    )}
                                </PopoverContent>
                            </Popover>
                        </div>
                        {assignment ? (
                            <>
                                <Button
                                    variant="link"
                                    className="h-auto justify-start p-0 text-left whitespace-normal"
                                    onClick={() => onOpenTicket(assignment.id)}
                                >
                                    {assignment.title}
                                    <ArrowUpRight className="shrink-0" />
                                </Button>
                                <TicketMeta ticket={assignment} />
                                {!assigned && (
                                    <ClockFact
                                        name="Response SLA"
                                        clock={
                                            assignment.sla.clocks.first_response
                                        }
                                        compact
                                    />
                                )}
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    {assigned ? (
                                        <>
                                            <span
                                                role="status"
                                                className="flex items-center gap-1 text-sm"
                                            >
                                                <Check className="size-4" />
                                                Assignment saved
                                            </span>
                                            <Button
                                                ref={remainingRef}
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    choose('unassigned')
                                                }
                                            >
                                                Remaining work
                                                <ArrowRight />
                                            </Button>
                                        </>
                                    ) : assignment.can_manage && onAssign ? (
                                        <>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={assignmentBusy}
                                                onClick={() =>
                                                    assign(assignment)
                                                }
                                            >
                                                Assign to me
                                            </Button>
                                            <span className="text-caption">
                                                Review before saving
                                            </span>
                                        </>
                                    ) : (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                onOpenTicket(assignment.id)
                                            }
                                        >
                                            Review ticket
                                        </Button>
                                    )}
                                </div>
                            </>
                        ) : (
                            <p className="text-subtle">
                                No other unassigned tickets in this selection.
                            </p>
                        )}
                    </Card>
                    <Card
                        className="min-w-0 gap-3 p-4"
                        aria-label="Ageing follow-up action"
                    >
                        <div className="flex items-center gap-2">
                            <Clock className="size-4" aria-hidden />
                            <h3 className="text-sm font-medium">
                                Move an ageing ticket forward
                            </h3>
                        </div>
                        {followup ? (
                            <>
                                <Button
                                    variant="link"
                                    className="h-auto justify-start p-0 text-left whitespace-normal"
                                    onClick={() => onOpenTicket(followup.id)}
                                >
                                    {followup.title}
                                    <ArrowUpRight className="shrink-0" />
                                </Button>
                                <p className="text-caption">
                                    {followup.reference ?? `IT-${followup.id}`}{' '}
                                    · Ticket age:{' '}
                                    {followup.age ?? 'unavailable'} ·{' '}
                                    {overviewConversation(
                                        followup,
                                        conversationReady,
                                    )}
                                </p>
                                <ClockFact
                                    name="Resolution SLA"
                                    clock={followup.sla.clocks.resolution}
                                    compact
                                />
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            onOpenTicket(followup.id)
                                        }
                                    >
                                        Review follow-up
                                        <ArrowRight />
                                    </Button>
                                    <span className="text-caption">
                                        {followup.assigned_to_user_id ===
                                        actorId
                                            ? 'Assigned to you'
                                            : (followup.assignee ??
                                              'Unassigned')}
                                    </span>
                                </div>
                            </>
                        ) : (
                            <p className="text-subtle">
                                No other tickets older than seven days in this
                                selection.
                            </p>
                        )}
                    </Card>
                </div>
            </div>
            <section
                className="min-w-0 space-y-3"
                aria-label="Supporting ticket list"
            >
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 className="text-section-title">
                            {view === 'attention'
                                ? 'Next in line'
                                : overviewQueues[view]}
                        </h2>
                        <p className="text-subtle">
                            {view === 'attention'
                                ? 'Other tickets with a recorded SLA alert.'
                                : view === 'aging'
                                  ? 'Open for more than seven days, oldest first.'
                                  : 'A short preview of the selected queue.'}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <label
                            htmlFor="overview-queue"
                            className="text-caption"
                        >
                            Queue
                        </label>
                        <Select
                            value={view}
                            onValueChange={(value) =>
                                choose(value as OverviewQueue)
                            }
                        >
                            <SelectTrigger
                                id="overview-queue"
                                aria-label="Supporting ticket queue"
                                className="w-52 bg-card"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {queueKeys.map((key) => (
                                    <SelectItem key={key} value={key}>
                                        {overviewQueues[key]} (
                                        {scope.queues[key].total})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={overviewQueueHref(view, priority)}>
                                {fullQueueLabel}
                                <ArrowUpRight />
                            </Link>
                        </Button>
                    </div>
                </div>
                {shown.length ? (
                    <>
                        <Card className="hidden gap-0 overflow-hidden py-0 md:block">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Ticket</TableHead>
                                        <TableHead>SLA clocks</TableHead>
                                        <TableHead>Technician</TableHead>
                                        <TableHead>
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {shown.map((ticket) => (
                                        <TableRow
                                            key={ticket.id}
                                            onContextMenu={(event) =>
                                                openContext(event, ticket)
                                            }
                                        >
                                            <TableCell className="w-2/5 py-4 whitespace-normal">
                                                <Button
                                                    variant="link"
                                                    className="h-auto justify-start p-0 text-left whitespace-normal"
                                                    onClick={() =>
                                                        onOpenTicket(ticket.id)
                                                    }
                                                >
                                                    {ticket.title}
                                                </Button>
                                                <div className="mt-2">
                                                    <TicketMeta
                                                        ticket={ticket}
                                                    />
                                                </div>
                                                <p className="text-caption mt-1">
                                                    Ticket age:{' '}
                                                    {ticket.age ??
                                                        'unavailable'}{' '}
                                                    ·{' '}
                                                    {ticket.first_reply_needed
                                                        ? 'First reply needed'
                                                        : overviewConversation(
                                                              ticket,
                                                              conversationReady,
                                                          )}
                                                </p>
                                            </TableCell>
                                            <TableCell className="space-y-2 py-4 whitespace-normal">
                                                <ClockFact
                                                    name="Response"
                                                    clock={
                                                        ticket.sla.clocks
                                                            .first_response
                                                    }
                                                    compact
                                                />
                                                <ClockFact
                                                    name="Resolution"
                                                    clock={
                                                        ticket.sla.clocks
                                                            .resolution
                                                    }
                                                    compact
                                                />
                                            </TableCell>
                                            <TableCell className="whitespace-normal">
                                                {ticket.assigned_to_user_id ===
                                                actorId
                                                    ? 'You'
                                                    : (ticket.assignee ??
                                                      'Unassigned')}
                                            </TableCell>
                                            <TableCell>
                                                <EntityKebab
                                                    actions={actionsFor(ticket)}
                                                    label={`Actions for ${ticket.reference ?? `IT-${ticket.id}`}`}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Card>
                        <ul className="space-y-3 md:hidden">
                            {shown.map((ticket) => (
                                <li
                                    key={ticket.id}
                                    onContextMenu={(event) =>
                                        openContext(event, ticket)
                                    }
                                >
                                    <Card className="gap-3 p-4">
                                        <div className="flex items-start justify-between gap-2">
                                            <Button
                                                variant="link"
                                                className="h-auto p-0 text-left whitespace-normal"
                                                onClick={() =>
                                                    onOpenTicket(ticket.id)
                                                }
                                            >
                                                {ticket.title}
                                            </Button>
                                            <EntityKebab
                                                actions={actionsFor(ticket)}
                                                label={`Actions for ${ticket.reference ?? `IT-${ticket.id}`}`}
                                            />
                                        </div>
                                        <TicketMeta ticket={ticket} />
                                        <p className="text-caption">
                                            Ticket age:{' '}
                                            {ticket.age ?? 'unavailable'} ·{' '}
                                            {ticket.assignee ?? 'Unassigned'}
                                        </p>
                                        <ClockFact
                                            name="Response"
                                            clock={
                                                ticket.sla.clocks.first_response
                                            }
                                            compact
                                        />
                                        <ClockFact
                                            name="Resolution"
                                            clock={ticket.sla.clocks.resolution}
                                            compact
                                        />
                                        <p className="text-caption">
                                            {ticket.first_reply_needed
                                                ? 'First reply needed'
                                                : overviewConversation(
                                                      ticket,
                                                      conversationReady,
                                                  )}
                                        </p>
                                    </Card>
                                </li>
                            ))}
                        </ul>
                    </>
                ) : (
                    <EmptyState
                        variant="compact"
                        icon={Inbox}
                        title={
                            queue.total > 0 && view === 'attention'
                                ? 'The matching alerts are shown above'
                                : 'No tickets in this selection'
                        }
                        description="Choose another queue to review permitted work. Missing SLA measurements still need separate review."
                        action={
                            priority && onClearPriority ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={onClearPriority}
                                >
                                    Clear Priority
                                </Button>
                            ) : undefined
                        }
                    />
                )}
                <div className="text-caption flex flex-wrap items-center justify-between gap-2">
                    <span aria-live="polite">
                        {shown.length} of {total} shown
                        {view === 'attention'
                            ? ' · featured actions excluded'
                            : ''}
                    </span>
                    {rows.length > 3 && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                setSelection({
                                    queue: view,
                                    expanded: !expanded,
                                    priority,
                                })
                            }
                        >
                            {expanded
                                ? 'Show fewer'
                                : `Show ${rows.length - 3} more`}
                            <ChevronDown />
                        </Button>
                    )}
                </div>
            </section>
            <div className="text-caption flex flex-wrap items-center gap-2">
                <CircleHelp className="size-4" aria-hidden />
                <p className="min-w-0 flex-1">
                    Across your permitted open tickets, {board.unmeasured_total}{' '}
                    have incomplete SLA evidence. Check watchdog freshness in
                    the header.
                </p>
                <Button variant="ghost" size="sm" asChild>
                    <Link href={overviewQueueHref('unmeasured')}>
                        Review measurement
                        <ArrowRight />
                    </Link>
                </Button>
            </div>
            {!conversationReady && (
                <p role="status" className="text-subtle">
                    Reply responsibility is unavailable. First-response history
                    remains available.
                </p>
            )}
            {activity}
            <footer className="text-caption flex flex-wrap items-center justify-between gap-2">
                <span>
                    Average first response · 30d:{' '}
                    {average === null ? 'Unavailable' : `${average} min`}
                </span>
                <Button variant="ghost" size="sm" asChild>
                    <Link href="/it/reports">
                        Service reports
                        <ArrowUpRight />
                    </Link>
                </Button>
            </footer>
            {context && board.tickets[context.id] && (
                <EntityContextMenu
                    x={context.x}
                    y={context.y}
                    icon={Ticket}
                    title={
                        board.tickets[context.id].reference ??
                        `IT-${context.id}`
                    }
                    items={actionsFor(board.tickets[context.id])}
                    onClose={() => setContext(null)}
                />
            )}
        </section>
    );
}
