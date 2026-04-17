import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { type ReactNode } from 'react';

import { cn } from '@/lib/utils';

type StaffHeaderProps = {
    title: ReactNode;
    subtitle?: ReactNode;
    action?: ReactNode;
    backHref?: string;
    backLabel?: string;
    className?: string;
};

/**
 * Compact header for staff / frontline pages.
 *
 * Intentionally minimal compared to AppSidebarHeader:
 * - no breadcrumbs
 * - no global search
 * - no inbox menus
 * - no KPI strip
 *
 * Designed to sit inside StaffPageShell on mobile and small screens.
 */
export function StaffHeader({
    title,
    subtitle,
    action,
    backHref,
    backLabel = 'Back',
    className,
}: StaffHeaderProps) {
    return (
        <header
            className={cn(
                'sticky top-0 z-30 flex min-h-14 items-center gap-3 border-b border-border/50 bg-background/95 px-4 pb-2 backdrop-blur supports-[backdrop-filter]:bg-background/80',
                // Honour the top safe-area inset so the title clears the
                // status bar when the app is launched in standalone/PWA
                // mode. Resolves to pt-2 in a normal browser tab.
                'pt-[calc(env(safe-area-inset-top,0px)+0.5rem)]',
                className,
            )}
        >
            {backHref ? (
                <Link
                    href={backHref}
                    aria-label={backLabel}
                    className="-ml-2 inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                >
                    <ArrowLeft className="h-5 w-5" />
                </Link>
            ) : null}

            <div className="min-w-0 flex-1">
                <h1 className="truncate text-base font-semibold leading-tight tracking-tight">
                    {title}
                </h1>
                {subtitle ? (
                    <p className="truncate text-xs text-muted-foreground">
                        {subtitle}
                    </p>
                ) : null}
            </div>

            {action ? (
                <div className="flex shrink-0 items-center">{action}</div>
            ) : null}
        </header>
    );
}

export default StaffHeader;
