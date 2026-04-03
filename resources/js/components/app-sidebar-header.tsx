import { Breadcrumbs } from '@/components/breadcrumbs';
import GlobalQueryBar from '@/components/global-query-bar';
import InboxMenus from '@/components/inbox-menus';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem as BreadcrumbItemType, type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { PanelLeftIcon, ShieldAlert } from 'lucide-react';

export function AppSidebarHeader({
    breadcrumbs = [],
    onMobileMenuToggle,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    onMobileMenuToggle?: () => void;
}) {
    const { auth } = usePage<SharedData>().props;

    const handleStopImpersonating = () => {
        router.post('/system/users/stop-impersonating');
    };

    return (
        <>
            {auth.impersonating && (
                <div className="flex items-center justify-between gap-2 bg-violet-600 px-6 py-2 text-sm font-medium text-white md:px-4">
                    <div className="flex items-center gap-2">
                        <ShieldAlert className="h-4 w-4 shrink-0" />
                        <span>
                            You are impersonating <strong>{auth.user.name}</strong>
                            {auth.impersonator && (
                                <> (logged in as {auth.impersonator.name})</>
                            )}
                        </span>
                    </div>
                    <Button
                        size="sm"
                        className="shrink-0 border border-white/30 bg-white text-violet-700 hover:bg-violet-50"
                        onClick={handleStopImpersonating}
                    >
                        Stop Impersonating
                    </Button>
                </div>
            )}
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
        </>
    );
}
