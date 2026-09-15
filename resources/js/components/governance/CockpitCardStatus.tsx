import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { humaniseGovernanceValue } from '@/lib/governance-labels';

const CARD_STATUS: Record<string, { label: string; variant: StatusVariant }> = {
    critical: { label: 'Needs action', variant: 'critical' },
    warning: { label: 'Needs watching', variant: 'warning' },
    good: { label: 'No concerns', variant: 'success' },
    done: { label: 'Done', variant: 'success' },
    in_progress: { label: 'In progress', variant: 'info' },
    unknown: { label: 'Not available', variant: 'neutral' },
    unavailable: { label: 'Not available', variant: 'neutral' },
};

/** Whether a presenter card's source actually reported (not unknown/unavailable). */
export function isCardStatusKnown(status: string | null | undefined): boolean {
    return Boolean(status) && status !== 'unknown' && status !== 'unavailable';
}

/**
 * Status chip for a `GovernancePresenter` card. A card whose source failed
 * reads "Not available" — never "No concerns" or a raw status key.
 */
export function CockpitCardStatus({ status }: { status: string }) {
    const meta = CARD_STATUS[status] ?? {
        label: humaniseGovernanceValue(status) || 'Not available',
        variant: 'neutral' as const,
    };

    return (
        <StatusBadge size="sm" variant={meta.variant}>
            {meta.label}
        </StatusBadge>
    );
}
