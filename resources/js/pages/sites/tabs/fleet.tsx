import {
    SiteProfileModuleSummary,
    type SiteProfileSummaryModule,
} from './module-summary-panel';

export function SiteProfileFleet({ data }: { data: SiteProfileSummaryModule }) {
    return (
        <SiteProfileModuleSummary
            label="Fleet"
            description="Vehicle operations remain in the canonical Fleet & Assets dashboard."
            data={data}
            actionLabel="Open filtered Fleet"
        />
    );
}
