import { StatusBadge } from '@/components/ui/status-badge';
import { WorkforcePageHeader } from '@/components/workforce/workforce-page-header';
/* Attendance — shared page header and connected Sessions / On the clock / Handovers views, with
 * right-click context menus on every list row. Clock workflows are multi-step
 * wizards on the shared wizard shell; the Handover wizard and ReasonDialog are
 * the existing shared components, not duplicates. */
import { AddClientDialog } from '@/components/clients/add-client-dialog';
import {
    PageHeaderFilterButton,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
} from '@/components/page';
import PageShell from '@/components/page-shell';
import { PageHeaderPrimaryButton } from '@/components/page/page-header';
import { ReasonDialog } from '@/components/reason-dialog';
import {
    ShiftContextMenu,
    WeekPicker,
    addDaysWP,
    startOfWeek,
    weekLabel,
    type RosterTabItem,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import { TimesheetStatusBadge } from '@/components/timesheet-status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import {
    formatDateOnly,
    formatDateTime,
    formatDateTimeInZone,
    formatTime,
    toDateInput,
} from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeftRight,
    CalendarDays,
    CalendarRange,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Coffee,
    FileText,
    Info,
    Link2,
    ListChecks,
    LogIn,
    LogOut,
    Pill,
    Plus,
    Timer,
    UserCheck,
    User as UserIcon,
    Users,
    Wrench,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { openHoursInPeriod } from './components/period-hours';

import { HandoverDetailDialog } from '@/pages/operations/handovers/components/handover-detail-dialog';
import { HandoverWizard } from '@/pages/operations/handovers/components/handover-wizard';
import {
    clientName,
    type Catalogue,
    type Handover,
} from '@/pages/operations/handovers/components/shared';

import { ClockInWizard } from './components/clock-in-wizard';
import { ClockOutWizard } from './components/clock-out-wizard';
import { FixClockOutWizard } from './components/fix-clock-out-wizard';
import {
    STALE_MS,
    fmtDur,
    toYMD,
    type EligibleShift,
    type FixCandidate,
    type OnClockRow,
    type OpenSession,
    type Session,
} from './components/shared';

type AttendanceHandover = Handover & {
    /** True only for the worker currently authorised to acknowledge. */
    incoming: boolean;
    /** Immutable submit-time recipient evidence; may differ after reassignment. */
    submitted_recipient: boolean;
};

type Props = {
    sessions: Session[];
    sessionPagination?: {
        current_page: number;
        last_page: number;
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    totalSessions: number;
    summary: {
        user_id: number;
        sessions_total: number;
        closed_sessions: number;
        open_sessions: number;
        closed_hours: number;
        on_clock_now_total: number | null;
        stale_on_clock_total: number | null;
    };
    evidence: {
        period_start: string;
        period_end_exclusive: string;
        timezone: string;
        checked_at: string;
        today: string;
        today_period_start: string;
        today_period_end_exclusive: string;
    };
    lists: Record<
        'sessions' | 'onClockNow' | 'handovers',
        {
            total: number | null;
            shown: number;
            limit: number;
            truncated: boolean;
        }
    >;
    openSession: OpenSession | null;
    activeShift: {
        id: number;
        starts_at: string;
        ends_at: string;
        status: string;
        location: string | null;
    } | null;
    eligibleShifts: EligibleShift[];
    staff: Array<{ id: number; name: string; email: string }>;
    filters: { user_id?: number | null; week?: string | null };
    todayHours: number;
    weekHours: number;
    onClockNow: OnClockRow[];
    handovers: AttendanceHandover[];
    canManageAny: boolean;
    canClock: boolean;
    canCreateHandovers: boolean;
    currentUser: { id: number; name: string };
    catalogue?: Catalogue;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Attendance', href: '/attendance' },
];

const ANY = '__any__';

/** Timesheets are viewed in the redesigned index's ViewTimesheetDialog —
 *  deep-linked via ?view= (the full edit page is reserved for editing). */
function timesheetViewUrl(id: number): string {
    return `/operations/timesheets?view=${id}`;
}

/** "8 Jun → 14 Jun" for the week stepper centre button. */
function weekCompactRange(weekStart: Date): string {
    const f = (d: Date) =>
        d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
    return `${f(weekStart)} → ${f(addDaysWP(weekStart, 6))}`;
}

/** Parse the controller's "YYYY-MM-DD" week into a LOCAL date (not UTC). */
function parseYmd(ymd: string): Date {
    const [y, m, d] = ymd.split('-').map(Number);
    return new Date(y ?? 1970, (m ?? 1) - 1, d ?? 1);
}

function PulseDot({ stale = false }: { stale?: boolean }) {
    return (
        <span className="relative flex h-2 w-2 shrink-0">
            <span
                className={cn(
                    'absolute inline-flex h-full w-full rounded-full opacity-75',
                    stale
                        ? 'bg-status-warning'
                        : 'animate-ping bg-status-success',
                )}
            />
            <span
                className={cn(
                    'relative inline-flex h-2 w-2 rounded-full',
                    stale ? 'bg-status-warning' : 'bg-status-success',
                )}
            />
        </span>
    );
}

function SectionEmpty({ children }: { children: React.ReactNode }) {
    return (
        <div className="px-4 py-8 text-center text-sm text-muted-foreground">
            {children}
        </div>
    );
}

function StatusPill({
    variant,
    children,
}: {
    variant: 'success' | 'warning';
    children: React.ReactNode;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold',
                variant === 'success'
                    ? 'border-status-success/25 bg-status-success-bg text-status-success'
                    : 'border-status-warning/25 bg-status-warning-bg text-status-warning',
            )}
        >
            {children}
        </span>
    );
}

/* ─────────────────────────── Sessions pane ─────────────────────────── */

function SessionsCard({
    sessions,
    weekLabelText,
    weekRangeText,
    onContext,
}: {
    sessions: Session[];
    weekLabelText: string;
    weekRangeText: string;
    onContext: (e: React.MouseEvent, s: Session) => void;
}) {
    return (
        <Card>
            <CardHeader className="flex-row items-center justify-between space-y-0">
                <CardTitle className="text-base">
                    Sessions · {weekLabelText}
                    <span className="ml-2 text-sm font-normal text-muted-foreground">
                        {weekRangeText}
                    </span>
                </CardTitle>
                <span className="text-xs text-muted-foreground">
                    {sessions.length} session{sessions.length === 1 ? '' : 's'}
                </span>
            </CardHeader>
            <CardContent className="p-0">
                {/* Mobile: stacked cards (no horizontal scroll) */}
                <ul className="divide-y md:hidden">
                    {sessions.length === 0 ? (
                        <li>
                            <SectionEmpty>
                                No attendance sessions this week.
                            </SectionEmpty>
                        </li>
                    ) : (
                        sessions.map((session) => (
                            <li
                                key={session.id}
                                onContextMenu={(e) => onContext(e, session)}
                                className="space-y-2 px-4 py-3 text-sm"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="font-medium">
                                            {formatDateTime(
                                                session.clock_in_at,
                                            )}
                                        </div>
                                        <div className="mt-0.5 text-xs text-muted-foreground">
                                            {session.clock_out_at
                                                ? `Out ${formatDateTime(session.clock_out_at)}`
                                                : 'Still clocked in'}
                                        </div>
                                    </div>
                                    <span className="shrink-0 rounded-full border bg-muted/60 px-2 py-0.5 text-xs font-medium tabular-nums">
                                        {session.worked_hours.toFixed(2)}h
                                    </span>
                                </div>
                                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                    <span>Break {session.break_minutes}m</span>
                                    {session.timesheet_id ? (
                                        <Link
                                            href={timesheetViewUrl(
                                                session.timesheet_id,
                                            )}
                                            className="inline-flex items-center gap-1.5"
                                        >
                                            <span className="underline">
                                                Timesheet #
                                                {session.timesheet_id}
                                            </span>
                                            {session.timesheet_status ? (
                                                <TimesheetStatusBadge
                                                    status={
                                                        session.timesheet_status
                                                    }
                                                    className="text-[10px]"
                                                />
                                            ) : null}
                                        </Link>
                                    ) : (
                                        <span>Not synced to timesheet</span>
                                    )}
                                </div>
                            </li>
                        ))
                    )}
                </ul>

                {/* Desktop: dense table */}
                <div className="hidden md:block">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-muted/40">
                            <tr>
                                <th className="px-4 py-3 text-left font-medium">
                                    Clock In
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    Clock Out
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    Break
                                </th>
                                <th className="px-4 py-3 text-right font-medium">
                                    Hours
                                </th>
                                <th className="px-4 py-3 text-left font-medium">
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <span className="inline-flex cursor-default items-center gap-1">
                                                Timesheet
                                                <Info className="h-3 w-3 text-muted-foreground" />
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            Linked to a timesheet for payroll
                                            processing
                                        </TooltipContent>
                                    </Tooltip>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {sessions.map((session) => (
                                <tr
                                    key={session.id}
                                    onContextMenu={(e) => onContext(e, session)}
                                    className="transition-colors hover:bg-muted/20"
                                >
                                    <td className="px-4 py-3">
                                        {formatDateTime(session.clock_in_at)}
                                    </td>
                                    <td className="px-4 py-3">
                                        {session.clock_out_at ? (
                                            formatDateTime(session.clock_out_at)
                                        ) : (
                                            <span className="inline-flex items-center gap-2">
                                                <PulseDot /> Open
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {session.break_minutes}m
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {session.worked_hours.toFixed(2)}h
                                    </td>
                                    <td className="px-4 py-3">
                                        {session.timesheet_id ? (
                                            <Link
                                                className="inline-flex items-center gap-1.5"
                                                href={timesheetViewUrl(
                                                    session.timesheet_id,
                                                )}
                                            >
                                                <span className="underline">
                                                    #{session.timesheet_id}
                                                </span>
                                                {session.timesheet_status ? (
                                                    <TimesheetStatusBadge
                                                        status={
                                                            session.timesheet_status
                                                        }
                                                        className="text-[10px]"
                                                    />
                                                ) : null}
                                            </Link>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Not synced
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {sessions.length === 0 ? (
                                <tr>
                                    <td colSpan={5}>
                                        <SectionEmpty>
                                            No attendance sessions this week —
                                            use the week stepper above to look
                                            back.
                                        </SectionEmpty>
                                    </td>
                                </tr>
                            ) : null}
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    );
}

/* ─────────────────────────── On-the-clock pane ─────────────────────────── */

function OnClockBoard({
    rows,
    now,
    onView,
    onEnd,
    onContext,
}: {
    rows: OnClockRow[];
    now: Date;
    onView: (userId: number) => void;
    onEnd: (row: OnClockRow) => void;
    onContext: (e: React.MouseEvent, row: OnClockRow) => void;
}) {
    const staleCount = rows.filter((r) => r.is_stale).length;
    return (
        <Card>
            <CardHeader className="flex-row items-center justify-between space-y-0">
                <CardTitle className="text-base">
                    On the clock now
                    <span className="ml-2 text-sm font-normal text-muted-foreground">
                        {rows.length} open session{rows.length === 1 ? '' : 's'}
                    </span>
                </CardTitle>
                {staleCount > 0 ? (
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-status-warning/30 bg-status-warning-bg px-2.5 py-1 text-xs font-medium text-status-warning">
                        <AlertTriangle className="h-3.5 w-3.5" />
                        {staleCount} likely missed clock-out
                        {staleCount === 1 ? '' : 's'}
                    </span>
                ) : null}
            </CardHeader>
            <CardContent className="p-0">
                {rows.length === 0 ? (
                    <SectionEmpty>
                        No one is on the clock right now.
                    </SectionEmpty>
                ) : (
                    <ul className="divide-y">
                        {rows.map((s) => (
                            <li
                                key={s.id}
                                onContextMenu={(e) => onContext(e, s)}
                                className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2.5 text-sm"
                            >
                                <PulseDot stale={s.is_stale} />
                                {/* eslint-disable-next-line no-restricted-syntax -- inline text link inside a list row; a shadcn Button would add box chrome. */}
                                <button
                                    type="button"
                                    onClick={() => onView(s.user_id)}
                                    className="min-w-0 truncate font-medium hover:text-primary hover:underline"
                                >
                                    {s.user_name ?? `User #${s.user_id}`}
                                </button>
                                <span className="text-muted-foreground">
                                    since {formatDateTime(s.clock_in_at)} ·{' '}
                                    {fmtDur(
                                        now.getTime() -
                                            new Date(s.clock_in_at).getTime(),
                                    )}
                                </span>
                                {s.shift_id ? (
                                    <span className="text-muted-foreground">
                                        Shift #{s.shift_id}
                                        {s.shift_location
                                            ? ` · ${s.shift_location}`
                                            : ''}
                                        {s.shift_ends_at
                                            ? ` · ends ${formatTime(s.shift_ends_at)}`
                                            : ''}
                                    </span>
                                ) : (
                                    <span className="text-muted-foreground">
                                        No shift linked
                                    </span>
                                )}
                                <span className="ml-auto flex items-center gap-2">
                                    {s.is_stale ? (
                                        <span className="inline-flex items-center gap-1 rounded-full bg-status-warning-bg px-2 py-0.5 text-xs font-medium text-status-warning">
                                            <AlertTriangle className="h-3 w-3" />
                                            16h+ open
                                        </span>
                                    ) : null}
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className={cn(
                                            'h-7 px-2.5 text-xs',
                                            s.is_stale &&
                                                'border-status-critical/30 text-status-critical hover:bg-status-critical-bg',
                                        )}
                                        onClick={() => onEnd(s)}
                                    >
                                        End session
                                    </Button>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

/* ─────────────────────────── Handovers pane ─────────────────────────── */

function HandoversList({
    rows,
    currentUserId,
    viewingSelf,
    canCreate,
    onOpen,
    onAcknowledge,
    onNew,
    onContext,
}: {
    rows: AttendanceHandover[];
    currentUserId: number;
    /** False when a manager has the staff filter on someone else. */
    viewingSelf: boolean;
    canCreate: boolean;
    onOpen: (h: AttendanceHandover) => void;
    onAcknowledge: (h: AttendanceHandover) => void;
    onNew: () => void;
    onContext: (e: React.MouseEvent, h: AttendanceHandover) => void;
}) {
    const pending = rows.filter((r) => r.status === 'submitted').length;
    const personName = (
        staff: { id: number; name: string } | null,
        fallback: string,
    ) => (staff ? (staff.id === currentUserId ? 'You' : staff.name) : fallback);

    // Actionable first: your pending acknowledgements, then other pending,
    // then acknowledged — newest within each group.
    const sorted = [...rows].sort((a, b) => {
        const rank = (h: AttendanceHandover) =>
            h.status === 'submitted' ? (h.incoming ? 0 : 1) : 2;
        const ts = (h: AttendanceHandover) =>
            new Date(h.submitted_at ?? h.created_at ?? 0).getTime();
        return rank(a) - rank(b) || ts(b) - ts(a);
    });

    return (
        <Card>
            <CardHeader className="flex-row items-center justify-between space-y-0">
                <CardTitle className="text-base">
                    Handovers
                    <span className="ml-2 text-sm font-normal text-muted-foreground">
                        {pending} awaiting acknowledgement
                    </span>
                </CardTitle>
                {canCreate ? (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={onNew}
                        data-test="attendance-new-handover"
                    >
                        <Plus className="h-3.5 w-3.5" /> New handover
                    </Button>
                ) : null}
            </CardHeader>
            <CardContent className="p-0">
                {rows.length === 0 ? (
                    <SectionEmpty>
                        {viewingSelf
                            ? 'No handovers involving you'
                            : 'No handovers involving this staff member'}
                        {canCreate
                            ? ' — use “New handover” to brief the next shift.'
                            : '.'}
                    </SectionEmpty>
                ) : (
                    <ul className="divide-y">
                        {sorted.map((h) => {
                            const tasks = [
                                ...(h.follow_up_items ?? []),
                                ...(h.tasks_pending ?? []),
                            ];
                            const pendingRow = h.status === 'submitted';
                            const currentAssignee =
                                h.current_incoming_staff ?? null;
                            const submittedRecipient =
                                h.submitted_incoming_staff ?? null;
                            const recipientChanged =
                                submittedRecipient &&
                                submittedRecipient.id !== currentAssignee?.id;
                            return (
                                <li
                                    key={h.id}
                                    role="button"
                                    tabIndex={0}
                                    aria-label={`Open handover for ${clientName(h.client)}`}
                                    onClick={() => onOpen(h)}
                                    onKeyDown={(e) => {
                                        if (
                                            e.key === 'Enter' ||
                                            e.key === ' '
                                        ) {
                                            e.preventDefault();
                                            onOpen(h);
                                        }
                                    }}
                                    onContextMenu={(e) => onContext(e, h)}
                                    className="flex cursor-pointer flex-col gap-2 px-4 py-3 text-sm transition-colors hover:bg-muted/20 focus-visible:bg-muted/20 focus-visible:outline-none sm:flex-row sm:items-start"
                                >
                                    <span
                                        className={cn(
                                            'grid h-9 w-9 shrink-0 place-items-center rounded-lg',
                                            pendingRow
                                                ? 'bg-primary/10 text-primary'
                                                : 'bg-status-success-bg text-status-success',
                                        )}
                                    >
                                        <ArrowLeftRight className="h-4 w-4" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">
                                                {personName(
                                                    h.outgoing_staff,
                                                    'Unassigned',
                                                )}{' '}
                                                →{' '}
                                                {personName(
                                                    currentAssignee,
                                                    'No current assignee',
                                                )}{' '}
                                                · {clientName(h.client)}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {formatDateTime(
                                                    h.submitted_at ??
                                                        h.created_at,
                                                )}
                                            </span>
                                            {pendingRow ? (
                                                <StatusPill variant="warning">
                                                    {viewingSelf && h.incoming
                                                        ? 'Awaiting your acknowledgement'
                                                        : 'Awaiting acknowledgement'}
                                                </StatusPill>
                                            ) : (
                                                <StatusPill variant="success">
                                                    <Check className="h-3 w-3" />{' '}
                                                    Acknowledged
                                                </StatusPill>
                                            )}
                                            {recipientChanged ? (
                                                <span className="text-xs text-muted-foreground">
                                                    Submitted recipient:{' '}
                                                    {personName(
                                                        submittedRecipient,
                                                        'Not recorded',
                                                    )}
                                                </span>
                                            ) : null}
                                        </div>
                                        <p className="mt-1 text-[13px] leading-relaxed text-muted-foreground">
                                            {h.handover_notes}
                                        </p>
                                        {h.medications_due.length ||
                                        tasks.length ? (
                                            <div className="mt-1.5 flex flex-wrap gap-1.5">
                                                {h.medications_due.map(
                                                    (m, i) => (
                                                        <span
                                                            key={`m${i}`}
                                                            className="inline-flex items-center gap-1 rounded-full bg-status-critical-bg px-2.5 py-1 text-[11px] font-semibold text-status-critical-foreground"
                                                        >
                                                            <Pill className="h-3 w-3" />
                                                            {m}
                                                        </span>
                                                    ),
                                                )}
                                                {tasks.map((t, i) => (
                                                    <span
                                                        key={`t${i}`}
                                                        className="inline-flex items-center gap-1 rounded-full bg-accent px-2.5 py-1 text-[11px] font-semibold text-accent-foreground"
                                                    >
                                                        <ListChecks className="h-3 w-3" />
                                                        {t}
                                                    </span>
                                                ))}
                                            </div>
                                        ) : null}
                                    </div>
                                    {viewingSelf &&
                                    pendingRow &&
                                    h.incoming &&
                                    h.can_acknowledge ? (
                                        <Button
                                            size="sm"
                                            className="shrink-0"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                onAcknowledge(h);
                                            }}
                                            data-test="attendance-acknowledge-handover"
                                        >
                                            <Check className="h-4 w-4" />{' '}
                                            Acknowledge
                                        </Button>
                                    ) : null}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

/* ─────────────────────────── The page ─────────────────────────── */

export default function AttendanceIndex({
    sessions,
    totalSessions,
    sessionPagination,
    summary,
    evidence,
    lists,
    openSession,
    eligibleShifts,
    staff,
    filters,
    todayHours,
    weekHours,
    onClockNow,
    handovers,
    canManageAny,
    canClock,
    canCreateHandovers,
    currentUser,
    catalogue,
}: Props) {
    const { auth } = usePage<SharedData>().props;

    const [now, setNow] = useState(() => new Date());
    const [tab, setTab] = useState('sessions');
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [pickerOpen, setPickerOpen] = useState(false);
    const weekBtnRef = useRef<HTMLButtonElement | null>(null);

    const [clockInOpen, setClockInOpen] = useState(false);
    const [clockOutOpen, setClockOutOpen] = useState(false);
    const [fixSessions, setFixSessions] = useState<FixCandidate[] | null>(null);
    const [handoverOpen, setHandoverOpen] = useState(false);
    const [editingHandover, setEditingHandover] = useState<Handover | null>(
        null,
    );
    const [detailId, setDetailId] = useState<number | null>(null);
    const [addClientOpen, setAddClientOpen] = useState(false);
    const [pendingClientId, setPendingClientId] = useState<number | null>(null);
    const [endTarget, setEndTarget] = useState<OnClockRow | null>(null);

    useEffect(() => {
        const iv = setInterval(() => setNow(new Date()), 10_000);
        return () => clearInterval(iv);
    }, []);

    // Workers never see the manager-only tab; fall back if the role changes.
    useEffect(() => {
        if (!canManageAny && tab === 'onclock') setTab('sessions');
    }, [canManageAny, tab]);

    /* ── viewed user / week ── */
    const selectedUserId = filters.user_id ?? null;
    const viewedStaff = useMemo(
        () =>
            canManageAny && selectedUserId && selectedUserId !== auth?.user?.id
                ? (staff.find((member) => member.id === selectedUserId) ?? null)
                : null,
        [auth?.user?.id, canManageAny, selectedUserId, staff],
    );

    const weekStartDate = useMemo(
        () =>
            filters.week
                ? startOfWeek(parseYmd(filters.week))
                : startOfWeek(parseYmd(toDateInput(now))),
        [filters.week, now],
    );
    const isCurrentWeek =
        weekStartDate.getTime() ===
        startOfWeek(parseYmd(toDateInput(now))).getTime();

    const visitParams = (weekStart: Date, userId: number | null) => ({
        ...(toYMD(weekStart) !== toYMD(startOfWeek(parseYmd(toDateInput(now))))
            ? { week: toYMD(weekStart) }
            : {}),
        ...(canManageAny && userId ? { user_id: userId } : {}),
    });

    const goWeek = (weekStart: Date) => {
        router.get('/attendance', visitParams(weekStart, selectedUserId), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: [
                'sessions',
                'sessionPagination',
                'filters',
                'summary',
                'evidence',
                'lists',
                'weekHours',
                'todayHours',
                'openSession',
                'onClockNow',
                'handovers',
                'eligibleShifts',
                'canManageAny',
                'canClock',
                'canCreateHandovers',
            ],
        });
    };

    const viewStaff = (userId: number | null) => {
        router.get('/attendance', visitParams(weekStartDate, userId), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    /* ── live derived state ── */
    const onBreak = !!openSession?.on_break;
    const trackedBreakM = useMemo(() => {
        if (!openSession) return 0;
        return openSession.breaks.reduce(
            (acc, b) =>
                acc +
                (b.ended_at
                    ? (b.minutes ?? 0)
                    : b.started_at
                      ? Math.max(
                            0,
                            Math.round(
                                (now.getTime() -
                                    new Date(b.started_at).getTime()) /
                                    60000,
                            ),
                        )
                      : 0),
            0,
        );
    }, [openSession, now]);

    const openElapsedMs = openSession
        ? Math.max(
              0,
              now.getTime() - new Date(openSession.clock_in_at).getTime(),
          )
        : 0;
    const ownStale = !!openSession && openElapsedMs > STALE_MS;
    const todayOpenHours = openHoursInPeriod(
        openSession,
        evidence?.today_period_start,
        evidence?.today_period_end_exclusive,
        now,
    );
    const weekOpenHours = openHoursInPeriod(
        openSession,
        evidence?.period_start,
        evidence?.period_end_exclusive,
        now,
    );
    const selectedPeriod = `${formatDateOnly(toYMD(weekStartDate))} → ${formatDateOnly(toYMD(addDaysWP(weekStartDate, 6)))}`;
    const activeList =
        lists?.[
            tab === 'onclock'
                ? 'onClockNow'
                : tab === 'handovers'
                  ? 'handovers'
                  : 'sessions'
        ];

    const eligible = openSession ? [] : eligibleShifts;
    const staleRows = onClockNow.filter((r) => r.is_stale);
    const pendingIncoming = handovers.filter(
        (h) => h.incoming && h.status === 'submitted',
    ).length;

    const ownFixCandidate: FixCandidate | null = openSession
        ? {
              id: openSession.id,
              user_name: 'You',
              clock_in_at: openSession.clock_in_at,
              clock_out_at: null,
              break_minutes: openSession.break_minutes,
              shift_id: openSession.shift_id,
              location: openSession.shift_location,
              is_stale: ownStale,
          }
        : null;

    const fixCandidates: FixCandidate[] = canManageAny
        ? [...staleRows, ...onClockNow.filter((r) => !r.is_stale)].map((r) => ({
              id: r.id,
              user_name: r.user_name ?? `User #${r.user_id}`,
              clock_in_at: r.clock_in_at,
              clock_out_at: null,
              break_minutes: 0,
              shift_id: r.shift_id,
              location: r.shift_location,
              is_stale: r.is_stale,
          }))
        : ownFixCandidate
          ? [ownFixCandidate]
          : [];

    /* ── actions (success toasts come from the global FlashToaster) ── */
    const startBreak = () => {
        if (!openSession) return;
        router.post(
            '/attendance/break/start',
            { session_id: openSession.id },
            {
                preserveScroll: true,
                onError: (errs) =>
                    toast.error(errs.break ?? 'Could not start the break.'),
            },
        );
    };

    const endBreak = () => {
        if (!openSession) return;
        router.post(
            '/attendance/break/end',
            { session_id: openSession.id },
            {
                preserveScroll: true,
                onError: (errs) =>
                    toast.error(errs.break ?? 'Could not end the break.'),
            },
        );
    };

    const acknowledgeHandover = (h: AttendanceHandover) => {
        router.patch(
            `/attendance/handover/${h.id}/acknowledge`,
            {},
            {
                preserveScroll: true,
                onError: (errs) =>
                    toast.error(
                        errs.handover ?? 'Could not acknowledge the handover.',
                    ),
            },
        );
    };

    const openHandoverWizard = (editing: Handover | null = null) => {
        if (!editing && !canCreateHandovers) return;
        setDetailId(null);
        const launch = () => {
            setEditingHandover(editing);
            setHandoverOpen(true);
        };
        if (catalogue) {
            launch();
            return;
        }
        router.reload({ only: ['catalogue'], onSuccess: launch });
    };

    const detailHandover = handovers.find((h) => h.id === detailId) ?? null;

    /* ── context menus (ShiftContextMenu host) ── */
    const openCtx = (
        e: React.MouseEvent,
        tag: string,
        meta: string,
        items: ShiftCtxItem[],
    ) => {
        e.preventDefault();
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag,
            tagBg: 'color-mix(in oklch, var(--primary) 15%, transparent)',
            tagColor: 'var(--primary)',
            meta,
            items,
        });
    };

    const newHandoverItem: ShiftCtxItem[] = canCreateHandovers
        ? [
              { sep: true },
              {
                  icon: <ArrowLeftRight className="h-3.5 w-3.5" />,
                  label: 'New handover…',
                  onClick: () => openHandoverWizard(),
              },
          ]
        : [];

    const sessionCtx = (e: React.MouseEvent, s: Session) => {
        const timesheetId = s.timesheet_id;
        openCtx(e, 'Session', formatDateTime(s.clock_in_at), [
            timesheetId
                ? {
                      icon: <FileText className="h-3.5 w-3.5" />,
                      label: `View timesheet #${timesheetId}`,
                      onClick: () =>
                          router.visit(timesheetViewUrl(timesheetId)),
                  }
                : {
                      icon: <FileText className="h-3.5 w-3.5" />,
                      label: 'No linked timesheet',
                      sub: 'Not synced yet',
                  },
            s.timesheet_status === 'approved'
                ? {
                      icon: <Wrench className="h-3.5 w-3.5" />,
                      label: 'Correct times…',
                      sub: 'Timesheet approved — use an amendment',
                  }
                : {
                      icon: <Wrench className="h-3.5 w-3.5" />,
                      label: 'Correct times…',
                      onClick: () =>
                          setFixSessions([
                              {
                                  id: s.id,
                                  user_name: viewedStaff?.name ?? 'You',
                                  clock_in_at: s.clock_in_at,
                                  clock_out_at: s.clock_out_at,
                                  break_minutes: s.break_minutes,
                                  shift_id: null,
                                  location: s.location,
                                  is_stale:
                                      !s.clock_out_at &&
                                      now.getTime() -
                                          new Date(s.clock_in_at).getTime() >
                                          STALE_MS,
                              },
                          ]),
                  },
            ...newHandoverItem,
        ]);
    };

    const onClockCtx = (e: React.MouseEvent, s: OnClockRow) =>
        openCtx(
            e,
            s.user_name ?? `User #${s.user_id}`,
            `since ${formatTime(s.clock_in_at)}`,
            [
                {
                    icon: <UserIcon className="h-3.5 w-3.5" />,
                    label: `View ${(s.user_name ?? '').split(' ')[0] || 'their'}’s sessions`,
                    onClick: () => {
                        viewStaff(s.user_id);
                        setTab('sessions');
                    },
                },
                {
                    icon: <Wrench className="h-3.5 w-3.5" />,
                    label: 'Correct clock-out…',
                    onClick: () =>
                        setFixSessions([
                            {
                                id: s.id,
                                user_name: s.user_name ?? `User #${s.user_id}`,
                                clock_in_at: s.clock_in_at,
                                clock_out_at: null,
                                break_minutes: 0,
                                shift_id: s.shift_id,
                                location: s.shift_location,
                                is_stale: s.is_stale,
                            },
                        ]),
                },
                { sep: true },
                {
                    icon: <LogOut className="h-3.5 w-3.5" />,
                    label: 'End session…',
                    tone: 'critical',
                    onClick: () => setEndTarget(s),
                },
            ],
        );

    const handoverCtx = (e: React.MouseEvent, h: AttendanceHandover) =>
        openCtx(e, 'Handover', clientName(h.client), [
            {
                icon: <FileText className="h-3.5 w-3.5" />,
                label: 'View details',
                onClick: () => setDetailId(h.id),
            },
            ...(!viewedStaff &&
            h.status === 'submitted' &&
            h.incoming &&
            h.can_acknowledge
                ? [
                      {
                          icon: <Check className="h-3.5 w-3.5" />,
                          label: 'Acknowledge',
                          onClick: () => acknowledgeHandover(h),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            {
                icon: <ArrowLeftRight className="h-3.5 w-3.5" />,
                label: 'Open in Shift Handovers',
                onClick: () => {
                    const day =
                        h.outgoing_shift?.starts_at ?? h.created_at ?? null;
                    router.visit(
                        `/operations/handovers${day ? `?week=${toYMD(new Date(day))}` : ''}`,
                    );
                },
            },
            ...newHandoverItem,
        ]);

    /* ── hero content ── */
    const heroBadges: {
        icon?: typeof Timer;
        label: string;
        tone?: 'default' | 'success' | 'warning' | 'critical' | 'info';
        dot?: boolean;
        onClick?: () => void;
    }[] = [
        openSession
            ? {
                  icon: CheckCircle2,
                  label: `On the clock since ${formatTime(openSession.clock_in_at)} · ${fmtDur(openElapsedMs)}`,
                  tone: 'success' as const,
              }
            : { label: 'Not clocked in', tone: 'default' as const, dot: true },
        ...(onBreak && openSession?.break_started_at
            ? [
                  {
                      icon: Coffee,
                      label: `On break since ${formatTime(openSession.break_started_at)}`,
                      tone: 'warning' as const,
                  },
              ]
            : []),
        ...(!onBreak && openSession && trackedBreakM > 0
            ? [
                  {
                      icon: Coffee,
                      label: `${trackedBreakM}m breaks tracked`,
                      tone: 'default' as const,
                  },
              ]
            : []),
        ...(ownStale && !viewedStaff
            ? [
                  {
                      icon: AlertTriangle,
                      label: 'Open 16h+ — likely missed clock-out',
                      tone: 'warning' as const,
                      onClick: () => setClockOutOpen(true),
                  },
              ]
            : []),
        ...(eligible.length > 0
            ? [
                  {
                      icon: CalendarDays,
                      label: `${eligible.length} eligible shift${eligible.length === 1 ? '' : 's'} in the clock-in window`,
                      tone: 'info' as const,
                  },
              ]
            : []),
        ...(openSession?.timesheet_id
            ? [
                  {
                      icon: Link2,
                      label: `Linked timesheet #${openSession.timesheet_id}`,
                      tone: 'default' as const,
                  },
              ]
            : []),
        ...(canManageAny && staleRows.length > 0
            ? [
                  {
                      icon: AlertTriangle,
                      label: `${staleRows.length} likely missed clock-out${staleRows.length === 1 ? '' : 's'}`,
                      tone: 'warning' as const,
                      onClick: () => setFixSessions(fixCandidates),
                  },
              ]
            : []),
        ...(viewedStaff
            ? [
                  {
                      icon: UserCheck,
                      label: `Viewing ${viewedStaff.name}`,
                      tone: 'warning' as const,
                  },
              ]
            : []),
    ];

    const tabItems: RosterTabItem[] = [
        {
            id: 'sessions',
            label: 'Sessions',
            icon: Timer,
            tone: 'primary',
            badge: summary?.sessions_total ?? sessions.length,
        },
        ...(canManageAny
            ? [
                  {
                      id: 'onclock',
                      label: 'On the clock',
                      icon: Users,
                      tone: (staleRows.length > 0
                          ? 'warning'
                          : 'success') as RosterTabItem['tone'],
                      badge: summary?.on_clock_now_total ?? onClockNow.length,
                  },
              ]
            : []),
        {
            id: 'handovers',
            label: 'Handovers',
            icon: ArrowLeftRight,
            tone: 'info',
            badge: pendingIncoming > 0 ? pendingIncoming : undefined,
        },
    ];

    const prevLab = weekLabel(addDaysWP(weekStartDate, -7));
    const nextLab = weekLabel(addDaysWP(weekStartDate, 7));
    const curLab = weekLabel(weekStartDate);

    const headerPrimaryAction =
        viewedStaff || !canClock ? null : openSession ? (
            <PageHeaderPrimaryButton
                icon={LogOut}
                onClick={() => setClockOutOpen(true)}
                data-test="attendance-clock-out"
            >
                Clock out
            </PageHeaderPrimaryButton>
        ) : (
            <PageHeaderPrimaryButton
                icon={LogIn}
                onClick={() => setClockInOpen(true)}
                data-test="attendance-clock-in"
            >
                Clock in
            </PageHeaderPrimaryButton>
        );
    const headerSecondaryActions =
        viewedStaff || !canClock ? null : (
            <>
                {openSession ? (
                    <>
                        <PageHeaderGlassButton
                            onClick={onBreak ? endBreak : startBreak}
                            data-test={
                                onBreak
                                    ? 'attendance-end-break'
                                    : 'attendance-start-break'
                            }
                        >
                            <Coffee className="mr-1 h-4 w-4" />
                            {onBreak ? 'End break' : 'Start break'}
                        </PageHeaderGlassButton>
                        {canCreateHandovers ? (
                            <PageHeaderGlassButton
                                onClick={() => openHandoverWizard()}
                                data-test="attendance-handover"
                            >
                                <ArrowLeftRight className="mr-1 h-4 w-4" />
                                Handover
                            </PageHeaderGlassButton>
                        ) : null}
                    </>
                ) : null}
                <PageHeaderGlassButton
                    aria-label="Fix a missed clock-out"
                    title={
                        fixCandidates.length
                            ? 'Fix a missed clock-out'
                            : 'No open sessions to correct'
                    }
                    disabled={!fixCandidates.length}
                    onClick={() => setFixSessions(fixCandidates)}
                    data-test="attendance-fix-clock-out"
                >
                    <Wrench className="h-4 w-4" />
                </PageHeaderGlassButton>
            </>
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Attendance" />

            <PageShell>
                <WorkforcePageHeader
                    variant="profile"
                    icon={Timer}
                    title={
                        viewedStaff
                            ? `${viewedStaff.name} · Attendance`
                            : 'My attendance'
                    }
                    subline={`${selectedPeriod} · Clock sessions, breaks and recorded timesheet links`}
                    actions={
                        <>
                            {headerPrimaryAction}
                            {headerSecondaryActions}
                        </>
                    }
                    mobilePrimaryAction={headerPrimaryAction}
                    mobileSecondaryActions={headerSecondaryActions}
                    mobileSummary={selectedPeriod}
                    meters={
                        <>
                            <PageHeaderMeterBlock
                                label="Hours today"
                                onClick={() => setTab('sessions')}
                                ariaLabel="View attendance sessions"
                            >
                                <PageHeaderMeterBig>
                                    {todayHours.toFixed(1)} hr
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Closed sessions starting{' '}
                                    {formatDateOnly(
                                        evidence?.today ?? toDateInput(now),
                                    )}
                                    {openSession
                                        ? todayOpenHours === null
                                            ? ' · Open time needs review'
                                            : ` · ${todayOpenHours.toFixed(1)} hr open estimate`
                                        : ''}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Selected week"
                                onClick={() => setTab('sessions')}
                                ariaLabel="View weekly sessions"
                            >
                                <PageHeaderMeterBig>
                                    {weekHours.toFixed(1)} hr
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Closed sessions by clock-in date
                                    {openSession
                                        ? weekOpenHours === null
                                            ? ' · Open time needs review'
                                            : ` · ${weekOpenHours.toFixed(1)} hr open estimate`
                                        : ''}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Clock status"
                                onClick={() => setTab('sessions')}
                                ariaLabel="View clock session"
                            >
                                <PageHeaderMeterBig>
                                    {openSession
                                        ? onBreak
                                            ? 'On break'
                                            : 'Clocked in'
                                        : 'Not clocked in'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {openSession
                                        ? `Since ${formatTime(openSession.clock_in_at)}`
                                        : 'No open session recorded'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Clock-in choices"
                                onClick={() => {
                                    if (
                                        !viewedStaff &&
                                        canClock &&
                                        !openSession
                                    )
                                        setClockInOpen(true);
                                    else setTab('sessions');
                                }}
                                ariaLabel="Review shifts in the clock-in window"
                            >
                                <PageHeaderMeterBig>
                                    {eligible.length}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Current clock-in choices
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </>
                    }
                    details={
                        <div className="flex w-full flex-wrap gap-2">
                            {heroBadges.map((badge, index) =>
                                badge.onClick ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        key={index}
                                        onClick={badge.onClick}
                                    >
                                        {badge.icon && (
                                            <badge.icon className="size-4" />
                                        )}
                                        {badge.label}
                                    </Button>
                                ) : (
                                    <StatusBadge
                                        key={index}
                                        variant={
                                            badge.tone === 'default'
                                                ? 'neutral'
                                                : (badge.tone ?? 'neutral')
                                        }
                                    >
                                        {badge.label}
                                    </StatusBadge>
                                ),
                            )}
                        </div>
                    }
                    filters={
                        <div className="flex w-full flex-wrap items-center justify-end gap-1.5">
                            <div className="flex flex-wrap items-center gap-1.5">
                                <PageHeaderFilterButton
                                    icon={ChevronLeft}
                                    onClick={() =>
                                        goWeek(addDaysWP(weekStartDate, -7))
                                    }
                                    aria-label="Previous week"
                                />
                                <PageHeaderFilterButton
                                    ref={weekBtnRef}
                                    icon={CalendarRange}
                                    onClick={() => setPickerOpen(!pickerOpen)}
                                    aria-haspopup="dialog"
                                    aria-expanded={pickerOpen}
                                >
                                    {curLab} · {weekCompactRange(weekStartDate)}{' '}
                                    {weekStartDate.getFullYear()}
                                    {isCurrentWeek ? ' · this week' : ''}
                                </PageHeaderFilterButton>
                                <PageHeaderFilterButton
                                    icon={ChevronRight}
                                    onClick={() =>
                                        goWeek(addDaysWP(weekStartDate, 7))
                                    }
                                    aria-label="Next week"
                                />
                            </div>
                            {canManageAny ? (
                                <Select
                                    value={
                                        viewedStaff
                                            ? String(viewedStaff.id)
                                            : ANY
                                    }
                                    onValueChange={(value) =>
                                        viewStaff(
                                            value === ANY
                                                ? null
                                                : Number(value),
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        aria-label="Whose sessions"
                                        className="h-[23px] w-48 rounded-[8px] border-band-foreground/20 bg-band-foreground/10 px-2 py-0 text-[11.5px] text-band-foreground"
                                    >
                                        <SelectValue placeholder="My sessions" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>
                                            My sessions
                                        </SelectItem>
                                        {staff.map((member) => (
                                            <SelectItem
                                                key={member.id}
                                                value={String(member.id)}
                                            >
                                                {member.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            ) : null}
                        </div>
                    }
                    rail={
                        <PageHeaderRail
                            items={tabItems.map((item) => ({
                                key: item.id,
                                label: item.label,
                                icon: item.icon,
                                badge: item.badge,
                            }))}
                            value={tab}
                            onSelect={setTab}
                            ariaLabel="Attendance views"
                        />
                    }
                />

                <div className="text-caption flex flex-wrap items-center justify-between gap-2 text-muted-foreground">
                    <p>
                        {tab === 'sessions'
                            ? `${selectedPeriod} · ${totalSessions} recorded sessions across all dates`
                            : tab === 'onclock'
                              ? 'Current open sessions at permitted sites'
                              : 'Recorded handovers for this person across all dates'}
                        {evidence
                            ? ` · Updated ${formatDateTimeInZone(evidence.checked_at, evidence.timezone)}`
                            : ''}
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => router.reload()}
                    >
                        Refresh records
                    </Button>
                    {openSession && tab === 'sessions' ? (
                        <p className="w-full">
                            Open estimate today (
                            {formatDateOnly(
                                evidence?.today ?? toDateInput(now),
                            )}
                            ):{' '}
                            {todayOpenHours === null
                                ? 'needs review'
                                : `${todayOpenHours.toFixed(1)} hr`}{' '}
                            · Selected week:{' '}
                            {weekOpenHours === null
                                ? 'needs review'
                                : `${weekOpenHours.toFixed(1)} hr`}
                            . Estimates cover only time inside each period,
                            excluding recorded breaks. Closed hours keep the
                            session’s clock-in date.
                        </p>
                    ) : null}
                    {activeList?.truncated ? (
                        <p role="status" className="w-full text-status-warning">
                            {activeList.shown} of {activeList.total} records
                            loaded. The totals include all permitted records;
                            {tab === 'sessions'
                                ? 'Use the page controls to review more.'
                                : `Only the most recent ${activeList.limit} records appear here.`}
                        </p>
                    ) : null}
                </div>

                {tab === 'sessions' && sessionPagination ? (
                    <LaravelPagination
                        links={sessionPagination.links}
                        lastPage={sessionPagination.last_page}
                        className="flex-wrap"
                    />
                ) : null}
                {tab === 'sessions' ? (
                    <SessionsCard
                        sessions={sessions}
                        weekLabelText={curLab}
                        weekRangeText={`${selectedPeriod}${viewedStaff ? ` · ${viewedStaff.name}` : ''}`}
                        onContext={sessionCtx}
                    />
                ) : null}

                {tab === 'onclock' && canManageAny ? (
                    <OnClockBoard
                        rows={onClockNow}
                        now={now}
                        onView={(userId) => {
                            viewStaff(userId);
                            setTab('sessions');
                        }}
                        onEnd={setEndTarget}
                        onContext={onClockCtx}
                    />
                ) : null}

                {tab === 'handovers' ? (
                    <HandoversList
                        rows={handovers}
                        currentUserId={currentUser.id}
                        viewingSelf={!viewedStaff}
                        canCreate={canCreateHandovers}
                        onOpen={(h) => setDetailId(h.id)}
                        onAcknowledge={acknowledgeHandover}
                        onNew={() => openHandoverWizard()}
                        onContext={handoverCtx}
                    />
                ) : null}
            </PageShell>

            {/* ── workflows (wizards + dialogs) ── */}
            <ClockInWizard
                timezone={evidence?.timezone}
                open={clockInOpen}
                onClose={() => setClockInOpen(false)}
                shifts={eligible}
            />
            <ClockOutWizard
                timezone={evidence?.timezone}
                open={clockOutOpen}
                onClose={() => setClockOutOpen(false)}
                session={openSession}
            />
            <FixClockOutWizard
                timezone={evidence?.timezone}
                open={fixSessions !== null}
                onClose={() => setFixSessions(null)}
                sessions={fixSessions ?? []}
            />

            {/* Full handover record — the same detail pop-up as Shift Handovers. */}
            <HandoverDetailDialog
                handover={detailHandover}
                open={detailId != null}
                onOpenChange={(open) => !open && setDetailId(null)}
                onEdit={(h) => openHandoverWizard(h)}
                onSubmit={(h) =>
                    router.patch(
                        `/operations/handovers/${h.id}/submit`,
                        {},
                        {
                            preserveScroll: true,
                            onSuccess: () => setDetailId(null),
                        },
                    )
                }
                onAcknowledge={(h) => {
                    setDetailId(null);
                    acknowledgeHandover(h as AttendanceHandover);
                }}
            />

            {handoverOpen && catalogue ? (
                <HandoverWizard
                    open={handoverOpen}
                    onOpenChange={(open) => {
                        if (!open) {
                            setHandoverOpen(false);
                            setEditingHandover(null);
                        }
                    }}
                    editing={editingHandover}
                    catalogue={catalogue}
                    currentUser={currentUser}
                    preselectClientId={
                        pendingClientId ?? openSession?.client_id ?? null
                    }
                    onAddClient={() => setAddClientOpen(true)}
                    onSubmitted={() => {
                        setTab('handovers');
                        router.reload({ only: ['handovers'] });
                    }}
                />
            ) : null}

            {catalogue ? (
                <AddClientDialog
                    isOpen={addClientOpen}
                    onClose={() => setAddClientOpen(false)}
                    sites={catalogue.sites}
                    serviceContexts={catalogue.serviceContexts.map((s) => ({
                        id: s.id,
                        name: s.name,
                        type: s.type ?? undefined,
                    }))}
                    keyWorkers={catalogue.staff.map((s) => ({
                        id: s.id,
                        name: s.name,
                    }))}
                    geofences={[]}
                    defaultServiceContextId={
                        catalogue.serviceContexts[0]?.id ?? null
                    }
                    onSaved={(id) => {
                        setAddClientOpen(false);
                        router.reload({
                            only: ['catalogue'],
                            onSuccess: () => setPendingClientId(id),
                        });
                    }}
                />
            ) : null}

            <ReasonDialog
                open={endTarget !== null}
                onClose={() => setEndTarget(null)}
                title={
                    endTarget?.user_name
                        ? `End ${endTarget.user_name}'s session?`
                        : 'End this session?'
                }
                description="This closes the open attendance session, ends any running break, and syncs a draft timesheet. The reason is recorded in the audit log."
                label="Reason"
                placeholder="e.g. Missed clock-out on Monday"
                confirmLabel="End session"
                onConfirm={(reason, done) => {
                    if (!endTarget) return;
                    router.post(
                        `/attendance/sessions/${endTarget.id}/end`,
                        { reason },
                        {
                            preserveScroll: true,
                            onSuccess: () => setEndTarget(null),
                            onError: (errs) =>
                                toast.error(
                                    errs.end_session ??
                                        'Could not end the session.',
                                ),
                            onFinish: done,
                        },
                    );
                }}
            />

            {/* right-click menu host */}
            {ctx ? (
                <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
            ) : null}

            {/* week-picker popover (rostering chrome) */}
            {pickerOpen ? (
                <WeekPicker
                    selectedWeekStart={weekStartDate}
                    anchorRef={weekBtnRef}
                    onSelect={(weekStart) => goWeek(startOfWeek(weekStart))}
                    onClose={() => setPickerOpen(false)}
                    showContextMenu={false}
                />
            ) : null}
        </AppLayout>
    );
}
