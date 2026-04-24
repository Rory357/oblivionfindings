import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowRight, ArrowUp } from 'lucide-react';
import type { ComponentType } from 'react';
import { MiniSparkline } from './mini-sparkline';

type KpiCardProps = {
    label: string;
    value: string | number;
    icon?: ComponentType<{ className?: string }>;
    trend?: { value: number; label: string; direction: 'up' | 'down' | 'neutral' };
    sparklineData?: number[];
    href?: string;
    className?: string;
};

export function KpiCard({
    label,
    value,
    icon: Icon,
    trend,
    sparklineData,
    href,
    className,
}: KpiCardProps) {
    const card = (
        <div
            className={cn(
                'relative overflow-hidden rounded-xl border bg-card p-5 shadow-sm transition-colors',
                href && 'hover:border-primary/50 cursor-pointer',
                className,
            )}
        >
            {/* icon */}
            {Icon && (
                <div className="absolute right-4 top-4">
                    <Icon className="h-5 w-5 text-muted-foreground/60" />
                </div>
            )}

            {/* value */}
            <div className="text-3xl font-bold tracking-tight">{value}</div>

            {/* label */}
            <div className="mt-1 text-sm text-muted-foreground">{label}</div>

            {/* trend + sparkline row */}
            {(trend || sparklineData) && (
                <div className="mt-3 flex items-end justify-between gap-2">
                    {trend && (
                        <div
                            className={cn(
                                'flex items-center gap-1 text-xs font-medium',
                                trend.direction === 'up' && 'text-status-success',
                                trend.direction === 'down' && 'text-status-critical',
                                trend.direction === 'neutral' && 'text-muted-foreground',
                            )}
                        >
                            {trend.direction === 'up' && <ArrowUp className="h-3 w-3" />}
                            {trend.direction === 'down' && <ArrowDown className="h-3 w-3" />}
                            {trend.direction === 'neutral' && <ArrowRight className="h-3 w-3" />}
                            <span>
                                {trend.value > 0 ? '+' : ''}
                                {trend.value}%
                            </span>
                            <span className="text-muted-foreground">{trend.label}</span>
                        </div>
                    )}

                    {sparklineData && sparklineData.length > 1 && (
                        <MiniSparkline
                            data={sparklineData}
                            width={80}
                            height={32}
                            color="var(--primary)"
                            fillOpacity={0.1}
                        />
                    )}
                </div>
            )}
        </div>
    );

    if (href) {
        return <Link href={href}>{card}</Link>;
    }

    return card;
}
