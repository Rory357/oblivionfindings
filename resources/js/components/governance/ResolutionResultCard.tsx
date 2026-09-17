import { ArrowRight, Vote as VoteIcon } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTimeLong } from '@/lib/datetime';
import {
    conflictTypeLabel,
    governanceStatus,
    outcomeSentence,
    thresholdExplanation,
    voteLabel,
} from '@/lib/governance-labels';

import { voteVariant, writtenRuleNote } from './resolution-voting';

type MemberRef =
    | string
    | { user?: { name?: string | null } | null }
    | null
    | undefined;

export interface ResolutionResultData {
    summary: { for: number; against: number; abstain: number };
    outcome: string;
    threshold?: string | null;
    applied_threshold?: string | null;
    written_unanimity_applied?: boolean;
    quorum_details?: {
        present?: number;
        required?: number;
        total_eligible?: number;
    } | null;
    closed_at?: string | null;
    individual_votes: Array<{
        board_member?: MemberRef;
        vote: string;
        vote_note?: string | null;
    }>;
    conflicts: Array<{
        board_member?: MemberRef;
        type: string;
        withdrew: boolean;
    }>;
    is_frozen?: boolean;
}

function memberName(member: MemberRef): string {
    if (!member) return 'A former board member';
    if (typeof member === 'string') return member;
    return member.user?.name ?? 'A former board member';
}

/**
 * The recorded result of a resolution vote, shared by the Resolutions
 * record page and the meeting workspace: one plain outcome sentence (what
 * happened and what it needed), the counts, the rule that decided it, how
 * each member voted and any conflicts of interest.
 */
export function ResolutionResultCard({
    result,
    votingThreshold,
    quorumRequired = true,
    followUpCount = 0,
    onViewFollowUps,
    id,
    className,
}: {
    result: ResolutionResultData;
    /** The rule chosen when the resolution was written. */
    votingThreshold?: string | null;
    quorumRequired?: boolean;
    followUpCount?: number;
    onViewFollowUps?: () => void;
    id?: string;
    className?: string;
}) {
    const applied =
        result.applied_threshold ?? result.threshold ?? votingThreshold ?? null;
    const chip = governanceStatus('resolution_outcome', result.outcome);
    const quorumCounts =
        quorumRequired && (result.quorum_details?.required ?? 0) > 0;
    const writtenNote = result.written_unanimity_applied
        ? writtenRuleNote(result.threshold ?? votingThreshold, applied)
        : null;

    return (
        <Card id={id} className={className} data-test="resolution-result">
            <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <CardTitle className="text-section-title flex items-center gap-2">
                            <VoteIcon className="size-4 text-primary" />
                            Result
                        </CardTitle>
                        <CardDescription>
                            {result.is_frozen
                                ? `Final result, recorded when voting closed${result.closed_at ? ` on ${formatDateTimeLong(result.closed_at)}` : ''}.`
                                : 'The result as recorded.'}
                        </CardDescription>
                    </div>
                    <StatusBadge variant={chip.variant}>{chip.label}</StatusBadge>
                </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-5">
                <p className="text-sm" data-test="outcome-sentence">
                    {outcomeSentence({
                        outcome: result.outcome,
                        for: result.summary.for,
                        against: result.summary.against,
                        abstain: result.summary.abstain,
                        participating: quorumCounts
                            ? (result.quorum_details?.present ?? null)
                            : null,
                        required: quorumCounts
                            ? (result.quorum_details?.required ?? null)
                            : null,
                        votingMembers: quorumCounts
                            ? (result.quorum_details?.total_eligible ?? null)
                            : null,
                        threshold: applied,
                    })}
                </p>
                {writtenNote ? <p className="text-subtle">{writtenNote}</p> : null}

                <div className="grid grid-cols-3 gap-5">
                    {(
                        [
                            ['for', result.summary.for],
                            ['against', result.summary.against],
                            ['abstain', result.summary.abstain],
                        ] as const
                    ).map(([vote, count]) => (
                        <div
                            key={vote}
                            className="flex flex-col items-center gap-1 rounded-lg border border-border p-4 text-center"
                        >
                            <span className="text-section-title">{count}</span>
                            <StatusBadge variant={voteVariant(vote)}>
                                {voteLabel(vote)}
                            </StatusBadge>
                        </div>
                    ))}
                </div>

                <p className="text-subtle">
                    <span className="font-medium text-foreground">
                        How it was decided:{' '}
                    </span>
                    {thresholdExplanation(applied)}
                </p>

                {followUpCount > 0 ? (
                    <div className="flex flex-wrap items-center gap-1 text-sm">
                        <span>
                            {`${followUpCount} follow-up action${followUpCount === 1 ? '' : 's'} created`}
                        </span>
                        {onViewFollowUps ? (
                            <Button
                                type="button"
                                variant="link"
                                size="xs"
                                onClick={onViewFollowUps}
                            >
                                View
                                <ArrowRight className="size-3.5" aria-hidden="true" />
                            </Button>
                        ) : null}
                    </div>
                ) : null}

                <div>
                    <p className="mb-2 text-sm font-semibold">
                        How each member voted
                    </p>
                    {result.individual_votes.length === 0 ? (
                        <p className="text-subtle">No votes were recorded.</p>
                    ) : (
                        <div className="flex flex-col divide-y divide-border">
                            {result.individual_votes.map((vote, index) => (
                                <div
                                    key={`${memberName(vote.board_member)}-${index}`}
                                    className="flex flex-wrap items-center justify-between gap-3 py-2"
                                >
                                    <div className="min-w-0">
                                        <p className="text-sm">
                                            {memberName(vote.board_member)}
                                        </p>
                                        {vote.vote_note ? (
                                            <p className="text-caption whitespace-pre-wrap">
                                                {`Reason: ${vote.vote_note}`}
                                            </p>
                                        ) : null}
                                    </div>
                                    <StatusBadge variant={voteVariant(vote.vote)}>
                                        {voteLabel(vote.vote)}
                                    </StatusBadge>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {result.conflicts.length > 0 ? (
                    <div>
                        <p className="mb-2 text-sm font-semibold">
                            Conflicts of interest
                        </p>
                        <ul className="flex flex-col gap-1 text-sm">
                            {result.conflicts.map((conflict, index) => (
                                <li key={index}>
                                    {`${memberName(conflict.board_member)} — ${conflictTypeLabel(conflict.type)} · ${
                                        conflict.withdrew
                                            ? 'stepped aside from the vote'
                                            : 'did not step aside'
                                    }`}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}
