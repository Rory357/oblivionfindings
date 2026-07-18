import {
    SiteProfileModuleSummary,
    type SiteProfileSummaryModule,
} from './module-summary-panel';

export function SiteProfileFirstAid({
    data,
}: {
    data: SiteProfileSummaryModule;
}) {
    return (
        <SiteProfileModuleSummary
            label="First aid"
            description="Recent first-aid records for this Site, without duplicating the register."
            data={data}
            actionLabel="Open First Aid register"
        />
    );
}
