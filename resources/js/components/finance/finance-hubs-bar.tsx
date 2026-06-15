import { Link } from '@inertiajs/react';
import {
    BarChart3,
    BookOpen,
    Coins,
    CreditCard,
    Landmark,
    Layers,
    Percent,
    Receipt,
    type LucideIcon,
} from 'lucide-react';

import { cn } from '@/lib/utils';

type HubChip = {
    label: string;
    href: string;
    icon: LucideIcon;
};

// Cross-hub navigation (Inertia <Link>, not tabs). Routes verified against
// routes/finance.php (prefix finance.): accounts/receivables/bills/
// bank-accounts/funding-streams/reports.profit-loss/gst-returns.
const HUBS: HubChip[] = [
    { label: 'Ledger', href: '/finance/accounts', icon: BookOpen },
    { label: 'Receivables', href: '/finance/receivables', icon: Receipt },
    { label: 'Payables', href: '/finance/bills', icon: CreditCard },
    { label: 'Banking', href: '/finance/bank-accounts', icon: Landmark },
    { label: 'Funding & Claims', href: '/finance/funding-streams', icon: Coins },
    { label: 'Reports', href: '/finance/reports/profit-loss', icon: BarChart3 },
    { label: 'Tax', href: '/finance/gst-returns', icon: Percent },
];

export function FinanceHubsBar({ className }: { className?: string }) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-center gap-3 rounded-2xl border border-border bg-card px-4 py-3',
                className,
            )}
        >
            <div className="flex items-center gap-2.5 border-border pr-3 sm:border-r">
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-accent text-primary">
                    <Layers className="h-4 w-4" />
                </span>
                <div className="leading-tight">
                    <div className="text-[13px] font-bold tracking-tight">Finance hubs</div>
                    <div className="text-[11.5px] text-muted-foreground">Jump to a workspace</div>
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                {HUBS.map((hub) => {
                    const Icon = hub.icon;
                    return (
                        <Link
                            key={hub.label}
                            href={hub.href}
                            className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border bg-muted/50 px-3 text-[13px] font-semibold text-muted-foreground transition-colors hover:border-primary/30 hover:bg-accent hover:text-primary"
                        >
                            <Icon className="h-[15px] w-[15px]" />
                            {hub.label}
                        </Link>
                    );
                })}
            </div>
        </div>
    );
}

export default FinanceHubsBar;
