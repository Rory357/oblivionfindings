import {
    SiteProfileModuleSummary,
    type SiteProfileSummaryModule,
} from './module-summary-panel';

export function SiteProfileHardware({
    data,
}: {
    data: SiteProfileSummaryModule;
}) {
    return (
        <SiteProfileModuleSummary
            label="Hardware"
            description="Security and connected-device status from the Site Hardware workspace."
            data={data}
            actionLabel="Open Site Hardware"
        />
    );
}
