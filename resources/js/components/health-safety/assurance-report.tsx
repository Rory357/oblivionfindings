import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';

type Value = string | number | boolean | null | Record<string, number>;
type Row = Record<string, Value>;
export type SafetyAssuranceReport = {
    period?: { start?: string; end?: string; from?: string; to?: string };
    generated_at?: string;
    summary?: Row;
    items?: Row[];
    event_summary?: Row;
    investigation_summary?: Row;
    corrective_action_summary?: Row;
    risk_posture?: Row;
    worksafe_status?: Row;
    training_compliance?: Row;
};

const labels: Record<string, string> = {
    total: 'Total records',
    total_period: 'Reported in this period',
    by_category: 'By category',
    by_severity: 'By severity',
    open_total: 'Open reviews',
    open_high_critical: 'Open high or critical reviews',
    closed_period: 'Closed in this period',
    active: 'Active investigations',
    overdue: 'Overdue',
    completed_with_findings: 'Completed with findings',
    awaiting_review: 'Awaiting review',
    by_type: 'By type',
    open: 'Open actions',
    awaiting_verification: 'Awaiting verification',
    verified_period: 'Currently verified',
    by_priority: 'By priority',
    total_active: 'Active assessments',
    by_level: 'By risk level',
    extreme_risks: 'Extreme risks',
    high_risks: 'High risks',
    unacceptable_risks: 'Risks recorded as unacceptable',
    due_for_review: 'Due for review',
    notifiable_period: 'Notifiable reviews reported in this period',
    notifiable_open: 'Open notifiable reviews',
    pending_notification: 'Awaiting notification',
    notified: 'Notified',
    acknowledged: 'Acknowledged',
    days_since_last_notifiable: 'Days since the last recorded notifiable event',
    total_requirements: 'Training requirements configured',
    blocking_count: 'Requirements that block work',
    non_compliant_staff: 'Staff recorded as expired or not started',
    total_completed: 'Completed investigations',
    by_methodology: 'By methodology',
    total_recommendations: 'Recommendations',
    verified: 'Verified actions',
    closed: 'Closed actions',
    applied_filter: 'Status filter',
    extreme: 'Extreme',
    high: 'High',
    medium: 'Medium',
    low: 'Low',
    unacceptable: 'Recorded as unacceptable',
    reference_number: 'Reference',
    title: 'Title',
    event_category: 'Event category',
    severity: 'Severity',
    status: 'Status',
    worksafe_status: 'WorkSafe status',
    worksafe_reference: 'WorkSafe reference',
    occurred_at: 'Occurred',
    reported_at: 'Reported',
    site_name: 'Site',
    investigation_required: 'Investigation required',
    has_investigation: 'Investigation recorded',
    investigation_type: 'Investigation type',
    methodology: 'Methodology',
    event_reference: 'Safety review',
    event_severity: 'Event severity',
    lead_investigator: 'Lead investigator',
    started_at: 'Started',
    completed_at: 'Completed',
    approved_by: 'Approved by',
    immediate_causes_count: 'Immediate causes',
    root_causes_count: 'Root causes',
    recommendation_count: 'Recommendations',
    findings_summary: 'Findings',
    lessons_learned: 'Lessons learned',
    action_type: 'Action type',
    priority: 'Priority',
    investigation_reference: 'Investigation',
    assigned_to: 'Accountable owner',
    due_date: 'Due date',
    is_overdue: 'Overdue',
    completed_by: 'Completed by',
    verified_at: 'Verified',
    verified_by: 'Verified by',
    effectiveness_confirmed: 'Effectiveness confirmed',
    assessable_type: 'Assessment subject',
    likelihood: 'Likelihood',
    consequence: 'Consequence',
    risk_score: 'Risk score',
    risk_level: 'Risk level',
    residual_risk_score: 'Residual risk score',
    residual_risk_level: 'Residual risk level',
    risk_acceptable: 'Risk recorded as acceptable',
    assessed_by: 'Assessed by',
    review_due_at: 'Review due',
    is_due_for_review: 'Review due',
};
const phrase = (value: string) =>
    value
        .replace(/_/g, ' ')
        .replace(/^\w/, (character) => character.toUpperCase());
function DisplayValue({ name, value }: { name: string; value: Value }) {
    if (value === null || value === '')
        return <span className="text-muted-foreground">Not recorded</span>;
    if (typeof value === 'boolean') return <>{value ? 'Yes' : 'No'}</>;
    if (typeof value === 'object')
        return Object.keys(value).length ? (
            <ul className="space-y-1">
                {Object.entries(value).map(([key, count]) => (
                    <li key={key}>
                        {phrase(key)}: {count.toLocaleString('en-NZ')}
                    </li>
                ))}
            </ul>
        ) : (
            <span className="text-muted-foreground">No records</span>
        );
    if (typeof value === 'number') return <>{value.toLocaleString('en-NZ')}</>;
    if (['due_date', 'review_due_at'].includes(name))
        return <>{formatDateOnly(value)}</>;
    if (name.endsWith('_at')) return <>{formatDateTime(value)}</>;
    if (
        [
            'status',
            'worksafe_status',
            'priority',
            'severity',
            'risk_level',
            'residual_risk_level',
        ].includes(name)
    )
        return <StatusBadge status={value} />;
    return (
        <>
            {[
                'event_category',
                'investigation_type',
                'methodology',
                'action_type',
            ].includes(name)
                ? phrase(value)
                : value}
        </>
    );
}

function Facts({
    row,
    omitReference = false,
}: {
    row: Row;
    omitReference?: boolean;
}) {
    return (
        <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {Object.entries(row)
                .filter(
                    ([key]) =>
                        labels[key] &&
                        !(omitReference && key === 'reference_number'),
                )
                .map(([key, value]) => (
                    <div key={key} className="min-w-0">
                        <dt className="text-caption text-muted-foreground">
                            {labels[key]}
                        </dt>
                        <dd className="mt-1 text-sm break-words whitespace-pre-wrap">
                            <DisplayValue name={key} value={value} />
                        </dd>
                    </div>
                ))}
        </dl>
    );
}

/** Only explicitly labelled measures are presented; legacy empty-denominator
 * percentages and computed overall assurance are not evidence of safety. */
export function AssuranceReport({
    report,
    query,
}: {
    report: SafetyAssuranceReport;
    query: string;
}) {
    const boardSections = [
        ['event_summary', 'Safety reviews'],
        ['investigation_summary', 'Investigations'],
        ['corrective_action_summary', 'Corrective actions'],
        ['risk_posture', 'Risk assessments'],
        ['worksafe_status', 'WorkSafe notifications'],
        ['training_compliance', 'Training records'],
    ] as const;
    const rows = report.items?.filter((row) =>
        Object.values(row).some(
            (value) =>
                typeof value === 'string' &&
                value
                    .toLocaleLowerCase('en-NZ')
                    .includes(query.trim().toLocaleLowerCase('en-NZ')),
        ),
    );
    return (
        <div className="space-y-5">
            {report.generated_at && (
                <p className="text-caption text-muted-foreground">
                    Generated {formatDateTime(report.generated_at)}
                </p>
            )}
            {report.summary && (
                <section
                    aria-label="Report summary"
                    className="rounded-xl border bg-card p-5"
                >
                    <Facts row={report.summary} />
                </section>
            )}
            {boardSections.map(
                ([key, title]) =>
                    report[key] && (
                        <section
                            key={key}
                            className="rounded-xl border bg-card p-5"
                            aria-label={title}
                        >
                            <h2 className="text-section-title mb-4">{title}</h2>
                            <Facts row={report[key]} />
                            {key === 'training_compliance' && (
                                <p className="mt-4 text-sm text-muted-foreground">
                                    These are recorded training checks. Missing
                                    or unassessed records do not establish
                                    compliance.
                                </p>
                            )}
                        </section>
                    ),
            )}
            {rows && (
                <>
                    <p className="text-sm text-muted-foreground" role="status">
                        {rows.length} of {report.items?.length} records
                    </p>
                    <ul aria-label="Report records" className="space-y-3">
                        {rows.map((row, index) => (
                            <li
                                key={`${row.reference_number ?? 'record'}:${index}`}
                                className="rounded-xl border bg-card p-5"
                            >
                                <h2 className="text-section-title mb-4">
                                    {String(row.reference_number ?? 'Record')}
                                </h2>
                                <Facts row={row} omitReference />
                            </li>
                        ))}
                    </ul>
                    {!rows.length && (
                        <p className="py-4 text-muted-foreground">
                            {query.trim()
                                ? 'No records match your search.'
                                : 'No records in this report’s scope.'}
                        </p>
                    )}
                </>
            )}
        </div>
    );
}
