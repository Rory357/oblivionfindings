import {
    SiteProfileModuleSummary,
    type SiteProfileSummaryModule,
} from './module-summary-panel';

export function SiteProfileDocuments({
    data,
}: {
    data: SiteProfileSummaryModule;
}) {
    return (
        <SiteProfileModuleSummary
            label="Documents"
            description="Recent Site documents and expiry status. Uploads, folders, versions, and document changes remain in the Site Documents workspace."
            data={data}
            actionLabel="Open Site Documents"
        />
    );
}
