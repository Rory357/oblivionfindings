import { type PropsWithChildren, type ReactNode } from 'react';

import FlashToaster from '@/components/flash-toaster';
import {
    StaffBottomNav,
    type StaffBottomNavItem,
} from '@/components/staff-bottom-nav';
import { StaffHeader } from '@/components/staff-header';
import { AppSidebar } from '@/components/app-sidebar';
import { cn } from '@/lib/utils';

type StaffPageShellProps = PropsWithChildren<{
    title: ReactNode;
    subtitle?: ReactNode;
    /**
     * Optional single primary action rendered in the compact header.
     * Keep this slot lean — one button or a small cluster.
     */
    headerAction?: ReactNode;
    backHref?: string;
    backLabel?: string;
    /**
     * Override the bottom nav items if a specific staff page needs custom
     * targets. Defaults to the shared Home / Meds / Clock / Report / More set.
     */
    bottomNavItems?: StaffBottomNavItem[];
    /**
     * Called when the user taps the "More" bottom nav item.
     * Intended as the hook point for a future drawer — optional for now.
     */
    onMore?: () => void;
    /**
     * When true, hide the desktop sidebar and render the frontline header on
     * all breakpoints. Most pages should keep the default (false) so managers
     * and admins still see the usual chrome on larger screens.
     */
    mobileOnly?: boolean;
    className?: string;
}>;

/**
 * Frontline / staff layout shell.
 *
 * Structure:
 *   - compact StaffHeader (sticky top)
 *   - main content area with bottom padding that clears the mobile nav
 *   - persistent StaffBottomNav below `lg`
 *   - desktop sidebar from the existing AppSidebar (kept for parity with
 *     manager/admin expectations unless `mobileOnly` is set)
 *
 * Later PRs will consume this shell for My Day, eMAR flows, the incident
 * wizard, and clock actions. This PR wires the primitives only — it does not
 * re-implement those flows.
 */
export default function StaffPageShell({
    children,
    title,
    subtitle,
    headerAction,
    backHref,
    backLabel,
    bottomNavItems,
    onMore,
    mobileOnly = false,
    className,
}: StaffPageShellProps) {
    return (
        <div className="min-h-svh w-full overflow-x-hidden">
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:bg-primary focus:px-4 focus:py-2 focus:rounded-md focus:text-primary-foreground"
            >
                Skip to main content
            </a>

            <FlashToaster />

            {!mobileOnly ? (
                <div className="hidden lg:block">
                    <AppSidebar />
                </div>
            ) : null}

            <main
                id="main-content"
                className={cn(
                    'relative flex min-h-svh flex-col bg-background',
                    !mobileOnly && 'lg:ml-14',
                    className,
                )}
            >
                <StaffHeader
                    title={title}
                    subtitle={subtitle}
                    action={headerAction}
                    backHref={backHref}
                    backLabel={backLabel}
                />

                <div
                    className={cn(
                        'staff-shell-content flex-1 px-4 pt-4 md:px-6 md:pt-6',
                    )}
                >
                    {children}
                </div>
            </main>

            <StaffBottomNav items={bottomNavItems} onMore={onMore} />
        </div>
    );
}
