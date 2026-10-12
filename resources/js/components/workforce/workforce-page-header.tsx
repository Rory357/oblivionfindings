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
    mobilePrimaryAction,
    mobileSecondaryActions,
    actions,
    subline,
    titleChip,
    ...props
}: Omit<PageHeaderProps, 'frontline' | 'expandedContent'> & {
    details?: ReactNode;
    mobilePrimaryAction?: ReactNode;
    mobileSecondaryActions?: ReactNode;
}) {
    const isMobile = useIsMobile();
    const compactMobile =
        isMobile &&
        (mobilePrimaryAction !== undefined ||
            mobileSecondaryActions !== undefined);
    return (
        <>
            <PageHeader
                {...props}
                frontline={isMobile}
                wrapTitle={compactMobile || props.wrapTitle}
                mobileSummary={mobileSummary}
                subline={compactMobile ? undefined : subline}
                titleChip={compactMobile ? undefined : titleChip}
                actions={
                    compactMobile ? (
                        <div
                            data-slot="workforce-primary-actions"
                            className="flex min-h-[44px] w-full min-w-0 flex-wrap items-center justify-between gap-2"
                        >
                            {titleChip}
                            {mobilePrimaryAction ? (
                                <div className="ml-auto flex max-w-full min-w-0 items-center">
                                    {mobilePrimaryAction}
                                </div>
                            ) : null}
                        </div>
                    ) : (
                        actions
                    )
                }
                expandedContent={
                    compactMobile ? (
                        <div className="space-y-3 pb-1">
                            {subline ? (
                                <div className="text-caption break-words text-band-foreground/80!">
                                    {subline}
                                </div>
                            ) : null}
                            {mobileSecondaryActions ? (
                                <div
                                    data-slot="workforce-secondary-actions"
                                    className="flex w-full min-w-0 flex-wrap items-center gap-2"
                                >
                                    {mobileSecondaryActions}
                                </div>
                            ) : null}
                        </div>
                    ) : undefined
                }
                className={cn(
                    'max-lg:[&_.eh-meter]:min-w-0! max-md:[&_[data-slot=page-header-meter-caption]]:overflow-visible max-md:[&_[data-slot=page-header-meter-caption]]:break-words max-md:[&_[data-slot=page-header-meter-caption]]:whitespace-normal max-lg:[&_[data-slot=page-header-meters]]:grid max-lg:[&_[data-slot=page-header-meters]]:grid-cols-2 max-lg:[&_[data-slot=page-header-meters]>div]:col-span-2 lg:[&_[data-slot=page-header-subline]]:truncate lg:[&_[data-slot=page-header-top]]:min-h-12',
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
