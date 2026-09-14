import type { StatusVariant } from '@/components/ui/status-badge';

/** Lifecycle status → verified status pair. */
export function resolutionStatusVariant(
    status: string | null | undefined,
): StatusVariant {
    switch (status) {
        case 'open':
            return 'info';
        case 'closed':
            return 'warning';
        case 'implemented':
            return 'success';
        default:
            return 'neutral';
    }
}

export function resolutionStatusLabel(
    status: string | null | undefined,
): string {
    switch (status) {
        case 'open':
            return 'Open for voting';
        case 'closed':
            return 'Voting closed';
        case null:
        case undefined:
        case '':
            return 'Unknown';
        default:
            return (
                status.charAt(0).toUpperCase() +
                status.slice(1).replace(/_/g, ' ')
            );
    }
}

export function resolutionOutcomeVariant(
    outcome: string | null | undefined,
): StatusVariant {
    switch (outcome) {
        case 'carried':
            return 'success';
        case 'defeated':
            return 'critical';
        case 'no_quorum':
            return 'warning';
        default:
            return 'neutral';
    }
}

export function resolutionOutcomeLabel(
    outcome: string | null | undefined,
): string {
    switch (outcome) {
        case 'carried':
            return 'Carried';
        case 'defeated':
            return 'Defeated';
        case 'no_quorum':
            return 'No valid decision — quorum not met';
        case null:
        case undefined:
        case '':
            return 'No outcome';
        default:
            return (
                outcome.charAt(0).toUpperCase() +
                outcome.slice(1).replace(/_/g, ' ')
            );
    }
}

export function formatThreshold(threshold: string | null | undefined): string {
    switch (threshold) {
        case 'simple_majority':
            return 'Simple majority (50% + 1)';
        case 'two_thirds':
            return 'Two-thirds majority (66.7%)';
        case 'special_majority':
        case 'three_quarters':
            return 'Special majority (75%)';
        case 'unanimous':
            return 'Unanimous (100% entitled)';
        case null:
        case undefined:
        case '':
            return 'Not set';
        default:
            return threshold.replace(/_/g, ' ');
    }
}
