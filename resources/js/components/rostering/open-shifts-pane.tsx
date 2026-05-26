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
    suggestions: Array<{ id: number; name: string }>;
    href?: string;
};

export type OpenShiftsPaneProps = {
    stats: MicroStat[];
    shifts: OpenShiftCard[];
    canManage: boolean;
    onAssign: (
        shift: OpenShiftCard,
        candidateUserId: number | string,
    ) => void;
    onBroadcast?: (shift: OpenShiftCard) => void;
    actionEndSlot?: ReactNode;
};

export function OpenShiftsPane({
    stats,
    shifts,
    canManage,
    onAssign,
    onBroadcast,
    actionEndSlot,
}: OpenShiftsPaneProps) {
    return (
        <div className="space-y-4">
            <MicroStats stats={stats} />
            <div className="space-y-3">
                {shifts.length === 0 ? (
                    <div className="rounded-[14px] border border-border bg-card p-6 text-center text-sm text-muted-foreground">
                        No open shifts in this week. 🎉
                    </div>
                ) : null}
                {shifts.map((sh) => (
                    <article
                        key={sh.id}
                        className="rounded-[14px] border border-border border-l-4 border-l-status-warning bg-card p-4"
                    >
                        <div className="grid items-center gap-4 md:grid-cols-[130px_1fr_auto_auto]">
                            <div>
                                <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
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
                                        : sh.reason ?? ''}
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
                                            onAssign(
                                                sh,
                                                sh.suggestions[0].id,
                                            )
                                        }
                                    >
                                        Assign
                                    </Button>
                                ) : null}
                                {canManage ? (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => onBroadcast?.(sh)}
                                    >
                                        Broadcast
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
                            <div className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                Suggested staff
                            </div>
                            <div className="flex flex-wrap items-center gap-1.5">
                                {sh.suggestions.length === 0 ? (
                                    <span className="text-[11px] italic text-muted-foreground">
                                        No eligible candidates — broaden
                                        criteria
                                    </span>
                                ) : (
                                    sh.suggestions
                                        .slice(0, 8)
                                        .map((nm, i) => (
                                            <button
                                                key={nm.id}
                                                type="button"
                                                disabled={!canManage}
                                                onClick={() =>
                                                    onAssign(sh, nm.id)
                                                }
                                                className={cn(
                                                    'inline-flex items-center gap-1.5 rounded-full border px-2 py-1 text-xs font-medium transition',
                                                    i === 0
                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                        : 'border-border bg-background text-foreground hover:bg-accent',
                                                )}
                                            >
                                                <span className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-background/50 text-[9px] font-bold uppercase text-foreground">
                                                    {nm.name
                                                        .split(' ')
                                                        .map((w) => w[0])
                                                        .slice(0, 2)
                                                        .join('')}
                                                </span>
                                                <span>{nm.name}</span>
                                                {i === 0 ? (
                                                    <span className="rounded-full bg-primary-foreground/15 px-1.5 py-0.5 text-[9px] font-bold uppercase">
                                                        best
                                                    </span>
                                                ) : null}
                                            </button>
                                        ))
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
            <div className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                {label}
            </div>
        </div>
    );
}

export default OpenShiftsPane;
