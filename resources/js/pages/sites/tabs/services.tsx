import {
    SiteProfileModuleSummary,
    type SiteProfileSummaryModule,
} from './module-summary-panel';

export function SiteProfileServices({
    data,
}: {
    data: SiteProfileSummaryModule;
}) {
    return (
        <SiteProfileModuleSummary
            label="Services"
            description="Service contexts currently linked to this Site. Authorised configuration remains in Settings."
            data={data}
            actionLabel="Manage Service Contexts"
        />
    );
}
