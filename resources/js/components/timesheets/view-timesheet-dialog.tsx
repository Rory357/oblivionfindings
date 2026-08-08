/* eslint-disable no-restricted-syntax -- Read-only timesheet detail surface with
 * a bespoke audit-trail timeline and hue-coded person avatars (white initials on
 * computed avatar hues, white glyphs on solid status dots — the established
 * avatar/timeline idiom). All status colours are semantic tokens, per
 * docs/DESIGN_TOKENS.md. */
import { ReasonDialog } from '@/components/reason-dialog';
import { TimesheetStatusBadge } from '@/components/timesheet-status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDateTime, formatTime, WORKER_TIMEZONE } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    Banknote,
    Briefcase,
    CalendarDays,
    Car,
    CheckCircle2,
    ClipboardList,
    Clock,
    Coffee,
    FileText,
    Link2,
    MapPin,
    MessageSquareWarning,
    Pencil,
    RotateCcw,
    Send,
    User,
    Users,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

export type ViewTimesheetRow = {
    id: number;
    work_date: string;
    starts_at: string;
    ends_at: string;
    break_minutes: number;
    mileage_km?: number | null;
    sleepover?: boolean;
    on_call?: boolean;
    public_holiday?: boolean;
    status: string;
    activity_type?: string | null;
    activity_items?: string[] | null;
    returned_notes?: string | null;
    notes?: string | null;
    submitted_at?: string | null;
    approved_at?: string | null;
    archived_at?: string | null;
    archived_reason?: string | null;
    exported_to_payroll_at?: string | null;
    payroll_reference?: string | null;
    total_hours?: number;
    hours?: number;
    tasks_total?: number;
    tasks_completed?: number;
    client?: { id: number; first_name: string; last_name: string } | null;
    staff?: { id: number; name: string } | null;
    shift?: {
        id: number;
        shift_type?: string | null;
        location?: string | null;
        service_context?: { id: number; name: string } | string | null;
    } | null;
    site?: { id: number; name: string } | null;
    client_allocations?: Array<{
        client_id: number;
        client_name?: string;
        hours?: number;
        minutes?: number;
        allocation_method?: string;
    }>;
};

function initials(name: string) {
    return name
        .split(' ')
        .map((w) => w[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
}
function hueFor(name: string) {
    let h = 0;
    for (let i = 0; i < name.length; i++)
        h = (h * 31 + name.charCodeAt(i)) % 360;
    return h;
}

function fmtTime(iso: string) {
    if (!iso) return '';
    return formatTime(iso);
}
// Payroll context keeps the year; pinned to the worker timezone so the date
// can't drift a day on non-NZ machines.
function fmtDate(iso: string) {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString('en-NZ', {
        timeZone: WORKER_TIMEZONE,
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function SectionTitle({
    icon: Icon,
    children,
}: {
    icon: React.ComponentType<{ className?: string }>;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-center gap-2 text-[11.5px] font-semibold tracking-wider text-muted-foreground uppercase">
            <Icon className="h-3.5 w-3.5" />
            {children}
        </div>
    );
}

function KV({
    label,
    value,
    sub,
    icon: Icon,
}: {
    label: string;
    value: string;
    sub?: string;
    icon?: React.ComponentType<{ className?: string }>;
}) {
    return (
        <div className="rounded-lg border border-border bg-muted/30 px-2.5 py-2">
            <div className="inline-flex items-center gap-1.5 text-[10.5px] font-semibold tracking-wider text-muted-foreground uppercase">
                {Icon ? <Icon className="h-3 w-3" /> : null}
                {label}
            </div>
            <div className="mt-1 text-sm font-semibold tabular-nums">
                {value}
            </div>
            {sub ? (
                <div className="text-[11px] text-muted-foreground">{sub}</div>
            ) : null}
        </div>
    );
}

function Person({
    label,
    name,
    role,
}: {
    label: string;
    name: string;
    role: string;
}) {
    return (
        <div className="mt-2 flex items-center gap-2.5">
            <div
                className="grid h-9 w-9 shrink-0 place-items-center rounded-full text-[12px] font-semibold text-white"
                style={{ background: `oklch(0.55 0.14 ${hueFor(name)})` }}
            >
                {initials(name)}
            </div>
            <div className="min-w-0">
                <div className="text-[10.5px] tracking-wider text-muted-foreground uppercase">
                    {label}
                </div>
                <div className="truncate text-[13px] font-semibold">{name}</div>
                <div className="truncate text-[11.5px] text-muted-foreground">
                    {role}
                </div>
            </div>
        </div>
    );
}

export default function ViewTimesheetDialog({
    open,
    timesheet,
    onOpenChange,
    canApprove = false,
}: {
    open: boolean;
    timesheet: ViewTimesheetRow | null;
    onOpenChange: (open: boolean) => void;
    canApprove?: boolean;
}) {
    const [busy, setBusy] = useState(false);
    const [reasonAction, setReasonAction] = useState<
        'reject' | 'return' | null
    >(null);

    if (!timesheet) return null;
    const t = timesheet;
    const hours = (t.total_hours ?? t.hours ?? 0) as number;
    const tagPills: string[] = [];
    if (t.sleepover) tagPills.push('Sleepover');
    if (t.on_call) tagPills.push('On-call');
    if (t.public_holiday) tagPills.push('Public holiday');

    const allocations = t.client_allocations ?? [];
    const totalMinutes = allocations.reduce(
        (s, a) => s + (a.minutes ?? (a.hours ? a.hours * 60 : 0)),
        0,
    );

    function call(method: 'post', url: string, data: Record<string, any> = {}) {
        setBusy(true);
        router[method](url, data, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
            onSuccess: () => onOpenChange(false),
        });
    }

    const shiftCtxName =
        typeof t.shift?.service_context === 'string'
            ? t.shift?.service_context
            : (t.shift?.service_context?.name ?? 'Care');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="flex max-h-[92vh] flex-col gap-0 overflow-hidden p-0"
                style={{
                    maxWidth: 'min(94vw, 920px)',
                    width: 'min(94vw, 920px)',
                }}
            >
                {/* Read-only detail header — icon tile + title + status, per
                    docs/POPUP_STYLE_GUIDE.md. */}
                <DialogHeader className="shrink-0 space-y-0 border-b border-border px-5 py-4">
                    <div className="flex items-start gap-3">
                        <div className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-primary/10">
                            <FileText className="h-5 w-5 text-primary" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <DialogTitle className="inline-flex items-center gap-2 text-lg font-semibold tracking-tight">
                                Timesheet #{t.id}
                                <TimesheetStatusBadge status={t.status} />
                            </DialogTitle>
                            <DialogDescription className="mt-0.5 text-[12.5px] text-muted-foreground">
                                {t.staff?.name ?? 'Staff'} →{' '}
                                {t.client
                                    ? `${t.client.first_name} ${t.client.last_name}`
                                    : (t.activity_type ?? 'Activity')}{' '}
                                · {fmtDate(t.work_date)} · {hours.toFixed(2)}h
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <div className="min-h-0 flex-1 overflow-y-auto bg-muted/30">
                    {t.status === 'returned' && t.returned_notes ? (
                        <div className="border-b border-status-critical/30 bg-status-critical-bg px-5 py-3">
                            <div className="flex items-start gap-2 text-status-critical">
                                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                <div className="min-w-0">
                                    <div className="text-[12.5px] font-semibold">
                                        Returned to {t.staff?.name ?? 'staff'}{' '}
                                        for changes
                                    </div>
                                    <div className="mt-0.5 text-[12px]">
                                        {t.returned_notes}
                                    </div>
                                </div>
                            </div>
                        </div>
                    ) : null}

                    <div className="grid gap-4 p-5 md:grid-cols-[1fr_320px]">
                        <div className="min-w-0 space-y-4">
                            <section className="rounded-xl border border-border bg-card p-4">
                                <SectionTitle icon={Clock}>
                                    Schedule &amp; hours
                                </SectionTitle>
                                <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                    <KV
                                        label="Start"
                                        value={fmtTime(t.starts_at)}
                                        sub={fmtDate(t.starts_at)}
                                    />
                                    <KV
                                        label="End"
                                        value={fmtTime(t.ends_at)}
                                        sub={fmtDate(t.ends_at)}
                                    />
                                    <KV
                                        label="Break"
                                        value={`${t.break_minutes}m`}
                                        icon={Coffee}
                                    />
                                    <KV
                                        label="Mileage"
                                        value={
                                            (t.mileage_km ?? 0) > 0
                                                ? `${t.mileage_km} km`
                                                : '—'
                                        }
                                        icon={Car}
                                    />
                                </div>
                                <div className="mt-3 flex items-center justify-between rounded-lg bg-status-info-bg px-3 py-2.5 text-[12.5px]">
                                    <span className="font-medium">
                                        Billable hours
                                    </span>
                                    <span className="text-base font-bold text-primary tabular-nums">
                                        {hours.toFixed(2)}h
                                    </span>
                                </div>
                                {tagPills.length > 0 ? (
                                    <div className="mt-3 flex flex-wrap gap-1.5">
                                        {tagPills.map((p) => (
                                            <span
                                                key={p}
                                                className="rounded-full border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-[10.5px] font-semibold text-indigo-700 dark:border-indigo-900/40 dark:bg-indigo-950/30 dark:text-indigo-200"
                                            >
                                                {p}
                                            </span>
                                        ))}
                                    </div>
                                ) : null}
                            </section>

                            {/* Activity items (manual mode) or tasks (shift mode) */}
                            {Array.isArray(t.activity_items) &&
                            t.activity_items.length > 0 ? (
                                <section className="rounded-xl border border-border bg-card p-4">
                                    <div className="flex items-center justify-between">
                                        <SectionTitle icon={ClipboardList}>
                                            Activity items
                                        </SectionTitle>
                                        <span className="text-[11.5px] text-muted-foreground tabular-nums">
                                            {t.activity_items.length} item
                                            {t.activity_items.length === 1
                                                ? ''
                                                : 's'}
                                        </span>
                                    </div>
                                    <ul className="mt-3 space-y-1.5">
                                        {t.activity_items.map((it, idx) => (
                                            <li
                                                key={idx}
                                                className="flex items-center gap-2 rounded-lg border border-border bg-card p-2"
                                            >
                                                <span className="grid h-5 w-5 place-items-center rounded-full bg-primary text-[10px] font-semibold text-primary-foreground">
                                                    {idx + 1}
                                                </span>
                                                <span className="text-[12.5px]">
                                                    {it}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            ) : t.shift ? (
                                <section className="rounded-xl border border-border bg-card p-4">
                                    <div className="flex items-center justify-between">
                                        <SectionTitle icon={ClipboardList}>
                                            Tasks pulled from shift
                                        </SectionTitle>
                                        <span className="text-[11.5px] text-muted-foreground tabular-nums">
                                            {t.tasks_completed ?? 0} /{' '}
                                            {t.tasks_total ?? 0} completed
                                        </span>
                                    </div>
                                    {(t.tasks_total ?? 0) === 0 ? (
                                        <div className="mt-3 rounded-lg border border-dashed border-border px-3 py-3 text-center text-[12px] text-muted-foreground">
                                            No tasks were attached to the linked
                                            shift.
                                        </div>
                                    ) : (
                                        <div className="mt-3 flex items-center gap-1.5">
                                            <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className={cn(
                                                        'h-full rounded-full',
                                                        (t.tasks_completed ??
                                                            0) ===
                                                            (t.tasks_total ?? 0)
                                                            ? 'bg-status-success'
                                                            : 'bg-primary',
                                                    )}
                                                    style={{
                                                        width:
                                                            ((t.tasks_completed ??
                                                                0) /
                                                                Math.max(
                                                                    t.tasks_total ??
                                                                        1,
                                                                    1,
                                                                )) *
                                                                100 +
                                                            '%',
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    )}
                                </section>
                            ) : null}

                            {allocations.length > 0 ? (
                                <section className="rounded-xl border border-border bg-card p-4">
                                    <SectionTitle icon={Users}>
                                        Client allocation
                                    </SectionTitle>
                                    <div className="mt-3 space-y-2">
                                        {allocations.map((a) => {
                                            const aHours =
                                                a.hours ??
                                                (a.minutes
                                                    ? a.minutes / 60
                                                    : 0);
                                            const aMinutes =
                                                a.minutes ?? aHours * 60;
                                            const pct =
                                                totalMinutes > 0
                                                    ? Math.round(
                                                          (aMinutes /
                                                              totalMinutes) *
                                                              100,
                                                      )
                                                    : 100;
                                            return (
                                                <div key={a.client_id}>
                                                    <div className="flex items-center justify-between text-[12.5px]">
                                                        <span className="truncate font-medium">
                                                            {a.client_name ??
                                                                `Client #${a.client_id}`}
                                                        </span>
                                                        <span className="text-muted-foreground tabular-nums">
                                                            {aHours.toFixed(2)}h
                                                            · {pct}%
                                                        </span>
                                                    </div>
                                                    <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                                                        <div
                                                            className="h-full rounded-full bg-primary"
                                                            style={{
                                                                width:
                                                                    pct + '%',
                                                            }}
                                                        />
                                                    </div>
                                                    {a.allocation_method ? (
                                                        <div className="mt-0.5 text-[10.5px] tracking-wider text-muted-foreground uppercase">
                                                            {
                                                                a.allocation_method
                                                            }
                                                        </div>
                                                    ) : null}
                                                </div>
                                            );
                                        })}
                                    </div>
                                </section>
                            ) : null}

                            <section className="rounded-xl border border-border bg-card p-4">
                                <SectionTitle icon={Pencil}>
                                    Staff notes
                                </SectionTitle>
                                {t.notes ? (
                                    <div className="mt-3 rounded-lg border border-status-warning/30 bg-status-warning-bg px-3 py-2.5 text-[13px] leading-relaxed">
                                        <p className="whitespace-pre-line">
                                            {t.notes}
                                        </p>
                                        <div className="mt-2 text-[10.5px] tracking-wider text-muted-foreground uppercase">
                                            Logged by {t.staff?.name ?? 'staff'}{' '}
                                            on {fmtDate(t.work_date)}
                                        </div>
                                    </div>
                                ) : (
                                    <div className="mt-3 rounded-lg border border-dashed border-border px-3 py-3 text-center text-[12px] text-muted-foreground">
                                        No notes left by{' '}
                                        {t.staff?.name ?? 'staff'} for this
                                        timesheet.
                                    </div>
                                )}
                            </section>
                        </div>

                        <aside className="space-y-4">
                            <section className="rounded-xl border border-border bg-card p-4">
                                <SectionTitle icon={User}>People</SectionTitle>
                                <Person
                                    label="Staff"
                                    name={t.staff?.name ?? 'Unknown'}
                                    role="Support worker"
                                />
                                {t.client ? (
                                    <Person
                                        label="Client"
                                        name={`${t.client.first_name} ${t.client.last_name}`}
                                        role={shiftCtxName}
                                    />
                                ) : t.activity_type ? (
                                    <Person
                                        label="Activity"
                                        name={t.activity_type}
                                        role="Manual entry"
                                    />
                                ) : null}
                            </section>

                            {t.shift ? (
                                <section className="rounded-xl border border-border bg-card p-4">
                                    <SectionTitle icon={Briefcase}>
                                        Linked shift
                                    </SectionTitle>
                                    <div className="mt-2 rounded-lg border border-primary/30 bg-status-info-bg p-3 text-[12.5px]">
                                        <div className="font-semibold">
                                            Shift #{t.shift.id}
                                        </div>
                                        {t.shift.location ? (
                                            <div className="mt-0.5 inline-flex items-center gap-1 text-[11.5px] text-muted-foreground">
                                                <MapPin className="h-3 w-3" />
                                                {t.shift.location}
                                            </div>
                                        ) : null}
                                        <div className="mt-0.5 text-[11.5px] text-muted-foreground capitalize">
                                            {(
                                                t.shift.shift_type ?? 'standard'
                                            ).replace('_', ' ')}{' '}
                                            · {shiftCtxName}
                                        </div>
                                        <button
                                            onClick={() =>
                                                router.visit(
                                                    `/operations/shifts/${t.shift!.id}`,
                                                )
                                            }
                                            className="mt-2 inline-flex items-center gap-1.5 rounded-md bg-background px-2 py-1 text-[11.5px] font-medium text-primary hover:bg-background/80"
                                        >
                                            <Link2 className="h-3 w-3" /> Open
                                            shift
                                        </button>
                                    </div>
                                </section>
                            ) : null}

                            {t.exported_to_payroll_at || t.payroll_reference ? (
                                <section className="rounded-xl border border-border bg-card p-4">
                                    <SectionTitle icon={Banknote}>
                                        Payroll
                                    </SectionTitle>
                                    <div className="mt-3 space-y-2">
                                        {t.exported_to_payroll_at ? (
                                            <div className="flex items-center justify-between rounded-lg border border-status-success/30 bg-status-success-bg px-3 py-2 text-[12.5px]">
                                                <span className="font-medium text-status-success">
                                                    Exported
                                                </span>
                                                <span className="text-status-success tabular-nums">
                                                    {fmtDate(
                                                        t.exported_to_payroll_at,
                                                    )}
                                                </span>
                                            </div>
                                        ) : null}
                                        {t.payroll_reference ? (
                                            <div className="rounded-lg border border-border bg-muted/30 px-3 py-2">
                                                <div className="text-[10.5px] tracking-wider text-muted-foreground uppercase">
                                                    Reference
                                                </div>
                                                <div className="mt-0.5 text-[12px] font-medium break-all">
                                                    {t.payroll_reference}
                                                </div>
                                            </div>
                                        ) : null}
                                    </div>
                                </section>
                            ) : null}

                            <section className="rounded-xl border border-border bg-card p-4">
                                <SectionTitle icon={CalendarDays}>
                                    Audit trail
                                </SectionTitle>
                                <ol className="mt-3 space-y-3 border-l-2 border-border pl-4">
                                    <li className="relative">
                                        <span className="absolute -left-[22px] grid h-4 w-4 place-items-center rounded-full bg-muted-foreground text-white shadow">
                                            <Pencil className="h-2.5 w-2.5" />
                                        </span>
                                        <div className="text-[12.5px] font-semibold">
                                            Timesheet drafted
                                        </div>
                                        <div className="text-[11px] text-muted-foreground">
                                            {t.staff?.name ?? 'Staff'} ·{' '}
                                            {fmtDate(t.work_date)}
                                        </div>
                                    </li>
                                    {t.submitted_at ? (
                                        <li className="relative">
                                            <span className="absolute -left-[22px] grid h-4 w-4 place-items-center rounded-full bg-status-warning text-white shadow">
                                                <Send className="h-2.5 w-2.5" />
                                            </span>
                                            <div className="text-[12.5px] font-semibold">
                                                Submitted for approval
                                            </div>
                                            <div className="text-[11px] text-muted-foreground">
                                                {t.staff?.name ?? 'Staff'} ·{' '}
                                                {formatDateTime(t.submitted_at)}
                                            </div>
                                        </li>
                                    ) : null}
                                    {t.status === 'returned' ? (
                                        <li className="relative">
                                            <span className="absolute -left-[22px] grid h-4 w-4 place-items-center rounded-full bg-status-critical text-white shadow">
                                                <RotateCcw className="h-2.5 w-2.5" />
                                            </span>
                                            <div className="text-[12.5px] font-semibold">
                                                Returned for changes
                                            </div>
                                            {t.returned_notes ? (
                                                <div className="mt-1 rounded-md bg-status-critical-bg px-2 py-1 text-[11.5px] text-status-critical">
                                                    {t.returned_notes}
                                                </div>
                                            ) : null}
                                        </li>
                                    ) : null}
                                    {t.approved_at ? (
                                        <li className="relative">
                                            <span className="absolute -left-[22px] grid h-4 w-4 place-items-center rounded-full bg-status-success text-white shadow">
                                                <CheckCircle2 className="h-2.5 w-2.5" />
                                            </span>
                                            <div className="text-[12.5px] font-semibold">
                                                Approved
                                            </div>
                                            <div className="text-[11px] text-muted-foreground">
                                                {formatDateTime(t.approved_at)}
                                            </div>
                                        </li>
                                    ) : null}
                                    {t.archived_at ? (
                                        <li className="relative">
                                            <span className="absolute -left-[22px] grid h-4 w-4 place-items-center rounded-full bg-muted-foreground text-white shadow">
                                                <MessageSquareWarning className="h-2.5 w-2.5" />
                                            </span>
                                            <div className="text-[12.5px] font-semibold">
                                                Archived
                                            </div>
                                            <div className="text-[11px] text-muted-foreground">
                                                {t.archived_reason ??
                                                    'Archived from row menu'}
                                            </div>
                                        </li>
                                    ) : null}
                                </ol>
                            </section>
                        </aside>
                    </div>
                </div>

                <footer className="shrink-0 border-t border-border bg-background p-3.5">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                className="gap-1.5"
                                onClick={() => {
                                    const url = `${window.location.origin}/operations/timesheets?view=${t.id}`;
                                    navigator.clipboard?.writeText(url);
                                }}
                            >
                                <Link2 className="h-3.5 w-3.5" /> Copy link
                            </Button>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {canApprove && t.status === 'submitted' ? (
                                <>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="gap-1.5 border-status-critical/30 text-status-critical hover:bg-status-critical-bg"
                                        onClick={() =>
                                            setReasonAction('reject')
                                        }
                                        disabled={busy}
                                    >
                                        <XCircle className="h-4 w-4" /> Reject
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="gap-1.5 border-status-warning/30 text-status-warning hover:bg-status-warning-bg"
                                        onClick={() =>
                                            setReasonAction('return')
                                        }
                                        disabled={busy}
                                    >
                                        <RotateCcw className="h-4 w-4" /> Return
                                        for changes
                                    </Button>
                                    <Button
                                        size="sm"
                                        className="gap-1.5 bg-status-success text-white hover:bg-status-success/90"
                                        onClick={() =>
                                            call(
                                                'post',
                                                `/operations/timesheets/${t.id}/approve`,
                                                {},
                                            )
                                        }
                                        disabled={busy}
                                    >
                                        <CheckCircle2 className="h-4 w-4" />{' '}
                                        Approve
                                    </Button>
                                </>
                            ) : null}
                            {t.status === 'draft' ? (
                                <Button
                                    size="sm"
                                    className="gap-1.5"
                                    onClick={() =>
                                        call(
                                            'post',
                                            `/operations/timesheets/${t.id}/submit`,
                                            {},
                                        )
                                    }
                                    disabled={busy}
                                >
                                    <Send className="h-4 w-4" /> Submit for
                                    approval
                                </Button>
                            ) : null}
                            <Button
                                size="sm"
                                onClick={() => onOpenChange(false)}
                            >
                                Close
                            </Button>
                        </div>
                    </div>
                </footer>
            </DialogContent>

            <ReasonDialog
                open={reasonAction !== null}
                onClose={() => setReasonAction(null)}
                title={
                    reasonAction === 'reject'
                        ? 'Reject timesheet?'
                        : 'Return for changes?'
                }
                description={
                    reasonAction === 'reject'
                        ? 'The staff member will see this timesheet as rejected, with your reason.'
                        : 'The timesheet goes back to the staff member to fix and resubmit.'
                }
                label={
                    reasonAction === 'reject'
                        ? 'Reason for rejection'
                        : 'What needs changing?'
                }
                confirmLabel={
                    reasonAction === 'reject'
                        ? 'Reject timesheet'
                        : 'Return to staff'
                }
                destructive={reasonAction === 'reject'}
                onConfirm={(reason) => {
                    if (reasonAction === 'reject') {
                        call('post', `/operations/timesheets/${t.id}/reject`, {
                            decision_notes: reason,
                        });
                    } else {
                        call('post', `/operations/timesheets/${t.id}/return`, {
                            returned_notes: reason,
                        });
                    }
                    setReasonAction(null);
                }}
            />
        </Dialog>
    );
}
