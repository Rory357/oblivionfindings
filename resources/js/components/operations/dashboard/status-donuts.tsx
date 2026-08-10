import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Card as GuardrailCard } from '@/components/ui/card';
import { cn } from '@/lib/utils';

export type DonutSegment = {
    key: string;
    label: string;
    value: number;
    color: string;
    href: string;
};

function InteractiveDonut({
    title,
    viewAllHref,
    viewAllLabel,
    segments,
    centerLabel = 'total',
}: {
    title: string;
    viewAllHref: string;
    viewAllLabel: string;
    segments: DonutSegment[];
    centerLabel?: string;
}) {
    const total = segments.reduce((sum, s) => sum + s.value, 0);
    const [hover, setHover] = useState<string | null>(null);

    const computed = useMemo(() => {
        let cum = 0;
        return segments.map((s) => {
            const pct = total > 0 ? (s.value / total) * 100 : 0;
            const dashArr = `${pct.toFixed(2)} ${(100 - pct).toFixed(2)}`;
            const dashOff = (25 - cum + 100) % 100;
            cum += pct;
            return { ...s, pct, dashArr, dashOff };
        });
    }, [segments, total]);

    const activeSeg = hover ? computed.find((s) => s.key === hover) : null;
    const centerValue = activeSeg
        ? activeSeg.value.toLocaleString()
        : total.toLocaleString();
    const centerSub = activeSeg ? activeSeg.label : centerLabel;

    return (
        <GuardrailCard
            unstyled
            className="rounded-xl border bg-card p-4"
            style={{ borderColor: 'var(--border)' }}
        >
            <div className="flex items-center justify-between">
                <h3 className="text-[13px] font-semibold">{title}</h3>
                <Link
                    href={viewAllHref}
                    className="inline-flex items-center gap-0.5 text-[11px] font-medium text-primary"
                >
                    {viewAllLabel} <ArrowRight className="h-3 w-3" />
                </Link>
            </div>
            <div className="mt-3 flex items-center gap-5">
                <svg viewBox="0 0 36 36" className="h-28 w-28 shrink-0">
                    <circle
                        cx="18"
                        cy="18"
                        r="15.9155"
                        fill="none"
                        stroke="var(--muted)"
                        strokeWidth="3.5"
                    />
                    {computed.map((s) => (
                        <Link key={s.key} href={s.href}>
                            <circle
                                cx="18"
                                cy="18"
                                r="15.9155"
                                fill="none"
                                stroke={s.color}
                                strokeWidth={hover === s.key ? 4.5 : 3.5}
                                strokeDasharray={s.dashArr}
                                strokeDashoffset={s.dashOff}
                                transform="rotate(-90 18 18)"
                                style={{
                                    cursor: 'pointer',
                                    transition: 'stroke-width .15s ease',
                                }}
                                onMouseEnter={() => setHover(s.key)}
                                onMouseLeave={() => setHover(null)}
                            />
                        </Link>
                    ))}
                    <text
                        x="18"
                        y="18"
                        textAnchor="middle"
                        dominantBaseline="central"
                        fontSize={centerValue.length > 4 ? '5' : '6'}
                        fontWeight="700"
                        fill="var(--foreground)"
                    >
                        {centerValue}
                    </text>
                    <text
                        x="18"
                        y="23"
                        textAnchor="middle"
                        dominantBaseline="central"
                        fontSize="2.6"
                        fill="var(--muted-foreground)"
                    >
                        {centerSub}
                    </text>
                </svg>
                <div className="min-w-0 flex-1 space-y-1 text-[11.5px]">
                    {computed.map((s) => (
                        <Link
                            key={s.key}
                            href={s.href}
                            onMouseEnter={() => setHover(s.key)}
                            onMouseLeave={() => setHover(null)}
                            className={cn(
                                'flex items-center gap-1.5 rounded px-1.5 py-1 hover:bg-muted/60',
                                hover === s.key && 'bg-muted/60',
                            )}
                        >
                            <span
                                className="h-2 w-2 rounded-full"
                                style={{ background: s.color }}
                            />
                            <span className="text-muted-foreground capitalize">
                                {s.label}
                            </span>
                            <span className="ml-auto font-semibold tabular-nums">
                                {s.value.toLocaleString()}
                            </span>
                            <span className="w-10 text-right text-[10px] text-muted-foreground tabular-nums">
                                {s.pct.toFixed(s.pct >= 10 ? 0 : 1)}%
                            </span>
                        </Link>
                    ))}
                </div>
            </div>
        </GuardrailCard>
    );
}

type Props = {
    clientStatus: Record<string, number>;
    shiftStatus: Record<string, number>;
};

const CLIENT_COLOR: Record<string, string> = {
    active: 'var(--status-success)',
    on_hold: 'var(--status-warning)',
    discharged: 'var(--muted-foreground)',
    onboarding: 'var(--primary)',
    inactive: 'var(--muted-foreground)',
};

const SHIFT_COLOR: Record<string, string> = {
    scheduled: 'var(--primary)',
    in_progress: 'oklch(0.65 0.18 200)',
    completed: 'var(--status-success)',
    cancelled: 'var(--status-critical)',
    draft: 'var(--muted-foreground)',
};

function entriesToSegments(
    rec: Record<string, number>,
    palette: Record<string, string>,
    baseHref: string,
): DonutSegment[] {
    return Object.entries(rec)
        .filter(([, v]) => v > 0)
        .map(([key, value]) => ({
            key,
            label: key.replace(/_/g, ' '),
            value,
            color: palette[key] ?? 'var(--muted-foreground)',
            href: `${baseHref}${baseHref.includes('?') ? '&' : '?'}status=${key}`,
        }));
}

export function StatusDonuts({ clientStatus, shiftStatus }: Props) {
    const clientSegs = entriesToSegments(
        clientStatus,
        CLIENT_COLOR,
        '/operations/clients',
    );
    const shiftSegs = entriesToSegments(
        shiftStatus,
        SHIFT_COLOR,
        '/operations/shifts',
    );
    return (
        <section className="grid gap-4 md:grid-cols-2">
            <InteractiveDonut
                title="Client status"
                viewAllHref="/operations/clients"
                viewAllLabel="All clients"
                segments={clientSegs}
            />
            <InteractiveDonut
                title="Shifts this week"
                viewAllHref="/operations/shifts"
                viewAllLabel="View shifts"
                segments={shiftSegs}
            />
        </section>
    );
}
