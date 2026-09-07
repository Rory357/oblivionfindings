import { AppHeader } from '@/components/app-header';
import { AppSidebar, AppSidebarMobile } from '@/components/app-sidebar';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Sheet } from '@/components/ui/sheet';
import { useAppSidebarState } from '@/hooks/use-app-sidebar-state';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import {
    type PropsWithChildren,
    type ReactNode,
    useCallback,
    useEffect,
    useState,
} from 'react';

/**
 * The Event Horizon app shell (design_styles/APP_SHELL_STYLE_GUIDE.md): a
 * full-width ink command header with the ink sidebar rail below it — one
 * continuous dark L-frame around the grey page ground. Page context (title,
 * date) lives in the page itself, so the shell only contributes the slim
 * breadcrumb strip above the page header band — every page passes a full
 * trail rooted at Home (`/dashboard`), so the strip renders on every page
 * (the length guard below only hides legacy single-crumb trails, which
 * would duplicate the band's own title).
 */

interface AppSidebarLayoutProps {
    breadcrumbs?: BreadcrumbItem[];
    /**
     * Replace the default breadcrumb strip with a custom node (e.g. the
     * `/my-day` extended StaffHeader). When `null`, the strip is omitted
     * entirely. When `undefined`, breadcrumbs render when there are any.
     * The global command header always renders — it is chrome, not content.
     */
    header?: ReactNode | null;
    /**
     * Override the inner content wrapper class so pages can opt out of the
     * default `px-5 py-6 md:px-8 md:py-10` padding (e.g. for full-bleed heroes
     * or pages that manage their own gutters).
     */
    contentClassName?: string;
}

/**
 * THE shell gutter (approved 2026-09-05, revised same day 10px → 20px):
 * the shell owns a single 20px gap between the ink chrome (sidebar + top
 * bar) and page content. Pages and PageLayout must NOT stack their own
 * outer padding on top of it — see DESIGN.md "Named anti-patterns" and
 * APP_SHELL_STYLE_GUIDE.md §4.
 */
const DEFAULT_CONTENT_CLASS = 'w-full p-5';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
    header,
    contentClassName,
}: PropsWithChildren<AppSidebarLayoutProps>) {
    const defaultSidebarOpen = usePage<SharedData>().props.sidebarOpen ?? true;
    const { collapsed, setExpanded } = useAppSidebarState(defaultSidebarOpen);
    const isMobile = useIsMobile();
    const [mobileOpen, setMobileOpen] = useState(false);
    const closeMobileSidebar = useCallback(() => setMobileOpen(false), []);

    useEffect(() => {
        if (!isMobile) {
            closeMobileSidebar();
        }
    }, [closeMobileSidebar, isMobile]);

    return (
        <Sheet modal open={mobileOpen} onOpenChange={setMobileOpen}>
            <div className="min-h-svh w-full bg-background">
                <a
                    href="#main-content"
                    className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-primary-foreground"
                >
                    Skip to main content
                </a>
                <AppHeader showMobileMenuTrigger />
                <div className="flex w-full items-start">
                    {/* Mount only the sidebar for the current viewport — both
                        build the full 200+-item nav tree, so rendering the
                        hidden one too doubled that work on every page.
                        (useIsMobile is a synchronous media-query read; if SSR
                        is ever enabled, revisit for hydration safety.) */}
                    {isMobile ? (
                        <AppSidebarMobile onClose={closeMobileSidebar} />
                    ) : (
                        <AppSidebar
                            collapsed={collapsed}
                            onCollapsedChange={(nextCollapsed) =>
                                setExpanded(!nextCollapsed)
                            }
                        />
                    )}
                    <main
                        id="main-content"
                        className="relative flex min-h-[calc(100svh-58px)] w-full min-w-0 flex-col bg-background"
                    >
                        {header === undefined
                            ? breadcrumbs.length > 1 && (
                                  /* 10px above AND below the crumbs (approved
                                   * 2026-09-06 — the strip's one exception to
                                   * the 20px rhythm); the content wrapper
                                   * drops its top padding beneath it. */
                                  <div className="flex items-center px-5 py-2.5 text-muted-foreground">
                                      <Breadcrumbs breadcrumbs={breadcrumbs} />
                                  </div>
                              )
                            : header}
                        <div
                            className={cn(
                                contentClassName ?? DEFAULT_CONTENT_CLASS,
                                header === undefined &&
                                    breadcrumbs.length > 1 &&
                                    'pt-0',
                            )}
                        >
                            {children}
                        </div>
                    </main>
                </div>
            </div>
        </Sheet>
    );
}
