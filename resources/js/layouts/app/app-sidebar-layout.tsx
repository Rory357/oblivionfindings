import { AppSidebar, AppSidebarMobile } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import FlashToaster from '@/components/flash-toaster';
import { useAppSidebarState } from '@/hooks/use-app-sidebar-state';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { type PropsWithChildren, useCallback, useState } from 'react';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: PropsWithChildren<{ breadcrumbs?: BreadcrumbItem[] }>) {
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
            <FlashToaster />
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
                <AppSidebarHeader
                    breadcrumbs={breadcrumbs}
                    onMobileMenuToggle={() => setMobileOpen(true)}
                />
                <div className="w-full px-5 py-6 md:px-8 md:py-10">
                    {children}
                </div>
            </main>
        </div>
    );
}
