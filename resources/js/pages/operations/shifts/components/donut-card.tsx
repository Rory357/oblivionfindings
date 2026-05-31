import { cn } from '@/lib/utils';
import { useState } from 'react';

export type DonutSegment = {
    key: string;
    label: string;
    value: number;
    color: string;
};

type DonutTone = 'primary' | 'warning' | 'success' | 'critical';

type Props = {
    tone: DonutTone;
    title: string;
    subtitle: string;
    segments: DonutSegment[];
    centerValue: number | string;
    centerLabel: string;
    /** Legend keys to emphasise (bold + foreground) — draws the eye to at-risk segments. */
    accentKeys?: string[];
    cta?: string;
    active?: boolean;
    onClick?: () => void;
};

const DONUT_TONE: Record<
    DonutTone,
    { bar: string; border: string; ring: string }
> = {
    primary: {
        bar: 'bg-primary/60 group-hover:bg-primary',
        border: 'border-primary/60',
        ring: 'ring-primary/15',
    },
    warning: {
        bar: 'bg-status-warning/60 group-hover:bg-status-warning',
        border: 'border-status-warning/60',
        ring: 'ring-status-warning/15',
    },
    success: {
        bar: 'bg-status-success/60 group-hover:bg-status-success',
        border: 'border-status-success/60',
        ring: 'ring-status-success/15',
    },
    critical: {
        bar: 'bg-status-critical/60 group-hover:bg-status-critical',
        border: 'border-status-critical/60',
        ring: 'ring-status-critical/15',
    },
};

function Donut({
    segments,
    size = 124,
    thickness = 14,
    centerValue,
    centerLabel,
}: {
    segments: DonutSegment[];
    size?: number;
    thickness?: number;
    centerValue: number | string;
    centerLabel: string;
}) {
    const [hover, setHover] = useState<number | null>(null);
    const total = segments.reduce((sum, s) => sum + s.value, 0) || 1;
    const radius = (size - thickness) / 2;
    const circumference = 2 * Math.PI * radius;
    const center = size / 2;
    const gap = 1.5;
    const usable = Math.max(circumference - segments.length * gap, 0);

    let cursor = 0;
    const arcs = segments.map((seg, i) => {
        const len = (seg.value / total) * usable;
        const arc = {
            ...seg,
            idx: i,
            dash: `${len} ${circumference - len}`,
            offset: -cursor,
        };
        cursor += len + gap;
        return arc;
    });

    return (
        <div className="relative inline-block">
            <svg
                width={size}
                height={size}
                viewBox={`0 0 ${size} ${size}`}
                role="img"
                aria-label={centerLabel}
            >
                <circle
                    cx={center}
                    cy={center}
                    r={radius}
                    fill="none"
                    stroke="color-mix(in oklch, var(--border) 60%, transparent)"
                    strokeWidth={thickness}
                />
                <g transform={`rotate(-90 ${center} ${center})`}>
                    {arcs.map((a) => (
                        <circle
                            key={a.key}
                            cx={center}
                            cy={center}
                            r={radius}
                            fill="none"
                            stroke={a.color}
                            strokeWidth={
                                hover === a.idx ? thickness + 3 : thickness
                            }
                            strokeDasharray={a.dash}
                            strokeDashoffset={a.offset}
                            strokeLinecap="butt"
                            onMouseEnter={() => setHover(a.idx)}
                            onMouseLeave={() => setHover(null)}
                            style={{
                                transition:
                                    'stroke-width 160ms ease, opacity 160ms ease',
                                opacity:
                                    hover !== null && hover !== a.idx ? 0.5 : 1,
                                cursor: 'pointer',
                            }}
                        />
                    ))}
                </g>
                <foreignObject x={0} y={0} width={size} height={size}>
                    <div className="flex h-full w-full flex-col items-center justify-center text-center leading-none">
                        <div className="text-[26px] font-extrabold tracking-tight text-foreground tabular-nums">
                            {centerValue}
                        </div>
                        <div className="mt-1 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                            {centerLabel}
                        </div>
                    </div>
                </foreignObject>
            </svg>
        </div>
    );
}

function DonutLegend({
    segments,
    accentKeys,
}: {
    segments: DonutSegment[];
    accentKeys?: string[];
}) {
    return (
        <ul className="mt-1 space-y-1.5">
            {segments.map((s) => {
                const accent = accentKeys?.includes(s.key);
                return (
                    <li
                        key={s.key}
                        className={cn(
                            'flex items-center gap-2 text-xs',
                            accent
                                ? 'font-semibold text-foreground'
                                : 'text-muted-foreground',
                        )}
                    >
                        <span
                            className="inline-block h-2.5 w-2.5 shrink-0 rounded-sm"
                            style={{ background: s.color }}
                        />
                        <span className="flex-1 truncate">{s.label}</span>
                        <span className="tabular-nums">{s.value}</span>
                    </li>
                );
            })}
        </ul>
    );
}

export function DonutCard({
    tone,
    title,
    subtitle,
    segments,
    centerValue,
    centerLabel,
    accentKeys,
    cta,
    active = false,
    onClick,
}: Props) {
    const t = DONUT_TONE[tone];

    return (
        // eslint-disable-next-line no-restricted-syntax -- The whole donut card is a single clickable selector surface (matches the redesign).
        <button
            type="button"
            onClick={onClick}
            data-active={active}
            className={cn(
                'group relative cursor-pointer overflow-hidden rounded-[14px] border border-border bg-card p-4 pl-5 text-left transition hover:-translate-y-px hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                active && `ring-4 ${t.border} ${t.ring}`,
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'absolute inset-y-0 left-0 w-1 rounded-l-[14px] transition-colors',
                    t.bar,
                )}
            />
            <div className="grid grid-cols-[124px_1fr] items-center gap-4">
                <Donut
                    segments={segments}
                    centerValue={centerValue}
                    centerLabel={centerLabel}
                />
                <div className="min-w-0">
                    <div className="text-sm font-bold tracking-tight text-foreground">
                        {title}
                    </div>
                    <div className="mb-2 text-xs text-muted-foreground">
                        {subtitle}
                    </div>
                    <DonutLegend segments={segments} accentKeys={accentKeys} />
                    {cta ? (
                        <div className="mt-3 flex items-center gap-1 text-xs font-semibold text-muted-foreground transition-colors group-hover:text-foreground">
                            <span>{cta}</span>
                            <span
                                aria-hidden
                                className="transition-transform group-hover:translate-x-1"
                            >
                                →
                            </span>
                        </div>
                    ) : null}
                </div>
            </div>
        </button>
    );
}
