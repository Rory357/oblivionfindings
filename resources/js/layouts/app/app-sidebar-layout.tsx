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
        <div className="flex min-h-svh w-full">
            <FlashToaster />
            <AppSidebar />
            <main className="bg-background relative flex min-h-svh flex-1 flex-col max-w-full">
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
