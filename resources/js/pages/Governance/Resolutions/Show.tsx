import {
    GovernanceAttachmentsPanel,
    type GovernanceAttachment,
} from '@/components/governance/GovernanceAttachmentsPanel';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import {
    close as closeResolution,
    open as openResolution,
    vote as voteResolution,
} from '@/routes/governance/resolutions';
import { declare as declareConflictRoute } from '@/routes/governance/resolutions/conflict';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle,
    CheckCircle,
    Gavel,
    MinusCircle,
    Paperclip,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

interface Vote {
    id: number;
    board_member: { user: { name: string } };
    vote: string;
    conflict_declared: boolean;
    voted_at: string;
}

interface Conflict {
    id: number;
    board_member: { user: { name: string } };
    declaration_type: string;
    withdrew_from_voting: boolean;
}

type ResultMember =
    | string
    | { user?: { name?: string | null } | null }
    | null
    | undefined;

interface ResultVote {
    board_member: ResultMember;
    vote: string;
    conflict_declared: boolean;
    voted_at: string;
}

interface ResultConflict {
    board_member: ResultMember;
    type: string;
    description: string;
    withdrew: boolean;
}

interface Resolution {
    id: number;
    resolution_reference: string;
    title: string;
    context: string;
    options: Array<{ label: string; description?: string }>;
    recommendation: string | null;
    voting_threshold: string;
    status: string;
    deadline: string | null;
    outcome: string | null;
    outcome_notes?: string | null;
    vote_summary: {
        for: number;
        against: number;
        abstain: number;
        total_votes: number;
    } | null;
    proposed_by: { name: string };
    meeting: { title: string } | null;
    votes: Vote[];
    conflict_declarations: Conflict[];
}

interface Props extends PageProps {
    resolution: Resolution;
    results: {
        summary: { for: number; against: number; abstain: number };
        percentages: { for: number; against: number };
        outcome: string;
        quorum_met: boolean;
        individual_votes: ResultVote[];
        conflicts: ResultConflict[];
    } | null;
    my_vote: Vote | null;
    can_vote: boolean;
    quorum: { present: number; required: number; met: boolean } | null;
    attachments: GovernanceAttachment[];
}

export default function ResolutionShow({
    auth,
    resolution,
    results,
    my_vote,
    can_vote,
    quorum,
    attachments,
}: Props) {
    const [selectedVote, setSelectedVote] = useState<string>('');
    const [conflictNote, setConflictNote] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [declaringConflict, setDeclaringConflict] = useState(false);
    const [finalNotes, setFinalNotes] = useState('');
    const [finalizing, setFinalizing] = useState(false);

    const permissions = auth?.can as
        | { governance?: { resolutions?: { manage?: boolean } } }
        | undefined;
    const canManage = permissions?.governance?.resolutions?.manage;
    const options = resolution.options ?? [];
    const isOpen = resolution.status === 'open';
    const isClosed = ['closed', 'implemented', 'archived'].includes(
        resolution.status,
    );
    const isFinalized = ['implemented', 'archived'].includes(resolution.status);

    const resolveMemberName = (member: ResultMember) => {
        if (!member) return 'Unknown';
        if (typeof member === 'string') return member;
        return member.user?.name ?? 'Unknown';
    };

    const submitVote = async () => {
        if (!selectedVote) return;
        setSubmitting(true);
        try {
            await axios.post(
                voteResolution.url({ resolution: resolution.id }),
                {
                    vote: selectedVote,
                    conflict_note: conflictNote || undefined,
                },
            );
            router.reload();
        } catch (error) {
            console.error('Vote failed:', error);
        } finally {
            setSubmitting(false);
        }
    };

    const handleDeclareConflict = async () => {
        setDeclaringConflict(true);
        try {
            await axios.post(
                declareConflictRoute.url({ resolution: resolution.id }),
                {
                    type: 'material',
                    description: conflictNote,
                    withdraw_from_voting: true,
                },
            );
            router.reload();
        } catch (error) {
            console.error('Conflict declaration failed:', error);
        } finally {
            setDeclaringConflict(false);
        }
    };

    const openVoting = async () => {
        try {
            await axios.post(openResolution.url({ resolution: resolution.id }));
            router.reload();
        } catch (error) {
            console.error('Failed to open voting:', error);
        }
    };

    const closeVoting = async () => {
        try {
            await axios.post(
                closeResolution.url({ resolution: resolution.id }),
            );
            router.reload();
        } catch (error) {
            console.error('Failed to close voting:', error);
        }
    };

    const finalizeResolution = async (status: 'implemented' | 'archived') => {
        setFinalizing(true);
        try {
            await axios.post(
                `/governance/resolutions/${resolution.id}/finalize`,
                {
                    status,
                    notes: finalNotes || undefined,
                },
            );
            router.reload();
        } catch (error) {
            console.error('Failed to finalize resolution:', error);
        } finally {
            setFinalizing(false);
        }
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Resolutions', href: '/governance/resolutions' },
                {
                    title: 'Resolution',
                    href: `/governance/resolutions/${resolution.id}`,
                },
            ]}
        >
            <Head title={resolution.title} />

            <PageLayout
                hero={
                    <PageHero
                        category="governance"
                        backHref="/governance/resolutions"
                        icon={Gavel}
                        title={
                            <span
                                className="flex flex-wrap items-center gap-3"
                                dusk="resolution-heading"
                            >
                                {resolution.title}
                            </span>
                        }
                        description={resolution.resolution_reference}
                        stats={[
                            { label: 'Status', value: resolution.status },
                            {
                                label: 'Outcome',
                                value: resolution.outcome ?? 'Pending',
                            },
                            {
                                label: 'For',
                                value: resolution.vote_summary?.for ?? 0,
                            },
                            {
                                label: 'Against',
                                value: resolution.vote_summary?.against ?? 0,
                            },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge
                                    className={cn(
                                        resolution.status === 'open' &&
                                            'bg-status-success-bg text-status-success',
                                        resolution.status === 'closed' &&
                                            'bg-primary/10 text-primary',
                                        resolution.status === 'implemented' &&
                                            'bg-status-success-bg text-status-success',
                                        resolution.status === 'archived' &&
                                            'bg-muted text-foreground',
                                        resolution.status === 'draft' &&
                                            'bg-muted text-foreground',
                                    )}
                                >
                                    {resolution.status}
                                </Badge>
                                {resolution.outcome && (
                                    <Badge
                                        className={cn(
                                            resolution.outcome === 'carried'
                                                ? 'bg-status-success-bg text-status-success'
                                                : 'bg-status-critical-bg text-status-critical',
                                        )}
                                    >
                                        {resolution.outcome}
                                    </Badge>
                                )}
                            </div>
                        }
                    />
                }
            >
                {/* Context */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>Context</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="whitespace-pre-wrap text-foreground">
                            {resolution.context}
                        </p>

                        {resolution.recommendation && (
                            <div className="mt-4 rounded-lg border border-status-info/30 bg-status-info-bg p-4">
                                <p className="font-medium text-status-info">
                                    Management Recommendation:
                                </p>
                                <p className="text-status-info">
                                    {resolution.recommendation}
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Options */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>Options</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-3">
                            {options.map((option, index) => (
                                <div
                                    key={index}
                                    className="rounded-lg border p-4"
                                >
                                    <p className="font-medium">
                                        {option.label}
                                    </p>
                                    {option.description && (
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {option.description}
                                        </p>
                                    )}
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {/* Voting Section */}
                {isOpen && can_vote && !my_vote && (
                    <Card className="mb-6 border-status-info/30">
                        <CardHeader>
                            <CardTitle>Cast Your Vote</CardTitle>
                            <CardDescription>
                                {resolution.deadline && (
                                    <span>
                                        Voting closes:{' '}
                                        {new Date(
                                            resolution.deadline,
                                        ).toLocaleString()}
                                    </span>
                                )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <RadioGroup
                                value={selectedVote}
                                onValueChange={setSelectedVote}
                                className="space-y-3"
                            >
                                <label className="flex cursor-pointer items-center gap-3 rounded-md border p-3 transition-colors hover:bg-muted [&:has([data-state=checked])]:border-primary [&:has([data-state=checked])]:bg-primary/5">
                                    <RadioGroupItem value="for" />
                                    <span className="flex items-center gap-2">
                                        <CheckCircle className="h-5 w-5 text-status-success" />
                                        For
                                    </span>
                                </label>
                                <label className="flex cursor-pointer items-center gap-3 rounded-md border p-3 transition-colors hover:bg-muted [&:has([data-state=checked])]:border-primary [&:has([data-state=checked])]:bg-primary/5">
                                    <RadioGroupItem value="against" />
                                    <span className="flex items-center gap-2">
                                        <XCircle className="h-5 w-5 text-status-critical" />
                                        Against
                                    </span>
                                </label>
                                <label className="flex cursor-pointer items-center gap-3 rounded-md border p-3 transition-colors hover:bg-muted [&:has([data-state=checked])]:border-primary [&:has([data-state=checked])]:bg-primary/5">
                                    <RadioGroupItem value="abstain" />
                                    <span className="flex items-center gap-2">
                                        <MinusCircle className="h-5 w-5 text-muted-foreground" />
                                        Abstain
                                    </span>
                                </label>
                            </RadioGroup>

                            <div className="mt-4">
                                <label
                                    htmlFor="conflict"
                                    className="text-sm font-medium text-foreground"
                                >
                                    Conflict Declaration (optional)
                                </label>
                                <Textarea
                                    id="conflict"
                                    placeholder="Describe any conflict of interest..."
                                    value={conflictNote}
                                    onChange={(e) =>
                                        setConflictNote(e.target.value)
                                    }
                                    className="mt-1"
                                />
                            </div>

                            <div className="mt-4 flex gap-2">
                                <Button
                                    onClick={submitVote}
                                    disabled={!selectedVote || submitting}
                                >
                                    {submitting
                                        ? 'Submitting...'
                                        : 'Submit Vote'}
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={handleDeclareConflict}
                                    disabled={declaringConflict}
                                >
                                    Declare Conflict & Abstain
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* My Vote */}
                {my_vote && (
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>Your Vote</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-2">
                                <Badge
                                    className={cn(
                                        my_vote.vote === 'for' &&
                                            'bg-status-success-bg text-status-success',
                                        my_vote.vote === 'against' &&
                                            'bg-status-critical-bg text-status-critical',
                                        my_vote.vote === 'abstain' &&
                                            'bg-muted text-foreground',
                                    )}
                                >
                                    {my_vote.vote.toUpperCase()}
                                </Badge>
                                <span className="text-sm text-muted-foreground">
                                    Voted{' '}
                                    {new Date(
                                        my_vote.voted_at,
                                    ).toLocaleString()}
                                </span>
                                {my_vote.conflict_declared && (
                                    <Badge
                                        variant="outline"
                                        className="text-status-warning"
                                    >
                                        <AlertTriangle className="mr-1 h-3 w-3" />
                                        Conflict Declared
                                    </Badge>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Results */}
                {isClosed && results && (
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>Voting Results</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="mb-6 grid grid-cols-3 gap-4">
                                <div className="rounded-lg bg-status-success-bg p-4 text-center">
                                    <p className="text-3xl font-bold text-status-success">
                                        {results.summary.for}
                                    </p>
                                    <p className="text-sm text-status-success">
                                        For ({results.percentages.for}%)
                                    </p>
                                </div>
                                <div className="rounded-lg bg-status-critical-bg p-4 text-center">
                                    <p className="text-3xl font-bold text-status-critical">
                                        {results.summary.against}
                                    </p>
                                    <p className="text-sm text-status-critical">
                                        Against ({results.percentages.against}%)
                                    </p>
                                </div>
                                <div className="rounded-lg bg-muted p-4 text-center">
                                    <p className="text-3xl font-bold text-muted-foreground">
                                        {results.summary.abstain}
                                    </p>
                                    <p className="text-sm text-foreground">
                                        Abstain
                                    </p>
                                </div>
                            </div>

                            <h4 className="mb-2 font-medium">
                                Individual Votes
                            </h4>
                            <div className="space-y-2">
                                {results.individual_votes.map((vote, index) => (
                                    <div
                                        key={`${resolveMemberName(vote.board_member)}-${vote.voted_at}-${index}`}
                                        className="flex items-center justify-between rounded border p-2"
                                    >
                                        <span>
                                            {resolveMemberName(
                                                vote.board_member,
                                            )}
                                        </span>
                                        <Badge
                                            className={cn(
                                                vote.vote === 'for' &&
                                                    'bg-status-success-bg text-status-success',
                                                vote.vote === 'against' &&
                                                    'bg-status-critical-bg text-status-critical',
                                                vote.vote === 'abstain' &&
                                                    'bg-muted text-foreground',
                                            )}
                                        >
                                            {vote.vote}
                                        </Badge>
                                    </div>
                                ))}
                            </div>

                            {results.conflicts.length > 0 && (
                                <div className="mt-4">
                                    <h4 className="mb-2 font-medium">
                                        Conflict Declarations
                                    </h4>
                                    {results.conflicts.map((conflict, i) => (
                                        <p
                                            key={i}
                                            className="text-sm text-status-warning"
                                        >
                                            {resolveMemberName(
                                                conflict.board_member,
                                            )}{' '}
                                            - {conflict.type}
                                        </p>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {isClosed && (
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>Decision Summary</CardTitle>
                            <CardDescription>
                                Finalize the resolution once the outcome has
                                been actioned.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-3">
                                <Textarea
                                    placeholder="Outcome notes or implementation summary..."
                                    value={finalNotes}
                                    onChange={(e) =>
                                        setFinalNotes(e.target.value)
                                    }
                                />
                                {resolution.outcome_notes && (
                                    <p className="text-sm text-muted-foreground">
                                        Previous notes:{' '}
                                        {resolution.outcome_notes}
                                    </p>
                                )}
                                {canManage &&
                                    resolution.status === 'closed' && (
                                        <div className="flex gap-2">
                                            <Button
                                                onClick={() =>
                                                    finalizeResolution(
                                                        'implemented',
                                                    )
                                                }
                                                disabled={finalizing}
                                            >
                                                Mark Implemented
                                            </Button>
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    finalizeResolution(
                                                        'archived',
                                                    )
                                                }
                                                disabled={finalizing}
                                            >
                                                Archive
                                            </Button>
                                        </div>
                                    )}
                                {isFinalized && (
                                    <p className="text-sm text-muted-foreground">
                                        This resolution is {resolution.status}.
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Admin Actions */}
                {canManage && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Admin Actions</CardTitle>
                        </CardHeader>
                        <CardContent className="flex gap-2">
                            {resolution.status === 'draft' && (
                                <Button onClick={openVoting}>
                                    Open Voting
                                </Button>
                            )}
                            {resolution.status === 'open' && (
                                <Button
                                    onClick={closeVoting}
                                    variant="destructive"
                                >
                                    Close Voting
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                )}

                <Card className="mt-6">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Paperclip className="h-5 w-5" />
                            Supporting documents
                            <span className="ml-1 text-sm font-normal text-muted-foreground">
                                ({attachments.length})
                            </span>
                        </CardTitle>
                        <CardDescription>
                            Background analyses, draft contracts, legal opinions
                            and other papers the board should review alongside
                            this resolution.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <GovernanceAttachmentsPanel
                            canManage={!!canManage}
                            attachments={attachments}
                            urls={{
                                upload: `/governance/resolutions/${resolution.id}/attachments`,
                                delete: (id) =>
                                    `/governance/resolutions/${resolution.id}/attachments/${id}`,
                            }}
                            reloadProp="attachments"
                            helperText="PDF, Office, images, CSV / TXT — up to 20 MB each."
                            emptyText={{
                                managed:
                                    'No supporting documents yet. Drop files above to attach one.',
                                readOnly:
                                    'No supporting documents have been attached to this resolution.',
                            }}
                        />
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
