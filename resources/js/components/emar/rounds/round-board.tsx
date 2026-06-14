/* eslint-disable no-restricted-syntax -- round cards/list rows are custom-layout
   bordered panels (progress bar + stat grid + assignee + action) that diverge
   from Card/CardHeader/CardContent; all colours are semantic tokens. */
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { roundActionLabel, roundStatusMeta, type RoundSummary, type StaffOption } from './types';
import { Clock, Play, ShieldCheck } from 'lucide-react';

type Props = {
    rounds: RoundSummary[];
    view: 'cards' | 'list';
    staff: StaffOption[];
    canManage: boolean;
    onOpenGuided: (roundId: number) => void;
    onAssign: (roundId: number, userId: number | null) => void;
};

const TONE_BADGE: Record<string, string> = {
    success: 'bg-status-success-bg text-status-success',
    info: 'bg-status-info-bg text-status-info',
    warning: 'bg-status-warning-bg text-status-warning',
    neutral: 'bg-muted text-muted-foreground',
};

function recorded(r: RoundSummary): number {
    return r.given + r.refused + r.withheld + r.missed;
}
function dueCount(r: RoundSummary): number {
    return Math.max(0, r.total_medications - recorded(r));
}
function percent(r: RoundSummary): number {
    return r.total_medications ? Math.round((recorded(r) / r.total_medications) * 100) : 0;
}

function StatusBadge({ status }: { status: string }) {
    const meta = roundStatusMeta(status);
    return (
        <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-semibold', TONE_BADGE[meta.tone])}>{meta.label}</span>
    );
}

function MiniStat({ label, value, tone }: { label: string; value: number; tone: string }) {
    return (
        <div className="rounded-lg border bg-background px-2 py-1.5 text-center">
            <div className={cn('text-sm font-bold', tone)}>{value}</div>
            <div className="text-[10px] uppercase tracking-wide text-muted-foreground">{label}</div>
        </div>
    );
}

function AssigneeSelect({ round, staff, onAssign }: { round: RoundSummary; staff: StaffOption[]; onAssign: Props['onAssign'] }) {
    return (
        <Select
            value={round.assigned_to ? String(round.assigned_to) : 'none'}
            onValueChange={(v) => onAssign(round.id, v === 'none' ? null : Number(v))}
        >
            <SelectTrigger className="h-8 w-full text-xs">
                <SelectValue placeholder="Unassigned" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="none">Unassigned</SelectItem>
                {staff.map((s) => (
                    <SelectItem key={s.id} value={String(s.id)}>
                        {s.name}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

export default function RoundBoard({ rounds, view, staff, canManage, onOpenGuided, onAssign }: Props) {
    if (rounds.length === 0) {
        return (
            <div className="rounded-2xl border bg-card px-5 py-12 text-center text-sm text-muted-foreground">
                No rounds for this day. Use <span className="font-medium text-foreground">Generate rounds</span> to create them from your templates.
            </div>
        );
    }

    if (view === 'list') {
        return (
            <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                <th className="px-4 py-2.5">Round</th>
                                <th className="px-4 py-2.5">Time</th>
                                <th className="px-4 py-2.5">Status</th>
                                <th className="px-4 py-2.5">Progress</th>
                                <th className="px-4 py-2.5">Given / R+H / Due</th>
                                <th className="px-4 py-2.5">Assignee</th>
                                <th className="px-4 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {rounds.map((r) => (
                                <tr key={r.id} className="border-b last:border-b-0">
                                    <td className="px-4 py-3 font-medium">{r.name}</td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        {r.scheduled_time} · ±{r.window_minutes}m
                                    </td>
                                    <td className="px-4 py-3">
                                        <StatusBadge status={r.status} />
                                    </td>
                                    <td className="px-4 py-3 text-muted-foreground">{percent(r)}%</td>
                                    <td className="px-4 py-3 text-muted-foreground">
                                        <span className="text-status-success">{r.given}</span> /{' '}
                                        <span className="text-status-warning">{r.refused + r.withheld}</span> /{' '}
                                        <span className="text-status-critical">{dueCount(r)}</span>
                                    </td>
                                    <td className="px-4 py-3">
                                        {canManage ? (
                                            <div className="w-40">
                                                <AssigneeSelect round={r} staff={staff} onAssign={onAssign} />
                                            </div>
                                        ) : (
                                            <span className="text-muted-foreground">{r.assignee ?? 'Unassigned'}</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <Button size="sm" variant="outline" onClick={() => onOpenGuided(r.id)}>
                                            {roundActionLabel(r.status)}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        );
    }

    return (
        <div className="grid grid-cols-1 gap-3.5 md:grid-cols-2 xl:grid-cols-3">
            {rounds.map((r) => (
                <div key={r.id} className="flex flex-col gap-3 rounded-2xl border bg-card p-4 shadow-sm">
                    <div className="flex items-start justify-between gap-2">
                        <div>
                            <div className="text-[15px] font-bold leading-tight">{r.name}</div>
                            <div className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                                <Clock className="h-3.5 w-3.5" />
                                {r.scheduled_time} · ±{r.window_minutes} min
                            </div>
                        </div>
                        <StatusBadge status={r.status} />
                    </div>

                    <div>
                        <div className="mb-1 flex items-center justify-between text-[11px] text-muted-foreground">
                            <span>
                                {recorded(r)}/{r.total_medications} recorded
                            </span>
                            <span>{percent(r)}%</span>
                        </div>
                        <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                            <div className="h-full rounded-full bg-primary" style={{ width: `${percent(r)}%` }} />
                        </div>
                    </div>

                    <div className="grid grid-cols-4 gap-1.5">
                        <MiniStat label="Given" value={r.given} tone="text-status-success" />
                        <MiniStat label="Refused" value={r.refused} tone="text-status-warning" />
                        <MiniStat label="Held" value={r.withheld} tone="text-status-warning" />
                        <MiniStat label="Due" value={dueCount(r)} tone="text-status-critical" />
                    </div>

                    {canManage ? (
                        <AssigneeSelect round={r} staff={staff} onAssign={onAssign} />
                    ) : (
                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <ShieldCheck className="h-3.5 w-3.5" />
                            {r.assignee ?? 'Unassigned'}
                        </div>
                    )}

                    <Button className="mt-auto w-full" onClick={() => onOpenGuided(r.id)}>
                        <Play className="h-4 w-4" />
                        {roundActionLabel(r.status)}
                    </Button>
                </div>
            ))}
        </div>
    );
}
