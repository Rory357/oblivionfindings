import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import StaffPageShell from '@/layouts/staff-page-shell';
import { type BreadcrumbItem } from '@/types';
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
}: AppLayoutProps) {
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
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>{children}</AppLayoutTemplate>
    );
}
