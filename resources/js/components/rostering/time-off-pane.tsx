import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import { avatarHueStyle } from './avatar-hue';
import { MicroStats, type MicroStat } from './micro-stats';

export type TimeOffRequest = {
    id: number;
    source?: 'staff_time_off' | 'hr_leave';
    sourceId?: number;
    staff: string;
    initials: string;
    hue: number;
    reason: string;
    type: string;
    starts_at: string;
    ends_at: string;
    days: number;
    impact: number;
    status: 'pending' | 'approved';
};

export type TimeOffPaneProps = {
    stats: MicroStat[];
    requests: TimeOffRequest[];
    weekStart: Date;
    canManage: boolean;
    onApprove?: (req: TimeOffRequest) => void;
    onDecline?: (req: TimeOffRequest) => void;
};

function fmtRange(starts: string, ends: string) {
    const s = new Date(starts);
    const e = new Date(ends);
    const fmt = (d: Date) =>
        d.toLocaleDateString(undefined, {
            weekday: 'short',
            day: '2-digit',
            month: 'short',
        });
    return `${fmt(s)} → ${fmt(e)}`;
}

function covers(req: TimeOffRequest, date: Date) {
    const s = new Date(req.starts_at);
    const e = new Date(req.ends_at);
    const d = date.getTime();
    s.setHours(0, 0, 0, 0);
    e.setHours(23, 59, 59, 999);
    return d >= s.getTime() && d <= e.getTime();
}

export function TimeOffPane({
    stats,
    requests,
    weekStart,
    canManage,
    onApprove,
    onDecline,
}: TimeOffPaneProps) {
    const pending = requests.filter((r) => r.status === 'pending');
    const pendingSubtitle =
        pending.length === 0
            ? 'All caught up · no pending requests'
            : 'Awaiting your decision · oldest first';
    const days14 = Array.from({ length: 14 }, (_, i) => {
        const d = new Date(weekStart);
        d.setDate(d.getDate() + i);
        return d;
    });

    return (
        <div className="space-y-4">
            <MicroStats stats={stats} />

            <div className="grid gap-4 xl:grid-cols-[1fr_1.1fr]">
                <section className="rounded-[14px] border border-border bg-card p-4 shadow-sm">
                    <div className="mb-3 flex items-center justify-between">
                        <div>
                            <h3 className="text-sm font-bold tracking-tight">
                                Pending requests
                            </h3>
                            <div className="text-[11px] text-muted-foreground">
                                {pendingSubtitle}
                            </div>
                        </div>
                        {canManage && pending.length > 0 ? (
                            <Button variant="outline" size="sm">
                                Bulk review
                            </Button>
                        ) : null}
                    </div>
                    <div className="space-y-2">
                        {pending.length === 0 ? (
                            <div className="rounded-md border border-dashed border-border p-4 text-center text-xs text-muted-foreground">
                                No pending leave requests.
                            </div>
                        ) : null}
                        {pending.map((req) => (
                            <article
                                key={req.id}
                                className="grid items-center gap-3 rounded-md border border-border p-3 md:grid-cols-[180px_1fr_auto]"
                            >
                                <div className="flex items-center gap-2.5">
                                    <div
                                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold uppercase"
                                        style={avatarHueStyle(req.hue)}
                                    >
                                        {req.initials}
                                    </div>
                                    <div className="min-w-0">
                                        <div className="truncate text-sm font-semibold">
                                            {req.staff}
                                        </div>
                                        <div className="truncate text-[11px] text-muted-foreground">
                                            {req.reason}
                                        </div>
                                    </div>
                                </div>
                                <div className="min-w-0">
                                    <div className="text-sm tabular-nums">
                                        {fmtRange(req.starts_at, req.ends_at)}
                                    </div>
                                    <div className="mt-0.5 flex flex-wrap items-center gap-2 text-[11px] text-muted-foreground">
                                        <span className="rounded bg-muted px-1.5 py-0.5 font-semibold uppercase">
                                            {req.type}
                                        </span>
                                        <span>
                                            {req.days}d · {req.days * 8}h
                                        </span>
                                        {req.impact > 0 ? (
                                            <span className="text-status-warning">
                                                ⚠ {req.impact} shifts to
                                                re-cover
                                            </span>
                                        ) : null}
                                    </div>
                                </div>
                                {canManage ? (
                                    <div className="flex items-center gap-1.5">
                                        <Button
                                            size="sm"
                                            onClick={() => onApprove?.(req)}
                                        >
                                            Approve
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => onDecline?.(req)}
                                        >
                                            Decline
                                        </Button>
                                    </div>
                                ) : null}
                            </article>
                        ))}
                    </div>
                </section>

                <section className="rounded-[14px] border border-border bg-card p-4 shadow-sm">
                    <div className="mb-3">
                        <h3 className="text-sm font-bold tracking-tight">
                            On leave · next 14 days
                        </h3>
                        <div className="text-[11px] text-muted-foreground">
                            Approved leave overlay
                        </div>
                    </div>
                    <div className="overflow-x-auto">
                        <div
                            className="grid border-b border-border"
                            style={{
                                gridTemplateColumns: `110px repeat(14, minmax(28px, 1fr))`,
                            }}
                        >
                            <span />
                            {days14.map((d, i) => (
                                <div
                                    key={i}
                                    className={cn(
                                        'text-center text-[10px]',
                                        (d.getDay() === 0 ||
                                            d.getDay() === 6) &&
                                            'text-muted-foreground/60',
                                    )}
                                >
                                    <div className="font-bold uppercase">
                                        {d
                                            .toLocaleDateString(undefined, {
                                                weekday: 'short',
                                            })
                                            .slice(0, 1)}
                                    </div>
                                    <div className="tabular-nums">
                                        {String(d.getDate()).padStart(2, '0')}
                                    </div>
                                </div>
                            ))}
                        </div>

                        {requests.length === 0 ? (
                            <div className="p-4 text-center text-xs text-muted-foreground">
                                No leave scheduled.
                            </div>
                        ) : null}
                        {requests.map((req) => (
                            <div
                                key={req.id}
                                className="grid items-center border-t border-border py-1"
                                style={{
                                    gridTemplateColumns: `110px repeat(14, minmax(28px, 1fr))`,
                                }}
                            >
                                <div className="flex items-center gap-2 pr-2">
                                    <div
                                        className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[9px] font-bold uppercase"
                                        style={avatarHueStyle(req.hue)}
                                    >
                                        {req.initials}
                                    </div>
                                    <span className="truncate text-xs">
                                        {req.staff.split(' ')[0]}
                                    </span>
                                </div>
                                {days14.map((d, i) => {
                                    const active = covers(req, d);
                                    if (!active) {
                                        return <div key={i} className="h-5" />;
                                    }
                                    return (
                                        <div
                                            key={i}
                                            className={cn(
                                                'mx-px h-5 rounded',
                                                req.status === 'approved'
                                                    ? 'bg-status-success'
                                                    : 'border border-status-warning/50 bg-[repeating-linear-gradient(45deg,var(--status-warning)_0_4px,transparent_4px_8px)]',
                                            )}
                                            aria-label={`${req.staff} on leave`}
                                        />
                                    );
                                })}
                            </div>
                        ))}
                    </div>
                    <div className="mt-3 flex flex-wrap items-center gap-3 text-[11px] text-muted-foreground">
                        <LegendDot
                            color="var(--status-success)"
                            label="Approved"
                        />
                        <LegendDot
                            color="var(--status-warning)"
                            label="Pending"
                        />
                        <span className="text-muted-foreground/70">
                            Conflicts surface in the Shifts view.
                        </span>
                    </div>
                </section>
            </div>
        </div>
    );
}

function LegendDot({ color, label }: { color: string; label: string }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span
                className="inline-block h-2 w-2 rounded-full"
                style={{ background: color }}
            />
            <span>{label}</span>
        </span>
    );
}

export default TimeOffPane;
