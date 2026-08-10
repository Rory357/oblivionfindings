import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface PageContentProps {
    children: ReactNode;
    /** Container width: 'full' (default), 'narrow' (forms), 'wide' (data tables / dashboards). */
    width?: 'full' | 'narrow' | 'wide';
    className?: string;
}

export function PageContent({
    children,
    width = 'full',
    className,
}: PageContentProps) {
    return (
        <div
            className={cn(
                'w-full',
                width === 'narrow' && 'mx-auto max-w-3xl',
                width === 'wide' && 'max-w-[1600px]',
                className,
            )}
        >
            {children}
        </div>
    );
}

export default PageContent;
