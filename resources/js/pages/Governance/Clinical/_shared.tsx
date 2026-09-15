/**
 * Shared pieces for the Care quality pages (this month + month by month): the
 * view toggle in each header's filter row, status chips and wording helpers.
 */
import { PageHeaderViewToggle } from '@/components/page';
import {
    governanceStatus,
    type GovernanceStatusChip,
} from '@/lib/governance-labels';
import { router } from '@inertiajs/react';
import { Activity, LayoutGrid } from 'lucide-react';

export type ClinicalView = 'dashboard' | 'trends';

const CLINICAL_VIEW_HREFS: Record<ClinicalView, string> = {
    dashboard: '/governance/clinical',
    trends: '/governance/clinical/trends',
};

/** This month · Month by month — switches between the Care quality views. */
export function ClinicalViewToggle({ value }: { value: ClinicalView }) {
    return (
        <PageHeaderViewToggle<ClinicalView>
            ariaLabel="Care quality view"
            value={value}
            onChange={(next) => {
                if (next !== value) router.visit(CLINICAL_VIEW_HREFS[next]);
            }}
            options={[
                { value: 'dashboard', label: 'This month', icon: LayoutGrid },
                { value: 'trends', label: 'Month by month', icon: Activity },
            ]}
        />
    );
}

export type IndicatorStatus = 'normal' | 'warning' | 'critical';

export type SnapshotValue = {
    indicator_id: number;
    indicator_code: string;
    value: number;
    status: IndicatorStatus;
    trend: 'up' | 'down' | 'stable';
    previous_value: number | null;
    /** False when nothing has ever been recorded where this number comes from. */
    recorded: boolean;
    source_href: string | null;
    source_label: string | null;
};

export type Snapshot = {
    id: number;
    period_start: string | null;
    period_end: string | null;
    period_label: string;
    short_label: string;
    is_complete: boolean;
    compared_with_label: string | null;
    indicator_values: SnapshotValue[];
};

/** The chip for one measure: "No data yet" until its records are in use. */
export function indicatorChip(
    value: SnapshotValue | null | undefined,
): GovernanceStatusChip {
    if (!value || !value.recorded) {
        return governanceStatus('care_quality_status', 'no_data');
    }
    return governanceStatus('care_quality_status', value.status);
}

export function indicatorStatusKey(
    value: SnapshotValue | null | undefined,
): IndicatorStatus | 'no_data' {
    return !value || !value.recorded ? 'no_data' : value.status;
}

/** "Target: none" for a zero target; the unit "count" is never shown. */
export function targetLabel(
    direction: 'above' | 'below' | 'equal',
    value: number | null,
): string {
    if (value === null) return 'No target set';
    if (direction === 'below') {
        return value === 0 ? 'Target: none' : `Target: ${value} or fewer`;
    }
    if (direction === 'above') return `Target: ${value} or more`;
    return `Target: ${value}`;
}

export function unitLabel(unit: string | null): string | null {
    if (!unit || unit.trim().toLowerCase() === 'count') return null;
    return unit;
}

/** "Up from 1 in 1–14 Aug" / "Down from 3 in August 2026" / "Same as 1–14 Aug". */
export function comparisonText(
    value: SnapshotValue,
    comparedWith: string | null,
): string | null {
    if (value.previous_value === null || !comparedWith) return null;
    const previous = formatCount(value.previous_value);
    if (value.value > value.previous_value) {
        return `Up from ${previous} in ${comparedWith}`;
    }
    if (value.value < value.previous_value) {
        return `Down from ${previous} in ${comparedWith}`;
    }
    return `Same as ${comparedWith}`;
}

export function formatCount(value: number): string {
    return Number.isInteger(value) ? String(value) : value.toFixed(1);
}
