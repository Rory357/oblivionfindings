import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Info,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export type SignalTone = 'critical' | 'warning' | 'info' | 'success';

export type Signal = {
    tone: SignalTone;
    title: string;
    body: string;
    cta: string;
    href?: string;
    onClick?: () => void;
};

const TONE_BORDER: Record<SignalTone, string> = {
    critical: 'border-l-status-critical',
    warning: 'border-l-status-warning',
    info: 'border-l-status-info',
    success: 'border-l-status-success',
};

const TONE_CHIP: Record<SignalTone, string> = {
    critical: 'bg-status-critical-bg text-status-critical',
    warning: 'bg-status-warning-bg text-status-warning',
    info: 'bg-status-info-bg text-status-info',
    success: 'bg-status-success-bg text-status-success',
};

const TONE_ICON: Record<SignalTone, LucideIcon> = {
    critical: AlertTriangle,
    warning: AlertTriangle,
    info: Info,
    success: CheckCircle2,
};

export type CapacityRow = {
    name: string;
    initials: string;
    hue: number;
    hours: number;
};

function capTone(hours: number): { color: string; label: string } {
    if (hours > 44) return { color: 'bg-status-critical', label: 'crit' };
    if (hours > 40) return { color: 'bg-status-warning', label: 'warn' };
    return { color: 'bg-status-info', label: 'ok' };
}

export function SignalRail({
    signals,
    capacity,
    className,
}: {
    signals: Signal[];
    capacity: CapacityRow[];
    className?: string;
}): ReactNode {
    return (
        <aside
            className={cn(
                'flex flex-col gap-6 rounded-[14px] border border-border bg-card p-4 shadow-sm',
                className,
            )}
        >
            <section>
                <header className="mb-3 flex items-center justify-between">
                    <h3 className="text-sm font-bold tracking-tight">
                        Needs you
                    </h3>
                    <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground tabular-nums">
                        {signals.length}
                    </span>
                </header>
                {signals.length === 0 ? (
                    <p className="text-xs text-muted-foreground">
                        All clear — no alerts.
                    </p>
                ) : (
                    <ul className="space-y-2">
                        {signals.map((s, i) => {
                            const Icon = TONE_ICON[s.tone];
                            const inner = (
                                <span
                                    className={cn(
                                        'flex w-full gap-2.5 rounded-md border-l-[3px] bg-background/60 p-2.5 text-left transition-colors hover:bg-accent',
                                        TONE_BORDER[s.tone],
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-md',
                                            TONE_CHIP[s.tone],
                                        )}
                                    >
                                        <Icon className="h-3 w-3" />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-xs font-semibold leading-tight text-foreground">
                                            {s.title}
                                        </span>
                                        <span className="mt-0.5 block text-[11px] leading-snug text-muted-foreground">
                                            {s.body}
                                        </span>
                                        <span className="mt-1 inline-flex items-center gap-1 text-[11px] font-semibold text-primary">
                                            {s.cta}
                                            <span aria-hidden="true">→</span>
                                        </span>
                                    </span>
                                </span>
                            );
                            return (
                                <li key={i}>
                                    {s.href ? (
                                        <Link href={s.href}>{inner}</Link>
                                    ) : s.onClick ? (
                                        <button
                                            type="button"
                                            onClick={s.onClick}
                                            className="block w-full"
                                        >
                                            {inner}
                                        </button>
                                    ) : (
                                        <div>{inner}</div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>

            <section>
                <header className="mb-3">
                    <h3 className="text-sm font-bold tracking-tight">
                        Capacity this week
                    </h3>
                </header>
                {capacity.length === 0 ? (
                    <p className="text-xs text-muted-foreground">
                        No staff rostered.
                    </p>
                ) : (
                    <ul className="space-y-2">
                        {capacity.slice(0, 8).map((row) => {
                            const t = capTone(row.hours);
                            return (
                                <li
                                    key={row.name}
                                    className="flex items-center gap-2.5"
                                >
                                    <span
                                        className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold uppercase"
                                        style={{
                                            background: `hsl(${row.hue} 55% 90%)`,
                                            color: `hsl(${row.hue} 50% 35%)`,
                                        }}
                                    >
                                        {row.initials}
                                    </span>
                                    <span className="flex-1 truncate text-xs">
                                        {row.name}
                                    </span>
                                    <span className="relative h-1.5 w-[70px] overflow-hidden rounded-full bg-muted">
                                        <span
                                            className={cn(
                                                'block h-full rounded-full transition-all',
                                                t.color,
                                            )}
                                            style={{
                                                width: `${Math.min(100, row.hours * 2)}%`,
                                            }}
                                        />
                                    </span>
                                    <span className="w-8 text-right text-[11px] font-semibold tabular-nums text-muted-foreground">
                                        {row.hours}h
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>
        </aside>
    );
}

export default SignalRail;
