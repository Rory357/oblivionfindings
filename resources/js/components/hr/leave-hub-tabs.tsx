import { router } from '@inertiajs/react';
import {
    BarChart3,
    CalendarDays,
    CalendarOff,
    CheckCircle2,
    LayoutDashboard,
    TrendingDown,
} from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

/**
 * Canonical Leave hub tab. The hub reads as a single surface: Overview,
 * Approvals and Calendar render in-page on `/hr/leave?tab=…`; Balances and
 * Reports are their own routes wearing the same strip. Public holidays live in
 * the trailing "More" affordance (handover design — not a primary tab).
 */
export type LeaveHubTab =
    | 'overview'
    | 'approvals'
    | 'calendar'
    | 'balances'
    | 'reports'
    | 'holidays';

const TAB_URLS: Record<LeaveHubTab, string> = {
    overview: '/hr/leave?tab=overview',
    approvals: '/hr/leave?tab=approvals',
    calendar: '/hr/leave?tab=calendar',
    balances: '/hr/leave/balances',
    reports: '/hr/leave/reports',
    holidays: '/hr/leave/holidays',
};

export function LeaveHubTabs({
    active,
    pendingCount = 0,
}: {
    active: LeaveHubTab;
    pendingCount?: number;
}) {
    const items: HrTabItem[] = [
        { id: 'overview', label: 'Overview', icon: LayoutDashboard, tone: 'primary' },
        {
            id: 'approvals',
            label: 'Approvals',
            icon: CheckCircle2,
            tone: 'warning',
            badge: pendingCount > 0 ? pendingCount : undefined,
        },
        { id: 'calendar', label: 'Calendar', icon: CalendarDays, tone: 'info' },
        { id: 'balances', label: 'Balances', icon: BarChart3, tone: 'violet' },
        { id: 'reports', label: 'Reports', icon: TrendingDown, tone: 'success' },
    ];

    return (
        <HrTabs
            value={active === 'holidays' ? '' : active}
            onChange={(id) => {
                if (id !== active) {
                    router.visit(TAB_URLS[id as LeaveHubTab], {
                        preserveScroll: false,
                    });
                }
            }}
            items={items}
            ariaLabel="Leave views"
            className="mb-6"
            trailing={
                // eslint-disable-next-line no-restricted-syntax -- overflow affordance styled as a tab chip, not a standard form button
                <button
                    type="button"
                    onClick={() => router.visit(TAB_URLS.holidays)}
                    className={`ml-auto inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors ${
                        active === 'holidays'
                            ? 'bg-primary/10 text-primary'
                            : 'text-muted-foreground hover:bg-muted'
                    }`}
                    title="Public holidays & settings"
                >
                    <CalendarOff className="h-4 w-4" />
                    Holidays
                </button>
            }
        />
    );
}

export default LeaveHubTabs;
