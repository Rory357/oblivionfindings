import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface PageHeroActionsProps {
    children: ReactNode;
    className?: string;
}

/**
 * Wraps action buttons inside the hero gradient and forces them to use
 * primary-foreground tokens for contrast against the brand background.
 *
 * Why a separate wrapper instead of a `variant`: the buttons inside the hero
 * are otherwise stock <Button>s with their own variants (default, outline, etc.).
 * We override visuals via descendant selectors so call sites don't need to know
 * they're inside a hero.
 */
export function PageHeroActions({ children, className }: PageHeroActionsProps) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-center gap-2',
                '[&_[data-slot=button]]:border-primary-foreground/20',
                '[&_[data-slot=button]]:bg-primary-foreground/10',
                '[&_[data-slot=button]]:text-primary-foreground',
                '[&_[data-slot=button]]:shadow-none',
                '[&_[data-slot=button]:hover]:bg-primary-foreground/20',
                className,
            )}
        >
            {children}
        </div>
    );
}

export default PageHeroActions;
