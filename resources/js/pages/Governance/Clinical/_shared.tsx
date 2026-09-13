/**
 * Shared pieces for the Clinical governance pages (dashboard + trends): the
 * view toggle that lives in each header's filter row, status token mapping
 * and date helpers.
 */
import { PageHeaderViewToggle } from '@/components/page';
import type { StatusVariant } from '@/components/ui/status-badge';
import { formatDateOnly } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { Activity, LayoutGrid } from 'lucide-react';

export type ClinicalView = 'dashboard' | 'trends';

const CLINICAL_VIEW_HREFS: Record<ClinicalView, string> = {
    dashboard: '/governance/clinical',
    trends: '/governance/clinical/trends',
};

/** Dashboard · Trends — switches between the clinical governance views. */
export function ClinicalViewToggle({ value }: { value: ClinicalView }) {
    return (
        <PageHeaderViewToggle<ClinicalView>
            ariaLabel="Clinical governance view"
            value={value}
            onChange={(next) => {
                if (next !== value) router.visit(CLINICAL_VIEW_HREFS[next]);
            }}
            options={[
                { value: 'dashboard', label: 'Dashboard', icon: LayoutGrid },
                { value: 'trends', label: 'Trends', icon: Activity },
            ]}
        />
    );
}

export type IndicatorStatus = 'normal' | 'warning' | 'critical';

export const INDICATOR_STATUS_VARIANTS: Record<IndicatorStatus, StatusVariant> =
    {
        normal: 'success',
        warning: 'warning',
        critical: 'critical',
    };

export const INDICATOR_STATUS_LABELS: Record<IndicatorStatus, string> = {
    normal: 'On target',
    warning: 'Warning',
    critical: 'Critical',
};

export function targetLabel(
    direction: 'above' | 'below' | 'equal',
    value: number | null,
): string {
    const symbol =
        direction === 'below' ? '≤' : direction === 'above' ? '≥' : '=';
    return `${symbol} ${value ?? '—'}`;
}

export function formatPeriod(start: string | null, end: string | null): string {
    if (!start || !end) return 'Current period';
    return `${formatDateOnly(start.slice(0, 10))} – ${formatDateOnly(end.slice(0, 10))}`;
}
