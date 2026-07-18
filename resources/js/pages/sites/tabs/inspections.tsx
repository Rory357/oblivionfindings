import {
    SiteProfileModuleSummary,
    type SiteProfileSummaryModule,
} from './module-summary-panel';

export function SiteProfileInspections({
    data,
}: {
    data: SiteProfileSummaryModule;
}) {
    return (
        <SiteProfileModuleSummary
            label="Inspections"
            description="Active inspection schedules and overdue checks for this Site."
            data={data}
            actionLabel="Open Site Inspections"
        />
    );
}
