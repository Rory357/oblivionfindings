import { Link } from '@inertiajs/react';
import { type ComponentType } from 'react';

import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export type PageHeroQuickAction = {
    icon: ComponentType<{ className?: string }>;
    label: string;
    href?: string;
    onClick?: () => void;
    /** Count badge in the top-right corner. */
    badge?: number;
    'aria-label'?: string;
};

interface PageHeroQuickActionsProps {
    /** Strip heading — defaults to 'Quick actions' in caps. */
    heading?: string;
    actions: PageHeroQuickAction[];
    className?: string;
}

/**
 * Compact icon-only action strip rendered inside the hero's right column,
 * below the stats panel. Each button shows a tooltip on hover; an optional
 * count badge sits in the top-right corner.
 */
export function PageHeroQuickActions({
    heading = 'Quick actions',
    actions,
    className,
}: PageHeroQuickActionsProps) {
    if (actions.length === 0) return null;

    return (
        <div
            className={cn(
                'flex w-full flex-wrap gap-1 rounded-xl border border-primary-foreground/20 bg-primary-foreground/10 px-2.5 py-2',
                className,
            )}
        >
            <div className="w-full px-1 pt-0.5 pb-1 text-[10px] font-bold tracking-[0.10em] text-primary-foreground/70 uppercase">
                {heading}
            </div>
            <TooltipProvider delayDuration={200}>
                {actions.map((action) => (
                    <QuickActionButton key={action.label} action={action} />
                ))}
            </TooltipProvider>
        </div>
    );
}

function QuickActionButton({ action }: { action: PageHeroQuickAction }) {
    const Icon = action.icon;
    const label = action['aria-label'] ?? action.label;

    const inner = (
        <span
            className={cn(
                'relative flex h-9 w-9 items-center justify-center rounded-md text-primary-foreground transition-colors',
                'hover:bg-primary-foreground/25',
            )}
        >
            <Icon className="h-[15px] w-[15px]" />
            {action.badge != null ? (
                <span className="absolute top-0.5 right-0.5 inline-flex h-3.5 min-w-[14px] items-center justify-center rounded-full bg-primary-foreground px-1 text-[9px] font-bold text-primary">
                    {action.badge}
                </span>
            ) : null}
        </span>
    );

    const triggerNode = action.href ? (
        <Link href={action.href} aria-label={label}>
            {inner}
        </Link>
    ) : (
        <button type="button" onClick={action.onClick} aria-label={label}>
            {inner}
        </button>
    );

    return (
        <Tooltip>
            <TooltipTrigger asChild>{triggerNode}</TooltipTrigger>
            <TooltipContent side="bottom">{action.label}</TooltipContent>
        </Tooltip>
    );
}

export default PageHeroQuickActions;
