import { router, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    Car,
    GraduationCap,
    LayoutGrid,
    ShieldCheck,
    UserCheck,
} from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type ComplianceTab =
    | 'overview'
    | 'matrix'
    | 'calendar'
    | 'training'
    | 'vetting'
    | 'drivers';

const TAB_URLS: Record<ComplianceTab, string> = {
    overview: '/hr/compliance',
    matrix: '/hr/compliance/matrix',
    calendar: '/hr/compliance/calendar',
    training: '/hr/compliance/training',
    vetting: '/hr/compliance/vetting',
    drivers: '/hr/compliance/drivers',
};

type HrCan = {
    compliance?: { view?: boolean; manage?: boolean };
    training?: { view?: boolean };
    vetting?: { view?: boolean };
    driver?: { view?: boolean };
};

/**
 * Section-level tab strip shared across the Compliance pages (which span the
 * compliance/, training/, vetting/ and drivers/ clusters). The six surfaces sit
 * behind DIFFERENT permission gates, so tabs are filtered by the shared
 * auth.can flags — a user only sees the views they can open (no 403-on-click).
 * The active tab is always shown so the current page never hides its own tab.
 */
export function ComplianceTabs({ active }: { active: ComplianceTab }) {
    const hr = (usePage().props as { auth?: { can?: { hr?: HrCan } } }).auth?.can
        ?.hr;

    const all: Array<{ item: HrTabItem; show: boolean }> = [
        {
            item: { id: 'overview', label: 'Overview', icon: ShieldCheck, tone: 'primary' },
            show: !!hr?.compliance?.view,
        },
        {
            item: { id: 'matrix', label: 'Matrix', icon: LayoutGrid, tone: 'info' },
            show: !!hr?.compliance?.manage,
        },
        {
            item: { id: 'calendar', label: 'Calendar', icon: CalendarDays, tone: 'violet' },
            show: !!hr?.compliance?.view,
        },
        {
            item: { id: 'training', label: 'Training', icon: GraduationCap, tone: 'success' },
            show: !!hr?.training?.view,
        },
        {
            item: { id: 'vetting', label: 'Vetting', icon: UserCheck, tone: 'warning' },
            show: !!hr?.vetting?.view,
        },
        {
            item: { id: 'drivers', label: 'Drivers', icon: Car, tone: 'critical' },
            show: !!hr?.driver?.view,
        },
    ];

    const items: HrTabItem[] = all
        .filter((t) => t.show || t.item.id === active)
        .map((t) => t.item);

    return (
        <HrTabs
            value={active}
            onChange={(id) => {
                if (id !== active) router.visit(TAB_URLS[id as ComplianceTab]);
            }}
            items={items}
            ariaLabel="Compliance views"
        />
    );
}

export default ComplianceTabs;
