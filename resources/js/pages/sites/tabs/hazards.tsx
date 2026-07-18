import {
    SiteProfileModuleSummary,
    type SiteProfileSummaryModule,
} from './module-summary-panel';

export function SiteProfileHazards({
    data,
}: {
    data: SiteProfileSummaryModule;
}) {
    return (
        <SiteProfileModuleSummary
            label="Hazards"
            description="Open Site hazards and their current resolution status."
            data={data}
            actionLabel="Open Hazard register"
        />
    );
}
