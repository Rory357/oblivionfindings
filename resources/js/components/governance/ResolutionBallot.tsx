import { Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CheckCircle,
    CheckCircle2,
    Loader2,
    MinusCircle,
    Vote as VoteIcon,
    XCircle,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { formatDateLong, formatDateTimeLong } from '@/lib/datetime';
import { conflictTypeLabel, voteLabel } from '@/lib/governance-labels';
import { cn } from '@/lib/utils';

import {
    ballotState,
    buildVotePayload,
    canDeclareConflictOnStatus,
    passRuleSentence,
    VOTE_CHOICES,
    voteChoiceHint,
    voteConfirmation,
    voteReceiptId,
    voteVariant,
    writtenRuleNote,
    type VoteChoice,
} from './resolution-voting';

export interface BallotVote {
    id: number;
    vote: string;
    voted_at: string;
    vote_note?: string | null;
    conflict_declared?: boolean | null;
}

export interface BallotConflict {
    id?: number;
    declaration_type: string;
    declaration_text?: string | null;
    withdrew_from_voting: boolean;
    withdrew_from_discussion?: boolean | null;
    declared_at?: string | null;
}

export interface BallotResolution {
    id: number;
    title: string;
    status: string;
    purpose?: string | null;
    deadline?: string | null;
    closed_at?: string | null;
    voting_threshold?: string | null;
    /** The rule the engine applies (written resolutions may need everyone). */
    applied_threshold?: string | null;
    governance_meeting_id?: number | null;
}

/** Where to go after voting ("Next resolution: … →" / "Back to resolutions"). */
export interface BallotNavigation {
    label: string;
    href?: string;
    onClick?: () => void;
}

export interface ResolutionBallotProps {
    resolution: BallotResolution;
    /** The final wording version members vote on. */
    version?: number | null;
    canVote: boolean;
    myVote?: BallotVote | null;
    myConflict?: BallotConflict | null;
    /** The member may declare (or update) a conflict of interest here. */
    canDeclareConflict?: boolean;
    onDeclareConflict?: () => void;
    votingSwitchedOff?: boolean;
    /** Plain server reason the viewer can't vote. */
    ineligibleReason?: string | null;
    next?: BallotNavigation | null;
    back?: BallotNavigation | null;
    id?: string;
    className?: string;
}

const CHOICE_ICONS: Record<VoteChoice, typeof CheckCircle> = {
    for: CheckCircle,
    against: XCircle,
    abstain: MinusCircle,
};

const CHOICE_TONES: Record<VoteChoice, string> = {
    for: 'text-status-success',
    against: 'text-status-critical',
    abstain: 'text-muted-foreground',
};

function NavButton({ nav, primary }: { nav: BallotNavigation; primary: boolean }) {
    const content = (
        <>
            {nav.label}
            {primary ? <ArrowRight className="h-4 w-4" aria-hidden="true" /> : null}
        </>
    );
    if (nav.href) {
        return (
            <Button asChild size="sm" variant={primary ? 'default' : 'outline'}>
                <Link href={nav.href} data-test="ballot-next">
                    {content}
                </Link>
            </Button>
        );
    }
    return (
        <Button
            type="button"
            size="sm"
            variant={primary ? 'default' : 'outline'}
            onClick={nav.onClick}
            data-test="ballot-next"
        >
            {content}
        </Button>
    );
}

/**
 * The ONE voting area for a resolution, shared by the Resolutions record
 * page and the meeting workspace. It always renders, with a plain state:
 * the ballot (confirmed before it is recorded), the member's receipt, or the
 * visible reason voting isn't possible right now.
 */
export function ResolutionBallot({
    resolution,
    version = null,
    canVote,
    myVote = null,
    myConflict = null,
    canDeclareConflict = false,
    onDeclareConflict,
    votingSwitchedOff = false,
    ineligibleReason = null,
    next = null,
    back = null,
    id,
    className,
}: ResolutionBallotProps) {
    const [choice, setChoice] = useState<VoteChoice | ''>('');
    const [note, setNote] = useState('');
    const [confirming, setConfirming] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const threshold = resolution.applied_threshold || resolution.voting_threshold;
    const state = ballotState({
        status: resolution.status,
        purpose: resolution.purpose,
        deadline: resolution.deadline,
        closedAt: resolution.closed_at,
        canVote,
        hasVoted: Boolean(myVote),
        steppedAside: Boolean(myConflict?.withdrew_from_voting),
        isMeetingPaper: Boolean(resolution.governance_meeting_id),
        votingSwitchedOff,
        ineligibleReason,
    });
    const isVotingPaper = state.key !== 'no_vote_needed';
    const writtenNote = writtenRuleNote(
        resolution.voting_threshold,
        resolution.applied_threshold,
    );
    const confirmation = choice ? voteConfirmation(choice, resolution.title) : null;

    const castVote = () => {
        if (!choice) return;
        setSubmitting(true);
        setError(null);
        router.post(
            `/governance/resolutions/${resolution.id}/vote`,
            buildVotePayload(choice, note),
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const flashError = (
                        page as { props?: { flash?: { error?: unknown } } }
                    )?.props?.flash?.error;
                    if (flashError) {
                        setError(String(flashError));
                        return;
                    }
                    setChoice('');
                    setNote('');
                },
                onError: (errors) => {
                    const first = Object.values(errors ?? {})[0];
                    setError(
                        first
                            ? String(first)
                            : "Your vote couldn't be recorded. Please try again.",
                    );
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    let body: ReactNode;
    if (state.key === 'can_vote') {
        body = (
            <div className="flex flex-col gap-4">
                <RadioGroup
                    value={choice}
                    onValueChange={(value) => {
                        setChoice(value as VoteChoice);
                        setError(null);
                    }}
                    className="grid gap-2 sm:grid-cols-3"
                    aria-label="Your vote"
                >
                    {VOTE_CHOICES.map((option) => {
                        const Icon = CHOICE_ICONS[option];
                        return (
                            <Label
                                key={option}
                                className="flex cursor-pointer items-start gap-3 rounded-lg border p-3 font-normal transition-colors hover:bg-accent has-[[data-state=checked]]:border-primary has-[[data-state=checked]]:bg-primary/5"
                            >
                                <RadioGroupItem
                                    value={option}
                                    className="mt-0.5"
                                    aria-label={voteLabel(option)}
                                />
                                <span className="min-w-0">
                                    <span className="flex items-center gap-1.5 text-sm font-medium">
                                        <Icon
                                            className={cn('size-4', CHOICE_TONES[option])}
                                            aria-hidden="true"
                                        />
                                        {voteLabel(option)}
                                    </span>
                                    <span className="text-caption mt-0.5 block">
                                        {voteChoiceHint(option, threshold)}
                                    </span>
                                </span>
                            </Label>
                        );
                    })}
                </RadioGroup>
                <div>
                    <Label htmlFor={`vote-note-${resolution.id}`}>
                        Reason for your vote (optional)
                    </Label>
                    <Textarea
                        id={`vote-note-${resolution.id}`}
                        className="mt-1.5"
                        rows={2}
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        placeholder="e.g. I support this because the costs are covered in this year's budget."
                    />
                    <p className="text-caption mt-1">
                        Kept with your vote in the voting record. This is not
                        where you declare a conflict of interest.
                    </p>
                </div>
                {error ? (
                    <p role="alert" className="text-sm text-status-critical">
                        {error}
                    </p>
                ) : null}
                <div className="flex flex-wrap items-center gap-3">
                    <Button
                        type="button"
                        disabled={!choice || submitting}
                        onClick={() => setConfirming(true)}
                        data-test="ballot-cast"
                    >
                        {submitting ? (
                            <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                            <VoteIcon className="h-4 w-4" />
                        )}
                        Cast vote
                    </Button>
                    <p className="text-caption">
                        Your vote is final once it’s recorded.
                    </p>
                </div>
            </div>
        );
    } else if (state.key === 'voted' && myVote) {
        body = (
            <div className="flex flex-col gap-3" data-test="vote-receipt">
                <div className="flex flex-wrap items-center gap-2">
                    <CheckCircle2
                        className="size-4 text-status-success"
                        aria-hidden="true"
                    />
                    <span className="text-sm font-medium">Your vote is recorded</span>
                    <StatusBadge variant={voteVariant(myVote.vote)}>
                        {voteLabel(myVote.vote)}
                    </StatusBadge>
                </div>
                <p className="text-subtle" data-test="paper-vote-receipt-time">
                    Recorded{' '}
                    <time dateTime={myVote.voted_at}>
                        {formatDateTimeLong(myVote.voted_at)}
                    </time>
                    {version ? ` · Resolution version ${version}` : ''}
                    {voteReceiptId(myVote.id)
                        ? ` · Receipt ${voteReceiptId(myVote.id)}`
                        : ''}
                </p>
                {myVote.vote_note ? (
                    <p className="text-sm whitespace-pre-wrap">
                        <span className="font-medium">Your reason: </span>
                        {myVote.vote_note}
                    </p>
                ) : null}
                {myConflict && !myConflict.withdrew_from_voting ? (
                    <p className="text-subtle">
                        You declared a conflict of interest and still voted.
                    </p>
                ) : null}
                {next || back ? (
                    <div className="flex flex-wrap gap-2 pt-1">
                        {next ? <NavButton nav={next} primary /> : null}
                        {back ? <NavButton nav={back} primary={false} /> : null}
                    </div>
                ) : null}
            </div>
        );
    } else {
        body = (
            <p
                className={cn(
                    'text-sm',
                    state.key === 'voting_switched_off'
                        ? 'text-status-critical'
                        : 'text-muted-foreground',
                )}
                data-test="ballot-state"
                data-state={state.key}
            >
                {state.message}
            </p>
        );
    }

    const declarable = canDeclareConflict && canDeclareConflictOnStatus(resolution.status);

    return (
        <Card id={id} className={className} data-test="resolution-ballot">
            <CardHeader>
                <CardTitle className="text-section-title flex items-center gap-2">
                    <VoteIcon className="size-4 text-primary" aria-hidden="true" />
                    Voting
                </CardTitle>
                {isVotingPaper ? (
                    <CardDescription>
                        {passRuleSentence(
                            resolution.voting_threshold,
                            resolution.applied_threshold,
                        )}{' '}
                        {resolution.status === 'open' && resolution.deadline
                            ? `Voting closes ${formatDateTimeLong(resolution.deadline)}.`
                            : null}
                    </CardDescription>
                ) : null}
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {writtenNote && isVotingPaper ? (
                    <p className="text-subtle">{writtenNote}</p>
                ) : null}
                {body}

                {myConflict || declarable ? (
                    <div
                        className="flex flex-wrap items-start justify-between gap-3 border-t border-border pt-4"
                        data-test="conflict-state"
                    >
                        <div className="min-w-0">
                            <p className="flex items-center gap-1.5 text-sm font-medium">
                                <AlertTriangle
                                    className="size-4 text-status-warning"
                                    aria-hidden="true"
                                />
                                {myConflict
                                    ? `You declared a conflict of interest${myConflict.declared_at ? ` on ${formatDateLong(myConflict.declared_at)}` : ''}`
                                    : 'Conflict of interest'}
                            </p>
                            <p className="text-caption mt-0.5">
                                {myConflict
                                    ? `${conflictTypeLabel(myConflict.declaration_type)} · ${
                                          myConflict.withdrew_from_voting
                                              ? 'You stepped aside from the vote.'
                                              : 'You did not step aside from the vote.'
                                      }`
                                    : 'If you, your family or an organisation you’re involved with could gain or lose from this decision, tell the board.'}
                            </p>
                        </div>
                        {declarable && onDeclareConflict ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={onDeclareConflict}
                                data-test="ballot-declare-conflict"
                            >
                                {myConflict
                                    ? 'Update declaration'
                                    : 'Declare a conflict of interest'}
                            </Button>
                        ) : null}
                    </div>
                ) : null}
            </CardContent>

            {confirmation ? (
                <ConfirmDialog
                    open={confirming}
                    onClose={() => setConfirming(false)}
                    onConfirm={castVote}
                    title={confirmation.title}
                    description={confirmation.description}
                    confirmText={confirmation.confirmText}
                    variant="default"
                />
            ) : null}
        </Card>
    );
}
