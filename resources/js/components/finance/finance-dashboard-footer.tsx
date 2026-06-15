import { Link } from '@inertiajs/react';
import { Download, Link as LinkIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

type FooterLink = { label: string; href?: string };

type FooterColumn = { heading: string; links: FooterLink[] };

// Only real, verified finance routes get an href; not-yet-built surfaces
// (resident trust funds, service agreements, remittances → Phase D) render as
// muted text rather than dead links.
const COLUMNS: FooterColumn[] = [
    {
        heading: 'General ledger',
        links: [
            { label: 'Chart of accounts', href: '/finance/accounts' },
            { label: 'Journals', href: '/finance/journals' },
            { label: 'Cost centres', href: '/finance/cost-centres' },
            { label: 'Fiscal periods', href: '/finance/fiscal-periods' },
        ],
    },
    {
        heading: 'Receivables & payables',
        links: [
            { label: 'Invoices', href: '/finance/invoices' },
            { label: 'Aged receivables', href: '/finance/reports/aged-receivables' },
            { label: 'Bills', href: '/finance/bills' },
            { label: 'Payment runs', href: '/finance/payment-runs' },
        ],
    },
    {
        heading: 'Supported living',
        links: [
            { label: 'Funding claims', href: '/finance/funding-streams' },
            { label: 'Resident trust funds' },
            { label: 'Service agreements' },
            { label: 'Remittances' },
        ],
    },
    {
        heading: 'Compliance',
        links: [
            { label: 'GST returns', href: '/finance/gst-returns' },
            { label: 'IRD filings', href: '/finance/ird-filings' },
            { label: 'Budgets vs actuals', href: '/finance/reports/budget-vs-actuals' },
            { label: 'Audit exports', href: '/finance/audit-exports' },
        ],
    },
];

function FooterButton({ href, icon: Icon, children }: { href: string; icon: typeof Download; children: string }) {
    return (
        <Link
            href={href}
            className="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-[12.5px] font-semibold text-muted-foreground transition-colors hover:bg-accent hover:text-primary"
        >
            <Icon className="h-[14px] w-[14px]" />
            {children}
        </Link>
    );
}

export function FinanceDashboardFooter({
    orgName = 'Whakaora Support Services',
    periodLabel = 'FY2026 · open period Jun 2026',
    className,
}: {
    orgName?: string;
    periodLabel?: string;
    className?: string;
}) {
    return (
        <footer className={cn('mt-2 border-t border-border pt-6', className)}>
            <div className="grid grid-cols-1 gap-8 md:grid-cols-2 lg:grid-cols-[1.4fr_repeat(4,1fr)]">
                <div className="space-y-3">
                    <div className="flex items-center gap-2.5">
                        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-primary to-primary/70 text-sm font-bold text-primary-foreground">
                            W
                        </span>
                        <span className="text-sm font-bold tracking-tight">Whakaora Finance</span>
                    </div>
                    <p className="max-w-xs text-[12px] leading-relaxed text-muted-foreground">
                        Live general ledger, supported-living funding claims and compliance for {orgName}.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <FooterButton href="/finance/audit-exports" icon={Download}>
                            Export pack
                        </FooterButton>
                        <FooterButton href="/finance/settings" icon={LinkIcon}>
                            Xero settings
                        </FooterButton>
                    </div>
                </div>
                {COLUMNS.map((col) => (
                    <div key={col.heading} className="space-y-2">
                        <div className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground/70">
                            {col.heading}
                        </div>
                        <ul className="space-y-1.5">
                            {col.links.map((l) => (
                                <li key={l.label}>
                                    {l.href ? (
                                        <Link
                                            href={l.href}
                                            className="text-[12.5px] text-muted-foreground transition-colors hover:text-primary"
                                        >
                                            {l.label}
                                        </Link>
                                    ) : (
                                        <span className="text-[12.5px] text-muted-foreground/50">{l.label}</span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
            <div className="mt-6 flex flex-wrap items-center justify-between gap-2 border-t border-border pt-4 text-[11.5px] text-muted-foreground/70">
                <span>© 2026 {orgName} · {periodLabel}</span>
                <span className="flex flex-wrap gap-3">
                    <span>Privacy</span>
                    <span>Data retention (7yr)</span>
                    <span>Status</span>
                    <span>Changelog</span>
                </span>
            </div>
        </footer>
    );
}

export default FinanceDashboardFooter;
