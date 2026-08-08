/* Meds Today — the worker-facing medication board at `/meds/today`.
 *
 * Desktop-first rebuild on the rostering page idiom: gradient PageHero with a
 * day stepper + filters in the banner footer, TabStrip view switcher
 * (Schedule / Rounds / PRN / Stock / Activity), a full-day schedule table with
 * right-click context menus, and Add-Client-contract wizards for recording
 * scheduled doses and PRNs.
 *
 * Source of truth: Emar/WorkerMedsController. Every write goes through the
 * same EnhancedMarService pipeline the admin paths use.
 */
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Ban,
    CalendarDays,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock,
    Copy,
    Eye,
    FileText,
    Hand,
    History,
    Home,
    MapPin,
    Package,
    Pill,
    Printer,
    RotateCcw,
    Search,
    Shield,
    User,
    X,
    Zap,
} from 'lucide-react';
import {
    useEffect,
    useMemo,
    useRef,
    useState,
    type MouseEvent as ReactMouseEvent,
} from 'react';
import { toast } from 'sonner';

import {
    CdBadge,
    ClientAvatar,
    DOSE_STATUS_META,
    StatusPill,
} from '@/components/meds/board-bits';
import { PageHero } from '@/components/page/page-hero';
import type { PageHeroBadge } from '@/components/page/page-hero-badges';
import type { PageHeroStat } from '@/components/page/page-hero-stats';
import { EntityFilter } from '@/components/rostering/entity-filter';
import {
    ShiftContextMenu,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering/shift-context-menu';
import { TabStrip, type RosterTabItem } from '@/components/rostering/tab-strip';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';

import {
    DayPickerChip,
    addDays,
    parseYmd,
} from '@/components/meds/day-picker-chip';
import { PrnEffectDialog } from './components/prn-effect-dialog';
import { PrnWizard } from './components/prn-wizard';
import { RecordDoseWizard } from './components/record-dose-wizard';
import { RecordedDetailDialog } from './components/recorded-detail-dialog';
import type {
    ActivityItem,
    ClientInfo,
    MedsTodayProps,
    PrnFollowUp,
    RoundInfo,
    ScheduleRow,
    StockAlert,
} from './types';

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

type WizardState =
    | { type: 'dose'; row: ScheduleRow; initialOutcome?: 'given' | 'withheld' }
    | { type: 'prn'; medId?: number }
    | null;

function computeBoard(schedule: ScheduleRow[]) {
    const overdue = schedule.filter((r) => r.status === 'overdue').length;
    const due = schedule.filter((r) => r.status === 'due').length;
    const later = schedule.filter((r) => r.status === 'upcoming').length;
    const done = schedule.filter((r) => r.recorded !== null).length;
    const given = schedule.filter((r) => r.status === 'given').length;
    const total = schedule.length;
    return {
        overdue,
        due,
        later,
        done,
        given,
        total,
        dueNow: overdue + due,
        recordedPct: total > 0 ? Math.round((done / total) * 100) : 0,
    };
}

/** Same time-of-day bucket the backend uses for round_label. */
function bucketForTime(hhmm: string | null): string {
    const hour = Number((hhmm ?? '').slice(0, 2));
    if (Number.isNaN(hour)) return 'Morning';
    if (hour < 11) return 'Morning';
    if (hour < 14) return 'Midday';
    if (hour < 17) return 'Afternoon';
    if (hour < 21) return 'Evening';
    return 'Night';
}

function clockLabel(hhmm?: string | null): string {
    return (hhmm ?? '').slice(0, 5);
}

/* ------------------------------------------------------------------ */
/*  Active round banner                                                */
/* ------------------------------------------------------------------ */

function ActiveRoundBanner({ round }: { round: RoundInfo }) {
    const verb = round.status === 'in_progress' ? 'Resume' : 'Start';
    return (
        <Link
            href={round.url}
            aria-label={`${verb} ${round.name}`}
            className="group block w-full rounded-xl border border-status-success/30 bg-status-success-bg p-4 text-left transition-shadow hover:shadow-sm"
        >
            <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-status-success text-white">
                    <Pill className="h-5 w-5" />
                </div>
                <div className="min-w-0 flex-1">
                    <p className="text-sm leading-tight font-semibold">
                        {verb} {round.name} — guided walk-through
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {round.completed} of {round.total} done
                        {round.scheduled_time
                            ? ` · scheduled ${clockLabel(round.scheduled_time)}`
                            : ''}
                    </p>
                    <Progress
                        value={round.percent}
                        className="mt-2 h-1.5 max-w-md"
                    />
                </div>
                <span className="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-[13px] font-semibold text-primary-foreground shadow-sm transition-colors group-hover:bg-primary/90">
                    {verb} round
                    <ArrowRight className="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5" />
                </span>
            </div>
        </Link>
    );
}

/* ------------------------------------------------------------------ */
/*  Schedule table                                                     */
/* ------------------------------------------------------------------ */

function DoseRow({
    row,
    client,
    onRecord,
    onCtx,
}: {
    row: ScheduleRow;
    client: ClientInfo | undefined;
    onRecord: (row: ScheduleRow) => void;
    onCtx: (e: ReactMouseEvent, row: ScheduleRow) => void;
}) {
    const actionable = row.recorded === null;
    return (
        <tr
            data-test="meds-due-row"
            className="border-b border-border/70 transition-colors last:border-0 hover:bg-muted/40"
            onContextMenu={(e) => onCtx(e, row)}
        >
            <td className="py-3 pr-3 pl-5 align-middle whitespace-nowrap">
                <div className="text-sm font-bold tabular-nums">{row.time}</div>
                <div className="text-[11px] text-muted-foreground">
                    {row.round_label}
                </div>
            </td>
            <td className="py-3 pr-3 align-middle">
                <div className="flex items-center gap-2.5">
                    <ClientAvatar
                        name={row.client_name}
                        clientId={row.client_id}
                    />
                    <div className="min-w-0">
                        <Link
                            href={`/operations/clients/${row.client_id}?tab=mar`}
                            className="block truncate text-sm font-semibold hover:underline"
                        >
                            {row.client_name}
                        </Link>
                        <div className="truncate text-[11px] text-muted-foreground">
                            {[
                                client?.site_name,
                                client?.nhi ? `NHI ${client.nhi}` : null,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </div>
                    </div>
                </div>
            </td>
            <td className="py-3 pr-3 align-middle">
                <div className="flex items-center gap-2">
                    <Link
                        href={row.mar_url}
                        className="truncate text-sm hover:underline"
                    >
                        {row.medication_name}
                    </Link>
                    {row.is_controlled ? <CdBadge /> : null}
                </div>
                {row.dose ? (
                    <div className="text-[11px] text-muted-foreground">
                        {row.dose}
                    </div>
                ) : null}
            </td>
            <td className="py-3 pr-3 align-middle text-sm whitespace-nowrap text-muted-foreground">
                {row.route ?? '—'}
            </td>
            <td className="py-3 pr-3 align-middle">
                <StatusPill status={row.status} />
            </td>
            <td className="py-3 pr-5 text-right align-middle whitespace-nowrap">
                {actionable ? (
                    <Button
                        size="sm"
                        variant={
                            row.status === 'overdue' ? 'default' : 'outline'
                        }
                        onClick={() => onRecord(row)}
                        aria-label={`Record ${row.medication_name} for ${row.client_name}`}
                    >
                        Record <ChevronRight className="h-3.5 w-3.5" />
                    </Button>
                ) : (
                    <div className="inline-flex flex-col items-end">
                        <span
                            className={`inline-flex items-center gap-1 text-[13px] font-semibold ${
                                row.status === 'given'
                                    ? 'text-status-success'
                                    : 'text-muted-foreground'
                            }`}
                        >
                            {row.status === 'given' ? (
                                <Check className="h-3.5 w-3.5" />
                            ) : (
                                <Hand className="h-3.5 w-3.5" />
                            )}
                            {row.recorded?.time ?? '—'}
                        </span>
                        <span className="text-[11px] text-muted-foreground">
                            {row.recorded?.by}
                            {row.recorded?.witness
                                ? ` · wit. ${row.recorded.witness}`
                                : ''}
                        </span>
                    </div>
                )}
            </td>
        </tr>
    );
}

function ScheduleCard({
    rows,
    clientById,
    search,
    onSearchChange,
    onRecord,
    onCtx,
}: {
    rows: ScheduleRow[];
    clientById: Map<number, ClientInfo>;
    search: string;
    onSearchChange: (next: string) => void;
    onRecord: (row: ScheduleRow) => void;
    onCtx: (e: ReactMouseEvent, row: ScheduleRow) => void;
}) {
    const [status, setStatus] = useState<'all' | 'due' | 'later' | 'given'>(
        'all',
    );
    const [page, setPage] = useState(0);
    const [perPage, setPerPage] = useState(8);

    const board = computeBoard(rows);

    const filtered = useMemo(() => {
        return rows.filter((row) => {
            if (status === 'due' && !['due', 'overdue'].includes(row.status))
                return false;
            if (status === 'later' && row.status !== 'upcoming') return false;
            if (status === 'given' && row.recorded === null) return false;
            return true;
        });
    }, [rows, status]);

    // `rows` gets a new identity whenever the hero search/site/client filters
    // change, so any filter change snaps pagination back to the first page.
    useEffect(() => {
        setPage(0);
    }, [search, status, perPage, rows]);

    const pageCount = Math.max(1, Math.ceil(filtered.length / perPage));
    const safePage = Math.min(page, pageCount - 1);
    const visible = filtered.slice(
        safePage * perPage,
        safePage * perPage + perPage,
    );
    const from = filtered.length === 0 ? 0 : safePage * perPage + 1;
    const to = Math.min(filtered.length, (safePage + 1) * perPage);

    const segment = (value: typeof status, label: string, count: number) => (
        // eslint-disable-next-line no-restricted-syntax -- segmented status filter (wizard Segmented idiom with count badges), not a shadcn Button.
        <button
            key={value}
            type="button"
            onClick={() => setStatus(value)}
            aria-pressed={status === value}
            className={`inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-[13px] font-semibold transition-colors ${
                status === value
                    ? 'bg-card text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground'
            }`}
        >
            {label}
            <span className="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-bold tabular-nums">
                {count}
            </span>
        </button>
    );

    return (
        <Card className="gap-0 overflow-hidden py-0">
            <CardHeader className="gap-3 px-5 pt-5 pb-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <CalendarDays className="h-4 w-4 text-muted-foreground" />
                            Today&rsquo;s schedule
                        </CardTitle>
                        <CardDescription className="mt-1">
                            Every scheduled dose for the clients on your shift,
                            across all sites.
                        </CardDescription>
                    </div>
                    <div className="inline-flex flex-wrap gap-1 rounded-lg bg-muted p-1">
                        {segment('all', 'All', board.total)}
                        {segment('due', 'Due now', board.dueNow)}
                        {segment('later', 'Later', board.later)}
                        {segment('given', 'Recorded', board.done)}
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative w-full max-w-xs">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            className="pl-8"
                            placeholder="Search client or medication…"
                            value={search}
                            onChange={(e) => onSearchChange(e.target.value)}
                            aria-label="Search doses"
                        />
                    </div>
                    {search || status !== 'all' ? (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                onSearchChange('');
                                setStatus('all');
                            }}
                        >
                            <X className="h-3.5 w-3.5" /> Clear
                        </Button>
                    ) : null}
                </div>
            </CardHeader>

            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-left">
                    <thead>
                        <tr className="border-y border-border bg-muted/50 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                            <th className="py-2.5 pr-3 pl-5 font-semibold">
                                Time
                            </th>
                            <th className="py-2.5 pr-3 font-semibold">
                                Client
                            </th>
                            <th className="py-2.5 pr-3 font-semibold">
                                Medication
                            </th>
                            <th className="py-2.5 pr-3 font-semibold">Route</th>
                            <th className="py-2.5 pr-3 font-semibold">
                                Status
                            </th>
                            <th className="py-2.5 pr-5 text-right font-semibold">
                                Record
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {visible.map((row) => (
                            <DoseRow
                                key={row.key}
                                row={row}
                                client={clientById.get(row.client_id)}
                                onRecord={onRecord}
                                onCtx={onCtx}
                            />
                        ))}
                    </tbody>
                </table>
                {visible.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 py-12 text-center">
                        <CheckCircle2 className="h-8 w-8 text-status-success" />
                        <p className="text-sm font-medium">No doses match</p>
                        <p className="max-w-sm text-xs text-muted-foreground">
                            Try clearing the search or filters — nothing with
                            this combination is on the schedule for this day.
                        </p>
                    </div>
                ) : null}
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border bg-muted/30 px-5 py-3">
                <span className="text-xs text-muted-foreground">
                    Showing{' '}
                    <strong className="text-foreground">
                        {from}–{to}
                    </strong>{' '}
                    of{' '}
                    <strong className="text-foreground">
                        {filtered.length}
                    </strong>{' '}
                    doses
                </span>
                <div className="flex items-center gap-2">
                    <span className="text-xs text-muted-foreground">Rows</span>
                    <Select
                        value={String(perPage)}
                        onValueChange={(v) => setPerPage(Number(v))}
                    >
                        <SelectTrigger
                            className="h-8 w-[72px]"
                            aria-label="Rows per page"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="8">8</SelectItem>
                            <SelectItem value="12">12</SelectItem>
                            <SelectItem value="20">20</SelectItem>
                        </SelectContent>
                    </Select>
                    <div className="ml-2 flex items-center gap-1">
                        <Button
                            variant="outline"
                            size="icon"
                            className="h-8 w-8"
                            disabled={safePage === 0}
                            onClick={() => setPage(safePage - 1)}
                            aria-label="Previous page"
                        >
                            <ChevronLeft className="h-4 w-4" />
                        </Button>
                        <span className="px-1.5 text-xs text-muted-foreground tabular-nums">
                            {safePage + 1} / {pageCount}
                        </span>
                        <Button
                            variant="outline"
                            size="icon"
                            className="h-8 w-8"
                            disabled={safePage >= pageCount - 1}
                            onClick={() => setPage(safePage + 1)}
                            aria-label="Next page"
                        >
                            <ChevronRight className="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </div>
        </Card>
    );
}

/* ------------------------------------------------------------------ */
/*  Right rail cards                                                   */
/* ------------------------------------------------------------------ */

function PrnQuickCard({
    count,
    onOpen,
}: {
    count: number;
    onOpen: () => void;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- tinted quick-action card (mobile board idiom), not a shadcn Button.
        <button
            type="button"
            onClick={onOpen}
            disabled={count === 0}
            data-test="meds-prn-button"
            aria-label={
                count === 0
                    ? 'Give as-needed med — none set up'
                    : `Give as-needed med (${count} available)`
            }
            className="group flex w-full items-center gap-3 rounded-xl border border-status-warning/30 bg-status-warning-bg p-4 text-left transition-shadow hover:shadow-sm disabled:cursor-not-allowed disabled:opacity-60"
        >
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-status-warning text-white">
                <Zap className="h-5 w-5" />
            </div>
            <div className="min-w-0 flex-1">
                <p className="text-sm leading-tight font-semibold">
                    Give as-needed med
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {count === 0
                        ? 'No as-needed meds set up for your clients today'
                        : `${count} PRN med${count === 1 ? '' : 's'} ready · quick record`}
                </p>
            </div>
            <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground/60 transition-transform group-hover:translate-x-0.5" />
        </button>
    );
}

function FollowUpsCard({
    followUps,
    clientById,
    onRecordEffect,
}: {
    followUps: PrnFollowUp[];
    clientById: Map<number, ClientInfo>;
    onRecordEffect: (followUp: PrnFollowUp) => void;
}) {
    if (followUps.length === 0) return null;
    return (
        <Card className="gap-2 py-4">
            <CardHeader className="px-4 pb-0">
                <CardTitle className="flex items-center gap-2 text-sm">
                    <RotateCcw className="h-4 w-4 text-muted-foreground" />
                    PRN follow-ups
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-2 px-4">
                {followUps.map((f) => {
                    const client = clientById.get(f.client_id);
                    return (
                        <div
                            key={f.administration_id}
                            className="flex items-center justify-between gap-2 rounded-lg border border-border p-2.5"
                        >
                            <div className="min-w-0">
                                <div className="truncate text-[13px] font-semibold">
                                    {client?.preferred ??
                                        client?.name ??
                                        'Client'}{' '}
                                    — {f.medication_name ?? 'PRN'}
                                </div>
                                <div className="text-[11px] text-muted-foreground">
                                    Given {f.given_time ?? '—'} · check effect
                                    {f.check_at ? ` at ${f.check_at}` : ''}
                                </div>
                            </div>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => onRecordEffect(f)}
                            >
                                Record effect
                            </Button>
                        </div>
                    );
                })}
            </CardContent>
        </Card>
    );
}

function RoundsCard({ rounds }: { rounds: RoundInfo[] }) {
    if (rounds.length === 0) return null;
    return (
        <Card className="gap-2 py-4">
            <CardHeader className="px-4 pb-0">
                <CardTitle className="flex items-center gap-2 text-sm">
                    <Pill className="h-4 w-4 text-muted-foreground" />
                    Rounds today
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col divide-y divide-border/70 px-4">
                {rounds.map((r) => {
                    const isActive = r.status === 'in_progress';
                    const isDone = r.status === 'completed';
                    const verb = isActive ? 'Resume' : 'Start';
                    return (
                        <div
                            key={r.id}
                            className="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                        >
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2">
                                    <span className="truncate text-[13px] font-semibold">
                                        {r.name}
                                    </span>
                                    {isActive ? (
                                        <Badge
                                            variant="outline"
                                            className="border-status-success/40 text-[10px] tracking-wide text-status-success uppercase"
                                        >
                                            In progress
                                        </Badge>
                                    ) : null}
                                </div>
                                <div className="mt-0.5 flex items-center gap-1.5 text-[11px] text-muted-foreground">
                                    <Clock className="h-3 w-3" />
                                    {clockLabel(r.scheduled_time)} ·{' '}
                                    {r.completed} of {r.total} done
                                </div>
                                {!isDone ? (
                                    <Progress
                                        value={r.percent}
                                        className="mt-1.5 h-1"
                                    />
                                ) : null}
                            </div>
                            {isDone ? (
                                <span className="inline-flex items-center gap-1 text-[12px] font-semibold text-status-success">
                                    <CheckCircle2 className="h-4 w-4" /> Done
                                </span>
                            ) : (
                                <Button
                                    size="sm"
                                    variant={isActive ? 'default' : 'outline'}
                                    asChild
                                >
                                    <Link
                                        href={r.url}
                                        aria-label={`${verb} ${r.name}`}
                                    >
                                        {verb}
                                    </Link>
                                </Button>
                            )}
                        </div>
                    );
                })}
            </CardContent>
        </Card>
    );
}

function StockAlertRow({
    alert,
    canManage,
    compact,
}: {
    alert: StockAlert;
    canManage: boolean;
    compact?: boolean;
}) {
    const actionLabel = alert.type === 'stock_low' ? 'Reorder' : 'Replace';
    return (
        <div
            className={`flex flex-wrap items-center justify-between gap-2 rounded-lg border ${
                alert.tone === 'crit'
                    ? 'border-status-critical/30 bg-status-critical-bg'
                    : 'border-status-warning/30 bg-status-warning-bg'
            } ${compact ? 'p-2.5' : 'p-3.5'}`}
        >
            <div className="min-w-0">
                <div
                    className={`truncate font-semibold ${compact ? 'text-[13px]' : 'text-sm'}`}
                >
                    {alert.label}
                </div>
                <div
                    className={`text-muted-foreground ${compact ? 'text-[11px]' : 'text-xs'}`}
                >
                    {alert.detail}
                </div>
            </div>
            {canManage ? (
                <Button
                    size="sm"
                    variant="outline"
                    className="bg-card/70"
                    asChild
                >
                    <Link href="/emar/stock">{actionLabel}</Link>
                </Button>
            ) : null}
        </div>
    );
}

function StockCard({
    alerts,
    canManage,
}: {
    alerts: StockAlert[];
    canManage: boolean;
}) {
    if (alerts.length === 0) return null;
    return (
        <Card className="gap-2 py-4">
            <CardHeader className="px-4 pb-0">
                <CardTitle className="flex items-center gap-2 text-sm">
                    <Package className="h-4 w-4 text-muted-foreground" />
                    Stock &amp; CD alerts
                    <Badge
                        variant="outline"
                        className="ml-auto border-status-warning/40 bg-status-warning-bg text-[10.5px] text-status-warning"
                    >
                        {alerts.length}
                    </Badge>
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-2 px-4">
                {alerts.slice(0, 4).map((a) => (
                    <StockAlertRow
                        key={a.id}
                        alert={a}
                        canManage={canManage}
                        compact
                    />
                ))}
            </CardContent>
        </Card>
    );
}

function ActivityList({
    activity,
    limit = 6,
}: {
    activity: ActivityItem[];
    limit?: number;
}) {
    const iconFor = (kind: string): [typeof Check, string] =>
        kind === 'refused'
            ? [Hand, 'bg-status-critical-bg text-status-critical']
            : kind === 'cd'
              ? [Shield, 'bg-primary/10 text-primary']
              : kind === 'prn'
                ? [Zap, 'bg-status-warning-bg text-status-warning']
                : [Check, 'bg-status-success-bg text-status-success'];
    if (activity.length === 0) {
        return (
            <p className="py-2 text-xs text-muted-foreground">
                Nothing recorded yet for this day.
            </p>
        );
    }
    return (
        <div className="flex flex-col gap-3">
            {activity.slice(0, limit).map((a) => {
                const [Icon, cls] = iconFor(a.icon);
                return (
                    <div key={a.id} className="flex items-start gap-2.5">
                        <span
                            className={`mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full ${cls}`}
                        >
                            <Icon className="h-3 w-3" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="text-[13px] leading-snug">{a.text}</p>
                            <p className="text-[11px] text-muted-foreground">
                                {a.time} · {a.by}
                            </p>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

function ActivityCard({
    activity,
    canViewAudit,
}: {
    activity: ActivityItem[];
    canViewAudit: boolean;
}) {
    return (
        <Card className="gap-2 py-4">
            <CardHeader className="px-4 pb-0">
                <CardTitle className="flex items-center gap-2 text-sm">
                    <History className="h-4 w-4 text-muted-foreground" />
                    Recent activity
                </CardTitle>
            </CardHeader>
            <CardContent className="px-4">
                <ActivityList activity={activity} />
                {canViewAudit ? (
                    <Link
                        href="/emar/audit"
                        className="mt-3 inline-flex items-center gap-1 text-[12px] font-semibold text-primary hover:underline"
                    >
                        View full audit trail
                        <ArrowRight className="h-3 w-3" />
                    </Link>
                ) : null}
            </CardContent>
        </Card>
    );
}

/* ------------------------------------------------------------------ */
/*  Full-width tab panes                                               */
/* ------------------------------------------------------------------ */

function RoundsTab({
    rounds,
    schedule,
    clientById,
}: {
    rounds: RoundInfo[];
    schedule: ScheduleRow[];
    clientById: Map<number, ClientInfo>;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <Pill className="h-4 w-4 text-muted-foreground" />
                    Medication rounds — today
                </CardTitle>
                <CardDescription>
                    Guided walk-throughs group doses by time and site so nothing
                    gets missed.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                {rounds.length === 0 ? (
                    <p className="py-4 text-sm text-muted-foreground">
                        No medication rounds are set up for this day. Doses can
                        still be recorded straight from the schedule.
                    </p>
                ) : null}
                {rounds.map((r) => {
                    const isActive = r.status === 'in_progress';
                    const isDone = r.status === 'completed';
                    const verb = isActive ? 'Resume' : 'Start';
                    const roundDoses = schedule.filter(
                        (d) =>
                            d.round_label === bucketForTime(r.scheduled_time),
                    );
                    return (
                        <div
                            key={r.id}
                            className={`rounded-xl border p-4 ${
                                isActive
                                    ? 'border-status-success/40 bg-status-success-bg'
                                    : 'border-border'
                            }`}
                        >
                            <div className="flex flex-wrap items-center gap-3">
                                <div
                                    className={`grid h-10 w-10 shrink-0 place-items-center rounded-full ${
                                        isDone
                                            ? 'bg-status-success-bg text-status-success'
                                            : isActive
                                              ? 'bg-status-success text-white'
                                              : 'bg-muted text-muted-foreground'
                                    }`}
                                >
                                    {isDone ? (
                                        <CheckCircle2 className="h-5 w-5" />
                                    ) : (
                                        <Pill className="h-5 w-5" />
                                    )}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-bold">
                                            {r.name}
                                        </span>
                                        {isActive ? (
                                            <Badge
                                                variant="outline"
                                                className="border-status-success/40 text-[10px] tracking-wide text-status-success uppercase"
                                            >
                                                In progress
                                            </Badge>
                                        ) : null}
                                        {isDone ? (
                                            <Badge
                                                variant="outline"
                                                className="text-[10px] tracking-wide text-muted-foreground uppercase"
                                            >
                                                Complete
                                            </Badge>
                                        ) : null}
                                    </div>
                                    <div className="mt-0.5 text-xs text-muted-foreground">
                                        Scheduled {clockLabel(r.scheduled_time)}{' '}
                                        · {r.completed} of {r.total} done
                                    </div>
                                    <Progress
                                        value={r.percent}
                                        className="mt-2 h-1.5 max-w-sm"
                                    />
                                </div>
                                {!isDone ? (
                                    <Button
                                        size="sm"
                                        variant={
                                            isActive ? 'default' : 'outline'
                                        }
                                        asChild
                                    >
                                        <Link
                                            href={r.url}
                                            aria-label={`${verb} ${r.name}`}
                                        >
                                            {verb} round
                                            <ArrowRight className="h-3.5 w-3.5" />
                                        </Link>
                                    </Button>
                                ) : null}
                            </div>
                            {roundDoses.length > 0 ? (
                                <div className="mt-3 flex flex-wrap gap-1.5">
                                    {roundDoses.map((d) => (
                                        <span
                                            key={d.key}
                                            className="inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-2.5 py-1 text-[12px]"
                                        >
                                            <ClientAvatar
                                                name={d.client_name}
                                                clientId={d.client_id}
                                                className="h-4 w-4 text-[7px]"
                                            />
                                            {clientById.get(d.client_id)
                                                ?.preferred ??
                                                d.client_name.split(
                                                    ' ',
                                                )[0]}{' '}
                                            · {d.time}
                                            <StatusPill status={d.status} />
                                        </span>
                                    ))}
                                </div>
                            ) : null}
                        </div>
                    );
                })}
            </CardContent>
        </Card>
    );
}

function PrnTab({
    medications,
    followUps,
    clientById,
    onGive,
    onRecordEffect,
}: {
    medications: MedsTodayProps['prn_medications'];
    followUps: PrnFollowUp[];
    clientById: Map<number, ClientInfo>;
    onGive: (medId: number) => void;
    onRecordEffect: (followUp: PrnFollowUp) => void;
}) {
    return (
        <div className="space-y-4">
            <FollowUpsCard
                followUps={followUps}
                clientById={clientById}
                onRecordEffect={onRecordEffect}
            />
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Zap className="h-4 w-4 text-muted-foreground" />
                        As-needed (PRN) medications
                    </CardTitle>
                    <CardDescription>
                        Everything prescribed PRN for the clients on your shift
                        — with limits and last-given times.
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-2">
                    {medications.length === 0 ? (
                        <p className="py-4 text-sm text-muted-foreground">
                            No as-needed meds are set up for your clients.
                        </p>
                    ) : null}
                    {medications.map((m) => (
                        <div
                            key={m.id}
                            className="flex flex-wrap items-center gap-3 rounded-lg border border-border p-3.5"
                        >
                            <ClientAvatar
                                name={m.client_name}
                                clientId={m.client_id}
                                className="h-10 w-10 text-xs"
                            />
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                    {m.client_name} — {m.name} {m.dose ?? ''}
                                    {m.is_controlled ? <CdBadge /> : null}
                                </div>
                                <div className="mt-0.5 text-xs text-muted-foreground">
                                    {[
                                        m.prn_reason,
                                        m.max_per_day !== null
                                            ? `max ${m.max_per_day}×/24 h (${m.given_last_24h} given)`
                                            : null,
                                        m.min_hours_between
                                            ? `min ${m.min_hours_between} h apart`
                                            : null,
                                        m.last_given_label
                                            ? `last given ${m.last_given_label}`
                                            : 'not given before',
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </div>
                            </div>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => onGive(m.id)}
                                disabled={m.over_limit}
                                title={
                                    m.over_limit
                                        ? 'At the 24-hour limit — talk to your supervisor'
                                        : undefined
                                }
                            >
                                <Zap className="h-3.5 w-3.5" /> Give now
                            </Button>
                        </div>
                    ))}
                </CardContent>
            </Card>
        </div>
    );
}

function StockTab({
    alerts,
    canManage,
}: {
    alerts: StockAlert[];
    canManage: boolean;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <Package className="h-4 w-4 text-muted-foreground" />
                    Stock &amp; controlled-drug alerts
                </CardTitle>
                <CardDescription>
                    Items needing action soon. Full counts live in Stock
                    Management.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-2">
                {alerts.length === 0 ? (
                    <p className="py-4 text-sm text-muted-foreground">
                        No stock pressure right now — nothing low, expiring or
                        expired for your clients.
                    </p>
                ) : null}
                {alerts.map((a) => (
                    <StockAlertRow key={a.id} alert={a} canManage={canManage} />
                ))}
                {canManage ? (
                    <Link
                        href="/emar/stock"
                        className="mt-1 inline-flex items-center gap-1 self-start text-[12px] font-semibold text-primary hover:underline"
                    >
                        Open Stock Management
                        <ArrowRight className="h-3 w-3" />
                    </Link>
                ) : (
                    <p className="mt-1 text-[12px] text-muted-foreground">
                        Tell your coordinator or medication lead about anything
                        urgent here.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

function ActivityTab({ activity }: { activity: ActivityItem[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <History className="h-4 w-4 text-muted-foreground" />
                    Medication activity
                </CardTitle>
                <CardDescription>
                    Every recorded event for this day, newest first. The full
                    history lives in the Audit Trail.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ActivityList activity={activity} limit={50} />
            </CardContent>
        </Card>
    );
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function MedsToday(props: MedsTodayProps) {
    const {
        schedule,
        clients,
        sites,
        rounds,
        active_round,
        prn_medications,
        prn_follow_ups,
        stock_alerts,
        activity,
        witnesses,
        not_given_reasons,
        board_user,
        board_can,
        date,
        is_today,
    } = props;

    const [tab, setTab] = useState('schedule');
    const [search, setSearch] = useState('');
    const [siteFilter, setSiteFilter] = useState<number | null>(null);
    const [clientFilter, setClientFilter] = useState<number | null>(null);
    const [wizard, setWizard] = useState<WizardState>(null);
    const [ctxMenu, setCtxMenu] = useState<ShiftCtxState | null>(null);
    const [ctxRow, setCtxRow] = useState<ScheduleRow | null>(null);
    const [effectFollowUp, setEffectFollowUp] = useState<PrnFollowUp | null>(
        null,
    );
    const [recordedDetail, setRecordedDetail] = useState<ScheduleRow | null>(
        null,
    );

    const clientById = useMemo(
        () => new Map(clients.map((c) => [c.id, c])),
        [clients],
    );

    const overlayOpen =
        wizard !== null ||
        ctxMenu !== null ||
        effectFollowUp !== null ||
        recordedDetail !== null;
    const overlayOpenRef = useRef(false);
    useEffect(() => {
        overlayOpenRef.current = overlayOpen;
    }, [overlayOpen]);

    // Keep the live board honest: refresh props every 60s while visible and
    // no overlay is open (a reload mid-wizard would be rude).
    useEffect(() => {
        if (!is_today) return;
        const id = window.setInterval(() => {
            if (document.visibilityState !== 'visible') return;
            if (overlayOpenRef.current) return;
            router.reload({ preserveScroll: true });
        }, 60_000);
        return () => window.clearInterval(id);
    }, [is_today]);

    const filteredSchedule = useMemo(() => {
        const q = search.trim().toLowerCase();
        return schedule.filter((row) => {
            const client = clientById.get(row.client_id);
            if (siteFilter !== null && client?.site_id !== siteFilter)
                return false;
            if (clientFilter !== null && row.client_id !== clientFilter)
                return false;
            if (
                q &&
                !row.client_name.toLowerCase().includes(q) &&
                !row.medication_name.toLowerCase().includes(q)
            )
                return false;
            return true;
        });
    }, [schedule, search, siteFilter, clientFilter, clientById]);

    const board = useMemo(() => computeBoard(schedule), [schedule]);
    const cdDueCount = useMemo(
        () =>
            schedule.filter(
                (r) =>
                    r.is_controlled &&
                    (r.status === 'due' || r.status === 'overdue'),
            ).length,
        [schedule],
    );
    const prnTodayCount = useMemo(
        () => activity.filter((a) => a.icon === 'prn').length,
        [activity],
    );
    const overdueRows = useMemo(
        () => schedule.filter((r) => r.status === 'overdue'),
        [schedule],
    );

    const goDate = (ymd: string) =>
        router.get(
            '/meds/today',
            ymd === toLocalYmdToday() ? {} : { date: ymd },
            { preserveScroll: true },
        );

    function toLocalYmdToday(): string {
        const d = new Date();
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    }

    const copyNhi = (row: ScheduleRow) => {
        const nhi = clientById.get(row.client_id)?.nhi;
        if (!nhi) return;
        try {
            void navigator.clipboard.writeText(nhi);
            toast.success('NHI copied', {
                description: `${row.client_name} · ${nhi}`,
            });
        } catch {
            /* clipboard unavailable */
        }
    };

    const openDoseCtx = (e: ReactMouseEvent, row: ScheduleRow) => {
        e.preventDefault();
        const client = clientById.get(row.client_id);
        const meta = DOSE_STATUS_META[row.status] ?? DOSE_STATUS_META.upcoming;
        const actionable = row.recorded === null;
        const preferred = client?.preferred ?? row.client_name.split(' ')[0];
        const isMac =
            typeof navigator !== 'undefined' &&
            navigator.platform.toUpperCase().includes('MAC');

        const common: ShiftCtxItem[] = [
            {
                icon: <FileText className="h-3.5 w-3.5" />,
                label: 'View MAR chart',
                sub: `Full history · ${row.medication_name}`,
                onClick: () => router.visit(row.mar_url),
            },
            {
                icon: <User className="h-3.5 w-3.5" />,
                label: `View ${preferred}'s profile`,
                onClick: () =>
                    router.visit(
                        `/operations/clients/${row.client_id}?tab=mar`,
                    ),
            },
            ...(client?.nhi
                ? [
                      {
                          icon: <Copy className="h-3.5 w-3.5" />,
                          label: 'Copy NHI',
                          sub: client.nhi,
                          kbd: isMac ? '⌘C' : 'Ctrl C',
                          onClick: () => copyNhi(row),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
        ];

        const items: ShiftCtxItem[] = actionable
            ? [
                  {
                      icon: <Check className="h-3.5 w-3.5" />,
                      label: 'Record dose now',
                      sub: 'Safety checks → sign to MAR',
                      kbd: 'R',
                      tone: 'primary',
                      onClick: () => setWizard({ type: 'dose', row }),
                  },
                  {
                      icon: <Zap className="h-3.5 w-3.5" />,
                      label: 'Give as-needed med instead',
                      onClick: () => setWizard({ type: 'prn' }),
                  },
                  { sep: true },
                  ...common,
                  { sep: true },
                  {
                      icon: <Ban className="h-3.5 w-3.5" />,
                      label: 'Mark as withheld',
                      sub: 'Needs a reason — audited weekly',
                      tone: 'critical',
                      onClick: () =>
                          setWizard({
                              type: 'dose',
                              row,
                              initialOutcome: 'withheld',
                          }),
                  },
              ]
            : [
                  {
                      icon: <Eye className="h-3.5 w-3.5" />,
                      label: 'View record',
                      sub: `${row.recorded?.time ?? ''}${row.recorded?.by ? ` · ${row.recorded.by}` : ''}`,
                      tone: 'primary',
                      onClick: () => setRecordedDetail(row),
                  },
                  { sep: true },
                  ...common,
              ];

        setCtxRow(row);
        setCtxMenu({
            x: e.clientX,
            y: e.clientY,
            tag: meta.label,
            tagBg: meta.tagBg,
            tagColor: meta.tagColor,
            meta: `${row.client_name} · ${row.medication_name}${row.dose ? ` ${row.dose}` : ''}`,
            items,
        });
    };

    const closeCtx = () => {
        setCtxMenu(null);
        setCtxRow(null);
    };

    // The context menu's kbd hints are real shortcuts: R records the dose,
    // Ctrl/⌘+C copies the NHI — both only while the menu is open.
    useEffect(() => {
        if (!ctxMenu || !ctxRow) return;
        const handler = (event: KeyboardEvent) => {
            if (
                event.key.toLowerCase() === 'r' &&
                !event.ctrlKey &&
                !event.metaKey &&
                !event.altKey &&
                ctxRow.recorded === null
            ) {
                event.preventDefault();
                setWizard({ type: 'dose', row: ctxRow });
                closeCtx();
            } else if (
                event.key.toLowerCase() === 'c' &&
                (event.ctrlKey || event.metaKey)
            ) {
                event.preventDefault();
                copyNhi(ctxRow);
                closeCtx();
            }
        };
        document.addEventListener('keydown', handler);
        return () => document.removeEventListener('keydown', handler);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- copyNhi/closeCtx are stable per render; ctxMenu+ctxRow gate the listener.
    }, [ctxMenu, ctxRow]);

    /* ── Hero content ────────────────────────────────────────────── */

    const heroBadges: PageHeroBadge[] = [];
    if (board.overdue > 0)
        heroBadges.push({
            label: `${board.overdue} dose${board.overdue === 1 ? '' : 's'} overdue`,
            tone: 'critical',
            icon: AlertTriangle,
        });
    if (active_round)
        heroBadges.push({
            label:
                active_round.status === 'in_progress'
                    ? `${active_round.name} in progress · ${active_round.completed}/${active_round.total}`
                    : `${active_round.name} ready to start`,
            tone: 'success',
            icon: CheckCircle2,
        });
    if (cdDueCount > 0)
        heroBadges.push({
            label: `${cdDueCount} controlled drug${cdDueCount === 1 ? '' : 's'} due — witness needed`,
            tone: 'warning',
            icon: Shield,
        });
    if (stock_alerts.length > 0)
        heroBadges.push({
            label: `${stock_alerts.length} stock alert${stock_alerts.length === 1 ? '' : 's'}`,
            tone: 'default',
            icon: Package,
        });

    const heroStats: PageHeroStat[] = [
        { label: 'Recorded', value: `${board.recordedPct}%` },
        { label: 'Due now', value: board.dueNow },
        { label: 'Later', value: board.later },
        { label: 'PRN today', value: prnTodayCount },
    ];

    const heroMeta = [
        ...(props.shift_label
            ? [{ icon: Clock, label: `Your shift · ${props.shift_label}` }]
            : []),
        {
            icon: MapPin,
            label: `${sites.length} site${sites.length === 1 ? '' : 's'} · ${clients.length} client${clients.length === 1 ? '' : 's'}`,
        },
        ...(board_user.med_competent
            ? [
                  {
                      icon: Shield,
                      label: board_user.cd_witness
                          ? 'Med-competent · CD witness authorised'
                          : 'Med-competent',
                  },
              ]
            : []),
    ];

    const selectedDay = parseYmd(date);
    // en-NZ renders "Wednesday, 10 June" — the design underlines "Wednesday 10 June".
    const dayTitle = selectedDay
        .toLocaleDateString('en-NZ', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
        })
        .replace(',', '');
    const stepLabel = (ymd: string) =>
        parseYmd(ymd).toLocaleDateString('en-NZ', {
            weekday: 'short',
            day: 'numeric',
        });

    const heroFooter = (
        <div className="flex flex-col items-stretch gap-2 py-3 md:flex-row md:items-center md:justify-between">
            <div className="flex flex-wrap items-center gap-1.5">
                {/* eslint-disable no-restricted-syntax -- segmented day-stepper on the dark hero; not a shadcn Button (rostering idiom). */}
                <button
                    type="button"
                    className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/20"
                    onClick={() => goDate(addDays(date, -1))}
                >
                    <ChevronLeft className="h-3.5 w-3.5" />
                    {stepLabel(addDays(date, -1))}
                </button>
                <DayPickerChip date={date} isToday={is_today} onPick={goDate} />
                <button
                    type="button"
                    className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/20"
                    onClick={() => goDate(addDays(date, 1))}
                >
                    {stepLabel(addDays(date, 1))}
                    <ChevronRight className="h-3.5 w-3.5" />
                </button>
                {!is_today ? (
                    <button
                        type="button"
                        className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/35 bg-primary-foreground/20 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/30"
                        onClick={() => goDate(toLocalYmdToday())}
                    >
                        Back to today
                    </button>
                ) : null}
                {/* eslint-enable no-restricted-syntax */}
            </div>
            <div className="flex flex-wrap items-center gap-2 md:ml-auto md:justify-end">
                <div className="relative w-full max-w-xs md:w-[260px]">
                    <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    {/* eslint-disable-next-line no-restricted-syntax -- white pill search on the dark hero per the design handoff. */}
                    <input
                        value={search}
                        onChange={(e) => {
                            setSearch(e.target.value);
                            if (tab !== 'schedule') setTab('schedule');
                        }}
                        placeholder="Search client or medication…"
                        aria-label="Search this day's doses"
                        className="h-8 w-full rounded-full border-0 bg-primary-foreground pr-3 pl-9 text-[13px] text-foreground shadow-sm outline-none placeholder:text-muted-foreground/80 focus:ring-2 focus:ring-primary-foreground/50"
                    />
                    {search ? (
                        // eslint-disable-next-line no-restricted-syntax -- inline clear affordance inside the pill search input.
                        <button
                            type="button"
                            aria-label="Clear search"
                            onClick={() => setSearch('')}
                            className="absolute top-1/2 right-2 grid h-5 w-5 -translate-y-1/2 place-items-center rounded-full text-muted-foreground hover:bg-muted"
                        >
                            <X className="h-3.5 w-3.5" />
                        </button>
                    ) : null}
                </div>
                <EntityFilter
                    label="Site"
                    allLabel="All sites"
                    items={sites}
                    value={siteFilter}
                    onChange={setSiteFilter}
                    onDark
                />
                <EntityFilter
                    label="Client"
                    allLabel="All clients"
                    items={clients.map((c) => ({
                        id: c.id,
                        name: c.name,
                        description: c.site_name,
                    }))}
                    value={clientFilter}
                    onChange={setClientFilter}
                    onDark
                />
            </div>
        </div>
    );

    const tabItems: RosterTabItem[] = [
        {
            id: 'schedule',
            label: 'Schedule',
            icon: CalendarDays,
            tone: 'primary',
            badge: board.total,
        },
        {
            id: 'rounds',
            label: 'Rounds',
            icon: Pill,
            tone: 'success',
            badge: rounds.length,
        },
        {
            id: 'prn',
            label: 'PRN',
            icon: Zap,
            tone: 'warning',
            badge: prn_medications.length,
        },
        {
            id: 'stock',
            label: 'Stock alerts',
            icon: Package,
            tone: 'critical',
            badge: stock_alerts.length,
        },
        { id: 'activity', label: 'Activity', icon: History, tone: 'info' },
    ];

    const firstOverdue = overdueRows[0];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Medication' },
                { title: 'Meds today', href: '/meds/today' },
            ]}
        >
            <Head title="Meds today" />
            <div className="space-y-4 p-4 md:p-5">
                <PageHero
                    category="ops"
                    icon={Pill}
                    title={
                        <span>
                            <span className="mb-2 flex items-center justify-center gap-2 text-[10.5px] font-semibold tracking-wider text-primary-foreground/80 uppercase md:justify-start">
                                {is_today ? (
                                    <span
                                        aria-hidden="true"
                                        className="relative inline-flex h-2 w-2"
                                    >
                                        <span className="absolute inset-0 inline-flex h-full w-full animate-ping rounded-full bg-status-success/70" />
                                        <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success ring-2 ring-status-success/30" />
                                    </span>
                                ) : (
                                    <CalendarDays className="h-3 w-3" />
                                )}
                                {is_today
                                    ? `Live medication board · refreshed ${props.now_label}`
                                    : 'Medication board · day view'}
                            </span>
                            <span className="block">
                                <span className="font-normal text-primary-foreground/80">
                                    Kia ora {board_user.first_name},{' '}
                                    {is_today
                                        ? 'today’s meds at a glance —'
                                        : 'the meds board for —'}
                                </span>{' '}
                                <span className="border-b-2 border-primary-foreground/40 pb-0.5">
                                    {dayTitle}
                                </span>
                            </span>
                        </span>
                    }
                    description={
                        board.total > 0 ? (
                            <span>
                                {board.total} dose
                                {board.total === 1 ? '' : 's'} across{' '}
                                {sites.length} site
                                {sites.length === 1 ? '' : 's'}. {board.dueNow}{' '}
                                due now
                                {board.overdue > 0
                                    ? ` (${board.overdue} overdue)`
                                    : ''}
                                , {board.later} later
                                {active_round
                                    ? `, and the ${active_round.name.toLowerCase()} is waiting on you.`
                                    : '.'}
                            </span>
                        ) : (
                            <span>
                                {props.has_shift_context
                                    ? 'No scheduled doses for the clients on your shift this day.'
                                    : 'You don’t have a shift this day — once one is rostered, meds for those clients show here.'}
                            </span>
                        )
                    }
                    meta={heroMeta}
                    badges={heroBadges}
                    stats={heroStats}
                    actions={
                        <>
                            {active_round ? (
                                <Button
                                    size="sm"
                                    className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                                    asChild
                                >
                                    <Link
                                        href={active_round.url}
                                        aria-label={`${active_round.status === 'in_progress' ? 'Resume' : 'Start'} ${active_round.name}`}
                                    >
                                        <Pill className="h-4 w-4" />
                                        {active_round.status === 'in_progress'
                                            ? 'Resume'
                                            : 'Start'}{' '}
                                        {active_round.name.toLowerCase()}
                                    </Link>
                                </Button>
                            ) : null}
                            {board_can.view_emar ? (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="border-primary-foreground/30 bg-transparent text-primary-foreground hover:bg-primary-foreground/10"
                                    asChild
                                >
                                    <Link href={`/emar/mar?date=${date}`}>
                                        <Printer className="h-4 w-4" />
                                        Print this day&rsquo;s MAR
                                    </Link>
                                </Button>
                            ) : null}
                        </>
                    }
                    footer={heroFooter}
                />

                {firstOverdue ? (
                    <div className="flex items-start gap-3 rounded-lg border border-status-critical/30 bg-status-critical-bg p-3 text-sm">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-status-critical" />
                        <div className="min-w-0">
                            <p className="font-medium text-status-critical">
                                {board.overdue} dose
                                {board.overdue === 1 ? '' : 's'} past due —{' '}
                                {firstOverdue.client_name}&rsquo;s{' '}
                                {firstOverdue.medication_name} was scheduled for{' '}
                                {firstOverdue.time}.
                            </p>
                            <p className="mt-0.5 text-xs text-status-critical/90">
                                Give now if safe, or tell your supervisor.
                                Don&rsquo;t skip a dose without recording why.
                            </p>
                        </div>
                        <Button
                            size="sm"
                            className="ml-auto shrink-0"
                            onClick={() =>
                                setWizard({ type: 'dose', row: firstOverdue })
                            }
                        >
                            Record now
                        </Button>
                    </div>
                ) : null}

                <TabStrip
                    value={tab}
                    onChange={setTab}
                    items={tabItems}
                    ariaLabel="Medication board views"
                />

                {tab === 'schedule' ? (
                    <div className="grid grid-cols-1 items-start gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
                        <div className="space-y-4">
                            {active_round ? (
                                <ActiveRoundBanner round={active_round} />
                            ) : null}
                            <ScheduleCard
                                rows={filteredSchedule}
                                clientById={clientById}
                                search={search}
                                onSearchChange={setSearch}
                                onRecord={(row) =>
                                    setWizard({ type: 'dose', row })
                                }
                                onCtx={openDoseCtx}
                            />
                        </div>
                        <div className="space-y-4">
                            <PrnQuickCard
                                count={prn_medications.length}
                                onOpen={() => setWizard({ type: 'prn' })}
                            />
                            <FollowUpsCard
                                followUps={prn_follow_ups}
                                clientById={clientById}
                                onRecordEffect={setEffectFollowUp}
                            />
                            <RoundsCard rounds={rounds} />
                            <StockCard
                                alerts={stock_alerts}
                                canManage={board_can.view_emar}
                            />
                            <ActivityCard
                                activity={activity}
                                canViewAudit={board_can.view_audit}
                            />
                        </div>
                    </div>
                ) : null}

                {tab === 'rounds' ? (
                    <RoundsTab
                        rounds={rounds}
                        schedule={schedule}
                        clientById={clientById}
                    />
                ) : null}
                {tab === 'prn' ? (
                    <PrnTab
                        medications={prn_medications}
                        followUps={prn_follow_ups}
                        clientById={clientById}
                        onGive={(medId) => setWizard({ type: 'prn', medId })}
                        onRecordEffect={setEffectFollowUp}
                    />
                ) : null}
                {tab === 'stock' ? (
                    <StockTab
                        alerts={stock_alerts}
                        canManage={board_can.view_emar}
                    />
                ) : null}
                {tab === 'activity' ? (
                    <ActivityTab activity={activity} />
                ) : null}

                <footer className="mt-2 border-t border-border pt-4">
                    <div className="flex flex-wrap items-center justify-between gap-3 pb-1">
                        <nav className="flex flex-wrap items-center gap-x-4 gap-y-1.5">
                            <Link
                                href="/my-day"
                                className="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground transition-colors hover:text-primary"
                            >
                                <Home className="h-3 w-3" />
                                My Day
                            </Link>
                            {board_can.view_emar ? (
                                <>
                                    <Link
                                        href="/emar"
                                        className="text-xs font-medium text-muted-foreground transition-colors hover:text-primary"
                                    >
                                        eMAR Dashboard
                                    </Link>
                                    <Link
                                        href="/emar/mar"
                                        className="text-xs font-medium text-muted-foreground transition-colors hover:text-primary"
                                    >
                                        MAR Charts
                                    </Link>
                                    <Link
                                        href="/emar/prn"
                                        className="text-xs font-medium text-muted-foreground transition-colors hover:text-primary"
                                    >
                                        PRN Records
                                    </Link>
                                    <Link
                                        href="/emar/controlled"
                                        className="text-xs font-medium text-muted-foreground transition-colors hover:text-primary"
                                    >
                                        Controlled Drugs
                                    </Link>
                                    <Link
                                        href="/emar/stock"
                                        className="text-xs font-medium text-muted-foreground transition-colors hover:text-primary"
                                    >
                                        Stock Management
                                    </Link>
                                </>
                            ) : null}
                            {board_can.view_audit ? (
                                <Link
                                    href="/emar/audit"
                                    className="text-xs font-medium text-muted-foreground transition-colors hover:text-primary"
                                >
                                    Audit Trail
                                </Link>
                            ) : null}
                        </nav>
                        <div className="flex items-center gap-2 text-[11px] text-muted-foreground">
                            <span className="relative inline-flex h-1.5 w-1.5">
                                <span className="absolute inset-0 rounded-full bg-status-success" />
                            </span>
                            {is_today
                                ? `Live board · updated ${props.now_label} NZT`
                                : `Day view · ${props.date_label}`}
                        </div>
                    </div>
                </footer>
            </div>

            {/* ── Overlays ── */}
            {wizard?.type === 'dose' ? (
                <RecordDoseWizard
                    row={wizard.row}
                    client={clientById.get(wizard.row.client_id)}
                    date={date}
                    witnesses={witnesses}
                    notGivenReasons={not_given_reasons}
                    signedAs={board_user}
                    initialOutcome={wizard.initialOutcome ?? 'given'}
                    onClose={() => setWizard(null)}
                />
            ) : null}
            {wizard?.type === 'prn' ? (
                <PrnWizard
                    medications={prn_medications}
                    clients={clientById}
                    date={date}
                    witnesses={witnesses}
                    signedAs={board_user}
                    initialMedId={wizard.medId ?? null}
                    onClose={() => setWizard(null)}
                />
            ) : null}
            {effectFollowUp ? (
                <PrnEffectDialog
                    followUp={effectFollowUp}
                    client={clientById.get(effectFollowUp.client_id)}
                    onClose={() => setEffectFollowUp(null)}
                />
            ) : null}
            {recordedDetail ? (
                <RecordedDetailDialog
                    row={recordedDetail}
                    canViewMar={board_can.view_emar}
                    onClose={() => setRecordedDetail(null)}
                />
            ) : null}
            {ctxMenu ? (
                <ShiftContextMenu ctx={ctxMenu} onClose={closeCtx} />
            ) : null}
        </AppLayout>
    );
}
