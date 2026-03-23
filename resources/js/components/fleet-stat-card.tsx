import { SparklineChart, FLEET_COLORS } from '@/components/fleet-charts';
import { Card, CardContent } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

const COLOR_MAP: Record<string, { bg: string; text: string; iconBg: string }> = {
    purple: { bg: 'bg-purple-50 dark:bg-purple-950/30', text: 'text-purple-700 dark:text-purple-300', iconBg: 'bg-purple-100 dark:bg-purple-900/40' },
    blue: { bg: 'bg-blue-50 dark:bg-blue-950/30', text: 'text-blue-700 dark:text-blue-300', iconBg: 'bg-blue-100 dark:bg-blue-900/40' },
    amber: { bg: 'bg-amber-50 dark:bg-amber-950/30', text: 'text-amber-700 dark:text-amber-300', iconBg: 'bg-amber-100 dark:bg-amber-900/40' },
    cyan: { bg: 'bg-cyan-50 dark:bg-cyan-950/30', text: 'text-cyan-700 dark:text-cyan-300', iconBg: 'bg-cyan-100 dark:bg-cyan-900/40' },
    red: { bg: 'bg-red-50 dark:bg-red-950/30', text: 'text-red-700 dark:text-red-300', iconBg: 'bg-red-100 dark:bg-red-900/40' },
    slate: { bg: 'bg-slate-50 dark:bg-slate-900/30', text: 'text-slate-700 dark:text-slate-300', iconBg: 'bg-slate-100 dark:bg-slate-800/40' },
};

const SPARKLINE_COLOR_MAP: Record<string, string> = {
    purple: FLEET_COLORS.primary,
    blue: FLEET_COLORS.secondary,
    amber: FLEET_COLORS.warning,
    cyan: FLEET_COLORS.accent,
    red: FLEET_COLORS.danger,
    slate: FLEET_COLORS.muted,
};

interface FleetStatCardProps {
    label: string;
    value: string | number;
    icon: LucideIcon;
    color?: keyof typeof COLOR_MAP;
    subtitle?: string;
    trend?: number[];
    href?: string;
    valueClassName?: string;
}

export function FleetStatCard({
    label,
    value,
    icon: Icon,
    color = 'purple',
    subtitle,
    trend,
    href,
    valueClassName,
}: FleetStatCardProps) {
    const colors = COLOR_MAP[color] ?? COLOR_MAP.purple;
    const sparkColor = SPARKLINE_COLOR_MAP[color] ?? FLEET_COLORS.primary;

    // Animate number from 0 to value on mount
    const [displayValue, setDisplayValue] = useState(0);
    const numericValue = typeof value === 'number' ? value : null;
    useEffect(() => {
        if (numericValue === null || numericValue === 0) {
            setDisplayValue(0);
            return;
        }
        const duration = 600; // ms
        const start = performance.now();
        const animate = (now: number) => {
            const progress = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3); // ease-out cubic
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
                        <p className={`mt-0.5 text-2xl font-bold tabular-nums ${colors.text} ${valueClassName ?? ''}`}>{renderedValue}</p>
                    </div>
                    <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ${colors.iconBg}`}>
                        <Icon className={`h-4 w-4 ${colors.text}`} />
                    </div>
                </div>
                {(subtitle || trend) && (
                    <div className="mt-2 flex items-center justify-between gap-2">
                        {subtitle && <span className="text-[10px] text-muted-foreground truncate">{subtitle}</span>}
                        {trend && trend.length > 1 && (
                            <SparklineChart data={trend} color={sparkColor} height={20} width={80} />
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );

    if (href) {
        return <Link href={href} className="block transition-all duration-200 hover:shadow-lg hover:-translate-y-0.5">{content}</Link>;
    }
    return content;
}
