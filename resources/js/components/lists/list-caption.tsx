/**
 * The section caption row that precedes every list surface
 * (design_styles/LIST_STYLE_GUIDE.md §1): title (14/650) + "N of N shown"
 * caption, with an optional grouping chip / control cluster on the right.
 */
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export function ListCaption({
    title,
    caption,
    right,
    className,
}: {
    title: ReactNode;
    caption?: ReactNode;
    right?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-center justify-between gap-3',
                className,
            )}
        >
            <div className="flex min-w-0 items-baseline gap-2.5">
                <h2 className="truncate text-[14px] font-[650] tracking-tight text-foreground">
                    {title}
                </h2>
                {caption ? (
                    <span className="text-caption shrink-0">{caption}</span>
                ) : null}
            </div>
            {right ? (
                <div className="flex items-center gap-2">{right}</div>
            ) : null}
        </div>
    );
}
