/* Meds today › Rounds and Stock alerts (P01 C3). Both keep today's content
 * until their package redesigns them: the Rounds tab still opens today's
 * guided round (the walker arrives in C4), and Stock alerts is designed in
 * P06. Moved unchanged from the old Meds today page. */
import { Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, Package, Pill } from 'lucide-react';

import { ClientAvatar, StatusPill } from '@/components/meds/board-bits';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';

import type { ClientInfo, RoundInfo, ScheduleRow, StockAlert } from './types';

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

export function RoundsTab({
    rounds,
    schedule,
    clientById,
    canRecord,
}: {
    rounds: RoundInfo[];
    schedule: ScheduleRow[];
    clientById: Map<number, ClientInfo>;
    canRecord: boolean;
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
                                {!isDone && canRecord ? (
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
                                            <StatusPill
                                                status={d.status}
                                                awayReason={d.away_reason}
                                            />
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

export function StockTab({
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
