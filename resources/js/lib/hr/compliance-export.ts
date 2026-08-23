import type { ComplianceTab } from '@/components/hr/compliance-tabs';

const EXPORT_DATASET_BY_TAB: Record<ComplianceTab, string> = {
    overview: 'staff',
    matrix: 'staff',
    calendar: 'renewals',
    vetting: 'vetting',
    drivers: 'drivers',
};

export function complianceExportHref(
    active: ComplianceTab,
    allowed: boolean,
): string | null {
    return allowed
        ? `/hr/compliance/export?dataset=${EXPORT_DATASET_BY_TAB[active]}`
        : null;
}
