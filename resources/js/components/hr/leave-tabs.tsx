import { router, usePage } from '@inertiajs/react';
import { BarChart3, CalendarOff, List, TrendingDown } from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type LeaveTab = 'requests' | 'balances' | 'holidays' | 'reports';

const TAB_URLS: Record<LeaveTab, string> = {
    requests: '/hr/leave',
    balances: '/hr/leave/balances',
    holidays: '/hr/leave/holidays',
    reports: '/hr/leave/reports',
};

type HrCan = {
    leave?: { viewAny?: boolean };
};

/**
 * Section-level tab strip shared across the Leave & Rosters pages (Requests ·
 * Balances · Holidays · Reports) so the cluster reads as one hub. All four
 * surfaces sit behind the SAME hr.leave.viewAny gate, so they appear together;
 * the strip is still filtered by the shared auth.can flag and the active tab is
 * always shown so the current page never hides its own tab.
 */
export function LeaveTabs({ active }: { active: LeaveTab }) {
    const hr = (usePage().props as { auth?: { can?: { hr?: HrCan } } }).auth?.can
        ?.hr;

    const canView = !!hr?.leave?.viewAny;

    const all: Array<{ item: HrTabItem; show: boolean }> = [
        {
            item: { id: 'requests', label: 'Requests', icon: List, tone: 'primary' },
            show: canView,
        },
        {
            item: { id: 'balances', label: 'Balances', icon: BarChart3, tone: 'info' },
            show: canView,
        },
        {
            item: { id: 'holidays', label: 'Holidays', icon: CalendarOff, tone: 'violet' },
            show: canView,
        },
        {
            item: { id: 'reports', label: 'Reports', icon: TrendingDown, tone: 'success' },
            show: canView,
        },
    ];

    const items: HrTabItem[] = all
        .filter((t) => t.show || t.item.id === active)
        .map((t) => t.item);

    return (
        <HrTabs
            value={active}
            onChange={(id) => {
                if (id !== active) router.visit(TAB_URLS[id as LeaveTab]);
            }}
            items={items}
            ariaLabel="Leave views"
        />
    );
}

export default LeaveTabs;
