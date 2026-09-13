import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';

const CARD_STATUS: Record<string, { label: string; variant: StatusVariant }> = {
    critical: { label: 'Critical', variant: 'critical' },
    warning: { label: 'Warning', variant: 'warning' },
    good: { label: 'Good', variant: 'success' },
    done: { label: 'Done', variant: 'success' },
    in_progress: { label: 'In progress', variant: 'info' },
    unknown: { label: 'Unavailable', variant: 'neutral' },
    unavailable: { label: 'Unavailable', variant: 'neutral' },
};

/** Whether a presenter card's source actually reported (not unknown/unavailable). */
export function isCardStatusKnown(status: string | null | undefined): boolean {
    return Boolean(status) && status !== 'unknown' && status !== 'unavailable';
}

/**
 * Status chip for a `GovernancePresenter` cockpit card. A card whose source
 * failed reads "Unavailable" — never "good" or a bare status key.
 */
export function CockpitCardStatus({ status }: { status: string }) {
    const meta = CARD_STATUS[status] ?? {
        label: status.replace(/_/g, ' '),
        variant: 'neutral' as const,
    };

    return (
        <StatusBadge size="sm" variant={meta.variant}>
            {meta.label}
        </StatusBadge>
    );
}
