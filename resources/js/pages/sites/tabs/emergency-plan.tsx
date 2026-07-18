import {
    SiteProfileModuleSummary,
    type SiteProfileSummaryModule,
} from './module-summary-panel';

export type EmergencyPlanModule = SiteProfileSummaryModule & {
    summary?: {
        location?: string | null;
        medication_storage_location?: string | null;
    } | null;
};

export function SiteProfileEmergencyPlan({
    data,
}: {
    data: EmergencyPlanModule;
}) {
    return (
        <SiteProfileModuleSummary
            label="Emergency plan"
            description="Site-owned emergency and medication storage locations, linked to the full plan."
            data={data}
            actionLabel="Open Emergency Plan"
        />
    );
}
