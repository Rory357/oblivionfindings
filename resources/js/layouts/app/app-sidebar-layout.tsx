import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import FlashToaster from '@/components/flash-toaster';
import { type BreadcrumbItem } from '@/types';
import { type PropsWithChildren, useState } from 'react';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: PropsWithChildren<{ breadcrumbs?: BreadcrumbItem[] }>) {
    const [mobileOpen, setMobileOpen] = useState(false);

    return (
        <div className="min-h-svh w-full">
            <a href="#main-content" className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:bg-primary focus:text-primary-foreground focus:px-4 focus:py-2 focus:rounded-md">
                Skip to main content
            </a>
            <FlashToaster />
            <AppSidebar />
            {/* Main content offset by sidebar width (w-14 = 56px) */}
            <main id="main-content" className="bg-background relative flex min-h-svh flex-col md:ml-14">
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
