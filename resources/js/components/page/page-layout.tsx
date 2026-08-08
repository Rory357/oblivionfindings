import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

import { PageContent } from './page-content';

interface PageLayoutProps {
    /** The hero (PageHero or compatible). Rendered first. */
    hero?: ReactNode;
    /** Optional PageTabs block. When provided, tab content lives INSIDE PageTabs
     *  and `children` here is optional / typically empty. */
    tabs?: ReactNode;
    /** Page body. Wrapped in PageContent with the chosen width. */
    children?: ReactNode;
    /** Content container width. */
    width?: 'full' | 'narrow' | 'wide';
    /** Outer padding around the layout. Default: 'p-6'. */
    padding?: 'none' | 'sm' | 'md' | 'lg';
    className?: string;
}

const PADDING = {
    none: '',
    sm: 'p-4',
    md: 'p-6',
    lg: 'p-8',
} as const;

/**
 * PageLayout orchestrates the standard page shell: hero → tabs → content,
 * with consistent vertical rhythm.
 *
 * Sits BENEATH AppLayout — pages still wrap with `<AppLayout breadcrumbs=...>`.
 */
export function PageLayout({
    hero,
    tabs,
    children,
    width = 'full',
    padding = 'md',
    className,
}: PageLayoutProps) {
    return (
        <div
            className={cn(
                'flex w-full flex-col gap-6',
                PADDING[padding],
                className,
            )}
        >
            {hero}
            {tabs}
            {children ? (
                <PageContent width={width}>{children}</PageContent>
            ) : null}
        </div>
    );
}

export default PageLayout;
