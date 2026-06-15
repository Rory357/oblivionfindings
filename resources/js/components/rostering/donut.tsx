import { useState, type ReactNode } from 'react';

import { cn } from '@/lib/utils';

export type DonutSegment = {
    key: string;
    label: string;
    value: number;
    color: string;
};

export type DonutLegendProps = {
    segments: DonutSegment[];
    accentKeys?: string[];
    className?: string;
    /**
     * Optional formatter for the per-row value (e.g. money). Defaults to the
     * raw number — additive so existing callers (rostering) are unchanged.
     */
    formatValue?: (value: number) => ReactNode;
    /** Show each segment's share of the total as a right-aligned percent. */
    showPercent?: boolean;
};

export type DonutProps = {
    segments: DonutSegment[];
    size?: number;
    thickness?: number;
    centerValue: string | number;
    centerLabel: string;
    ariaLabel?: string;
    className?: string;
};

export function Donut({
    segments,
    size = 132,
    thickness = 14,
    centerValue,
    centerLabel,
    ariaLabel,
    className,
}: DonutProps) {
    const [hover, setHover] = useState<number | null>(null);

    const total = segments.reduce((sum, s) => sum + s.value, 0) || 1;
    const r = (size - thickness) / 2;
    const c = 2 * Math.PI * r;
    const cx = size / 2;
    const cy = size / 2;
    const gap = 1.5;
    const usableC = Math.max(c - segments.length * gap, 0);

    let cursor = 0;
    const arcs = segments.map((seg, i) => {
        const len = (seg.value / total) * usableC;
        const arc = {
            ...seg,
            idx: i,
            dash: `${len} ${c - len}`,
            offset: -cursor,
        };
        cursor += len + gap;
        return arc;
    });

    return (
        <div className={cn('relative inline-block', className)}>
            <svg
                width={size}
                height={size}
                viewBox={`0 0 ${size} ${size}`}
                role="img"
                aria-label={ariaLabel ?? centerLabel}
            >
                <circle
                    cx={cx}
                    cy={cy}
                    r={r}
                    fill="none"
                    stroke="var(--ring-track, color-mix(in oklch, var(--border) 60%, transparent))"
                    strokeWidth={thickness}
                />
                <g transform={`rotate(-90 ${cx} ${cy})`}>
                    {arcs.map((a) => (
                        <circle
                            key={a.key}
                            cx={cx}
                            cy={cy}
                            r={r}
                            fill="none"
                            stroke={a.color}
                            strokeWidth={hover === a.idx ? thickness + 3 : thickness}
                            strokeDasharray={a.dash}
                            strokeDashoffset={a.offset}
                            strokeLinecap="butt"
                            onMouseEnter={() => setHover(a.idx)}
                            onMouseLeave={() => setHover(null)}
                            style={{
                                transition:
                                    'stroke-width 160ms ease, opacity 160ms ease',
                                opacity:
                                    hover !== null && hover !== a.idx ? 0.55 : 1,
                                cursor: 'pointer',
                            }}
                        />
                    ))}
                </g>
                <foreignObject x={0} y={0} width={size} height={size}>
                    <div className="flex h-full w-full flex-col items-center justify-center text-center leading-none">
                        <div className="text-[26px] font-extrabold tracking-tight tabular-nums text-muted-foreground">
                            {centerValue}
                        </div>
                        <div className="mt-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground/80">
                            {centerLabel}
                        </div>
                    </div>
                </foreignObject>
            </svg>
        </div>
    );
}

export function DonutLegend({
    segments,
    accentKeys,
    className,
    formatValue,
    showPercent,
}: DonutLegendProps) {
    const total = segments.reduce((sum, s) => sum + s.value, 0) || 1;
    return (
        <ul className={cn('mt-1 space-y-1.5', className)}>
            {segments.map((s) => {
                const isAccent = accentKeys?.includes(s.key);
                return (
                    <li
                        key={s.key}
                        className={cn(
                            'flex items-center gap-2 text-xs',
                            isAccent
                                ? 'font-semibold text-muted-foreground'
                                : 'text-muted-foreground/80',
                        )}
                    >
                        <span
                            className="inline-block h-2.5 w-2.5 shrink-0 rounded-sm"
                            style={{ background: s.color }}
                        />
                        <span className="flex-1 truncate">{s.label}</span>
                        <span className="tabular-nums">
                            {formatValue ? formatValue(s.value) : s.value}
                        </span>
                        {showPercent ? (
                            <span className="w-9 shrink-0 text-right tabular-nums text-muted-foreground/60">
                                {Math.round((s.value / total) * 100)}%
                            </span>
                        ) : null}
                    </li>
                );
            })}
        </ul>
    );
}

export default Donut;
