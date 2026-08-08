import { Link } from '@inertiajs/react';
import type { ComponentType, ReactNode } from 'react';

import { cn } from '@/lib/utils';

export type PageHeroMetaItem = {
    icon?: ComponentType<{ className?: string }>;
    label: ReactNode;
    href?: string;
};

interface PageHeroMetaProps {
    items: PageHeroMetaItem[];
    /** Centre on mobile, start on md+. Disable for compact layouts. */
    alignResponsive?: boolean;
    className?: string;
}

export function PageHeroMeta({
    items,
    alignResponsive = true,
    className,
}: PageHeroMetaProps) {
    if (items.length === 0) return null;

    return (
        <div
            className={cn(
                'mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-primary-foreground/60',
                alignResponsive
                    ? 'justify-center md:justify-start'
                    : 'justify-start',
                className,
            )}
        >
            {items.map((item, idx) => {
                const Icon = item.icon;
                const inner = (
                    <span className="inline-flex items-center gap-1.5">
                        {Icon ? <Icon className="h-3.5 w-3.5" /> : null}
                        <span>{item.label}</span>
                    </span>
                );
                if (item.href) {
                    return (
                        <Link
                            key={idx}
                            href={item.href}
                            className="inline-flex items-center transition-colors hover:text-primary-foreground/90"
                        >
                            {inner}
                        </Link>
                    );
                }
                return <span key={idx}>{inner}</span>;
            })}
        </div>
    );
}

export default PageHeroMeta;
