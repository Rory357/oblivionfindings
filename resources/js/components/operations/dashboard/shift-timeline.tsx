import { Link } from '@inertiajs/react';
import { ArrowRight, Building2, Moon, Route as RouteIcon, Sun, Sunset, UserX } from 'lucide-react';
import { useState, type ComponentType } from 'react';

import { cn } from '@/lib/utils';

import type { TimelineBar, TimelineData, TimelineRow } from './types';
import { Button as GuardrailButton } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';

const TYPE_ICON: Record<string, ComponentType<{ className?: string }>> = {
    overnight: Moon,
    day: Sun,
    evening: Sunset,
    community: RouteIcon,
    'building-2': Building2,
};

function barBackground(bar: TimelineBar): string {
    if (bar.type === 'open' || bar.unassigned) {
        return 'color-mix(in oklch, var(--status-critical) 18%, transparent)';
    }
    switch (bar.type) {
        case 'overnight':
            return 'color-mix(in oklch, var(--primary) 75%, black)';
        case 'day':
            return 'var(--primary)';
        case 'evening':
            return 'color-mix(in oklch, var(--primary) 85%, white)';
        case 'community':
            return 'color-mix(in oklch, var(--primary) 60%, white)';
        default:
            return 'var(--primary)';
    }
}

function barBorder(bar: TimelineBar): string | undefined {
    if (bar.type === 'open' || bar.unassigned) {
        return '1px dashed var(--status-critical)';
    }
    return undefined;
}

function barColor(bar: TimelineBar): string {
    if (bar.type === 'open' || bar.unassigned) return 'var(--status-critical)';
    return 'white';
}

function TimelineRowView({ row, hasNowLine }: { row: TimelineRow; hasNowLine: boolean }) {
    const Icon = row.icon ? TYPE_ICON[row.icon] ?? Building2 : null;
    const labelInner = (
        <div className="flex w-[140px] shrink-0 items-center gap-2">
            {row.avatar !== undefined ? (
                row.is_open ? (
                    <div
                        className="flex h-6 w-6 items-center justify-center rounded-full border-2 border-dashed"
                        style={{ borderColor: 'var(--status-critical)', color: 'var(--status-critical)' }}
                    >
                        <UserX className="h-3 w-3" />
                    </div>
                ) : (
                    <div className="inline-flex h-6 w-6 items-center justify-center rounded-full bg-[color:var(--accent)] text-[9px] font-semibold text-[color:var(--primary)]">
                        {row.avatar ?? '—'}
                    </div>
                )
            ) : Icon ? (
                <div
                    className="flex h-6 w-6 items-center justify-center rounded-full text-white"
                    style={{
                        background:
                            row.type === 'overnight'
                                ? 'color-mix(in oklch, var(--primary) 75%, black)'
                                : row.type === 'day'
                                ? 'var(--primary)'
                                : row.type === 'evening'
                                ? 'color-mix(in oklch, var(--primary) 85%, white)'
                                : row.type === 'community'
                                ? 'color-mix(in oklch, var(--primary) 60%, white)'
                                : 'color-mix(in oklch, var(--primary) 80%, transparent)',
                    }}
                >
                    <Icon className="h-3 w-3" />
                </div>
            ) : null}
            <div className="min-w-0">
                <div
                    className={cn(
                        'truncate text-[12px] font-semibold',
                        row.is_open && 'text-[color:var(--status-critical)]',
                    )}
                >
                    {row.label}
                </div>
                <div className="truncate text-[10px] text-muted-foreground">{row.sublabel}</div>
            </div>
        </div>
    );
    return (
        <div className="flex items-center gap-3">
            {row.href ? (
                <Link href={row.href} className="block w-[140px] shrink-0 hover:opacity-80">
                    {labelInner}
                </Link>
            ) : (
                labelInner
            )}
            <div
                className="relative h-7 flex-1 rounded-md bg-muted"
                style={{
                    backgroundImage:
                        'repeating-linear-gradient(to right, transparent 0, transparent calc(100%/24 - 1px), var(--border) calc(100%/24 - 1px), var(--border) calc(100%/24))',
                }}
            >
                {row.bars.map((bar, i) => (
                    <div
                        key={i}
                        title={bar.time_label}
                        className="absolute flex h-full items-center overflow-hidden rounded-md px-2 text-[10px] font-medium"
                        style={{
                            left: `${bar.left}%`,
                            width: `${bar.width}%`,
                            background: barBackground(bar),
                            border: barBorder(bar),
                            color: barColor(bar),
                        }}
                    >
                        <span className="truncate">{bar.label}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

type Props = {
    timeline: TimelineData;
};

type ViewKey = 'site' | 'staff' | 'type';

export function ShiftTimeline({ timeline }: Props) {
    const [view, setView] = useState<ViewKey>('site');

    const rows =
        view === 'site' ? timeline.sites : view === 'staff' ? timeline.staff : timeline.shift_types;

    return (
        <section>
            <GuardrailCard unstyled className="rounded-xl border bg-card" style={{ borderColor: 'var(--border)' }}>
                <div
                    className="flex items-center justify-between border-b px-4 py-3"
                    style={{ borderColor: 'var(--border)' }}
                >
                    <div>
                        <h3 className="text-[14px] font-semibold">Today’s shift timeline</h3>
                        <p className="text-[11px] text-muted-foreground">
                            Live coverage across {rows.length} {view === 'staff' ? 'staff' : view === 'type' ? 'types' : 'sites'} · times in NZST
                        </p>
                    </div>
                    <div className="flex items-center gap-1.5">
                        <div
                            role="tablist"
                            className="inline-flex overflow-hidden rounded-md border"
                            style={{ borderColor: 'var(--border)' }}
                        >
                            {[
                                { v: 'site' as ViewKey, label: 'By site' },
                                { v: 'staff' as ViewKey, label: 'By staff' },
                                { v: 'type' as ViewKey, label: 'By shift type' },
                            ].map((opt, i) => (
                                <GuardrailButton unstyled
                                    key={opt.v}
                                    type="button"
                                    role="tab"
                                    aria-pressed={view === opt.v}
                                    onClick={() => setView(opt.v)}
                                    className={cn(
                                        'px-2.5 py-1 text-[11px] font-medium transition-colors',
                                        i > 0 && 'border-l',
                                        view === opt.v
                                            ? 'bg-accent text-accent-foreground font-semibold'
                                            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                    )}
                                    style={{ borderColor: 'var(--border)' }}
                                >
                                    {opt.label}
                                </GuardrailButton>
                            ))}
                        </div>
                        <Link
                            href="/operations/rostering"
                            className="ml-1 inline-flex items-center gap-0.5 text-[11px] font-semibold text-primary"
                        >
                            Full roster <ArrowRight className="h-3 w-3" />
                        </Link>
                    </div>
                </div>

                <div className="p-4">
                    <div className="mb-1.5 ml-[152px] flex select-none text-[10px] tabular-nums text-muted-foreground">
                        {['00', '03', '06', '09', '12', '15', '18', '21', '24'].map((h) => (
                            <div key={h} className="flex-1 text-center">
                                {h}
                            </div>
                        ))}
                    </div>

                    <div className="relative space-y-2">
                        {/* Now indicator */}
                        <div
                            className="pointer-events-none absolute bottom-0 top-0 z-10"
                            style={{ left: `calc(152px + (100% - 152px) * ${timeline.now_pct})` }}
                        >
                            <div
                                className="absolute -top-1 -translate-x-1/2 rounded px-1.5 py-0.5 text-[9px] font-bold tabular-nums text-white"
                                style={{ background: 'var(--primary)' }}
                            >
                                {timeline.now_label}
                            </div>
                            <div
                                className="h-full"
                                style={{ marginLeft: '-1px', width: '2px', background: 'var(--primary)' }}
                            />
                        </div>

                        {rows.length === 0 ? (
                            <div className="rounded-md border border-dashed py-6 text-center text-[12px] text-muted-foreground">
                                No shifts scheduled for today.
                            </div>
                        ) : (
                            rows.map((row) => <TimelineRowView key={row.key} row={row} hasNowLine />)
                        )}
                    </div>

                    <div
                        className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 border-t pt-3 text-[10.5px] text-muted-foreground"
                        style={{ borderColor: 'var(--border)' }}
                    >
                        <span className="inline-flex items-center gap-1.5">
                            <span
                                className="h-2.5 w-2.5 rounded"
                                style={{ background: 'color-mix(in oklch, var(--primary) 75%, black)' }}
                            />
                            Overnight
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                            <span className="h-2.5 w-2.5 rounded" style={{ background: 'var(--primary)' }} />
                            Day
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                            <span
                                className="h-2.5 w-2.5 rounded"
                                style={{ background: 'color-mix(in oklch, var(--primary) 85%, white)' }}
                            />
                            Evening
                        </span>
                        <span className="inline-flex items-center gap-1.5">
                            <span
                                className="h-2.5 w-2.5 rounded border border-dashed"
                                style={{ background: 'color-mix(in oklch, var(--primary) 60%, transparent)' }}
                            />
                            Unassigned
                        </span>
                        <span className="ml-auto inline-flex items-center gap-1.5">
                            <span className="h-2.5 w-2.5 rounded" style={{ background: 'var(--status-critical)' }} />
                            Conflict/breach
                        </span>
                    </div>
                </div>
            </GuardrailCard>
        </section>
    );
}
