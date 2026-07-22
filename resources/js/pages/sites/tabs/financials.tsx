import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import SiteLedgerPanel, { type SiteLedgerPanelData } from '../_ledger-panel';
import {
    SiteProfileEmptyState,
    SiteProfileLockedState,
} from './site-profile-states';

export type SiteProfileFinancialsModule = {
    locked?: boolean;
    site: { id: number; name: string; type: string };
    href?: string | null;
    house_ledger?: SiteLedgerPanelData | null;
};

export function SiteProfileFinancials({
    data,
}: {
    data: SiteProfileFinancialsModule;
}) {
    if (data.locked) return <SiteProfileLockedState label="Financials" />;

    if (!data.href && !data.house_ledger) {
        return (
            <SiteProfileEmptyState
                title="Financial workflows unavailable"
                description="No Finance dashboard or House Ledger is available for this Site."
            />
        );
    }

    return (
        <div className="space-y-5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-lg font-semibold">Financials</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Authorised Site finance reporting and the complete
                        operational House Ledger.
                    </p>
                </div>
                {data.href ? (
                    <Button asChild size="sm" variant="outline">
                        <Link href={data.href}>
                            Finance Site Dashboard
                            <ExternalLink className="ml-1.5 h-4 w-4" />
                        </Link>
                    </Button>
                ) : null}
            </div>
            {data.house_ledger ? (
                <SiteLedgerPanel
                    site={data.site}
                    ledgerData={data.house_ledger}
                />
            ) : null}
        </div>
    );
}
