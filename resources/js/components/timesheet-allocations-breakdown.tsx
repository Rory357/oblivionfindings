import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ResidentDot } from '@/pages/my-day/components/resident-dot';
import { residentHue, residentInitials } from '@/pages/my-day/lib/resident-hue';
import {
    AlertTriangle,
    CheckCircle2,
    ClipboardList,
    Clock,
    Home,
    UserRound,
    Users,
} from 'lucide-react';
import type { ComponentType } from 'react';

export type AllocationMethod =
    | 'single'
    | 'residential_house'
    | 'equal_split'
    | 'manual'
    | 'time_segmented';

export interface ClientAllocation {
    id: number | null;
    client_id: number;
    hours: number;
    allocation_method: AllocationMethod;
    starts_at: string | null;
    ends_at: string | null;
    notes: string | null;
    sort_order: number;
}

export interface AllocationCandidate {
    id: number;
    name: string;
    is_primary?: boolean;
}

interface Props {
    allocations: ClientAllocation[];
    candidates: AllocationCandidate[];
    method: AllocationMethod;
    /** Timesheet's total hours; used for the sum-check banner. */
    totalHours: number;
    /** Drops the card chrome — for embedding directly in row layouts. */
    compact?: boolean;
}

const METHOD_META: Record<
    AllocationMethod,
    {
        label: string;
        icon: ComponentType<{ className?: string }>;
        accent: string;
        counterNoun: string;
    }
> = {
    single: {
        label: 'Single client',
        icon: UserRound,
        accent: 'text-status-info',
        counterNoun: 'client',
    },
    residential_house: {
        label: 'Residential house',
        icon: Home,
        accent: 'text-primary',
        counterNoun: 'resident',
    },
    equal_split: {
        label: 'Equal split',
        icon: Users,
        accent: 'text-status-info',
        counterNoun: 'client',
    },
    manual: {
        label: 'Manual',
        icon: ClipboardList,
        accent: 'text-status-warning',
        counterNoun: 'client',
    },
    time_segmented: {
        label: 'Time segments',
        icon: Clock,
        accent: 'text-status-warning',
        counterNoun: 'visit',
    },
};

function formatHM(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleTimeString('en-NZ', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

function initialsFromName(name: string): string {
    const parts = name.trim().split(/\s+/);
    const first = parts[0] ?? '';
    const last = parts.length > 1 ? parts[parts.length - 1] : '';
    return residentInitials(first, last);
}

function candidateName(
    candidates: AllocationCandidate[],
    clientId: number,
): string {
    return (
        candidates.find((c) => c.id === clientId)?.name ?? `Client #${clientId}`
    );
}

/**
 * Read-only per-client time allocation breakdown for the approver UI.
 *
 * Legacy timesheets without explicit allocation rows arrive as a synthesised
 * single-row representation (id === null, method === 'single'). Those render
 * as a compact one-line notice so they don't look broken.
 *
 * Editing lives in the worker-side My Day popup — this view is read-only.
 */
export function TimesheetAllocationsBreakdown({
    allocations,
    candidates,
    method,
    totalHours,
    compact = false,
}: Props) {
    const isSynthesised =
        allocations.length === 1 && allocations[0].id === null;

    if (isSynthesised) {
        const a = allocations[0];
        const name = candidateName(candidates, a.client_id);
        return (
            <div className="flex items-center gap-2 rounded-lg border border-dashed border-muted-foreground/20 bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
                <UserRound className="h-3.5 w-3.5 shrink-0" />
                <span>
                    <span className="font-medium text-foreground">
                        Single client
                    </span>
                    {' · '}all {Number(a.hours).toFixed(2)}h attributed to{' '}
                    {name} (no breakdown recorded)
                </span>
            </div>
        );
    }

    const meta = METHOD_META[method] ?? METHOD_META.single;
    const Icon = meta.icon;
    const sum = allocations.reduce((s, a) => s + Number(a.hours || 0), 0);
    const variance = Math.abs(sum - totalHours);
    const balanced = variance < 0.01;

    const body = (
        <div className={compact ? 'space-y-2' : 'space-y-3'}>
            <div className="flex items-center gap-2">
                <Badge variant="secondary" className="gap-1">
                    <Icon className={`h-3 w-3 ${meta.accent}`} />
                    {meta.label}
                    {' · '}
                    {allocations.length} {meta.counterNoun}
                    {allocations.length === 1 ? '' : 's'}
                </Badge>
            </div>

            <div className="divide-y rounded-md border">
                {allocations.map((a) => {
                    const name = candidateName(candidates, a.client_id);
                    const initials = initialsFromName(name);
                    const hue = residentHue(a.client_id);
                    return (
                        <div
                            key={`${a.client_id}-${a.id ?? 'syn'}`}
                            className="flex items-center justify-between gap-3 px-3 py-2"
                        >
                            <div className="flex min-w-0 items-center gap-2">
                                <ResidentDot hue={hue} initials={initials} />
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-medium">
                                        {name}
                                    </p>
                                    {a.allocation_method === 'time_segmented' &&
                                    a.starts_at &&
                                    a.ends_at ? (
                                        <p className="text-xs text-muted-foreground">
                                            {formatHM(a.starts_at)} –{' '}
                                            {formatHM(a.ends_at)}
                                        </p>
                                    ) : null}
                                    {a.notes ? (
                                        <p className="text-xs text-muted-foreground italic">
                                            “{a.notes}”
                                        </p>
                                    ) : null}
                                </div>
                            </div>
                            <span className="text-sm font-medium tabular-nums">
                                {Number(a.hours).toFixed(2)}h
                            </span>
                        </div>
                    );
                })}
            </div>

            <div
                className={`flex items-center gap-2 rounded-md border px-3 py-2 text-xs ${
                    balanced
                        ? 'border-status-success/30 bg-status-success/10 text-status-success'
                        : 'border-status-warning/30 bg-status-warning/10 text-status-warning'
                }`}
            >
                {balanced ? (
                    <CheckCircle2 className="h-3.5 w-3.5 shrink-0" />
                ) : (
                    <AlertTriangle className="h-3.5 w-3.5 shrink-0" />
                )}
                <span>
                    {balanced
                        ? `Balances at ${sum.toFixed(2)}h`
                        : `${variance.toFixed(2)}h variance — allocated ${sum.toFixed(2)}h vs ${totalHours.toFixed(2)}h logged`}
                </span>
            </div>
        </div>
    );

    if (compact) {
        return body;
    }

    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="flex items-center gap-2 text-xs font-medium tracking-wider text-muted-foreground uppercase">
                    <Icon className={`h-3.5 w-3.5 ${meta.accent}`} />
                    Time allocation
                </CardTitle>
            </CardHeader>
            <CardContent>{body}</CardContent>
        </Card>
    );
}

export default TimesheetAllocationsBreakdown;
