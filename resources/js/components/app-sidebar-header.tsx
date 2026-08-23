import { Breadcrumbs } from '@/components/breadcrumbs';
import GlobalNavSearch from '@/components/global-nav-search';
import GlobalQueryBar from '@/components/global-query-bar';
import InboxMenus from '@/components/inbox-menus';
import { Button } from '@/components/ui/button';
import { SheetTrigger } from '@/components/ui/sheet';
import {
    type BreadcrumbItem as BreadcrumbItemType,
    type SharedData,
} from '@/types';
import { router, usePage } from '@inertiajs/react';
import { PanelLeftIcon, ShieldAlert } from 'lucide-react';

export function AppSidebarHeader({
    breadcrumbs = [],
    showMobileMenuTrigger = false,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    showMobileMenuTrigger?: boolean;
}) {
    const { auth } = usePage<SharedData>().props;

    const handleStopImpersonating = () => {
        router.post('/system/users/stop-impersonating');
    };

    return (
        <>
            {auth.impersonating && (
                <div className="flex items-center justify-between gap-2 bg-primary px-6 py-2 text-sm font-medium text-primary-foreground md:px-4">
                    <div className="flex items-center gap-2">
                        <ShieldAlert className="h-4 w-4 shrink-0" />
                        <span>
                            You are impersonating{' '}
                            <strong>{auth.user.name}</strong>
                            {auth.impersonator && (
                                <> (logged in as {auth.impersonator.name})</>
                            )}
                        </span>
                    </div>
                    <Button
                        size="sm"
                        className="shrink-0 border border-primary-foreground/30 bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                        onClick={handleStopImpersonating}
                    >
                        Stop Impersonating
                    </Button>
                </div>
            )}
            <header className="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-border/50 px-6 md:px-4">
                <div className="flex min-w-0 items-center gap-2">
                    {showMobileMenuTrigger && (
                        <SheetTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="frontline-focus frontline-tap -ml-1 h-11 w-11 md:hidden"
                            >
                                <PanelLeftIcon className="h-4 w-4" />
                                <span className="sr-only">Toggle Menu</span>
                            </Button>
                        </SheetTrigger>
                    )}
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>

                <div className="flex items-center">
                    <GlobalNavSearch />
                    <GlobalQueryBar />
                    <InboxMenus />
                </div>
            </header>
        </>
    );
}
