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
    /**
     * Outer padding around the layout. Default: 'none' — inside the app
     * shell, the shell already owns the single 20px chrome-to-content
     * gutter (approved 2026-09-05; see APP_SHELL_STYLE_GUIDE.md §4), so
     * PageLayout must not stack its own outer padding on top. Only pass a
     * value on surfaces outside the shell (e.g. marketing/careers pages).
     */
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
    padding = 'none',
    className,
}: PageLayoutProps) {
    return (
        <div
            className={cn(
                // 20px rhythm between sections (hero → tabs → content) — the
                // approved shell spacing (APP_SHELL_STYLE_GUIDE.md §4): 20px
                // everywhere between chrome, sections, and cards.
                'flex w-full flex-col gap-5',
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
