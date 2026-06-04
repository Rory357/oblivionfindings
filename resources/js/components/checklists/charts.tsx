// Lightweight SVG chart primitives for the Overview + Reports panes.
import { type ReactNode, useId } from 'react';

export function Donut({
    value,
    size = 132,
    stroke = 13,
    color = 'var(--primary)',
    track = 'var(--muted)',
    label,
    sub,
    valueSuffix = '%',
}: {
    value: number;
    size?: number;
    stroke?: number;
    color?: string;
    track?: string;
    label?: string;
    sub?: string;
    valueSuffix?: string;
}) {
    const r = (size - stroke) / 2;
    const c = 2 * Math.PI * r;
    const off = c * (1 - Math.min(100, Math.max(0, value)) / 100);
    return (
        <div className="relative" style={{ width: size, height: size }}>
            <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
                <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={track} strokeWidth={stroke} />
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={r}
                    fill="none"
                    stroke={color}
                    strokeWidth={stroke}
                    strokeLinecap="round"
                    strokeDasharray={c}
                    strokeDashoffset={off}
                    transform={`rotate(-90 ${size / 2} ${size / 2})`}
                    style={{ transition: 'stroke-dashoffset .6s ease' }}
                />
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">
                <div className="font-bold leading-none tabular-nums" style={{ fontSize: size * 0.26 }}>
                    {value}
                    <span style={{ fontSize: size * 0.14 }}>{valueSuffix}</span>
                </div>
                {label ? <div className="mt-1 text-[11px] font-medium text-muted-foreground">{label}</div> : null}
                {sub ? <div className="text-[10px] text-muted-foreground/80">{sub}</div> : null}
            </div>
        </div>
    );
}

export interface DonutSegment {
    key: string;
    label: string;
    value: number;
    color: string;
}

export function SegmentDonut({
    segments,
    size = 150,
    stroke = 16,
    centerValue,
    centerLabel,
}: {
    segments: DonutSegment[];
    size?: number;
    stroke?: number;
    centerValue: ReactNode;
    centerLabel: string;
}) {
    const r = (size - stroke) / 2;
    const c = 2 * Math.PI * r;
    const total = segments.reduce((s, x) => s + x.value, 0) || 1;
    let acc = 0;
    return (
        <div className="relative" style={{ width: size, height: size }}>
            <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
                <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="var(--muted)" strokeWidth={stroke} />
                {segments.map((seg) => {
                    const frac = seg.value / total;
                    const dash = frac * c;
                    const gap = 0.012 * c;
                    const el = (
                        <circle
                            key={seg.key}
                            cx={size / 2}
                            cy={size / 2}
                            r={r}
                            fill="none"
                            stroke={seg.color}
                            strokeWidth={stroke}
                            strokeLinecap="butt"
                            strokeDasharray={`${Math.max(dash - gap, 0)} ${c - Math.max(dash - gap, 0)}`}
                            strokeDashoffset={-acc * c}
                            transform={`rotate(-90 ${size / 2} ${size / 2})`}
                            style={{ transition: 'stroke-dasharray .6s ease' }}
                        />
                    );
                    acc += frac;
                    return el;
                })}
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">
                <div className="text-2xl font-bold leading-none tabular-nums">{centerValue}</div>
                <div className="mt-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                    {centerLabel}
                </div>
            </div>
        </div>
    );
}

export function Sparkline({
    series,
    w = 260,
    h = 64,
    color = 'var(--primary)',
    fill = true,
}: {
    series: number[];
    w?: number;
    h?: number;
    color?: string;
    fill?: boolean;
}) {
    const id = useId();
    const max = Math.max(...series, 1);
    const step = series.length > 1 ? w / (series.length - 1) : w;
    const pts = series.map((v, i) => [i * step, h - (v / max) * (h - 6) - 3] as const);
    const line = pts.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)} ${p[1].toFixed(1)}`).join(' ');
    const area = `${line} L ${w} ${h} L 0 ${h} Z`;
    return (
        <svg
            width="100%"
            height={h}
            viewBox={`0 0 ${w} ${h}`}
            preserveAspectRatio="none"
            className="overflow-visible"
        >
            {fill ? (
                <defs>
                    <linearGradient id={id} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor={color} stopOpacity={0.28} />
                        <stop offset="100%" stopColor={color} stopOpacity={0} />
                    </linearGradient>
                </defs>
            ) : null}
            {fill ? <path d={area} fill={`url(#${id})`} /> : null}
            <path d={line} fill="none" stroke={color} strokeWidth={2.5} strokeLinejoin="round" strokeLinecap="round" />
            {pts.length ? (
                <circle cx={pts[pts.length - 1][0]} cy={pts[pts.length - 1][1]} r={3.5} fill={color} />
            ) : null}
        </svg>
    );
}

export function MiniRing({
    value,
    size = 40,
    stroke = 5,
    color,
}: {
    value: number;
    size?: number;
    stroke?: number;
    color?: string;
}) {
    const r = (size - stroke) / 2;
    const c = 2 * Math.PI * r;
    const off = c * (1 - value / 100);
    const col =
        color || (value >= 95 ? 'var(--status-success)' : value >= 85 ? 'var(--primary)' : 'var(--status-warning)');
    return (
        <div className="relative shrink-0" style={{ width: size, height: size }}>
            <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
                <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="var(--muted)" strokeWidth={stroke} />
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={r}
                    fill="none"
                    stroke={col}
                    strokeWidth={stroke}
                    strokeLinecap="round"
                    strokeDasharray={c}
                    strokeDashoffset={off}
                    transform={`rotate(-90 ${size / 2} ${size / 2})`}
                />
            </svg>
            <div className="absolute inset-0 flex items-center justify-center text-[9px] font-bold tabular-nums">
                {value}
            </div>
        </div>
    );
}

export function LegendDot({ color, label, value }: { color: string; label: string; value?: ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-2 text-xs">
            <span className="flex min-w-0 items-center gap-1.5">
                <span className="h-2.5 w-2.5 shrink-0 rounded-sm" style={{ background: color }} />
                <span className="truncate text-muted-foreground">{label}</span>
            </span>
            {value != null ? <span className="shrink-0 font-semibold tabular-nums">{value}</span> : null}
        </div>
    );
}
