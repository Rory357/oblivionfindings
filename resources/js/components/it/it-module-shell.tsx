import { usePage } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import type { ReactNode } from 'react';
import { ItSideNavigation, type ItNavigationGroup } from './it-side-navigation';

interface Props {
    children: ReactNode;
}

export function ItModuleShell({ children }: Props) {
    const page = usePage<{ itNavigation?: ItNavigationGroup[] | null }>();
    const groups = page.props.itNavigation ?? [];

    if (groups.length === 0) {
        return <>{children}</>;
    }

    return (
        <div className="mx-auto grid w-full max-w-[1800px] gap-5 px-4 py-4 lg:grid-cols-[17rem_minmax(0,1fr)] lg:px-6">
            <aside className="hidden self-start rounded-2xl border border-border bg-card p-3 shadow-sm lg:sticky lg:top-4 lg:block">
                <div className="mb-4 border-b border-border px-3 pb-3">
                    <p className="text-sm font-bold">IT & Support</p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Service management
                    </p>
                </div>
                <ItSideNavigation groups={groups} currentUrl={page.url} />
            </aside>

            <details className="group rounded-2xl border border-border bg-card p-3 shadow-sm lg:hidden">
                <summary className="frontline-focus flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-xl px-2 font-semibold">
                    <Menu className="h-5 w-5 text-primary" aria-hidden="true" />
                    IT & Support navigation
                </summary>
                <div className="mt-3 border-t border-border pt-3">
                    <ItSideNavigation groups={groups} currentUrl={page.url} />
                </div>
            </details>

            <div className="min-w-0">{children}</div>
        </div>
    );
}
