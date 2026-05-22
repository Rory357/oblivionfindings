import { Link } from '@inertiajs/react';
import { ArrowRight, ChevronDown } from 'lucide-react';
import { type ComponentType, type ReactNode, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export type PageHeroBadgeTone = 'default' | 'success' | 'warning' | 'critical' | 'info';

export type PageHeroBadgePopoverItemTone = 'critical' | 'warning' | 'info';

export type PageHeroBadgePopoverItem = {
    icon?: ComponentType<{ className?: string }>;
    label: ReactNode;
    sub?: ReactNode;
    meta?: ReactNode;
    href?: string;
    tone?: PageHeroBadgePopoverItemTone;
};

export type PageHeroBadgePopover = {
    title: string;
    subtitle?: string;
    items?: PageHeroBadgePopoverItem[];
    action?: { label: string; href: string };
};

export type PageHeroBadge = {
    icon?: ComponentType<{ className?: string }>;
    label: ReactNode;
    tone?: PageHeroBadgeTone;
    onClick?: () => void;
    href?: string;
    /** Small leading dot (decorative). */
    dot?: boolean;
    /**
     * Hover/click-to-pin popover with related items + action.
     * When set, badge becomes clickable and reveals the popover.
     */
    popover?: PageHeroBadgePopover;
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
        'border-status-critical/30 bg-primary-foreground text-status-critical',
    info: 'border-status-info/30 bg-status-info-bg text-status-info',
};

const DOT_CLASSES: Record<PageHeroBadgeTone, string> = {
    default: 'bg-primary-foreground/80',
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    info: 'bg-status-info',
};

const ITEM_TONE_CLASSES: Record<PageHeroBadgePopoverItemTone, string> = {
    critical: 'bg-status-critical-bg text-status-critical',
    warning: 'bg-status-warning-bg text-status-warning',
    info: 'bg-accent text-primary',
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
            {badges.map((badge, idx) => (
                <PageHeroBadgeItem key={idx} badge={badge} />
            ))}
        </div>
    );
}

function PageHeroBadgeItem({ badge }: { badge: PageHeroBadge }) {
    const [pinned, setPinned] = useState(false);
    const [hover, setHover] = useState(false);
    const Icon = badge.icon;
    const tone = badge.tone ?? 'default';
    const hasPopover = !!badge.popover;
    const open = hasPopover && (pinned || hover);

    const inner = (
        <Badge
            className={cn(
                'inline-flex items-center gap-1.5 border',
                TONE_CLASSES[tone],
                pinned && 'ring-2 ring-primary-foreground/40 ring-offset-1 ring-offset-transparent',
            )}
            aria-label={badge['aria-label']}
        >
            {badge.dot ? (
                <span className={cn('inline-block h-1.5 w-1.5 shrink-0 rounded-full', DOT_CLASSES[tone])} />
            ) : null}
            {Icon ? <Icon className="mr-0.5 h-3 w-3" /> : null}
            {badge.label}
            {hasPopover ? (
                <ChevronDown
                    className={cn('ml-0.5 h-2.5 w-2.5 transition-transform duration-150', open && 'rotate-180')}
                />
            ) : null}
        </Badge>
    );

    if (hasPopover) {
        return (
            <Popover open={open} onOpenChange={(next) => setPinned(next)}>
                <PopoverTrigger asChild>
                    <button
                        type="button"
                        onMouseEnter={() => setHover(true)}
                        onMouseLeave={() => setHover(false)}
                        onClick={() => setPinned((v) => !v)}
                        className="rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-foreground/40"
                        aria-label={badge['aria-label']}
                        aria-expanded={open}
                    >
                        {inner}
                    </button>
                </PopoverTrigger>
                <PopoverContent
                    align="start"
                    sideOffset={8}
                    className="w-[300px] p-0 text-popover-foreground"
                    onMouseEnter={() => setHover(true)}
                    onMouseLeave={() => setHover(false)}
                >
                    <PageHeroBadgePopoverBody popover={badge.popover!} />
                </PopoverContent>
            </Popover>
        );
    }

    if (badge.href) {
        return <Link href={badge.href}>{inner}</Link>;
    }

    if (badge.onClick) {
        return (
            <button
                type="button"
                onClick={badge.onClick}
                className="rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-foreground/40"
                aria-label={badge['aria-label']}
            >
                {inner}
            </button>
        );
    }

    return <span>{inner}</span>;
}

function PageHeroBadgePopoverBody({ popover }: { popover: PageHeroBadgePopover }) {
    return (
        <div className="overflow-hidden">
            <div className="border-b border-border px-3.5 py-3">
                <div className="text-sm font-semibold tracking-tight">{popover.title}</div>
                {popover.subtitle ? (
                    <div className="mt-0.5 text-[11.5px] text-muted-foreground">{popover.subtitle}</div>
                ) : null}
            </div>
            {popover.items && popover.items.length > 0 ? (
                <div className="py-1">
                    {popover.items.map((item, i) => {
                        const ItemIcon = item.icon;
                        const tone = item.tone ?? 'info';
                        const tile = ItemIcon ? (
                            <span
                                className={cn(
                                    'flex h-[26px] w-[26px] shrink-0 items-center justify-center rounded-md',
                                    ITEM_TONE_CLASSES[tone],
                                )}
                            >
                                <ItemIcon className="h-3 w-3" />
                            </span>
                        ) : null;
                        const body = (
                            <>
                                {tile}
                                <span className="min-w-0 flex-1">
                                    <span className="block font-medium leading-snug text-foreground">{item.label}</span>
                                    {item.sub ? (
                                        <span className="mt-px block text-[11px] text-muted-foreground">{item.sub}</span>
                                    ) : null}
                                </span>
                                {item.meta ? (
                                    <span className="shrink-0 text-[11px] tabular-nums text-muted-foreground">
                                        {item.meta}
                                    </span>
                                ) : null}
                            </>
                        );
                        const className =
                            'flex items-center gap-2.5 px-3.5 py-2 text-[12.5px] text-foreground transition-colors hover:bg-muted';
                        return item.href ? (
                            <Link key={i} href={item.href} className={className}>
                                {body}
                            </Link>
                        ) : (
                            <div key={i} className={className}>
                                {body}
                            </div>
                        );
                    })}
                </div>
            ) : null}
            {popover.action ? (
                <Link
                    href={popover.action.href}
                    className="flex items-center gap-1.5 border-t border-border bg-muted px-3.5 py-2.5 text-[12.5px] font-semibold text-primary"
                >
                    <span>{popover.action.label}</span>
                    <ArrowRight className="ml-auto h-3 w-3" />
                </Link>
            ) : null}
        </div>
    );
}

export default PageHeroBadges;
