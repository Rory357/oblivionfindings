/**
 * Reusable Fleet Chart Components (Pure SVG, no external dependencies)
 */

export const FLEET_COLORS = {
    primary: '#7c3aed',      // purple-600
    primaryLight: '#a78bfa',  // purple-400
    primaryDark: '#5b21b6',   // purple-800
    secondary: '#3b82f6',     // blue-500
    accent: '#06b6d4',        // cyan-500
    warning: '#f59e0b',       // amber-500
    danger: '#ef4444',        // red-500
    success: '#10b981',       // emerald-500 (only for status dots)
    neutral: '#64748b',       // slate-500
    muted: '#94a3b8',         // slate-400
};

/* ------------------------------------------------------------------ */
/*  HalfMoonGauge - Semi-circle gauge                                  */
/* ------------------------------------------------------------------ */

export function HalfMoonGauge({
    value,
    label,
    sublabel,
    size = 160,
    color = FLEET_COLORS.primary,
}: {
    value: number;
    label?: string;
    sublabel?: string;
    size?: number;
    color?: string;
}) {
    const clampedValue = Math.max(0, Math.min(100, value));
    const strokeWidth = size * 0.1;
    const radius = (size - strokeWidth) / 2;
    const cx = size / 2;
    const cy = size / 2 + size * 0.05;

    // Semi-circle path from left to right (180 degrees)
    const startAngle = Math.PI;
    const endAngle = 0;
    const totalAngle = Math.PI;
    const filledAngle = totalAngle * (clampedValue / 100);

    const bgStartX = cx + radius * Math.cos(startAngle);
    const bgStartY = cy + radius * Math.sin(startAngle);
    const bgEndX = cx + radius * Math.cos(endAngle);
    const bgEndY = cy + radius * Math.sin(endAngle);

    const filledEndAngle = startAngle - filledAngle;
    const filledEndX = cx + radius * Math.cos(filledEndAngle);
    const filledEndY = cy + radius * Math.sin(filledEndAngle);
    const largeArc = clampedValue > 50 ? 1 : 0;

    const bgPath = `M ${bgStartX} ${bgStartY} A ${radius} ${radius} 0 1 1 ${bgEndX} ${bgEndY}`;
    const filledPath = clampedValue > 0
        ? `M ${bgStartX} ${bgStartY} A ${radius} ${radius} 0 ${largeArc} 1 ${filledEndX} ${filledEndY}`
        : '';

    const viewHeight = size * 0.65;

    return (
        <div className="flex flex-col items-center">
            <svg width={size} height={viewHeight} viewBox={`0 0 ${size} ${viewHeight + strokeWidth}`}>
                {/* Background arc */}
                <path
                    d={bgPath}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth={strokeWidth}
                    strokeLinecap="round"
                    className="text-muted/20"
                />
                {/* Filled arc */}
                {filledPath && (
                    <path
                        d={filledPath}
                        fill="none"
                        stroke={color}
                        strokeWidth={strokeWidth}
                        strokeLinecap="round"
                    />
                )}
                {/* Value text */}
                <text
                    x={cx}
                    y={cy - size * 0.02}
                    textAnchor="middle"
                    dominantBaseline="central"
                    className="fill-foreground"
                    style={{ fontSize: size * 0.2, fontWeight: 700 }}
                >
                    {Math.round(clampedValue)}%
                </text>
            </svg>
            {label && (
                <span className="text-sm font-medium text-foreground -mt-1">{label}</span>
            )}
            {sublabel && (
                <span className="text-xs text-muted-foreground mt-0.5">{sublabel}</span>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  SparklineChart - Tiny line chart for trends                        */
/* ------------------------------------------------------------------ */

export function SparklineChart({
    data,
    color = FLEET_COLORS.primary,
    height = 32,
    width = 80,
}: {
    data: number[];
    color?: string;
    height?: number;
    width?: number;
}) {
    if (!data || data.length < 2) {
        return <svg width={width} height={height} />;
    }

    const padding = 2;
    const maxVal = Math.max(...data, 1);
    const minVal = Math.min(...data, 0);
    const range = maxVal - minVal || 1;

    const points = data.map((v, i) => {
        const x = padding + (i / (data.length - 1)) * (width - padding * 2);
        const y = padding + (1 - (v - minVal) / range) * (height - padding * 2);
        return `${x},${y}`;
    });

    const polyline = points.join(' ');
    const areaPath = `M ${padding},${height - padding} L ${points.join(' L ')} L ${width - padding},${height - padding} Z`;

    return (
        <svg width={width} height={height} viewBox={`0 0 ${width} ${height}`}>
            <defs>
                <linearGradient id={`sparkGrad-${color.replace('#', '')}`} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={color} stopOpacity="0.3" />
                    <stop offset="100%" stopColor={color} stopOpacity="0.02" />
                </linearGradient>
            </defs>
            <path d={areaPath} fill={`url(#sparkGrad-${color.replace('#', '')})`} />
            <polyline
                points={polyline}
                fill="none"
                stroke={color}
                strokeWidth="1.5"
                strokeLinejoin="round"
                strokeLinecap="round"
            />
        </svg>
    );
}

/* ------------------------------------------------------------------ */
/*  HorizontalBarChart                                                 */
/* ------------------------------------------------------------------ */

export function HorizontalBarChart({
    items,
    heightPerBar = 28,
    color = FLEET_COLORS.primary,
}: {
    items: Array<{ label: string; value: number; color?: string; maxValue?: number }>;
    heightPerBar?: number;
    color?: string;
}) {
    if (!items || items.length === 0) {
        return <p className="py-4 text-center text-sm text-muted-foreground">No data available.</p>;
    }

    const maxValue = Math.max(...items.map((item) => item.maxValue ?? item.value), 1);

    return (
        <div className="space-y-2">
            {items.map((item, i) => {
                const pct = Math.max((item.value / maxValue) * 100, 3);
                const barColor = item.color ?? color;
                return (
                    <div key={i}>
                        <div className="flex items-center justify-between text-xs mb-0.5">
                            <span className="truncate text-muted-foreground max-w-[60%]">{item.label}</span>
                            <span className="font-medium tabular-nums ml-2">{typeof item.value === 'number' && item.value % 1 !== 0 ? item.value.toFixed(1) : item.value}</span>
                        </div>
                        <div className="h-2 w-full rounded-full bg-muted/30">
                            <div
                                className="h-full rounded-full transition-all"
                                style={{ width: `${pct}%`, backgroundColor: barColor }}
                            />
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  ProgressRing - Thin ring with percentage                           */
/* ------------------------------------------------------------------ */

export function ProgressRing({
    value,
    size = 100,
    color = FLEET_COLORS.primary,
    label,
}: {
    value: number;
    size?: number;
    color?: string;
    label?: string;
}) {
    const clampedValue = Math.max(0, Math.min(100, value));
    const strokeWidth = size * 0.06;
    const radius = (size - strokeWidth * 2) / 2;
    const circumference = 2 * Math.PI * radius;
    const dashLength = (clampedValue / 100) * circumference;

    return (
        <div className="flex flex-col items-center">
            <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
                {/* Background ring */}
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth={strokeWidth}
                    className="text-muted/20"
                />
                {/* Filled ring */}
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    stroke={color}
                    strokeWidth={strokeWidth}
                    strokeDasharray={`${dashLength} ${circumference - dashLength}`}
                    strokeLinecap="round"
                    transform={`rotate(-90 ${size / 2} ${size / 2})`}
                />
                {/* Value */}
                <text
                    x="50%"
                    y="50%"
                    textAnchor="middle"
                    dominantBaseline="central"
                    className="fill-foreground"
                    style={{ fontSize: size * 0.22, fontWeight: 700 }}
                >
                    {Math.round(clampedValue)}%
                </text>
            </svg>
            {label && (
                <span className="text-xs text-muted-foreground mt-1">{label}</span>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  MiniBarChart - Small vertical bar chart                            */
/* ------------------------------------------------------------------ */

export function MiniBarChart({
    data,
    color = FLEET_COLORS.primary,
    height = 120,
}: {
    data: Array<{ label: string; value: number }>;
    color?: string;
    height?: number;
}) {
    if (!data || data.length === 0) {
        return <p className="py-4 text-center text-sm text-muted-foreground">No data available.</p>;
    }

    const maxVal = Math.max(...data.map((d) => d.value), 1);
    const barWidth = Math.min(32, Math.max(16, 300 / data.length - 6));
    const gap = 6;
    const totalWidth = data.length * (barWidth + gap) - gap;
    const chartHeight = height - 24;

    return (
        <div className="flex justify-center overflow-x-auto">
            <svg
                width={Math.max(totalWidth + 20, 100)}
                height={height + 8}
                viewBox={`0 0 ${Math.max(totalWidth + 20, 100)} ${height + 8}`}
            >
                {data.map((item, i) => {
                    const barHeight = Math.max((item.value / maxVal) * chartHeight, 3);
                    const x = 10 + i * (barWidth + gap);
                    const y = chartHeight - barHeight;
                    return (
                        <g key={i}>
                            <rect
                                x={x}
                                y={y}
                                width={barWidth}
                                height={barHeight}
                                rx={3}
                                fill={color}
                                opacity={0.8}
                            />
                            <text
                                x={x + barWidth / 2}
                                y={y - 3}
                                textAnchor="middle"
                                className="fill-muted-foreground"
                                style={{ fontSize: 9 }}
                            >
                                {item.value}
                            </text>
                            <text
                                x={x + barWidth / 2}
                                y={chartHeight + 14}
                                textAnchor="middle"
                                className="fill-muted-foreground"
                                style={{ fontSize: 9 }}
                            >
                                {item.label}
                            </text>
                        </g>
                    );
                })}
            </svg>
        </div>
    );
}
