import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { DoorOpen } from 'lucide-react';
import {
    SiteProfileModuleSummary,
    type SiteProfileSummaryModule,
} from './module-summary-panel';

export type SitePlanSummaryModule = SiteProfileSummaryModule & {
    inventory_href?: string | null;
    inventory_label?: string | null;
};

export function SiteProfilePlan({ data }: { data: SitePlanSummaryModule }) {
    return (
        <div className="space-y-4">
            <SiteProfileModuleSummary
                label="Plan & Rooms"
                description="Site-owned spatial plan, room inventory, and emergency markers."
                data={data}
                actionLabel="Open Site plan"
            />
            {data.inventory_href ? (
                <Button variant="outline" asChild className="min-h-11">
                    <Link href={data.inventory_href}>
                        <DoorOpen className="mr-2 h-4 w-4" />
                        {data.inventory_label ?? 'Manage Site spaces'}
                    </Link>
                </Button>
            ) : null}
        </div>
    );
}
