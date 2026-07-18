import {
    SiteProfileModuleSummary,
    type SiteProfileSummaryModule,
} from './module-summary-panel';

export function SiteProfileRiskAssessments({
    data,
}: {
    data: SiteProfileSummaryModule;
}) {
    return (
        <SiteProfileModuleSummary
            label="Risk assessments"
            description="Site-linked assessments with review dates and current risk levels."
            data={data}
            actionLabel="Open Risk Assessments"
        />
    );
}
