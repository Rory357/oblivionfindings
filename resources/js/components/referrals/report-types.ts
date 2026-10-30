import type { Page, Permissions, ReferralSummary } from './types';

export const reportClassificationLabels: Record<string, string> = {
    other: 'Other enquiries and referrals',
    duplicate: 'Confirmed duplicate closures',
    test: 'Test-entry closures',
    spam: 'Spam closures',
};
export const reportClassificationLabel = (key?: string) =>
    key
        ? (reportClassificationLabels[key] ?? 'Unknown classification')
        : 'All classifications';

export type ReportFilters = {
    scope: 'received' | 'current';
    view: 'overview' | 'details';
    from: string;
    to: string;
    site: string;
    owner: string;
    source: string;
    stage: string;
    metric: string;
    page: number;
    stage_age?: string;
    funding_age?: string;
    funding_kind?: string;
    region?: string;
    classification?: string;
};
/**
 * Why a report number is hidden (config/referral-reports.php): `small` is a
 * group of 1 to N-1 ("Fewer than 5"); `paired` is a larger group hidden with
 * it so the small one can't be worked out from the total ("Hidden"). A hidden
 * number is always null, never 0.
 */
export type Suppressed = 'small' | 'paired';
export type ReportGroup = {
    key: string;
    count: number | null;
    label?: string;
    suppressed?: Suppressed;
    /** "Other reported regions": how many hidden regions it combines. */
    regions?: number;
};
type HiddenCount = { count: number | null; suppressed?: Suppressed };
export type HistoryMeasure = HiddenCount & {
    key: string;
    elapsed: HiddenCount & {
        total_hours: number | null;
        mean_hours: number | null;
    };
    working: HiddenCount & {
        total_hours: number | null;
        mean_hours: number | null;
    };
    unknown_dates: number | null;
    unknown_dates_suppressed?: Suppressed;
    unknown_calendar: number | null;
    unknown_calendar_suppressed?: Suppressed;
};
export type ReportMetric = {
    key: string;
    label: string;
    definition: string;
    value: number | null;
    suppressed?: Suppressed;
};
export type ReferralReport = {
    generated_at: string;
    filters: ReportFilters;
    metrics: ReportMetric[];
    trend: {
        date: string;
        received: number | null;
        contacted: number | null;
        received_suppressed?: Suppressed;
        contacted_suppressed?: Suppressed;
    }[];
    groups: Record<string, ReportGroup[]>;
    delays: Record<
        'attempt' | 'contact',
        HiddenCount & { mean_hours: number | null }
    >;
    funding: null | {
        cases: number | null;
        cases_suppressed?: Suppressed;
        provider_cases: number | null;
        provider_cases_suppressed?: Suppressed;
        income_cases: number | null;
        income_cases_suppressed?: Suppressed;
        by_pathway: (ReportGroup & { personal_income: boolean })[];
        by_status: ReportGroup[];
        outcomes: ReportGroup[];
        pending_age?: { provider: ReportGroup[]; income: ReportGroup[] };
    };
    working_time?: {
        key: string;
        label: string;
        elapsed: HiddenCount & { mean_hours: number | null };
        working: HiddenCount & { mean_hours: number | null };
        unknown_dates: number | null;
        unknown_dates_suppressed?: Suppressed;
        unknown_calendar: number | null;
        unknown_calendar_suppressed?: Suppressed;
    }[];
    history?: {
        stages: HistoryMeasure[];
        holds: HistoryMeasure[];
        reopenings: number | null;
        reopenings_suppressed?: Suppressed;
        reopened_referrals: number | null;
        reopened_referrals_suppressed?: Suppressed;
        incomplete_referrals: number | null;
        incomplete_referrals_suppressed?: Suppressed;
        referrals: number | null;
        referrals_suppressed?: Suppressed;
    };
    /** The small-group rule in force for this report. */
    privacy?: {
        threshold: number;
        label: string;
        paired_label: string;
        active: boolean;
    };
    definitions: Record<
        'scope' | 'cutoff' | 'trend' | 'delays' | 'funding',
        string
    > &
        Partial<
            Record<
                | 'stage_age'
                | 'funding_age'
                | 'working_time'
                | 'region'
                | 'classification'
                | 'history'
                | 'privacy',
                string
            >
        >;
};

/** The words a hidden report number shows instead (never 0). */
export function hiddenLabel(
    report: Pick<ReferralReport, 'privacy'>,
    reason: Suppressed,
) {
    return reason === 'paired'
        ? (report.privacy?.paired_label ?? 'Hidden')
        : (report.privacy?.label ?? 'Fewer than 5');
}

/** A count as shown on the page: its number, or the small-group words. */
export function shownCount(
    report: Pick<ReferralReport, 'privacy'>,
    value: number | null,
    reason?: Suppressed,
): string {
    if (reason) return hiddenLabel(report, reason);
    return value === null ? 'Not recorded' : String(value);
}

/** A mean or total drawn from a hidden group of referrals. */
export const hiddenMeasureLabel = (report: Pick<ReferralReport, 'privacy'>) =>
    `Not shown: fewer than ${report.privacy?.threshold ?? 5} referrals`;

/** The one-line caption under any breakdown or chart that hides a number. */
export const suppressionCaption = (
    report: Pick<ReferralReport, 'privacy'>,
    unit: 'referrals' | 'cases' = 'referrals',
) =>
    `Groups of fewer than ${report.privacy?.threshold ?? 5} ${unit} show as “${hiddenLabel(report, 'small')}”, and one more may show as “${hiddenLabel(report, 'paired')}”, so nobody can be picked out or worked out from the total.`;
export type ReferralReportProps = {
    report: ReferralReport;
    records: Page<ReferralSummary & { stage_age_label?: string }>;
    sites: { id: number; name: string }[];
    permissions: Permissions;
};
