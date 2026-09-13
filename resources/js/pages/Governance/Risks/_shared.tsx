/**
 * Shared pieces for the Risk register pages (register, heatmap, trends,
 * committee views): the register view toggle that lives in each header's
 * filter row, and the status/severity token mappings.
 */
import { PageHeaderViewToggle } from '@/components/page';
import type { StatusVariant } from '@/components/ui/status-badge';
import { router } from '@inertiajs/react';
import { Grid3x3, List, TrendingUp } from 'lucide-react';

export type RiskView = 'register' | 'heatmap' | 'trends';

const RISK_VIEW_HREFS: Record<RiskView, string> = {
    register: '/governance/risks',
    heatmap: '/governance/risks/heatmap',
    trends: '/governance/risks/trends',
};

/** Register · Heatmap · Trends — switches between the register's views. */
export function RiskViewToggle({ value }: { value: RiskView | null }) {
    return (
        <PageHeaderViewToggle<RiskView | 'none'>
            ariaLabel="Risk register view"
            value={value ?? 'none'}
            onChange={(next) => {
                if (next !== 'none' && next !== value) {
                    router.visit(RISK_VIEW_HREFS[next]);
                }
            }}
            options={[
                { value: 'register', label: 'Register', icon: List },
                { value: 'heatmap', label: 'Heatmap', icon: Grid3x3 },
                { value: 'trends', label: 'Trends', icon: TrendingUp },
            ]}
        />
    );
}

export const RISK_STATUS_FILTERS = [
    { value: 'all', label: 'All statuses' },
    { value: 'active', label: 'Active' },
    { value: 'mitigating', label: 'Mitigating' },
    { value: 'accepted', label: 'Accepted' },
    { value: 'transferred', label: 'Transferred' },
    { value: 'avoided', label: 'Avoided' },
    { value: 'voided', label: 'Closed' },
];

export const RISK_SEVERITY_FILTERS = [
    { value: 'all', label: 'All severities' },
    { value: 'critical', label: 'Critical (20+)' },
    { value: 'high', label: 'High (15–19)' },
];

/** Residual/inherent score → status token pair (20+ critical, 10+ warning). */
export function riskLevelVariant(score: number | null | undefined): StatusVariant {
    const n = Number(score ?? 0);
    if (n >= 20) return 'critical';
    if (n >= 10) return 'warning';
    return 'success';
}

export function riskStatusVariant(status: string): StatusVariant {
    switch (status) {
        case 'active':
        case 'open':
            return 'info';
        case 'mitigating':
            return 'warning';
        case 'accepted':
            return 'success';
        default:
            return 'neutral';
    }
}

export function riskStatusLabel(status: string): string {
    if (status === 'voided') return 'Closed';
    return status
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}
