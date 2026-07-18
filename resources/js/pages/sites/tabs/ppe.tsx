import {
    SiteProfileModuleSummary,
    type SiteProfileSummaryModule,
} from './module-summary-panel';

export function SiteProfilePpe({ data }: { data: SiteProfileSummaryModule }) {
    return (
        <SiteProfileModuleSummary
            label="PPE"
            description="Current Site PPE inventory, inspection dates, and expiry cues."
            data={data}
            actionLabel="Open PPE register"
        />
    );
}
