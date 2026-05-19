import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export type PageHeroStat = {
    label: ReactNode;
    value: ReactNode;
    icon?: LucideIcon;
    href?: string;
    /** Hide this stat below md. Default true to match the Site Detail reference. */
    hideOnMobile?: boolean;
};

interface PageHeroStatsProps {
    stats: PageHeroStat[];
    /** Layout density. 'inline' is the right column of the hero (gap-6, no boxes).
     *  'tiles' wraps each stat in a soft tinted box — useful when stats span the full hero width. */
    layout?: 'inline' | 'tiles';
    className?: string;
}

export function PageHeroStats({ stats, layout = 'inline', className }: PageHeroStatsProps) {
    if (stats.length === 0) return null;

    if (layout === 'tiles') {
        return (
            <div className={cn('flex flex-wrap items-center gap-3', className)}>
                {stats.map((stat) => {
                    const Icon = stat.icon;
                    const content = (
                        <div className="rounded-xl border border-primary-foreground/15 bg-primary-foreground/10 px-4 py-2 text-center backdrop-blur-sm transition-colors hover:bg-primary-foreground/15">
                            <div className="flex items-center justify-center gap-2">
                                {Icon ? <Icon className="h-4 w-4 text-primary-foreground/70" /> : null}
                                <div className="text-lg font-bold tabular-nums">{stat.value}</div>
                            </div>
                            <div className="mt-0.5 text-[10px] font-medium uppercase tracking-wider text-primary-foreground/60">
                                {stat.label}
                            </div>
                        </div>
                    );

                    return stat.href ? (
                        <Link key={String(stat.label)} href={stat.href}>
                            {content}
                        </Link>
                    ) : (
                        <div key={String(stat.label)}>{content}</div>
                    );
                })}
            </div>
        );
    }

    return (
        <div className={cn('flex flex-wrap items-start gap-x-6 gap-y-3 text-center', className)}>
            {stats.map((stat) => {
                const inner = (
                    <div
                        className={cn(
                            'min-w-0',
                            stat.hideOnMobile === false ? '' : 'hidden md:block',
                        )}
                    >
                        <p className="text-2xl font-bold tabular-nums">{stat.value}</p>
                        <p className="text-xs text-primary-foreground/60">{stat.label}</p>
                    </div>
                );

                return stat.href ? (
                    <Link
                        key={String(stat.label)}
                        href={stat.href}
                        className="transition-opacity hover:opacity-80"
                    >
                        {inner}
                    </Link>
                ) : (
                    <div key={String(stat.label)}>{inner}</div>
                );
            })}
        </div>
    );
}

export default PageHeroStats;
