/* Button twin of FleetHeroAction (fleet-hero-kit) for quick actions that open
 * an in-page modal instead of navigating. Identical on-dark chrome; semantic
 * tokens only. */
import { cn } from '@/lib/utils';
import { type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

export function HeroActionButton({
    onClick,
    icon: Icon,
    children,
    emphasis = false,
}: {
    onClick: () => void;
    icon: LucideIcon;
    children: ReactNode;
    emphasis?: boolean;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- on-dark hero quick action, mirrors FleetHeroAction chrome (not a shadcn Button).
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'inline-flex h-[34px] items-center gap-2 rounded-lg px-3.5 text-[12.5px] font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none',
                emphasis
                    ? 'bg-primary-foreground font-extrabold text-primary shadow-sm hover:bg-primary-foreground/90'
                    : 'border border-primary-foreground/25 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20',
            )}
        >
            <Icon className="h-[15px] w-[15px]" />
            {children}
        </button>
    );
}
