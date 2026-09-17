/**
 * Voting and conflict-of-interest rules shared by every place a board member
 * votes on a resolution — the Resolutions record page and the meeting
 * workspace both render `<ResolutionBallot>` and `<DeclareConflictDialog>`,
 * which read their wording and payloads from here so the two surfaces can't
 * drift apart again (GOV plain-language audit P0-1 / P0-2 / P0-3).
 *
 * Wording source of truth:
 *   docs/audits/2026-09-14-governance-plain-language-ux/vocabulary.md
 */
import type { StatusVariant } from '@/components/ui/status-badge';
import { formatDateLong, formatDateTimeLong } from '@/lib/datetime';
import {
    thresholdExplanation,
    voteLabel,
    votingThresholdLabel,
} from '@/lib/governance-labels';

/** Server minimum for a conflict description (ResolutionController). */
export const CONFLICT_DESCRIPTION_MIN = 20;

/** Resolution statuses where a member can still declare a conflict. */
export const CONFLICT_DECLARABLE_STATUSES = ['draft', 'proposed', 'open'];

const CLOSED_STATUSES = ['closed', 'implemented', 'archived', 'cancelled'];

/** For decision papers go to a vote; For discussion / For information never do. */
export function isDecisionPurpose(purpose: string | null | undefined): boolean {
    return !purpose || purpose === 'decision';
}

export function canDeclareConflictOnStatus(status: string | null | undefined): boolean {
    return CONFLICT_DECLARABLE_STATUSES.includes(String(status ?? ''));
}

export type BallotStateKey =
    | 'can_vote'
    | 'voted'
    | 'stepped_aside'
    | 'no_vote_needed'
    | 'not_open_yet'
    | 'voting_switched_off'
    | 'deadline_passed'
    | 'closed'
    | 'not_eligible';

export interface BallotStateInput {
    status: string;
    purpose?: string | null;
    deadline?: string | null;
    closedAt?: string | null;
    canVote: boolean;
    hasVoted: boolean;
    steppedAside: boolean;
    isMeetingPaper: boolean;
    /** The body's voting rules aren't confirmed yet (server knows). */
    votingSwitchedOff?: boolean;
    /** A plain, server-written reason the viewer can't vote. */
    ineligibleReason?: string | null;
    now?: number;
}

export interface BallotState {
    key: BallotStateKey;
    /** One plain sentence; empty while the ballot itself is shown. */
    message: string;
}

/**
 * What the voting area says to this member right now. Blocked states always
 * say why (vocabulary.md "Blocked states"), and the ballot is hidden once the
 * deadline has passed even if nobody has closed voting yet.
 */
export function ballotState(input: BallotStateInput): BallotState {
    const now = input.now ?? Date.now();

    if (!isDecisionPurpose(input.purpose)) {
        return {
            key: 'no_vote_needed',
            message:
                input.purpose === 'discussion'
                    ? "This paper is for discussion — the board talks it through but doesn't vote on it."
                    : "This paper is for information — the board reads it but doesn't vote on it.",
        };
    }

    if (input.hasVoted) {
        return { key: 'voted', message: 'Your vote is recorded.' };
    }

    if (input.steppedAside) {
        return {
            key: 'stepped_aside',
            message:
                'You stepped aside from this vote because you declared a conflict of interest.',
        };
    }

    if (input.status === 'draft' || input.status === 'proposed') {
        if (input.votingSwitchedOff) {
            return {
                key: 'voting_switched_off',
                message:
                    'Board voting is switched off until the voting rules are confirmed. The chair or board secretary can confirm them in Settings.',
            };
        }
        return {
            key: 'not_open_yet',
            message: input.isMeetingPaper
                ? "Voting hasn't opened yet — the chair or secretary opens it at the meeting."
                : "Voting hasn't opened yet — the chair or secretary will open it and set a deadline.",
        };
    }

    if (input.status === 'open') {
        const deadline = input.deadline ? new Date(input.deadline).getTime() : null;
        if (deadline !== null && !Number.isNaN(deadline) && deadline <= now) {
            return {
                key: 'deadline_passed',
                message: `Voting closed on ${formatDateTimeLong(input.deadline)}. The chair or secretary will record the result.`,
            };
        }
        if (input.canVote) {
            return { key: 'can_vote', message: '' };
        }
        return {
            key: 'not_eligible',
            message:
                input.ineligibleReason?.trim() ||
                "You're not on the voting list for this resolution, so you can't vote on it.",
        };
    }

    if (CLOSED_STATUSES.includes(input.status)) {
        return {
            key: 'closed',
            message: input.closedAt
                ? `Voting closed on ${formatDateLong(input.closedAt)}.`
                : 'Voting has closed.',
        };
    }

    return {
        key: 'not_open_yet',
        message: "Voting hasn't opened yet.",
    };
}

export type VoteChoice = 'for' | 'against' | 'abstain';

export const VOTE_CHOICES: VoteChoice[] = ['for', 'against', 'abstain'];

/** One plain line under each ballot option. */
export function voteChoiceHint(choice: VoteChoice, threshold: string | null | undefined): string {
    switch (choice) {
        case 'for':
            return 'You agree with the resolution wording.';
        case 'against':
            return "You don't agree with it.";
        default:
            return threshold === 'unanimous'
                ? "You take part without voting either way — but this resolution needs everyone's For vote, so it can't pass."
                : "You take part without voting either way — it doesn't count For or Against.";
    }
}

export function voteVariant(vote: string | null | undefined): StatusVariant {
    if (vote === 'for') return 'success';
    if (vote === 'against') return 'critical';
    return 'neutral';
}

/** The confirm step before a vote is recorded (POPUP guide: title ends "?"). */
export function voteConfirmation(vote: string, resolutionTitle: string) {
    return {
        title: 'Cast your vote?',
        description: `You're voting ${voteLabel(vote)} on "${resolutionTitle}". You can't change your vote once it's recorded.`,
        confirmText: 'Cast vote',
    };
}

/**
 * The receipt reference members also see in My work
 * (GovernanceWorkQuery: `VOTE-RCP-{vote id}`).
 */
export function voteReceiptId(voteId: number | string | null | undefined): string {
    return voteId === null || voteId === undefined || voteId === '' ? '' : `VOTE-RCP-${voteId}`;
}

/** "It passes if …" for the rule the engine actually applies. */
export function passRuleSentence(
    storedThreshold: string | null | undefined,
    appliedThreshold?: string | null,
): string {
    return thresholdExplanation(appliedThreshold || storedThreshold);
}

/**
 * Written resolutions can be decided by a stricter rule than the one chosen
 * when the paper was written (the board's "written votes need everyone's
 * agreement" setting). Say so, instead of showing the chosen rule.
 */
export function writtenRuleNote(
    storedThreshold: string | null | undefined,
    appliedThreshold: string | null | undefined,
): string | null {
    if (!appliedThreshold || appliedThreshold === storedThreshold) return null;
    if (appliedThreshold !== 'unanimous') return null;
    return `This is a vote outside a meeting, and the board's voting rules say those need everyone's agreement — so it uses "${votingThresholdLabel('unanimous')}" instead of "${votingThresholdLabel(storedThreshold)}".`;
}

/** What stepping aside means, in one breath (vocabulary.md: "step aside"). */
export function stepAsideConsequence(appliedThreshold: string | null | undefined): string {
    const base =
        "If you step aside, you won't vote and your name is recorded as stepping aside. You still count towards the board's size, so the others need enough people to take part.";
    return appliedThreshold === 'unanimous'
        ? `${base} This resolution needs everyone entitled to vote For, so stepping aside means it cannot pass.`
        : base;
}

/** The vote request body. A note is never sent as a conflict. */
export function buildVotePayload(vote: string, note: string): { vote: string; vote_note?: string } {
    const trimmed = note.trim();
    return trimmed === '' ? { vote } : { vote, vote_note: trimmed };
}

export interface ConflictFormValues {
    type: string;
    description: string;
    withdraw_from_voting: boolean;
    withdraw_from_discussion: boolean;
}

/** Exactly the fields `ResolutionController::declareConflict` validates. */
export function buildConflictPayload(values: ConflictFormValues): ConflictFormValues {
    return {
        type: values.type,
        description: values.description.trim(),
        withdraw_from_voting: Boolean(values.withdraw_from_voting),
        withdraw_from_discussion: Boolean(values.withdraw_from_discussion),
    };
}

/** Plain descriptions under the conflict type tiles (labels come from governance-labels). */
export const CONFLICT_TYPE_DESCRIPTIONS: Record<string, string> = {
    material: 'You, or someone close to you, could gain or lose money or business.',
    related: 'Family, a close friend, or an organisation you are involved with is part of it.',
    prejudicial: 'Another loyalty — for example another organisation, or a past role.',
    other: 'Something else that could look unfair.',
};

export const CONFLICT_TYPES = ['material', 'related', 'prejudicial', 'other'] as const;
