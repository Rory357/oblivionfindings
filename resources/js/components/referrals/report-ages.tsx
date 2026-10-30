import { ReferralDistribution } from './report-charts';
import type { ReferralReport } from './report-types';

export const reportAgeOptions = [
    { value: '0_6', label: 'Under 7 elapsed days' },
    { value: '7_13', label: '7–13 elapsed days' },
    { value: '14_29', label: '14–29 elapsed days' },
    { value: '30_plus', label: '30+ elapsed days' },
    { value: 'unknown', label: 'Date missing or invalid' },
];
export const reportAgeLabel = (key?: string) =>
    reportAgeOptions.find((option) => option.value === key)?.label ?? 'Any age';

export function ReferralReportAges({
    report,
    onStage,
    onFunding,
}: {
    report: ReferralReport;
    onStage: (bucket: string) => void;
    onFunding: (kind: 'provider' | 'income', bucket: string) => void;
}) {
    return (
        <section className="space-y-4" aria-label="Waiting time reports">
            <h2 className="text-section-title">Where work is waiting</h2>
            <ReferralDistribution
                title="Time in current intake stage"
                rows={report.groups.stage_age ?? []}
                description={report.definitions.stage_age}
                onSelect={onStage}
            />
            {report.funding?.pending_age && (
                <>
                    <p className="text-subtle">
                        {report.definitions.funding_age}
                    </p>
                    <div className="grid gap-5 lg:grid-cols-2">
                        <ReferralDistribution
                            title="Pending provider-funding applications"
                            rows={report.funding.pending_age.provider}
                            unit="cases"
                            onSelect={(bucket) => onFunding('provider', bucket)}
                        />
                        <ReferralDistribution
                            title="Pending personal-income applications"
                            rows={report.funding.pending_age.income}
                            unit="cases"
                            onSelect={(bucket) => onFunding('income', bucket)}
                        />
                    </div>
                </>
            )}
        </section>
    );
}
