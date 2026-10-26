import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { label } from './types';

/**
 * Referral stage tones. Kept in the referrals module rather than the shared
 * status map: keys such as `new`, `triage` and `assessment` mean different
 * things in other modules and must not be recoloured there.
 */
const STAGE_TONES: Record<string, StatusVariant> = {
    new: 'info',
    triage: 'info',
    assessment: 'info',
    funding_assistance: 'warning',
    ready_for_handover: 'success',
    on_hold: 'neutral',
    converted: 'success',
    closed: 'neutral',
};

export function stageTone(stage: string): StatusVariant {
    return STAGE_TONES[stage] ?? 'neutral';
}

/** Configured stage names win over the stable key ("Funding help" rather than "Funding assistance"). */
export function stageLabel(
    stage: string,
    stages?: { id: string; label: string }[],
): string {
    return stages?.find((option) => option.id === stage)?.label ?? label(stage);
}

export function ReferralStageBadge({
    stage,
    stages,
    size,
}: {
    stage: string;
    stages?: { id: string; label: string }[];
    size?: 'sm' | 'md';
}) {
    return (
        <StatusBadge variant={stageTone(stage)} size={size}>
            {stageLabel(stage, stages)}
        </StatusBadge>
    );
}
