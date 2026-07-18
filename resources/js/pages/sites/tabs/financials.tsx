import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import { ExternalLink, NotebookTabs } from 'lucide-react';
import {
    SiteProfileEmptyState,
    SiteProfileLockedState,
} from './site-profile-states';

export type SiteProfileFinancialsModule = {
    locked?: boolean;
    href?: string | null;
    house_ledger?: { href: string; label: string } | null;
};

export function SiteProfileFinancials({
    data,
}: {
    data: SiteProfileFinancialsModule;
}) {
    if (data.locked) return <SiteProfileLockedState label="Financials" />;

    if (!data.href) {
        return (
            <SiteProfileEmptyState
                title="Financial dashboard unavailable"
                description="No Finance Site Dashboard is available for this Site."
            />
        );
    }

    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold">Financials</h2>
                    <p className="text-sm text-muted-foreground">
                        Finance owns budgets, actuals, transactions, and
                        reporting for this Site.
                    </p>
                </div>
                <Button asChild className="min-h-11">
                    <Link href={data.href}>
                        Open Finance Site Dashboard
                        <ExternalLink className="ml-2 h-4 w-4" />
                    </Link>
                </Button>
            </div>

            {data.house_ledger ? (
                <Card>
                    <CardContent className="flex flex-wrap items-center gap-4 p-4">
                        <NotebookTabs className="h-5 w-5 shrink-0 text-muted-foreground" />
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="font-semibold">
                                    {data.house_ledger.label}
                                </p>
                                <Badge variant="outline">
                                    Secondary Site workflow
                                </Badge>
                            </div>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Operational house income and expenses remain in
                                the dedicated ledger.
                            </p>
                        </div>
                        <Button variant="outline" asChild className="min-h-11">
                            <Link href={data.house_ledger.href}>
                                Open house ledger
                            </Link>
                        </Button>
                    </CardContent>
                </Card>
            ) : null}
        </div>
    );
}
