import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import { MicroStats, type MicroStat } from './micro-stats';

export type OpenShiftCard = {
    id: number;
    day: string;
    start: string;
    end: string;
    hours: number;
    client: string;
    site: string | null;
    reason: string | null;
    eligible: number;
    warnings: number;
    blocked?: string[];
    suggestions: Array<{
        id: number;
        name: string;
        hours?: number | null;
        meta?: string | null;
    }>;
    href?: string;
};

export type EligibilityAlertItem = {
    id: number;
    starts_at: string;
    staff: string;
    site: string;
    reason: string;
};

export type ReplacementRequestCard = {
    id: number;
    shift_id: number;
    status?: string | null;
    reason?: string | null;
    requested_at?: string | null;
    starts_at?: string | null;
    ends_at?: string | null;
    client?: string | null;
    location?: string | null;
    current_staff?: string | null;
    requested_by?: string | null;
    replacement_staff?: string | null;
};

export type OpenShiftsPaneProps = {
    stats: MicroStat[];
    shifts: OpenShiftCard[];
    canManage: boolean;
    replacementRequests?: ReplacementRequestCard[];
    eligibilityAlerts?: {
        blocked?: EligibilityAlertItem[];
        warnings?: EligibilityAlertItem[];
    };
    onAssign: (shift: OpenShiftCard, candidateUserId: number | string) => void;
    onFindReplacement?: (request: ReplacementRequestCard) => void;
    actionEndSlot?: ReactNode;
};

export function OpenShiftsPane({
    stats,
    shifts,
    canManage,
    replacementRequests = [],
    eligibilityAlerts,
    onAssign,
    onFindReplacement,
    actionEndSlot,
}: OpenShiftsPaneProps) {
    const blockedAlerts = eligibilityAlerts?.blocked ?? [];
    const warningAlerts = eligibilityAlerts?.warnings ?? [];
    const hasEligibilityAlerts =
        blockedAlerts.length > 0 || warningAlerts.length > 0;

    return (
        <div className="space-y-4">
            <MicroStats stats={stats} />
            {hasEligibilityAlerts ? (
                <section className="rounded-[14px] border border-border bg-card p-4 shadow-sm">
                    <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h3 className="text-sm font-bold tracking-tight">
                                Eligibility watchlist
                            </h3>
                            <div className="text-[11px] text-muted-foreground">
                                Shift-level blockers and warnings already
                                returned by the eligibility service
                            </div>
                        </div>
                        <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground tabular-nums">
                            {blockedAlerts.length + warningAlerts.length}
                        </span>
                    </div>
                    <div className="grid gap-2 md:grid-cols-2">
                        {blockedAlerts.map((alert) => (
                            <EligibilityAlertRow
                                key={`blocked-${alert.id}`}
                                alert={alert}
                                tone="blocked"
                            />
                        ))}
                        {warningAlerts.map((alert) => (
                            <EligibilityAlertRow
                                key={`warning-${alert.id}`}
                                alert={alert}
                                tone="warning"
                            />
                        ))}
                    </div>
                </section>
            ) : null}
            {replacementRequests.length > 0 ? (
                <section className="rounded-[14px] border border-status-warning/35 bg-status-warning-bg/40 p-4">
                    <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h3 className="text-sm font-bold tracking-tight">
                                Replacement requests
                            </h3>
                            <div className="text-[11px] text-muted-foreground">
                                Awaiting cover for assigned shifts
                            </div>
                        </div>
                        <span className="rounded-full bg-background/70 px-2 py-0.5 text-[11px] font-semibold text-muted-foreground tabular-nums">
                            {replacementRequests.length}
                        </span>
                    </div>
                    <div className="space-y-2">
                        {replacementRequests.map((req) => (
                            <article
                                key={req.id}
                                className="grid items-center gap-3 rounded-md border border-border bg-card p-3 md:grid-cols-[140px_1fr_auto]"
                            >
                                <div>
                                    <div className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        {req.starts_at
                                            ? fmtDay(req.starts_at)
                                            : 'Shift'}
                                    </div>
                                    <div className="text-sm font-bold tabular-nums">
                                        {fmtTimeRange(
                                            req.starts_at,
                                            req.ends_at,
                                        )}
                                    </div>
                                </div>
                                <div className="min-w-0">
                                    <div className="truncate text-sm font-semibold">
                                        {req.requested_by ??
                                            'Replacement requested'}
                                    </div>
                                    <div className="truncate text-xs text-muted-foreground">
                                        {req.current_staff
                                            ? `Current staff: ${req.current_staff}`
                                            : 'Current staff not set'}
                                    </div>
                                    <div className="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] text-muted-foreground">
                                        {req.client ? (
                                            <span className="rounded bg-muted px-1.5 py-0.5">
                                                {req.client}
                                            </span>
                                        ) : null}
                                        {req.location ? (
                                            <span className="rounded bg-muted px-1.5 py-0.5">
                                                {req.location}
                                            </span>
                                        ) : null}
                                        {req.reason ? (
                                            <span className="rounded bg-status-warning-bg px-1.5 py-0.5 text-status-warning">
                                                {req.reason}
                                            </span>
                                        ) : null}
                                    </div>
                                </div>
                                <div className="flex items-center justify-end gap-2">
                                    {canManage ? (
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                onFindReplacement?.(req)
                                            }
                                        >
                                            Find cover
                                        </Button>
                                    ) : null}
                                    <Link
                                        href={`/operations/shifts/${req.shift_id}`}
                                    >
                                        <Button size="sm" variant="ghost">
                                            View
                                        </Button>
                                    </Link>
                                </div>
                            </article>
                        ))}
                    </div>
                </section>
            ) : null}
            <div className="space-y-3">
                {shifts.length === 0 ? (
                    <div className="rounded-[14px] border border-border bg-card p-6 text-center text-sm text-muted-foreground">
                        No open shifts in this week.
                    </div>
                ) : null}
                {shifts.map((sh) => (
                    <article
                        key={sh.id}
                        className="rounded-[14px] border border-l-4 border-border border-l-status-warning bg-card p-4"
                    >
                        <div className="grid items-center gap-4 md:grid-cols-[130px_1fr_auto_auto]">
                            <div>
                                <div className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                    {sh.day}
                                </div>
                                <div className="text-base font-bold tabular-nums">
                                    {sh.start} – {sh.end}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {sh.hours.toFixed(1)}h
                                </div>
                            </div>
                            <div className="min-w-0">
                                <div className="truncate font-semibold">
                                    {sh.client}
                                </div>
                                <div className="truncate text-xs text-muted-foreground">
                                    {sh.site
                                        ? sh.reason
                                            ? `${sh.site} · ${sh.reason}`
                                            : sh.site
                                        : (sh.reason ?? '')}
                                </div>
                            </div>
                            <div className="flex items-center gap-3">
                                <Stat
                                    label="Eligible"
                                    value={sh.eligible}
                                    tone="ok"
                                />
                                <Stat
                                    label="Warnings"
                                    value={sh.warnings}
                                    tone="warn"
                                />
                                <Stat
                                    label="Blocked"
                                    value={sh.blocked?.length ?? 0}
                                    tone="crit"
                                />
                            </div>
                            <div className="flex items-center gap-2">
                                {canManage && sh.suggestions[0] ? (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            onAssign(sh, sh.suggestions[0].id)
                                        }
                                    >
                                        Assign
                                    </Button>
                                ) : null}
                                {sh.href ? (
                                    <Link href={sh.href}>
                                        <Button size="sm" variant="ghost">
                                            View
                                        </Button>
                                    </Link>
                                ) : null}
                            </div>
                        </div>

                        <div className="mt-3 border-t border-dashed border-border pt-3">
                            <div className="mb-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                Suggested staff
                            </div>
                            <div className="flex flex-wrap items-center gap-1.5">
                                {sh.suggestions.length === 0 ? (
                                    <span className="text-[11px] text-muted-foreground italic">
                                        No eligible candidates — broaden
                                        criteria
                                    </span>
                                ) : (
                                    sh.suggestions.slice(0, 8).map((nm, i) => {
                                        const meta = candidateMeta(nm);

                                        return (
                                            <button
                                                key={nm.id}
                                                type="button"
                                                disabled={!canManage}
                                                onClick={() =>
                                                    onAssign(sh, nm.id)
                                                }
                                                className={cn(
                                                    'inline-flex min-h-11 max-w-full items-center gap-1.5 rounded-full border px-2 py-1 text-xs font-medium transition',
                                                    i === 0
                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                        : 'border-border bg-background text-foreground hover:bg-accent',
                                                )}
                                            >
                                                <span className="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-background/50 text-[9px] font-bold text-foreground uppercase">
                                                    {nm.name
                                                        .split(' ')
                                                        .map((w) => w[0])
                                                        .slice(0, 2)
                                                        .join('')}
                                                </span>
                                                <span className="flex min-w-0 flex-col items-start leading-tight">
                                                    <span className="max-w-[9rem] truncate">
                                                        {nm.name}
                                                    </span>
                                                    {meta ? (
                                                        <span
                                                            className={cn(
                                                                'text-[10px] font-medium',
                                                                i === 0
                                                                    ? 'text-primary-foreground/75'
                                                                    : 'text-muted-foreground',
                                                            )}
                                                        >
                                                            {meta}
                                                        </span>
                                                    ) : null}
                                                </span>
                                                {i === 0 ? (
                                                    <span className="rounded-full bg-primary-foreground/15 px-1.5 py-0.5 text-[9px] font-bold uppercase">
                                                        best
                                                    </span>
                                                ) : null}
                                            </button>
                                        );
                                    })
                                )}
                            </div>
                            {sh.blocked && sh.blocked.length > 0 ? (
                                <div className="mt-2 space-y-1">
                                    {sh.blocked.map((b, i) => (
                                        <div
                                            key={i}
                                            className="text-[11px] text-status-critical"
                                        >
                                            × {b}
                                        </div>
                                    ))}
                                </div>
                            ) : null}
                        </div>
                    </article>
                ))}
            </div>
            {actionEndSlot}
        </div>
    );
}

function EligibilityAlertRow({
    alert,
    tone,
}: {
    alert: EligibilityAlertItem;
    tone: 'blocked' | 'warning';
}) {
    const blocked = tone === 'blocked';

    return (
        <Link
            href={`/operations/shifts/${alert.id}`}
            className={cn(
                'rounded-md border p-3 transition hover:bg-accent',
                blocked
                    ? 'border-status-critical/30 bg-status-critical-bg/35'
                    : 'border-status-warning/30 bg-status-warning-bg/35',
            )}
        >
            <div className="flex flex-wrap items-center gap-2">
                <span
                    className={cn(
                        'rounded px-1.5 py-0.5 text-[10px] font-bold uppercase',
                        blocked
                            ? 'bg-status-critical text-white'
                            : 'bg-status-warning text-white',
                    )}
                >
                    {blocked ? 'Blocker' : 'Warning'}
                </span>
                <span className="text-xs font-semibold">{alert.staff}</span>
                <span className="text-[11px] text-muted-foreground">
                    {fmtDay(alert.starts_at)} - {alert.site}
                </span>
            </div>
            <div className="mt-1 text-xs text-foreground">{alert.reason}</div>
        </Link>
    );
}

function fmtDay(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
    });
}

function fmtClock(iso?: string | null): string | null {
    if (!iso) return null;
    return new Date(iso).toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

function fmtTimeRange(starts?: string | null, ends?: string | null): string {
    const start = fmtClock(starts);
    const end = fmtClock(ends);
    if (start && end) return `${start} - ${end}`;
    if (start) return start;
    return 'Time not set';
}

function formatHours(hours: number): string {
    const rounded = Math.round(hours * 10) / 10;
    return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1);
}

function candidateMeta(candidate: {
    hours?: number | null;
    meta?: string | null;
}): string | null {
    if (candidate.meta) return candidate.meta;
    if (
        typeof candidate.hours !== 'number' ||
        !Number.isFinite(candidate.hours)
    )
        return null;
    return `${formatHours(candidate.hours)}h this week`;
}

function Stat({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone: 'ok' | 'warn' | 'crit';
}) {
    const c =
        tone === 'crit'
            ? 'text-status-critical'
            : tone === 'warn'
              ? 'text-status-warning'
              : 'text-status-success';
    return (
        <div className="text-center">
            <div className={cn('text-base font-bold tabular-nums', c)}>
                {value}
            </div>
            <div className="text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                {label}
            </div>
        </div>
    );
}

export default OpenShiftsPane;
