import { AppSidebar, AppSidebarMobile } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { useAppSidebarState } from '@/hooks/use-app-sidebar-state';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { type PropsWithChildren, type ReactNode, useCallback, useState } from 'react';

interface AppSidebarLayoutProps {
    breadcrumbs?: BreadcrumbItem[];
    /**
     * Replace the default breadcrumb header with a custom node (e.g. the
     * `/my-day` extended StaffHeader). When `null`, the header row is omitted
     * entirely. When `undefined`, the default AppSidebarHeader renders.
     */
    header?: ReactNode | null;
    /**
     * Override the inner content wrapper class so pages can opt out of the
     * default `px-5 py-6 md:px-8 md:py-10` padding (e.g. for full-bleed heroes
     * or pages that manage their own gutters).
     */
    contentClassName?: string;
}

const DEFAULT_CONTENT_CLASS = 'w-full px-5 py-6 md:px-8 md:py-10';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
    header,
    contentClassName,
}: PropsWithChildren<AppSidebarLayoutProps>) {
    const defaultSidebarOpen = usePage<SharedData>().props.sidebarOpen ?? true;
    const { collapsed, setExpanded } = useAppSidebarState(defaultSidebarOpen);
    const [mobileOpen, setMobileOpen] = useState(false);
    const closeMobileSidebar = useCallback(() => setMobileOpen(false), []);

    return (
        <div className="min-h-svh w-full">
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-primary-foreground"
            >
                Skip to main content
            </a>
            <AppSidebar
                collapsed={collapsed}
                onCollapsedChange={(nextCollapsed) =>
                    setExpanded(!nextCollapsed)
                }
            />
            <AppSidebarMobile open={mobileOpen} onClose={closeMobileSidebar} />
            <main
                id="main-content"
                className={cn(
                    'relative flex min-h-svh flex-col bg-background transition-[margin-left] duration-200 ease-in-out',
                    collapsed ? 'md:ml-16' : 'md:ml-64',
                )}
            >
                {header === undefined ? (
                    <AppSidebarHeader
                        breadcrumbs={breadcrumbs}
                        onMobileMenuToggle={() => setMobileOpen(true)}
                    />
                ) : header}
                <div className={cn(contentClassName ?? DEFAULT_CONTENT_CLASS)}>
                    {children}
                </div>
            </main>
        </div>
    );
}
