import { router, usePage } from '@inertiajs/react';
import { Banknote, FileText } from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type PayrollTab = 'runs' | 'payslips';

const TAB_URLS: Record<PayrollTab, string> = {
    runs: '/hr/payroll',
    payslips: '/hr/payroll/payslips',
};

type HrCan = {
    payroll?: { view?: boolean };
    payslips?: { view?: boolean };
};

/**
 * Section-level tab strip shared across the Payroll pages (Runs + Payslips) so
 * the cluster reads as one hub. The two surfaces sit behind DIFFERENT
 * permission gates — Runs is hr.payroll.view, Payslips is hr.payslips.view — so
 * tabs are filtered by the shared auth.can flags: a user only sees the views
 * they can open (no 403-on-click). The active tab is always shown so the
 * current page never hides its own tab.
 */
export function PayrollTabs({ active }: { active: PayrollTab }) {
    const hr = (usePage().props as { auth?: { can?: { hr?: HrCan } } }).auth?.can
        ?.hr;

    const all: Array<{ item: HrTabItem; show: boolean }> = [
        {
            item: { id: 'runs', label: 'Runs', icon: Banknote, tone: 'primary' },
            show: !!hr?.payroll?.view,
        },
        {
            item: { id: 'payslips', label: 'Payslips', icon: FileText, tone: 'info' },
            show: !!hr?.payslips?.view,
        },
    ];

    const items: HrTabItem[] = all
        .filter((t) => t.show || t.item.id === active)
        .map((t) => t.item);

    return (
        <HrTabs
            value={active}
            onChange={(id) => {
                if (id !== active) router.visit(TAB_URLS[id as PayrollTab]);
            }}
            items={items}
            ariaLabel="Payroll views"
            className="mb-6"
        />
    );
}

export default PayrollTabs;
