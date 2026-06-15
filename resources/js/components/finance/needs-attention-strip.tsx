import { Link } from '@inertiajs/react';
import { AlertTriangle, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export type AttentionSeverity = 'critical' | 'warning' | 'info' | 'success';

export type AttentionItem = {
    id: string;
    severity: AttentionSeverity;
    icon: LucideIcon;
    title: string;
    body: ReactNode;
    /** Right-aligned count/amount pill. */
    tag: string;
    href?: string;
};

const SEVERITY: Record<
    AttentionSeverity,
    { leftBorder: string; tile: string; pill: string }
> = {
    critical: {
        leftBorder: 'border-l-status-critical',
        tile: 'bg-status-critical-bg text-status-critical',
        pill: 'bg-status-critical-bg text-status-critical',
    },
    warning: {
        leftBorder: 'border-l-status-warning',
        tile: 'bg-status-warning-bg text-status-warning',
        pill: 'bg-status-warning-bg text-status-warning',
    },
    info: {
        leftBorder: 'border-l-status-info',
        tile: 'bg-status-info-bg text-status-info',
        pill: 'bg-status-info-bg text-status-info',
    },
    success: {
        leftBorder: 'border-l-status-success',
        tile: 'bg-status-success-bg text-status-success',
        pill: 'bg-status-success-bg text-status-success',
    },
};

export function NeedsAttentionStrip({
    items,
    subtitle,
    viewAllHref,
    className,
}: {
    items: AttentionItem[];
    subtitle?: string;
    viewAllHref?: string;
    className?: string;
}) {
    if (items.length === 0) return null;

    return (
        <section className={cn('space-y-3', className)}>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <AlertTriangle className="h-[18px] w-[18px] text-status-warning" />
                <h2 className="text-sm font-bold tracking-tight">Needs attention</h2>
                {subtitle ? (
                    <span className="text-[12px] text-muted-foreground">{subtitle}</span>
                ) : null}
                {viewAllHref ? (
                    <Link
                        href={viewAllHref}
                        className="ml-auto inline-flex items-center gap-1 text-[12.5px] font-semibold text-primary hover:underline"
                    >
                        View all →
                    </Link>
                ) : null}
            </div>
            <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                {items.map((item) => {
                    const Icon = item.icon;
                    const s = SEVERITY[item.severity];
                    const card = (
                        <div
                            className={cn(
                                'flex h-full items-start gap-3 rounded-2xl border border-l-[3px] border-border bg-card p-3',
                                s.leftBorder,
                            )}
                        >
                            <span
                                className={cn(
                                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                                    s.tile,
                                )}
                            >
                                <Icon className="h-[15px] w-[15px]" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="text-[13px] font-bold tracking-tight">{item.title}</div>
                                <div className="mt-0.5 text-[11.8px] leading-snug text-muted-foreground">
                                    {item.body}
                                </div>
                            </div>
                            <span
                                className={cn(
                                    'shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums',
                                    s.pill,
                                )}
                            >
                                {item.tag}
                            </span>
                        </div>
                    );
                    return item.href ? (
                        <Link key={item.id} href={item.href} className="block transition-transform hover:-translate-y-px">
                            {card}
                        </Link>
                    ) : (
                        <div key={item.id}>{card}</div>
                    );
                })}
            </div>
        </section>
    );
}

export default NeedsAttentionStrip;
