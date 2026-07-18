import {
    SiteProfileModuleSummary,
    type SiteProfileSummaryModule,
} from './module-summary-panel';

export function SiteProfileDrills({
    data,
}: {
    data: SiteProfileSummaryModule;
}) {
    return (
        <SiteProfileModuleSummary
            label="Emergency drills"
            description="Scheduled and completed drills recorded in Health & Safety."
            data={data}
            actionLabel="Open Emergency Drills"
        />
    );
}
