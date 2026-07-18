import {
    SiteProfileModuleSummary,
    type SiteProfileSummaryModule,
} from './module-summary-panel';

export function SiteProfileVendors({
    data,
}: {
    data: SiteProfileSummaryModule;
}) {
    return (
        <SiteProfileModuleSummary
            label="Vendors & Credentials"
            description="Permission-shaped counts for this Site. Vendor records and credential access stay in the unified secure workspace."
            data={data}
            actionLabel="Open Vendors & Credentials"
        />
    );
}
