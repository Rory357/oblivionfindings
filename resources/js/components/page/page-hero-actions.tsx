import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface PageHeroActionsProps {
    children: ReactNode;
    className?: string;
}

/**
 * Wraps action buttons inside the hero gradient and forces them to use
 * band-foreground tokens for contrast against the brand background.
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
                '[&_[data-slot=button]]:border-band-foreground/20',
                '[&_[data-slot=button]]:bg-band-foreground/10',
                '[&_[data-slot=button]]:text-band-foreground',
                '[&_[data-slot=button]]:shadow-none',
                '[&_[data-slot=button]:hover]:bg-band-foreground/20',
                className,
            )}
        >
            {children}
        </div>
    );
}

export default PageHeroActions;
