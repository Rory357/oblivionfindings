import {
    SiteProfileModuleSummary,
    type SiteProfileSummaryModule,
} from './module-summary-panel';

export function SiteProfileAssets({
    data,
}: {
    data: SiteProfileSummaryModule;
}) {
    return (
        <SiteProfileModuleSummary
            label="Assets"
            description="Site-linked assets and their current condition, managed in Fleet & Assets."
            data={data}
            actionLabel="Open filtered Assets"
        />
    );
}
