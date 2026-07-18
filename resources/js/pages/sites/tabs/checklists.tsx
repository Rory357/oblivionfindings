import {
    SiteProfileModuleSummary,
    type SiteProfileSummaryModule,
} from './module-summary-panel';

export function SiteProfileChecklists({
    data,
}: {
    data: SiteProfileSummaryModule;
}) {
    return (
        <SiteProfileModuleSummary
            label="Checklists"
            description="A compact view of recent runs and exceptions from the canonical Checklists workspace."
            data={data}
            actionLabel="Open Checklists workspace"
        />
    );
}
