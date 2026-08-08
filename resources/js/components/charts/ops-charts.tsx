/**
 * Ops chart primitives — extracted from ops-stat-card.tsx so the stat card
 * itself can be a thin shim around the unified StatTile component.
 *
 * Consumers should prefer importing from this module directly. The old paths
 * (`@/components/ops-stat-card`) continue to re-export these for backwards
 * compatibility with ~50 dashboard pages.
 */

export const OPS_COLORS = {
    primary: '#6366f1', // indigo-500
    primaryLight: '#818cf8', // indigo-400
    secondary: '#3b82f6', // blue-500
    accent: '#06b6d4', // cyan-500
    warning: '#f59e0b', // amber-500
    danger: '#ef4444', // red-500
    success: '#10b981', // emerald-500
    neutral: '#64748b', // slate-500
    muted: '#94a3b8', // slate-400
    purple: '#8b5cf6', // violet-500
};

/* ── Sparkline ────────────────────────────────────────────────────── */

export function SparklineChart({
    data,
    color,
    height = 20,
    width = 80,
}: {
    data: number[];
    color: string;
    height?: number;
    width?: number;
}) {
    if (!data || data.length < 2) return null;
    const max = Math.max(...data);
    const min = Math.min(...data);
    const range = max - min || 1;
    const step = width / (data.length - 1);
    const points = data
        .map((v, i) => `${i * step},${height - ((v - min) / range) * height}`)
        .join(' ');
    return (
        <svg
            width={width}
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            className="shrink-0"
        >
            <polyline
                fill="none"
                stroke={color}
                strokeWidth={1.5}
                strokeLinecap="round"
                strokeLinejoin="round"
                points={points}
            />
        </svg>
    );
}

/* ── Donut Chart ──────────────────────────────────────────────────── */

export type DonutSegment = {
    label: string;
    value: number;
    color: string;
};

export function DonutChart({
    segments,
    size = 140,
    strokeWidth = 18,
    centerLabel,
    centerValue,
}: {
    segments: DonutSegment[];
    size?: number;
    strokeWidth?: number;
    centerLabel?: string;
    centerValue?: string | number;
}) {
    const radius = (size - strokeWidth) / 2;
    const circumference = 2 * Math.PI * radius;
    const total = segments.reduce((sum, s) => sum + s.value, 0);

    if (total === 0) {
        return (
            <div
                className="flex flex-col items-center justify-center"
                style={{ width: size, height: size }}
            >
                <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
                    <circle
                        cx={size / 2}
                        cy={size / 2}
                        r={radius}
                        fill="none"
                        stroke="currentColor"
                        strokeWidth={strokeWidth}
                        className="text-muted/20"
                    />
                    <text
                        x="50%"
                        y="50%"
                        textAnchor="middle"
                        dominantBaseline="central"
                        className="fill-muted-foreground text-xs"
                    >
                        No data
                    </text>
                </svg>
            </div>
        );
    }

    let offset = 0;
    return (
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
            <circle
                cx={size / 2}
                cy={size / 2}
                r={radius}
                fill="none"
                stroke="currentColor"
                strokeWidth={strokeWidth}
                className="text-muted/10"
            />
            {segments
                .filter((s) => s.value > 0)
                .map((segment, i) => {
                    const pct = segment.value / total;
                    const dashLength = pct * circumference;
                    const dashGap = circumference - dashLength;
                    const rotation = (offset / total) * 360 - 90;
                    offset += segment.value;
                    return (
                        <circle
                            key={i}
                            cx={size / 2}
                            cy={size / 2}
                            r={radius}
                            fill="none"
                            stroke={segment.color}
                            strokeWidth={strokeWidth}
                            strokeDasharray={`${dashLength} ${dashGap}`}
                            strokeLinecap="butt"
                            transform={`rotate(${rotation} ${size / 2} ${size / 2})`}
                        />
                    );
                })}
            {centerValue !== undefined && (
                <>
                    <text
                        x="50%"
                        y="46%"
                        textAnchor="middle"
                        dominantBaseline="central"
                        className="fill-foreground text-2xl font-bold"
                        style={{ fontSize: 22, fontWeight: 700 }}
                    >
                        {centerValue}
                    </text>
                    {centerLabel && (
                        <text
                            x="50%"
                            y="64%"
                            textAnchor="middle"
                            dominantBaseline="central"
                            className="fill-muted-foreground"
                            style={{
                                fontSize: 9,
                                textTransform: 'uppercase',
                                letterSpacing: '0.05em',
                            }}
                        >
                            {centerLabel}
                        </text>
                    )}
                </>
            )}
        </svg>
    );
}

/* ── Bar Chart ────────────────────────────────────────────────────── */

export function BarChart({
    data,
    height = 120,
    barColor = OPS_COLORS.primary,
}: {
    data: { label: string; value: number }[];
    height?: number;
    barColor?: string;
}) {
    if (!data || data.length === 0) return null;
    const max = Math.max(...data.map((d) => d.value), 1);
    const barWidth = Math.min(
        40,
        Math.max(16, Math.floor(280 / data.length) - 8),
    );

    return (
        <div
            className="flex items-end justify-between gap-1"
            style={{ height }}
        >
            {data.map((d, i) => (
                <div key={i} className="flex flex-col items-center gap-1">
                    <span className="text-[9px] text-muted-foreground tabular-nums">
                        {d.value}
                    </span>
                    <div
                        className="rounded-t transition-all duration-300"
                        style={{
                            width: barWidth,
                            height: Math.max(
                                4,
                                (d.value / max) * (height - 28),
                            ),
                            backgroundColor: barColor,
                            opacity: 0.85,
                        }}
                    />
                    <span className="text-[9px] text-muted-foreground">
                        {d.label}
                    </span>
                </div>
            ))}
        </div>
    );
}
