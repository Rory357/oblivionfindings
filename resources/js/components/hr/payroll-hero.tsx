import { HrHero } from '@/components/hr/hr-hero';
import { Banknote, Receipt } from 'lucide-react';
import type { ReactNode } from 'react';

type RunCounts = {
    total: number;
    draft: number;
    locked: number;
    exported: number;
};

type PayslipCounts = { total: number; draft: number; paid: number };

export function PayrollHero({
    surface,
    counts,
    actions,
}: {
    surface: 'runs' | 'payslips';
    counts: RunCounts | PayslipCounts;
    actions?: ReactNode;
}) {
    const runs = surface === 'runs';
    const stats = runs
        ? [
              { label: 'Total runs', value: counts.total, href: '/hr/payroll' },
              {
                  label: 'Drafts',
                  value: counts.draft,
                  href: '/hr/payroll?status=draft',
              },
              {
                  label: 'Locked',
                  value: (counts as RunCounts).locked,
                  href: '/hr/payroll?status=locked',
              },
              {
                  label: 'Exported',
                  value: (counts as RunCounts).exported,
                  href: '/hr/payroll?status=exported',
              },
          ]
        : [
              {
                  label: 'Total',
                  value: counts.total,
                  href: '/hr/payroll/payslips',
              },
              {
                  label: 'Drafts',
                  value: counts.draft,
                  href: '/hr/payroll/payslips?status=draft',
              },
              {
                  label: 'Paid',
                  value: (counts as PayslipCounts).paid,
                  href: '/hr/payroll/payslips?status=paid',
              },
          ];

    return (
        <HrHero
            icon={runs ? Banknote : Receipt}
            title={runs ? 'Payroll runs' : 'Payslips'}
            description={
                runs
                    ? 'Manage payroll periods, lock runs, and export to your payroll provider.'
                    : 'Generate, review, and distribute employee payslips.'
            }
            stats={stats}
            actions={actions}
        />
    );
}

export default PayrollHero;
