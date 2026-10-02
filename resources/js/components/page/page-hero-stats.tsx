import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export type PageHeroStatTone =
    | 'neutral'
    | 'success'
    | 'warning'
    | 'critical'
    | 'info';

export type PageHeroStat = {
    label: ReactNode;
    value: ReactNode;
    /** Optional secondary line under the value (e.g. "of 8h", "1 overdue"). */
    sub?: ReactNode;
    icon?: LucideIcon;
    href?: string;
    /** Opens something in the page (e.g. a dialog) instead of following a link. */
    onClick?: () => void;
    /** Hide this stat below md. Default true to match the Site Detail reference. */
    hideOnMobile?: boolean;
    tone?: PageHeroStatTone;
};

// The hero band is a dark brand shade, and the plain status tokens are tuned
// for cards: light mode's are dark (~L 0.45) and info IS the brand, so on the
// band they measured 1.0–2.4:1. The page-hero-tone-* classes (app.css) keep
// each tone's hue but lift it to L ≥ 0.84 (info 0.86): ≥ 4.7:1 on every
// brand and Site colour.
const TONE_VALUE_CLASS: Record<PageHeroStatTone, string> = {
    neutral: '',
    success: 'page-hero-tone-success',
    warning: 'page-hero-tone-warning',
    critical: 'page-hero-tone-critical',
    info: 'page-hero-tone-info',
};

interface PageHeroStatsProps {
    stats: PageHeroStat[];
    /** Layout density. 'inline' is the right column of the hero (gap-6, no boxes).
     *  'tiles' wraps each stat in a soft tinted box — useful when stats span the full hero width. */
    layout?: 'inline' | 'tiles';
    className?: string;
}

export function PageHeroStats({
    stats,
    layout = 'inline',
    className,
}: PageHeroStatsProps) {
    if (stats.length === 0) return null;

    if (layout === 'tiles') {
        return (
            <div className={cn('flex flex-wrap items-center gap-3', className)}>
                {stats.map((stat) => {
                    const Icon = stat.icon;
                    const content = (
                        <div className="rounded-xl border border-primary-foreground/15 bg-primary-foreground/10 px-4 py-2 text-center backdrop-blur-sm transition-colors hover:bg-primary-foreground/15">
                            <div className="flex items-center justify-center gap-2">
                                {Icon ? (
                                    <Icon className="h-4 w-4 text-primary-foreground/70" />
                                ) : null}
                                <div className="text-lg font-bold tabular-nums">
                                    {stat.value}
                                </div>
                            </div>
                            <div className="mt-0.5 text-[10px] font-medium tracking-wider text-primary-foreground/90 uppercase">
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
        <div
            className={cn(
                'flex flex-wrap items-start gap-x-6 gap-y-3 text-center',
                className,
            )}
        >
            {stats.map((stat) => {
                const inner = (
                    <div
                        className={cn(
                            'min-w-0',
                            stat.hideOnMobile === false
                                ? ''
                                : 'hidden md:block',
                        )}
                    >
                        <p className="text-[10px] font-semibold tracking-wider text-primary-foreground/70 uppercase">
                            {stat.label}
                        </p>
                        <p
                            className={cn(
                                'text-xl font-bold tabular-nums',
                                stat.tone
                                    ? TONE_VALUE_CLASS[stat.tone]
                                    : undefined,
                            )}
                        >
                            {stat.value}
                        </p>
                        {stat.sub ? (
                            <p className="text-[11px] text-primary-foreground/70">
                                {stat.sub}
                            </p>
                        ) : null}
                    </div>
                );

                return stat.onClick ? (
                    <button
                        key={String(stat.label)}
                        type="button"
                        onClick={stat.onClick}
                        className="rounded-md transition-opacity outline-none hover:opacity-80 focus-visible:ring-2 focus-visible:ring-primary-foreground/70"
                    >
                        {inner}
                    </button>
                ) : stat.href ? (
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
