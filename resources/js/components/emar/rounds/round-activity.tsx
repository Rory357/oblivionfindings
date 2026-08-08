/* eslint-disable no-restricted-syntax -- the activity feed rows are custom
   clickable panels and the status filter is a segmented pill; all colours are
   semantic tokens. */
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { Activity, AlertTriangle, Ban, Check, Hand } from 'lucide-react';
import type { ComponentType } from 'react';
import { useMemo, useState } from 'react';
import { doseStatusMeta, type ActivityItem, type RoundSummary } from './types';

type StatusFilter = 'all' | 'given' | 'refused' | 'withheld' | 'missed';

type Props = {
    activity: ActivityItem[];
    rounds: RoundSummary[];
    siteFilter: number | null;
    residentFilter: number | null;
    onView: (item: ActivityItem) => void;
};

const STATUS_FILTERS: { id: StatusFilter; label: string }[] = [
    { id: 'all', label: 'All' },
    { id: 'given', label: 'Given' },
    { id: 'refused', label: 'Refused' },
    { id: 'withheld', label: 'Held' },
    { id: 'missed', label: 'Missed' },
];

const ICON: Record<string, ComponentType<{ className?: string }>> = {
    given: Check,
    refused: Ban,
    withheld: Hand,
    missed: AlertTriangle,
};

const TONE: Record<string, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    muted: 'bg-muted text-muted-foreground',
};

export default function RoundActivity({
    activity,
    rounds,
    siteFilter,
    residentFilter,
    onView,
}: Props) {
    const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
    const [roundFilter, setRoundFilter] = useState<number | null>(null);

    const filtered = useMemo(
        () =>
            activity.filter(
                (a) =>
                    (siteFilter == null || a.site_id === siteFilter) &&
                    (residentFilter == null ||
                        a.resident_id === residentFilter) &&
                    (statusFilter === 'all' || a.status === statusFilter) &&
                    (roundFilter == null || a.round_id === roundFilter),
            ),
        [activity, siteFilter, residentFilter, statusFilter, roundFilter],
    );

    return (
        <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div className="flex flex-col gap-3 border-b px-4 py-3.5">
                <div>
                    <div className="text-sm font-semibold">
                        Recent round activity
                    </div>
                    <div className="mt-0.5 text-xs text-muted-foreground">
                        Every administration flows through the audit trail.
                        Click an entry for the full record.
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <div className="inline-flex gap-0.5 rounded-lg border bg-card p-0.5">
                        {STATUS_FILTERS.map((s) => (
                            <Button
                                key={s.id}
                                size="sm"
                                variant="ghost"
                                className={cn(
                                    'h-7',
                                    statusFilter === s.id
                                        ? 'bg-accent text-primary hover:bg-accent'
                                        : 'text-muted-foreground',
                                )}
                                onClick={() => setStatusFilter(s.id)}
                            >
                                {s.label}
                            </Button>
                        ))}
                    </div>
                    <Select
                        value={roundFilter ? String(roundFilter) : 'all'}
                        onValueChange={(v) =>
                            setRoundFilter(v === 'all' ? null : Number(v))
                        }
                    >
                        <SelectTrigger className="h-8 w-[170px] text-xs">
                            <SelectValue placeholder="All rounds" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All rounds</SelectItem>
                            {rounds.map((r) => (
                                <SelectItem key={r.id} value={String(r.id)}>
                                    {r.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <span className="ml-auto text-xs text-muted-foreground tabular-nums">
                        {filtered.length} of {activity.length}
                    </span>
                </div>
            </div>

            {filtered.length === 0 ? (
                <div className="px-5 py-10 text-center text-sm text-muted-foreground">
                    {activity.length === 0
                        ? 'No round activity yet today.'
                        : 'No activity matches the current filters.'}
                </div>
            ) : (
                <div className="flex flex-col gap-1 p-2">
                    {filtered.map((a) => {
                        const meta = doseStatusMeta(a.status);
                        const Icon = ICON[a.status] ?? Activity;
                        return (
                            <button
                                key={a.id}
                                type="button"
                                onClick={() => onView(a)}
                                className="flex items-start gap-3 rounded-xl px-3 py-2.5 text-left transition-colors hover:bg-accent/50"
                            >
                                <span
                                    className={cn(
                                        'mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full',
                                        TONE[meta.tone] ?? TONE.muted,
                                    )}
                                >
                                    <Icon className="h-4 w-4" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="text-[13px] leading-snug">
                                        <span className="font-semibold">
                                            {a.resident_name ?? 'Resident'}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {' — '}
                                            {a.medication_name ?? 'Medication'}
                                            {a.dose ? ` ${a.dose}` : ''} ·{' '}
                                            {meta.label.toLowerCase()}
                                        </span>
                                    </div>
                                    <div className="mt-0.5 truncate text-[11.5px] text-muted-foreground">
                                        {[a.time, a.staff, a.round_name]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </div>
                                </div>
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
