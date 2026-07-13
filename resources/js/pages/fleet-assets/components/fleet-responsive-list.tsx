import { cn } from '@/lib/utils';
import { cloneElement, type ReactElement } from 'react';

/**
 * Renders one table definition into a scan-friendly mobile card list and the
 * normal desktop table. Callers keep a single row mapping and identify the
 * primary, status, time and action cells with data-fleet-row-* attributes.
 */
export function FleetResponsiveTable({
    children,
    className,
}: {
    children: ReactElement<{ className?: string }>;
    className?: string;
}) {
    const mobileTable = cloneElement(children, {
        className: cn(children.props.className, 'block w-full'),
    });

    return (
        <>
            <div
                data-fleet-mobile-list
                className={cn(
                    'md:hidden',
                    '[&_table]:block [&_tbody]:block [&_thead]:sr-only',
                    '[&_tr]:mb-3 [&_tr]:grid [&_tr]:gap-2 [&_tr]:rounded-xl [&_tr]:border [&_tr]:bg-card [&_tr]:p-4 [&_tr]:shadow-sm',
                    '[&_td]:block [&_td]:p-0 [&_td]:text-left',
                    '[&_td[data-fleet-row-identity]]:text-base [&_td[data-fleet-row-identity]]:font-semibold',
                    '[&_td[data-fleet-row-time]]:text-sm [&_td[data-fleet-row-time]]:text-muted-foreground',
                    '[&_td[data-fleet-row-action]]:mt-1 [&_td[data-fleet-row-action]]:flex [&_td[data-fleet-row-action]]:min-h-11 [&_td[data-fleet-row-action]]:items-center',
                    className,
                )}
            >
                {mobileTable}
            </div>
            <div
                data-fleet-desktop-table
                className={cn('hidden overflow-x-auto md:block', className)}
            >
                {children}
            </div>
        </>
    );
}
