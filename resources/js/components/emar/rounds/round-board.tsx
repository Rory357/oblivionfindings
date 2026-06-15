/* eslint-disable no-restricted-syntax -- round cards/list rows are custom-layout
   bordered panels (progress bar + stat grid + assignee + actions + dose expander)
   that diverge from Card/CardHeader/CardContent; all colours are semantic tokens. */
import { ClientAvatar } from '@/components/meds/board-bits';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Activity, ArrowRight, Clock, ListChecks, Play, UserRound } from 'lucide-react';
import type { MouseEvent } from 'react';
import { DoseStatusBadge, RoundStatusBadge } from './round-bits';
import { roundActionLabel, roundCounts, type RoundCell, type RoundSummary } from './types';

type Props = {
    rounds: RoundSummary[];
    view: 'cards' | 'list';
    expanded: Record<number, boolean>;
    onToggleExpand: (id: number) => void;
    onOpen: (id: number) => void;
    onAudit: (round: RoundSummary) => void;
    onContext: (e: MouseEvent, round: RoundSummary) => void;
};

function MiniStat({ label, value, tone }: { label: string; value: number; tone: string }) {
    return (
        <div className="rounded-lg border bg-background px-2 py-1.5 text-center">
            <div className={cn('text-[17px] font-bold leading-tight', tone)}>{value}</div>
            <div className="text-[10px] tracking-wide text-muted-foreground uppercase">{label}</div>
        </div>
    );
}

function primaryMeta(status: string) {
    if (status === 'completed') return { variant: 'outline' as const, Icon: ListChecks };
    if (status === 'in_progress' || status === 'partial') return { variant: 'default' as const, Icon: Play };
    return { variant: 'default' as const, Icon: ArrowRight };
}

function Assignee({ name }: { name: string | null }) {
    if (!name) {
        return (
            <span className="flex items-center gap-2 text-xs text-muted-foreground">
                <span className="grid h-6 w-6 place-items-center rounded-full bg-muted text-muted-foreground">
                    <UserRound className="h-3.5 w-3.5" />
                </span>
                Unassigned
            </span>
        );
    }
    const initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]!.toUpperCase()).join('');
    return (
        <span className="flex items-center gap-2 text-xs text-muted-foreground">
            <span className="grid h-6 w-6 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">{initials}</span>
            {name}
        </span>
    );
}

function medLine(c: RoundCell): string {
    return [`${c.medication_name}${c.dose ? ` ${c.dose}` : ''}`, c.route, c.is_controlled ? 'CD' : null, c.is_high_risk ? 'high-risk' : null]
        .filter(Boolean)
        .join(' · ');
}

function DoseList({ cells }: { cells: RoundCell[] }) {
    return (
        <div className="border-t bg-background p-2">
            {cells.length === 0 ? (
                <div className="px-2 py-3 text-center text-xs text-muted-foreground">No scheduled doses in this round.</div>
            ) : (
                cells.map((c) => (
                    <div key={`${c.medication_id}-${c.scheduled_for}`} className="flex items-center gap-2.5 rounded-lg px-2 py-2">
                        <ClientAvatar name={c.resident_name} clientId={c.resident_id} className="h-7 w-7 text-[10px]" />
                        <div className="min-w-0 flex-1">
                            <div className="truncate text-[12.5px] font-semibold">{c.resident_name}</div>
                            <div className="truncate text-[11px] text-muted-foreground">{medLine(c)}</div>
                        </div>
                        <DoseStatusBadge status={c.status} />
                    </div>
                ))
            )}
        </div>
    );
}

export default function RoundBoard({ rounds, view, expanded, onToggleExpand, onOpen, onAudit, onContext }: Props) {
    if (rounds.length === 0) {
        return (
            <div className="rounded-2xl border bg-card px-5 py-12 text-center text-sm text-muted-foreground">
                No rounds match the current filters. Use <span className="font-medium text-foreground">Generate rounds</span> to create them from your templates.
            </div>
        );
    }

    if (view === 'list') {
        return (
            <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b bg-muted text-left text-[11px] tracking-wide text-muted-foreground uppercase">
                                <th className="px-4 py-2.5">Round</th>
                                <th className="px-4 py-2.5">Time</th>
                                <th className="px-4 py-2.5">Status</th>
                                <th className="px-4 py-2.5">Progress</th>
                                <th className="px-4 py-2.5">Given / R+H / Due</th>
                                <th className="px-4 py-2.5">Assignee</th>
                                <th className="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rounds.map((r) => {
                                const counts = roundCounts(r.cells);
                                const { variant } = primaryMeta(r.status);
                                return (
                                    <tr key={r.id} onContextMenu={(e) => onContext(e, r)} className="border-b last:border-b-0">
                                        <td className="px-4 py-3">
                                            <div className="font-medium">{r.name}</div>
                                            <div className="text-[11px] text-muted-foreground">{r.site_name ?? 'All sites'}</div>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">{r.scheduled_time}</td>
                                        <td className="px-4 py-3">
                                            <RoundStatusBadge status={r.status} />
                                        </td>
                                        <td className="min-w-[150px] px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                                    <div
                                                        className={cn('h-full rounded-full', r.status === 'completed' ? 'bg-status-success' : 'bg-primary')}
                                                        style={{ width: `${counts.pct}%` }}
                                                    />
                                                </div>
                                                <span className="text-[11px] text-muted-foreground tabular-nums">{counts.pct}%</span>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap">
                                            <span className="font-semibold text-status-success">{counts.given}</span> /{' '}
                                            <span className="font-semibold text-status-warning">{counts.refused + counts.held}</span> /{' '}
                                            <span className="font-semibold text-status-critical">{counts.due + counts.missed}</span>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">{r.assignee ?? 'Unassigned'}</td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="inline-flex items-center justify-end gap-1.5">
                                                <Button size="sm" variant={variant} onClick={() => onOpen(r.id)}>
                                                    {roundActionLabel(r.status)}
                                                </Button>
                                                <Button size="icon" variant="outline" className="h-8 w-8" onClick={() => onAudit(r)} aria-label="Audit and timeline">
                                                    <Activity className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        );
    }

    return (
        <div className="grid grid-cols-1 gap-3.5 md:grid-cols-2 xl:grid-cols-3">
            {rounds.map((r) => {
                const counts = roundCounts(r.cells);
                const { variant, Icon } = primaryMeta(r.status);
                const isOpen = !!expanded[r.id];
                return (
                    <div key={r.id} onContextMenu={(e) => onContext(e, r)} className="flex flex-col overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <div className="p-4">
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <div className="text-[15.5px] leading-tight font-bold">{r.name}</div>
                                    <div className="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <Clock className="h-3.5 w-3.5" />
                                        {r.scheduled_time} · ±{r.window_minutes} min
                                    </div>
                                </div>
                                <RoundStatusBadge status={r.status} />
                            </div>

                            <div className="mt-3">
                                <div className="mb-1 flex items-center justify-between text-[11.5px] text-muted-foreground">
                                    <span>
                                        {counts.recorded} of {counts.total} recorded
                                    </span>
                                    <span className="font-semibold text-foreground">{counts.pct}%</span>
                                </div>
                                <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                    <div
                                        className={cn('h-full rounded-full', r.status === 'completed' ? 'bg-status-success' : 'bg-primary')}
                                        style={{ width: `${counts.pct}%` }}
                                    />
                                </div>
                            </div>

                            <div className="mt-3.5 grid grid-cols-4 gap-1.5">
                                <MiniStat label="Given" value={counts.given} tone="text-status-success" />
                                <MiniStat label="Refused" value={counts.refused} tone="text-status-warning" />
                                <MiniStat label="Held" value={counts.held} tone="text-status-warning" />
                                <MiniStat label="Due/Missed" value={counts.due + counts.missed} tone="text-status-critical" />
                            </div>

                            <div className="mt-3">
                                <Assignee name={r.assignee} />
                            </div>
                        </div>

                        <div className="mt-auto flex items-center gap-2 border-t p-3">
                            <Button variant={variant} onClick={() => onOpen(r.id)}>
                                <Icon className="h-4 w-4" />
                                {roundActionLabel(r.status)}
                            </Button>
                            <Button variant="outline" onClick={() => onToggleExpand(r.id)}>
                                {isOpen ? 'Hide doses' : `View doses (${counts.total})`}
                            </Button>
                            <Button size="icon" variant="outline" className="ml-auto" onClick={() => onAudit(r)} aria-label="Audit and timeline">
                                <Activity className="h-4 w-4" />
                            </Button>
                        </div>

                        {isOpen ? <DoseList cells={r.cells} /> : null}
                    </div>
                );
            })}
        </div>
    );
}
