import { Button } from '@/components/ui/button';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import StaffPageShell from '@/layouts/staff-page-shell';
import type { SharedData } from '@/types';
import { type BreadcrumbItem } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

type Experience = 'default' | 'staff';

interface AppLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    /**
     * Switch the page into the frontline / staff shell.
     *   - 'default' (the existing sidebar layout — manager / admin / client)
     *   - 'staff'   (compact header + mobile bottom nav)
     *
     * Pages should pass `experience="staff"` along with `staffTitle`
     * (and optionally `staffSubtitle` / `staffAction`) to opt in.
     */
    experience?: Experience;
    staffTitle?: ReactNode;
    staffSubtitle?: ReactNode;
    staffAction?: ReactNode;
    staffBackHref?: string;
    staffBackLabel?: string;
    /**
     * Default-experience only: replace the default breadcrumb header with a
     * custom node (e.g. the desktop-redesigned `/my-day` StaffHeader). Pass
     * `null` to render no header at all.
     */
    header?: ReactNode | null;
    /**
     * Default-experience only: override the content wrapper class so pages
     * can opt out of the default page padding for full-bleed layouts.
     */
    contentClassName?: string;
    user?: unknown;
    [key: string]: unknown;
}

export default function AppLayout({
    children,
    breadcrumbs,
    experience = 'default',
    staffTitle,
    staffSubtitle,
    staffAction,
    staffBackHref,
    staffBackLabel,
    header,
    contentClassName,
}: AppLayoutProps) {
    const { auth } = usePage<SharedData>().props;
    if (auth?.user?.role === 'external_clinician') {
        return (
            <main className="min-h-screen bg-background p-5 text-foreground">
                <div className="mx-auto max-w-5xl space-y-5">
                    <header className="flex items-center justify-between border-b border-border pb-4">
                        <Link
                            href="/clinical-portal"
                            className="text-lg font-semibold"
                        >
                            Clinical portal
                        </Link>
                        <Button
                            variant="outline"
                            onClick={() => router.post('/logout')}
                        >
                            Sign out
                        </Button>
                    </header>
                    {children}
                </div>
            </main>
        );
    }
    if (experience === 'staff') {
        return (
            <StaffPageShell
                title={staffTitle ?? ''}
                subtitle={staffSubtitle}
                headerAction={staffAction}
                backHref={staffBackHref}
                backLabel={staffBackLabel}
            >
                {children}
            </StaffPageShell>
        );
    }

    return (
        <AppLayoutTemplate
            breadcrumbs={reachableCrumbs(breadcrumbs, auth)}
            header={header}
            contentClassName={contentClassName}
        >
            {children}
        </AppLayoutTemplate>
    );
}

/**
 * A crumb never links to a page that refuses the viewer: the H&S Overview needs hazards.view, so for anyone
 * without it the "Health & safety" crumb shows as plain text (found in the 30 Oct user test: report-only staff
 * and the provider manager landed on a 403 from it).
 */
function reachableCrumbs(
    breadcrumbs: BreadcrumbItem[] | undefined,
    auth: SharedData['auth'] | undefined,
): BreadcrumbItem[] | undefined {
    const canOpenSafetyOverview = !!(
        auth?.can as { hazards?: { view?: boolean } } | undefined
    )?.hazards?.view;
    if (!breadcrumbs || canOpenSafetyOverview) return breadcrumbs;
    return breadcrumbs.map((crumb) =>
        crumb.href === '/health-safety' ? { ...crumb, href: undefined } : crumb,
    );
}
