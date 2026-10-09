import {
    PageHeader,
    type PageHeaderProps,
} from '@/components/page/page-header';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import type { ReactNode } from 'react';

/** Reuse the app header with one Workforce rhythm; touch controls grow only on phones. */
export function WorkforcePageHeader({
    filters,
    details,
    className,
    mobileSummary = 'Overview',
    ...props
}: Omit<PageHeaderProps, 'frontline'> & { details?: ReactNode }) {
    const isMobile = useIsMobile();
    return (
        <>
            <PageHeader
                {...props}
                frontline={isMobile}
                mobileSummary={mobileSummary}
                className={cn(
                    'max-lg:[&_.eh-meter]:min-w-0! max-lg:[&_[data-slot=page-header-meters]]:grid max-lg:[&_[data-slot=page-header-meters]]:grid-cols-2 max-lg:[&_[data-slot=page-header-meters]>div]:col-span-2 lg:[&_[data-slot=page-header-subline]]:truncate lg:[&_[data-slot=page-header-top]]:min-h-12',
                    className,
                )}
                filters={
                    filters ?? (
                        <span aria-hidden="true" className="block h-[23px]" />
                    )
                }
            />
            {details ? (
                <div className="text-caption space-y-1 text-muted-foreground">
                    {details}
                </div>
            ) : null}
        </>
    );
}
