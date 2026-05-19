import { Link } from '@inertiajs/react';
import type { ComponentType, ReactNode } from 'react';

import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

export type PageHeroBadgeTone = 'default' | 'success' | 'warning' | 'critical' | 'info';

export type PageHeroBadge = {
    icon?: ComponentType<{ className?: string }>;
    label: ReactNode;
    tone?: PageHeroBadgeTone;
    onClick?: () => void;
    href?: string;
    'aria-label'?: string;
};

interface PageHeroBadgesProps {
    badges: PageHeroBadge[];
    /** Centre on mobile, start on md+. */
    alignResponsive?: boolean;
    className?: string;
}

const TONE_CLASSES: Record<PageHeroBadgeTone, string> = {
    default:
        'border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground/90',
    success:
        'border-status-success/30 bg-status-success-bg text-status-success',
    warning:
        'border-status-warning/30 bg-status-warning-bg text-status-warning',
    critical:
        'border-status-critical/30 bg-status-critical-bg text-status-critical',
    info: 'border-status-info/30 bg-status-info-bg text-status-info',
};

export function PageHeroBadges({ badges, alignResponsive = true, className }: PageHeroBadgesProps) {
    if (badges.length === 0) return null;

    return (
        <div
            className={cn(
                'mt-3 flex flex-wrap items-center gap-2',
                alignResponsive ? 'justify-center md:justify-start' : 'justify-start',
                className,
            )}
        >
            {badges.map((badge, idx) => {
                const Icon = badge.icon;
                const tone = badge.tone ?? 'default';
                const badgeEl = (
                    <Badge className={cn('border', TONE_CLASSES[tone])} aria-label={badge['aria-label']}>
                        {Icon ? <Icon className="mr-1 h-3 w-3" /> : null}
                        {badge.label}
                    </Badge>
                );

                if (badge.href) {
                    return (
                        <Link key={idx} href={badge.href}>
                            {badgeEl}
                        </Link>
                    );
                }

                if (badge.onClick) {
                    return (
                        <button
                            key={idx}
                            type="button"
                            onClick={badge.onClick}
                            className="rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-foreground/40"
                            aria-label={badge['aria-label']}
                        >
                            {badgeEl}
                        </button>
                    );
                }

                return <span key={idx}>{badgeEl}</span>;
            })}
        </div>
    );
}

export default PageHeroBadges;
