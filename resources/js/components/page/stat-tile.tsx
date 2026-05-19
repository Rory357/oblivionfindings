import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

export type StatTileTone =
    | 'primary'
    | 'success'
    | 'warning'
    | 'critical'
    | 'info'
    | 'neutral'
    | 'ops'
    | 'hr'
    | 'compliance'
    | 'incidents'
    | 'governance'
    | 'sites'
    | 'fleet';

export interface StatTileProps {
    label: ReactNode;
    value: ReactNode;
    icon?: LucideIcon;
    /** Maps to --status-* / --category-* tokens. Default 'primary'. */
    tone?: StatTileTone;
    subtitle?: ReactNode;
    /** Optional sparkline of recent values (≥2 points to render). */
    trend?: number[];
    /** Wraps the tile as an Inertia Link. */
    href?: string;
    /** Skip the count-up animation. */
    staticValue?: boolean;
    /** Where the tile lives. 'grid' (default) is the typical KPI row.
     *  'hero' is reserved for tiles inside a hero gradient — uses
     *  primary-foreground tokens for contrast. */
    placement?: 'grid' | 'hero';
    className?: string;
    /** Additional class on the value <p>. Preserves FleetStatCard.valueClassName API. */
    valueClassName?: string;
}

const TONE: Record<StatTileTone, { bg: string; text: string; iconBg: string }> = {
    primary: {
        bg: 'bg-primary/10 dark:bg-primary/30',
        text: 'text-primary dark:text-primary/80',
        iconBg: 'bg-primary/10 dark:bg-primary/40',
    },
    success: {
        bg: 'bg-status-success-bg',
        text: 'text-status-success',
        iconBg: 'bg-status-success-bg',
    },
    warning: {
        bg: 'bg-status-warning-bg',
        text: 'text-status-warning',
        iconBg: 'bg-status-warning-bg',
    },
    critical: {
        bg: 'bg-status-critical-bg',
        text: 'text-status-critical',
        iconBg: 'bg-status-critical-bg',
    },
    info: {
        bg: 'bg-status-info-bg',
        text: 'text-status-info',
        iconBg: 'bg-status-info-bg',
    },
    neutral: {
        bg: 'bg-muted dark:bg-muted/30',
        text: 'text-foreground dark:text-muted-foreground',
        iconBg: 'bg-muted dark:bg-muted/40',
    },
    ops: {
        bg: 'bg-category-ops-bg',
        text: 'text-category-ops',
        iconBg: 'bg-category-ops-bg',
    },
    hr: {
        bg: 'bg-category-hr-bg',
        text: 'text-category-hr',
        iconBg: 'bg-category-hr-bg',
    },
    compliance: {
        bg: 'bg-category-compliance-bg',
        text: 'text-category-compliance',
        iconBg: 'bg-category-compliance-bg',
    },
    incidents: {
        bg: 'bg-category-incidents-bg',
        text: 'text-category-incidents',
        iconBg: 'bg-category-incidents-bg',
    },
    governance: {
        bg: 'bg-category-governance-bg',
        text: 'text-category-governance',
        iconBg: 'bg-category-governance-bg',
    },
    sites: {
        bg: 'bg-category-sites-bg',
        text: 'text-category-sites',
        iconBg: 'bg-category-sites-bg',
    },
    fleet: {
        bg: 'bg-category-fleet-bg',
        text: 'text-category-fleet',
        iconBg: 'bg-category-fleet-bg',
    },
};

function useCountUp(target: number | null, enabled: boolean): number {
    const [displayValue, setDisplayValue] = useState(target ?? 0);
    useEffect(() => {
        if (!enabled || target === null) {
            setDisplayValue(target ?? 0);
            return;
        }
        if (target === 0) {
            setDisplayValue(0);
            return;
        }
        const duration = 600;
        const start = performance.now();
        let frame = 0;
        const animate = (now: number) => {
            const progress = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            setDisplayValue(Math.round(target * eased));
            if (progress < 1) frame = requestAnimationFrame(animate);
        };
        frame = requestAnimationFrame(animate);
        return () => cancelAnimationFrame(frame);
    }, [target, enabled]);
    return displayValue;
}

function MiniSparkline({ data, className }: { data: number[]; className?: string }) {
    if (!data || data.length < 2) return null;
    const max = Math.max(...data);
    const min = Math.min(...data);
    const range = max - min || 1;
    const width = 80;
    const height = 20;
    const step = width / (data.length - 1);
    const points = data
        .map((v, i) => `${i * step},${height - ((v - min) / range) * height}`)
        .join(' ');
    return (
        <svg
            width={width}
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            className={cn('shrink-0', className)}
        >
            <polyline
                fill="none"
                stroke="currentColor"
                strokeWidth={1.5}
                strokeLinecap="round"
                strokeLinejoin="round"
                points={points}
            />
        </svg>
    );
}

export function StatTile({
    label,
    value,
    icon: Icon,
    tone = 'primary',
    subtitle,
    trend,
    href,
    staticValue,
    placement = 'grid',
    className,
    valueClassName,
}: StatTileProps) {
    const numericValue = typeof value === 'number' ? value : null;
    const animated = useCountUp(numericValue, !staticValue);
    const renderedValue = numericValue !== null && !staticValue ? animated : value;
    const palette = TONE[tone];

    if (placement === 'hero') {
        const content = (
            <div className="min-w-0 text-center">
                <p className={cn('text-2xl font-bold tabular-nums text-primary-foreground', valueClassName)}>
                    {renderedValue}
                </p>
                <p className="text-xs text-primary-foreground/60">{label}</p>
            </div>
        );
        return href ? <Link href={href}>{content}</Link> : content;
    }

    const content = (
        <Card className={cn('border py-0 transition-shadow hover:shadow-md', palette.bg, className)}>
            <CardContent className="p-4">
                <div className="flex items-start justify-between">
                    <div className="min-w-0 flex-1">
                        <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                            {label}
                        </p>
                        <p className={cn('mt-0.5 text-2xl font-bold tabular-nums', palette.text, valueClassName)}>
                            {renderedValue}
                        </p>
                    </div>
                    {Icon ? (
                        <div className={cn('flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', palette.iconBg)}>
                            <Icon className={cn('h-4 w-4', palette.text)} />
                        </div>
                    ) : null}
                </div>
                {(subtitle || (trend && trend.length > 1)) && (
                    <div className="mt-2 flex items-center justify-between gap-2">
                        {subtitle ? (
                            <span className="truncate text-[10px] text-muted-foreground">{subtitle}</span>
                        ) : (
                            <span />
                        )}
                        {trend && trend.length > 1 ? (
                            <MiniSparkline data={trend} className={palette.text} />
                        ) : null}
                    </div>
                )}
            </CardContent>
        </Card>
    );

    if (href) {
        return (
            <Link
                href={href}
                className="block transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg"
            >
                {content}
            </Link>
        );
    }
    return content;
}

export default StatTile;
