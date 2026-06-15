import { router, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Bookmark,
    LayoutGrid,
    Settings,
    Users,
    Webhook,
    Wrench,
} from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type ReportsTab =
    | 'index'
    | 'builder'
    | 'saved'
    | 'automations'
    | 'webhooks'
    | 'analytics'
    | 'headcount';

const TAB_URLS: Record<ReportsTab, string> = {
    index: '/hr/reports',
    builder: '/hr/reports/builder',
    saved: '/hr/reports/saved',
    automations: '/hr/reports/automations',
    webhooks: '/hr/reports/webhooks',
    analytics: '/hr/analytics',
    headcount: '/hr/headcount',
};

type HrCan = {
    reports?: { view?: boolean };
    analytics?: { view?: boolean };
};

/**
 * Section-level tab strip shared across the HR Reports pages so the cluster
 * reads as one hub. The Analytics + Headcount dashboards are folded in here but
 * sit behind a DIFFERENT gate (hr.analytics.view) from the report builder
 * surfaces (hr.reports.view), so tabs are per-tab filtered by the shared
 * auth.can flags — a user only sees the views they can open (no 403-on-click).
 * The active tab is always shown so the current page never hides its own tab.
 */
export function ReportsTabs({ active }: { active: ReportsTab }) {
    const hr = (usePage().props as { auth?: { can?: { hr?: HrCan } } }).auth?.can
        ?.hr;

    const all: Array<{ item: HrTabItem; show: boolean }> = [
        {
            item: { id: 'index', label: 'Reports', icon: BarChart3, tone: 'primary' },
            show: !!hr?.reports?.view,
        },
        {
            item: { id: 'builder', label: 'Builder', icon: Wrench, tone: 'info' },
            show: !!hr?.reports?.view,
        },
        {
            item: { id: 'saved', label: 'Saved', icon: Bookmark, tone: 'violet' },
            show: !!hr?.reports?.view,
        },
        {
            item: { id: 'automations', label: 'Automations', icon: Settings, tone: 'success' },
            show: !!hr?.reports?.view,
        },
        {
            item: { id: 'webhooks', label: 'Webhooks', icon: Webhook, tone: 'warning' },
            show: !!hr?.reports?.view,
        },
        {
            item: { id: 'analytics', label: 'Analytics', icon: LayoutGrid, tone: 'critical' },
            show: !!hr?.analytics?.view,
        },
        {
            item: { id: 'headcount', label: 'Headcount', icon: Users, tone: 'info' },
            show: !!hr?.analytics?.view,
        },
    ];

    const items: HrTabItem[] = all
        .filter((t) => t.show || t.item.id === active)
        .map((t) => t.item);

    return (
        <HrTabs
            value={active}
            onChange={(id) => {
                if (id !== active) router.visit(TAB_URLS[id as ReportsTab]);
            }}
            items={items}
            ariaLabel="Reports views"
        />
    );
}

export default ReportsTabs;
