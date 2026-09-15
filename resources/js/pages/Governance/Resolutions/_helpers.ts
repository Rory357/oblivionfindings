/**
 * Resolutions page helpers. Every label comes from the shared Governance
 * label helpers (`@/lib/governance-labels`) so the register, the record page
 * and the meeting workspace say the same thing (vocabulary.md).
 */
import { isDecisionPurpose } from '@/components/governance/resolution-voting';
import {
    governanceStatus,
    votingThresholdLabel,
} from '@/lib/governance-labels';

/** "More For than Against" for the rule the engine applies; papers with no vote say so. */
export function howItPassesLabel(row: {
    purpose?: string | null;
    voting_threshold?: string | null;
    applied_threshold?: string | null;
}): string {
    if (!isDecisionPurpose(row.purpose)) return 'No vote';
    return votingThresholdLabel(row.applied_threshold || row.voting_threshold);
}

/** Outcome chip for a resolution outcome (Passed / Not passed / No decision). */
export function outcomeChip(outcome: string | null | undefined) {
    return governanceStatus('resolution_outcome', outcome);
}

/**
 * Canonical place to read and vote on a resolution: meeting resolutions
 * open inside their meeting workspace (GovernanceWorkQuery's
 * decisionWorkspaceHref), others on their own record page.
 */
export function resolutionWorkspaceHref(resolution: {
    id: number;
    governance_meeting_id?: number | null;
    meeting?: { id: number } | null;
}): string {
    const meetingId = resolution.meeting?.id ?? resolution.governance_meeting_id;
    return meetingId
        ? `/governance/meetings/${meetingId}?tab=resolutions&paper=${resolution.id}`
        : `/governance/resolutions/${resolution.id}`;
}
