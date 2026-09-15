import { describe, expect, it } from 'vitest';

import {
    ballotState,
    buildConflictPayload,
    buildVotePayload,
    canDeclareConflictOnStatus,
    stepAsideConsequence,
    voteChoiceHint,
    voteConfirmation,
    voteReceiptId,
    writtenRuleNote,
} from './resolution-voting';

const NOW = Date.parse('2026-09-15T00:00:00Z');

const base = {
    status: 'open',
    purpose: 'decision',
    deadline: '2026-09-20T05:00:00Z',
    canVote: true,
    hasVoted: false,
    steppedAside: false,
    isMeetingPaper: true,
    now: NOW,
};

describe('ballotState', () => {
    it('offers the ballot only while voting is open and before the deadline', () => {
        expect(ballotState(base).key).toBe('can_vote');

        const late = ballotState({ ...base, deadline: '2026-09-14T05:00:00Z' });
        expect(late.key).toBe('deadline_passed');
        expect(late.message).toContain('Voting closed on 14 September 2026, 5:00 pm');
    });

    it('always says why a member cannot vote', () => {
        expect(ballotState({ ...base, status: 'draft', canVote: false }).message).toBe(
            "Voting hasn't opened yet — the chair or secretary opens it at the meeting.",
        );
        expect(
            ballotState({ ...base, status: 'draft', canVote: false, votingSwitchedOff: true }).key,
        ).toBe('voting_switched_off');
        expect(ballotState({ ...base, canVote: false }).message).toBe(
            "You're not on the voting list for this resolution, so you can't vote on it.",
        );
        expect(
            ballotState({ ...base, canVote: false, ineligibleReason: 'Observers can’t vote.' }).message,
        ).toBe('Observers can’t vote.');
        expect(ballotState({ ...base, steppedAside: true }).key).toBe('stepped_aside');
        expect(ballotState({ ...base, hasVoted: true }).key).toBe('voted');
        expect(
            ballotState({ ...base, status: 'closed', closedAt: '2026-09-12T03:00:00Z' }).message,
        ).toBe('Voting closed on 12 September 2026.');
    });

    it('never offers a vote on papers for discussion or information', () => {
        expect(ballotState({ ...base, purpose: 'information' }).key).toBe('no_vote_needed');
        expect(ballotState({ ...base, purpose: 'discussion' }).message).toContain(
            "doesn't vote on it",
        );
    });
});

describe('payloads', () => {
    it('sends a reason for the vote as vote_note and never as a conflict', () => {
        expect(buildVotePayload('for', '  ')).toEqual({ vote: 'for' });
        expect(buildVotePayload('against', ' Too costly ')).toEqual({
            vote: 'against',
            vote_note: 'Too costly',
        });
    });

    it('builds exactly the conflict fields the server validates', () => {
        expect(
            buildConflictPayload({
                type: 'material',
                description: '  I own shares in the supplier.  ',
                withdraw_from_voting: true,
                withdraw_from_discussion: false,
            }),
        ).toEqual({
            type: 'material',
            description: 'I own shares in the supplier.',
            withdraw_from_voting: true,
            withdraw_from_discussion: false,
        });
    });
});

describe('plain wording', () => {
    it('confirms the choice, the resolution and that a vote is final', () => {
        expect(voteConfirmation('abstain', 'Replace the van')).toEqual({
            title: 'Cast your vote?',
            description:
                'You\'re voting Abstain on "Replace the van". You can\'t change your vote once it\'s recorded.',
            confirmText: 'Cast vote',
        });
    });

    it('explains what abstaining does under each rule', () => {
        expect(voteChoiceHint('abstain', 'simple_majority')).toContain("doesn't count");
        expect(voteChoiceHint('abstain', 'unanimous')).toContain("can't pass");
    });

    it('explains stepping aside, including on resolutions that need everyone', () => {
        expect(stepAsideConsequence('simple_majority')).toContain(
            "You still count towards the board's size",
        );
        expect(stepAsideConsequence('unanimous')).toContain(
            'stepping aside means it cannot pass',
        );
    });

    it('says when a written resolution uses the everyone-must-agree rule', () => {
        expect(writtenRuleNote('simple_majority', 'simple_majority')).toBeNull();
        expect(writtenRuleNote('simple_majority', 'unanimous')).toContain(
            "This is a vote outside a meeting, and the board's voting rules say those need everyone's agreement",
        );
    });

    it('uses the receipt reference members see in My work', () => {
        expect(voteReceiptId(12)).toBe('VOTE-RCP-12');
        expect(voteReceiptId(null)).toBe('');
    });

    it('allows conflict declarations until voting has closed', () => {
        expect(canDeclareConflictOnStatus('draft')).toBe(true);
        expect(canDeclareConflictOnStatus('open')).toBe(true);
        expect(canDeclareConflictOnStatus('closed')).toBe(false);
    });
});
