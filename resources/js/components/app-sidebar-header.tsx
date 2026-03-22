import { Breadcrumbs } from '@/components/breadcrumbs';
import GlobalQueryBar from '@/components/global-query-bar';
import InboxMenus from '@/components/inbox-menus';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';
import { PanelLeftIcon } from 'lucide-react';

export function AppSidebarHeader({
    breadcrumbs = [],
    onMobileMenuToggle,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    onMobileMenuToggle?: () => void;
}) {
    return (
        <header className="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-border/50 px-6 md:px-4">
            <div className="flex min-w-0 items-center gap-2">
                {onMobileMenuToggle && (
                    <button
                        onClick={onMobileMenuToggle}
                        className="-ml-1 h-7 w-7 inline-flex items-center justify-center rounded-md hover:bg-accent md:hidden"
                    >
                        <PanelLeftIcon className="h-4 w-4" />
                        <span className="sr-only">Toggle Menu</span>
                    </button>
                )}
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>

            <div className="flex items-center">
                <GlobalQueryBar />
                <InboxMenus />
            </div>
        </header>
    );
}
