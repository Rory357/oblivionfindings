import { Card, CardContent } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

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

const COLOR_MAP: Record<string, { bg: string; text: string; iconBg: string }> = {
    indigo: {
        bg: 'bg-primary/10 dark:bg-primary/30',
        text: 'text-primary dark:text-primary/70',
        iconBg: 'bg-primary/10 dark:bg-primary/40',
    },
    blue: {
        bg: 'bg-blue-50 dark:bg-blue-950/30',
        text: 'text-blue-700 dark:text-blue-300',
        iconBg: 'bg-blue-100 dark:bg-blue-900/40',
    },
    amber: {
        bg: 'bg-amber-50 dark:bg-amber-950/30',
        text: 'text-amber-700 dark:text-amber-300',
        iconBg: 'bg-amber-100 dark:bg-amber-900/40',
    },
    cyan: {
        bg: 'bg-cyan-50 dark:bg-cyan-950/30',
        text: 'text-cyan-700 dark:text-cyan-300',
        iconBg: 'bg-cyan-100 dark:bg-cyan-900/40',
    },
    red: {
        bg: 'bg-red-50 dark:bg-red-950/30',
        text: 'text-red-700 dark:text-red-300',
        iconBg: 'bg-red-100 dark:bg-red-900/40',
    },
    emerald: {
        bg: 'bg-emerald-50 dark:bg-emerald-950/30',
        text: 'text-emerald-700 dark:text-emerald-300',
        iconBg: 'bg-emerald-100 dark:bg-emerald-900/40',
    },
    slate: {
        bg: 'bg-muted dark:bg-muted/30',
        text: 'text-foreground dark:text-muted-foreground',
        iconBg: 'bg-muted dark:bg-muted/40',
    },
    violet: {
        bg: 'bg-primary/10 dark:bg-primary/30',
        text: 'text-primary dark:text-primary/70',
        iconBg: 'bg-primary/10 dark:bg-primary/40',
    },
};

interface OpsStatCardProps {
    label: string;
    value: string | number;
    icon: LucideIcon;
    color?: keyof typeof COLOR_MAP;
    subtitle?: string;
    trend?: number[];
    href?: string;
    valueClassName?: string;
}

export function OpsStatCard({
    label,
    value,
    icon: Icon,
    color = 'indigo',
    subtitle,
    trend,
    href,
    valueClassName,
}: OpsStatCardProps) {
    const colors = COLOR_MAP[color] ?? COLOR_MAP.indigo;

    // Animate number from 0 to value on mount
    const [displayValue, setDisplayValue] = useState(0);
    const numericValue = typeof value === 'number' ? value : null;
    useEffect(() => {
        if (numericValue === null || numericValue === 0) {
            setDisplayValue(0);
            return;
        }
        const duration = 600;
        const start = performance.now();
        const animate = (now: number) => {
            const progress = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            setDisplayValue(Math.round(numericValue * eased));
            if (progress < 1) requestAnimationFrame(animate);
        };
        requestAnimationFrame(animate);
    }, [numericValue]);

    const renderedValue = numericValue !== null ? displayValue : value;

    const content = (
        <Card className={`border ${colors.bg} transition-shadow hover:shadow-md`}>
            <CardContent className="p-4">
                <div className="flex items-start justify-between">
                    <div className="min-w-0 flex-1">
                        <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">{label}</p>
                        <p className={`mt-0.5 text-2xl font-bold tabular-nums ${colors.text} ${valueClassName ?? ''}`}>
                            {renderedValue}
                        </p>
                    </div>
                    <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ${colors.iconBg}`}>
                        <Icon className={`h-4 w-4 ${colors.text}`} />
                    </div>
                </div>
                {(subtitle || trend) && (
                    <div className="mt-2 flex items-center justify-between gap-2">
                        {subtitle && <span className="truncate text-[10px] text-muted-foreground">{subtitle}</span>}
                        {trend && trend.length > 1 && <SparklineChart data={trend} color={OPS_COLORS.primary} height={20} width={80} />}
                    </div>
                )}
            </CardContent>
        </Card>
    );

    if (href) {
        return (
            <Link href={href} className="block transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg">
                {content}
            </Link>
        );
    }
    return content;
}

/* ── Sparkline ────────────────────────────────────────────────────── */

function SparklineChart({ data, color, height = 20, width = 80 }: { data: number[]; color: string; height?: number; width?: number }) {
    if (!data || data.length < 2) return null;
    const max = Math.max(...data);
    const min = Math.min(...data);
    const range = max - min || 1;
    const step = width / (data.length - 1);
    const points = data.map((v, i) => `${i * step},${height - ((v - min) / range) * height}`).join(' ');
    return (
        <svg width={width} height={height} viewBox={`0 0 ${width} ${height}`} className="shrink-0">
            <polyline fill="none" stroke={color} strokeWidth={1.5} strokeLinecap="round" strokeLinejoin="round" points={points} />
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
            <div className="flex flex-col items-center justify-center" style={{ width: size, height: size }}>
                <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
                    <circle cx={size / 2} cy={size / 2} r={radius} fill="none" stroke="currentColor" strokeWidth={strokeWidth} className="text-muted/20" />
                    <text x="50%" y="50%" textAnchor="middle" dominantBaseline="central" className="fill-muted-foreground text-xs">
                        No data
                    </text>
                </svg>
            </div>
        );
    }

    let offset = 0;
    return (
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
            <circle cx={size / 2} cy={size / 2} r={radius} fill="none" stroke="currentColor" strokeWidth={strokeWidth} className="text-muted/10" />
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
                    <text x="50%" y="46%" textAnchor="middle" dominantBaseline="central" className="fill-foreground text-2xl font-bold" style={{ fontSize: 22, fontWeight: 700 }}>
                        {centerValue}
                    </text>
                    {centerLabel && (
                        <text x="50%" y="64%" textAnchor="middle" dominantBaseline="central" className="fill-muted-foreground" style={{ fontSize: 9, textTransform: 'uppercase', letterSpacing: '0.05em' }}>
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
    const barWidth = Math.min(40, Math.max(16, Math.floor(280 / data.length) - 8));

    return (
        <div className="flex items-end justify-between gap-1" style={{ height }}>
            {data.map((d, i) => (
                <div key={i} className="flex flex-col items-center gap-1">
                    <span className="text-[9px] tabular-nums text-muted-foreground">{d.value}</span>
                    <div
                        className="rounded-t transition-all duration-300"
                        style={{
                            width: barWidth,
                            height: Math.max(4, (d.value / max) * (height - 28)),
                            backgroundColor: barColor,
                            opacity: 0.85,
                        }}
                    />
                    <span className="text-[9px] text-muted-foreground">{d.label}</span>
                </div>
            ))}
        </div>
    );
}
