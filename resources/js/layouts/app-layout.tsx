import { SecurityDevicesModuleShell } from '@/components/security-devices/security-devices-module-shell';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import StaffPageShell from '@/layouts/staff-page-shell';
import { type BreadcrumbItem } from '@/types';
import { usePage } from '@inertiajs/react';
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
    const page = usePage();

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

    const content = page.url.startsWith('/security-devices') ? (
        <SecurityDevicesModuleShell>{children}</SecurityDevicesModuleShell>
    ) : (
        children
    );

    return (
        <AppLayoutTemplate
            breadcrumbs={breadcrumbs}
            header={header}
            contentClassName={contentClassName}
        >
            {content}
        </AppLayoutTemplate>
    );
}
