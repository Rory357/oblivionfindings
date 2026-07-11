import { router } from '@inertiajs/react';
import { AlertTriangle, CalendarClock, Loader2, X } from 'lucide-react';
import { useState } from 'react';

import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { Card as GuardrailCard } from '@/components/ui/card';

/* ------------------------------------------------------------------ */
/*  Types — mirror App\Services\Operations\ShiftSeriesPresenter@detail */
/* ------------------------------------------------------------------ */

export type SeriesOccurrence = {
    id: number;
    starts_at?: string | null;
    ends_at?: string | null;
    status: string;
    user_id: number | null;
    staff?: { id: number; name: string; email?: string | null } | null;
    service_context?: { id: number; name: string; type?: string | null } | null;
    location?: string | null;
    tasks_total: number;
    tasks_completed: number;
    incidents_count: number;
    replacement?: {
        id: number;
        status: string;
        reason: string;
    } | null;
};

export type SeriesDetail = {
    id: number;
    series: {
        id: number;
        status: string;
        shift_type?: string | null;
        client?: { id: number; name: string } | null;
        staff?: { id: number; name: string; email?: string | null } | null;
        service_context?: { id: number; name: string } | null;
        location?: string | null;
        notes?: string | null;
        weekdays: string[];
        starts_time?: string | null;
        ends_time?: string | null;
        start_date?: string | null;
        end_date?: string | null;
        is_sleepover?: boolean;
        is_on_call?: boolean;
        expected_break_minutes?: number | null;
    };
    stats: {
        occurrences_total: number;
        remaining_occurrences: number;
        open_occurrences: number;
        completed_occurrences: number;
        cancelled_occurrences: number;
        active_replacements: number;
    };
    nextOccurrence?: SeriesOccurrence | null;
    upcomingOccurrences: SeriesOccurrence[];
    recentOccurrences: SeriesOccurrence[];
    coverageAlignment: {
        linked_rule_issues: Array<{
            rule_name: string;
            window_label: string;
            missing_staff: number;
            unfilled_after_open_shifts?: number;
        }>;
        orphan_series?: {
            site_id?: number | null;
            site_name: string;
        } | null;
    };
};

const DAY_LABELS: Record<string, string> = {
    mon: 'Mon',
    tue: 'Tue',
    wed: 'Wed',
    thu: 'Thu',
    fri: 'Fri',
    sat: 'Sat',
    sun: 'Sun',
};

function seriesTimeLabel(startsTime?: string | null, endsTime?: string | null) {
    if (!startsTime || !endsTime) return 'Time not set';
    const overnight = endsTime <= startsTime;
    return `${startsTime}–${endsTime}${overnight ? ' overnight' : ''}`;
}

function formatTimeRange(startsAt?: string | null, endsAt?: string | null) {
    if (!startsAt || !endsAt) return 'Time not set';
    const start = new Date(startsAt);
    const end = new Date(endsAt);
    return `${start.toLocaleDateString('en-NZ', {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
    })} · ${start.toLocaleTimeString('en-NZ', {
        hour: '2-digit',
        minute: '2-digit',
    })}-${end.toLocaleTimeString('en-NZ', {
        hour: '2-digit',
        minute: '2-digit',
    })}`;
}

function StatTile({ label, value }: { label: string; value: number }) {
    return (
        <GuardrailCard unstyled className="rounded-xl border border-border bg-card p-3 text-center">
            <div className="text-xl font-bold tabular-nums">{value}</div>
            <div className="mt-0.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                {label}
            </div>
        </GuardrailCard>
    );
}

/* ------------------------------------------------------------------ */
/*  Detail dialog                                                      */
/* ------------------------------------------------------------------ */

export type SeriesDetailDialogProps = {
    seriesId: number | null;
    detail: SeriesDetail | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    canManage: boolean;
};

export function SeriesDetailDialog({
    seriesId,
    detail,
    open,
    onOpenChange,
    canManage,
}: SeriesDetailDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-h-[92vh] overflow-hidden p-0 [&>button]:hidden"
                style={{
                    maxWidth: 'min(94vw, 960px)',
                    width: 'min(94vw, 960px)',
                }}
            >
                <DialogTitle className="sr-only">
                    {detail?.series.client?.name ?? 'Recurring series'}
                </DialogTitle>
                <DialogDescription className="sr-only">
                    Review the recurring pattern, its occurrences and coverage.
                </DialogDescription>
                {open ? (
                    detail && detail.id === seriesId ? (
                        <DetailBody
                            detail={detail}
                            seriesId={seriesId}
                            onOpenChange={onOpenChange}
                            canManage={canManage}
                        />
                    ) : (
                        <div className="grid h-72 place-items-center text-sm text-muted-foreground">
                            <span className="inline-flex items-center gap-2">
                                <Loader2 className="h-4 w-4 animate-spin" />
                                Loading series…
                            </span>
                        </div>
                    )
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function DetailBody({
    detail,
    seriesId,
    onOpenChange,
    canManage,
}: {
    detail: SeriesDetail;
    seriesId: number | null;
    onOpenChange: (open: boolean) => void;
    canManage: boolean;
}) {
    const { series, stats, upcomingOccurrences, recentOccurrences, coverageAlignment } =
        detail;
    const [cancelOpen, setCancelOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const refresh = () =>
        router.reload({
            only: ['rosterSeries', 'seriesDetail'],
            data: { tab: 'recurring', series: seriesId ?? undefined },
        });

    const cancelFuture = () => {
        setProcessing(true);
        router.patch(
            `/operations/shifts/series/${series.id}/cancel-future`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCancelOpen(false);
                    refresh();
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const cancelOccurrence = (id: number) =>
        router.patch(
            `/operations/shifts/${id}/cancel`,
            {},
            { preserveScroll: true, onSuccess: refresh },
        );

    const reopenOccurrence = (id: number) =>
        router.patch(
            `/operations/shifts/${id}/reopen`,
            {},
            { preserveScroll: true, onSuccess: refresh },
        );

    return (
        <div className="flex max-h-[92vh] min-h-0 flex-col">
            <header className="flex shrink-0 items-start justify-between gap-3 border-b border-border px-5 py-4">
                <div className="min-w-0">
                    <h2 className="truncate text-lg font-bold tracking-tight">
                        {series.client?.name ?? 'Recurring support series'}
                    </h2>
                    <div className="mt-1 flex flex-wrap items-center gap-1.5 text-[12px] text-muted-foreground">
                        <span
                            className={cn(
                                'rounded-full px-2 py-0.5 font-semibold capitalize',
                                series.status === 'cancelled'
                                    ? 'bg-status-critical-bg text-status-critical'
                                    : 'bg-status-success-bg text-status-success',
                            )}
                        >
                            {series.status}
                        </span>
                        <span className="font-medium text-foreground">
                            {series.weekdays
                                .map((w) => DAY_LABELS[w] ?? w)
                                .join(', ')}
                        </span>
                        <span aria-hidden>·</span>
                        <span>
                            {seriesTimeLabel(
                                series.starts_time,
                                series.ends_time,
                            )}
                        </span>
                        <span aria-hidden>·</span>
                        <span>
                            {series.start_date ?? '—'} → {series.end_date ?? '—'}
                        </span>
                    </div>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    {canManage && stats.remaining_occurrences > 0 ? (
                        <Button
                            variant="outline"
                            size="sm"
                            className="text-status-critical hover:text-status-critical"
                            onClick={() => setCancelOpen(true)}
                        >
                            Cancel future
                        </Button>
                    ) : null}
                    <Button unstyled
                        type="button"
                        onClick={() => onOpenChange(false)}
                        aria-label="Close"
                        className="grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-muted"
                    >
                        <X className="h-5 w-5" />
                    </Button>
                </div>
            </header>

            <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <StatTile label="Total" value={stats.occurrences_total} />
                    <StatTile
                        label="Remaining"
                        value={stats.remaining_occurrences}
                    />
                    <StatTile label="Open" value={stats.open_occurrences} />
                    <StatTile
                        label="Replacements"
                        value={stats.active_replacements}
                    />
                </div>

                {/* Pattern summary */}
                <div className="mt-4 grid gap-2 sm:grid-cols-2">
                    <SummaryCell
                        label="Service"
                        value={series.service_context?.name ?? 'Not set'}
                    />
                    <SummaryCell
                        label="Assigned staff"
                        value={series.staff?.name ?? 'Recurring open shift'}
                    />
                    <SummaryCell
                        label="Location"
                        value={series.location ?? 'Not set'}
                    />
                    <SummaryCell
                        label="Shift type"
                        value={(series.shift_type ?? 'standard').replace(
                            /_/g,
                            ' ',
                        )}
                    />
                </div>
                {series.notes ? (
                    <div className="mt-2 rounded-xl border border-border p-3">
                        <div className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                            Pattern notes
                        </div>
                        <p className="mt-1 whitespace-pre-wrap text-sm">
                            {series.notes}
                        </p>
                    </div>
                ) : null}

                {/* Coverage drift */}
                {coverageAlignment.linked_rule_issues.length > 0 ? (
                    <div className="mt-4 rounded-xl border border-status-critical/30 bg-status-critical-bg p-3">
                        <div className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-status-critical">
                            <AlertTriangle className="h-3.5 w-3.5" /> Coverage
                            drift
                        </div>
                        <ul className="mt-1.5 space-y-1 text-[13px] text-status-critical">
                            {coverageAlignment.linked_rule_issues
                                .slice(0, 3)
                                .map((issue, i) => (
                                    <li key={i}>
                                        {issue.rule_name} — {issue.window_label}:
                                        missing {issue.missing_staff}
                                    </li>
                                ))}
                        </ul>
                    </div>
                ) : null}
                {coverageAlignment.orphan_series ? (
                    <div className="mt-2 rounded-xl border border-status-warning/30 bg-status-warning-bg p-3 text-[13px] text-status-warning">
                        No active coverage rule matches this series at{' '}
                        {coverageAlignment.orphan_series.site_name}.
                    </div>
                ) : null}

                {/* Upcoming */}
                <h3 className="mt-5 mb-2 text-sm font-bold">
                    Upcoming occurrences
                </h3>
                {upcomingOccurrences.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-border p-4 text-sm text-muted-foreground">
                        No future occurrences scheduled.
                    </div>
                ) : (
                    <div className="space-y-2">
                        {upcomingOccurrences.map((occ) => (
                            <OccurrenceRow
                                key={occ.id}
                                occ={occ}
                                canManage={canManage}
                                onCancel={() => cancelOccurrence(occ.id)}
                            />
                        ))}
                    </div>
                )}

                {/* Recent */}
                {recentOccurrences.length > 0 ? (
                    <>
                        <h3 className="mt-5 mb-2 text-sm font-bold">
                            Recent history
                        </h3>
                        <div className="space-y-2">
                            {recentOccurrences.map((occ) => (
                                <OccurrenceRow
                                    key={occ.id}
                                    occ={occ}
                                    canManage={canManage}
                                    onReopen={
                                        occ.status === 'cancelled'
                                            ? () => reopenOccurrence(occ.id)
                                            : undefined
                                    }
                                />
                            ))}
                        </div>
                    </>
                ) : null}
            </div>

            <AlertDialog open={cancelOpen} onOpenChange={setCancelOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Cancel future occurrences?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            This cancels all {stats.remaining_occurrences}{' '}
                            remaining active occurrence
                            {stats.remaining_occurrences === 1 ? '' : 's'} in
                            this series and marks the series cancelled. Completed
                            shifts are untouched.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep series</AlertDialogCancel>
                        <AlertDialogAction
                            disabled={processing}
                            className="bg-status-critical text-white hover:bg-status-critical/90"
                            onClick={(e) => {
                                e.preventDefault();
                                cancelFuture();
                            }}
                        >
                            Cancel future occurrences
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
}

function SummaryCell({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-xl border border-border p-3">
            <div className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                {label}
            </div>
            <div className="mt-0.5 text-sm font-medium capitalize">{value}</div>
        </div>
    );
}

function OccurrenceRow({
    occ,
    canManage,
    onCancel,
    onReopen,
}: {
    occ: SeriesOccurrence;
    canManage: boolean;
    onCancel?: () => void;
    onReopen?: () => void;
}) {
    return (
        <div className="rounded-xl border border-border p-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <div className="inline-flex items-center gap-1.5 text-sm font-medium">
                        <CalendarClock className="h-3.5 w-3.5 text-muted-foreground" />
                        {formatTimeRange(occ.starts_at, occ.ends_at)}
                    </div>
                    <div className="mt-0.5 text-[13px] text-muted-foreground">
                        {occ.staff?.name ?? 'Unassigned'}
                        {occ.location ? ` · ${occ.location}` : ''}
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold capitalize text-muted-foreground">
                        {occ.status.replace(/_/g, ' ')}
                    </span>
                    {occ.incidents_count > 0 ? (
                        <span className="rounded-full bg-status-critical-bg px-2 py-0.5 text-[11px] font-semibold text-status-critical">
                            {occ.incidents_count} incident
                        </span>
                    ) : null}
                    {occ.replacement ? (
                        <span className="rounded-full bg-status-info-bg px-2 py-0.5 text-[11px] font-semibold text-status-info">
                            Replacement
                        </span>
                    ) : null}
                </div>
            </div>
            <div className="mt-2 flex flex-wrap gap-2">
                <a
                    href={`/operations/shifts/${occ.id}`}
                    className="inline-flex h-7 items-center rounded-md border border-border px-2.5 text-[12px] font-semibold text-foreground hover:bg-accent"
                >
                    Open shift
                </a>
                {canManage && onCancel ? (
                    <Button unstyled
                        type="button"
                        onClick={onCancel}
                        className="inline-flex h-7 items-center rounded-md border border-border px-2.5 text-[12px] font-semibold text-muted-foreground hover:bg-accent"
                    >
                        Cancel occurrence
                    </Button>
                ) : null}
                {canManage && onReopen ? (
                    <Button unstyled
                        type="button"
                        onClick={onReopen}
                        className="inline-flex h-7 items-center rounded-md border border-border px-2.5 text-[12px] font-semibold text-muted-foreground hover:bg-accent"
                    >
                        Reopen occurrence
                    </Button>
                ) : null}
            </div>
        </div>
    );
}

export default SeriesDetailDialog;
