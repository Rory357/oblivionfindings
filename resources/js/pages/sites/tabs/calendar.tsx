import {
    SiteProfileModuleSummary,
    type SiteProfileSummaryModule,
} from './module-summary-panel';

export function SiteProfileCalendar({
    data,
}: {
    data: SiteProfileSummaryModule;
}) {
    return (
        <SiteProfileModuleSummary
            label="Calendar"
            description="Upcoming Site events, with scheduling kept in the Site calendar."
            data={data}
            actionLabel="Open Site calendar"
        />
    );
}
