// Synthetic preview contract. These are recorded evidence checks, not legal policy.
export type ComplianceEvidence = {
    name: string;
    applies: string;
    basis: string;
    outcome: string;
    evidence: string;
    due: string;
    low: number;
    high: number;
};

export function complianceIssue(
    record: ComplianceEvidence,
    recordedOdo: number,
    today: string,
    readingSource = 'recorded odometer',
): string {
    if (record.applies === 'Not applicable')
        return record.basis.trim()
            ? ''
            : `${record.name}: record the not-applicable basis.`;
    if (record.applies !== 'Applicable')
        return `${record.name}: assess applicability.`;
    if (!['Passed', 'Recorded'].includes(record.outcome))
        return `${record.name}: ${record.outcome === 'Failed' ? 'failed evidence requires review' : 'assessment is unresolved'}.`;
    if (!record.evidence.trim()) return `${record.name}: evidence is missing.`;
    if (record.name === 'RUC') {
        if (!(record.high > record.low) || record.low < 0)
            return 'RUC: record a valid licence range.';
        if (!(recordedOdo > 0))
            return 'RUC: record an odometer observation to check coverage.';
        // Retains the existing preview's upper-bound comparison; no new legal threshold.
        if (recordedOdo > record.high)
            return `RUC: ${readingSource} ${recordedOdo.toLocaleString('en-NZ')} km exceeds the recorded licence end ${record.high.toLocaleString('en-NZ')} km. Review coverage before vehicle use.`;
    } else if (!record.due)
        return `${record.name}: record the next due / expiry date.`;
    if (record.due && record.due < today)
        return `${record.name}: recorded evidence has expired.`;
    return '';
}

export const complianceIssues = (
    records: ComplianceEvidence[],
    recordedOdo: number,
    today: string,
    readingSource?: string,
) =>
    records
        .map((record) =>
            complianceIssue(record, recordedOdo, today, readingSource),
        )
        .filter(Boolean);

export const PREVIEW_NOW = '2026-09-22T09:30';

// Only the general toolbar default is moved forward. Explicit selected dates and edits
// keep the user's intent and are validated separately.
export function defaultBookingStart(day = PREVIEW_NOW.slice(0, 10)) {
    return day <= PREVIEW_NOW.slice(0, 10)
        ? '2026-09-22T10:00'
        : `${day}T09:00`;
}

export function bookingTimeIssue(
    start: string,
    end: string,
    editing = false,
    conflict = '',
) {
    if (!editing && start < PREVIEW_NOW)
        return {
            message: 'Choose a current or future pickup / block start.',
            field:
                start.slice(0, 10) < PREVIEW_NOW.slice(0, 10)
                    ? 'start-date'
                    : 'start-time',
        };
    if (end <= start)
        return { message: 'Return must follow pickup.', field: 'end-time' };
    if (conflict)
        return {
            message:
                conflict +
                ' Choose another time; no conflicting booking will be submitted.',
            field: 'start-date',
        };
    return null;
}
