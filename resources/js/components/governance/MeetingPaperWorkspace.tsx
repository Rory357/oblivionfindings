import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTimeLong } from '@/lib/datetime';
import { vote as voteResolution } from '@/routes/governance/resolutions';
import { declare as declareConflictRoute } from '@/routes/governance/resolutions/conflict';
import { Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    CheckCircle,
    CheckCircle2,
    DollarSign,
    Download,
    ExternalLink,
    FileText,
    Gavel,
    Lock,
    MinusCircle,
    Paperclip,
    Scale,
    ShieldAlert,
    Users,
    Vote,
    XCircle,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    actionHrefWithReturn,
    type MeetingWorkspaceFocus,
} from './meeting-workspace-links';

export interface OptionItem {
    label: string;
    description?: string;
    benefits?: string;
    drawbacks?: string;
}

export interface PaperResolution {
    id: number;
    resolution_reference: string;
    title: string;
    exact_motion?: string | null;
    purpose?: string | null;
    decision_type?: string | null;
    context?: string | null;
    options?: OptionItem[];
    single_option_reason?: string | null;
    recommendation?: string | null;
    cost_impact?: {
        has_cost?: boolean;
        amount?: string | number;
        currency?: string;
        budget_source?: string;
    } | null;
    service_user_implications?: string | null;
    risk_equity_implications?: string | null;
    status: string;
    outcome?: string | null;
    deadline?: string | null;
    voting_threshold?: string | null;
    paper_snapshot?: Record<string, any> | null;
    my_vote?: {
        id: number;
        vote: string;
        voting_method?: string;
        conflict_declared: boolean;
        voted_at: string;
    } | null;
    my_conflict?: {
        id: number;
        declaration_type: string;
        declaration_text?: string;
        withdrew_from_voting: boolean;
        declared_at?: string;
    } | null;
    can_vote?: boolean;
    can_manage?: boolean;
    results?: {
        outcome: string;
        is_frozen?: boolean;
        summary: { for: number; against: number; abstain: number };
        percentages: { for: number; against: number; abstain: number };
        individual_votes: Array<{
            board_member?: { user?: { name?: string | null } | null } | string | null;
            vote: string;
            conflict_declared: boolean;
            voted_at: string;
        }>;
        conflicts: Array<{
            board_member?: { user?: { name?: string | null } | null } | string | null;
            type: string;
            description: string;
            withdrew: boolean;
        }>;
    } | null;
    quorum?: {
        met: boolean;
        required: number;
        present: number;
        voted: number;
        total_eligible: number;
    } | null;
    /** Follow-up actions the viewer may see (server-filtered by record audience). */
    action_items?: PaperActionItem[];
    /** Follow-up actions on this paper the viewer is not permitted to see. */
    restricted_action_items_count?: number;
    attachments?: PaperAttachment[];
}

/** Explicit follow-up action payload from `GovernanceMeetingController::presentPaperAction`. */
export interface PaperActionItem {
    id: number;
    reference: string;
    title: string;
    status: string;
    priority?: string | null;
    due_date?: string | null;
    due_label?: string | null;
    assignee_name?: string | null;
    is_mine: boolean;
    can_open: boolean;
    /** Canonical action page; null when the viewer cannot pass its gate. */
    open_url: string | null;
}

/** Supporting document payload from `Resolution::presentAttachments`. */
export interface PaperAttachment {
    id: string | null;
    original_name: string;
    mime_type?: string | null;
    size_bytes?: number | null;
    uploaded_at?: string | null;
    uploaded_by_name?: string | null;
    /** Authorised download route; null when the viewer cannot download. */
    download_url: string | null;
}

function formatFileSize(bytes?: number | null): string | null {
    if (bytes === null || bytes === undefined || Number.isNaN(bytes)) return null;
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/** Decision outcome → the shared status token pairs. */
function outcomeVariant(outcome: string | null | undefined): StatusVariant {
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

function voteVariant(vote: string): StatusVariant {
    if (vote === 'for') return 'success';
    if (vote === 'against') return 'critical';
    return 'neutral';
}

const outcomeLabel = (outcome: string) =>
    outcome === 'no_quorum'
        ? 'No quorum'
        : outcome.charAt(0).toUpperCase() + outcome.slice(1).replace(/_/g, ' ');

interface Props {
    resolution: PaperResolution;
    meetingId: number;
    meetingTitle?: string;
    onClose: () => void;
    /** The next paper in agenda order, so a member can move on after voting. */
    nextPaper?: Pick<PaperResolution, 'id' | 'title' | 'resolution_reference'> | null;
    onOpenPaper?: (paperId: number) => void;
    /** Where to land when the workspace opens (e.g. back from an action). */
    focus?: MeetingWorkspaceFocus | null;
}

export function MeetingPaperWorkspace({
    resolution,
    meetingId,
    meetingTitle,
    onClose,
    nextPaper = null,
    onOpenPaper,
    focus = null,
}: Props) {
    const rootRef = useRef<HTMLDivElement>(null);
    const followUpsRef = useRef<HTMLDivElement>(null);

    // Keep the member's place: opening a paper (or returning from one of its
    // follow-up actions) scrolls to the paper, or straight to its follow-ups.
    useEffect(() => {
        const target =
            focus === 'follow-ups' && followUpsRef.current
                ? followUpsRef.current
                : rootRef.current;
        if (!target || typeof target.scrollIntoView !== 'function') return;
        const reduceMotion =
            typeof window !== 'undefined' &&
            typeof window.matchMedia === 'function' &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        target.scrollIntoView({
            behavior: reduceMotion ? 'auto' : 'smooth',
            block: 'start',
        });
        if (focus === 'follow-ups') {
            target.focus({ preventScroll: true });
        }
    }, [resolution.id, focus]);

    const [selectedVote, setSelectedVote] = useState<string>('');
    const [conflictNote, setConflictNote] = useState<string>('');
    const [submittingVote, setSubmittingVote] = useState(false);
    const [conflictDialogOpen, setConflictDialogOpen] = useState(false);
    const [conflictType, setConflictType] = useState('material');
    const [conflictDescription, setConflictDescription] = useState('');
    const [submittingConflict, setSubmittingConflict] = useState(false);

    // If paper has a frozen snapshot, prefer frozen terms
    const displayData = resolution.paper_snapshot ?? resolution;
    const options: OptionItem[] = Array.isArray(displayData.options) ? displayData.options : [];
    const isOpen = resolution.status === 'open';
    const isClosed = ['closed', 'implemented', 'archived'].includes(resolution.status);

    const submitVote = () => {
        if (!selectedVote) return;
        setSubmittingVote(true);
        router.post(
            voteResolution.url({ resolution: resolution.id }),
            {
                vote: selectedVote,
                voting_method: 'electronic',
                conflict_note: conflictNote || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelectedVote('');
                    setConflictNote('');
                },
                onFinish: () => setSubmittingVote(false),
            },
        );
    };

    const submitConflict = () => {
        if (conflictDescription.length < 20) return;
        setSubmittingConflict(true);
        router.post(
            declareConflictRoute.url({ resolution: resolution.id }),
            {
                declaration_type: conflictType,
                declaration_text: conflictDescription,
                withdrew_from_voting: true,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setConflictDialogOpen(false);
                    setConflictDescription('');
                },
                onFinish: () => setSubmittingConflict(false),
            },
        );
    };

    const resolveMemberName = (m: any): string => {
        if (!m) return 'Unknown Member';
        if (typeof m === 'string') return m;
        return m.user?.name ?? 'Board Member';
    };

    return (
        <div
            ref={rootRef}
            className="flex scroll-mt-5 flex-col gap-5"
            data-test="meeting-paper-workspace"
            data-paper-id={resolution.id}
        >
            {/* Top Return Header */}
            <Card className="flex-row flex-wrap items-center justify-between gap-3 p-4">
                <div className="flex items-center gap-3">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={onClose}
                        className="gap-1.5"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Back to meeting papers
                    </Button>
                    <div className="hidden sm:block text-xs text-muted-foreground">
                        {meetingTitle ? `Meeting: ${meetingTitle}` : 'Meeting Workspace'}
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-xs font-mono font-medium text-muted-foreground">
                        {resolution.resolution_reference}
                    </span>
                    <StatusBadge status={resolution.status} />
                    {resolution.outcome && (
                        <StatusBadge variant={outcomeVariant(resolution.outcome)}>
                            {outcomeLabel(resolution.outcome)}
                        </StatusBadge>
                    )}
                    {nextPaper && onOpenPaper && (
                        <Button
                            variant="outline"
                            size="sm"
                            className="gap-1.5"
                            onClick={() => onOpenPaper(nextPaper.id)}
                            aria-label={`Next paper: ${nextPaper.resolution_reference} ${nextPaper.title}`}
                            data-test="meeting-paper-next"
                        >
                            Next paper
                            <ArrowRight className="h-4 w-4" aria-hidden="true" />
                        </Button>
                    )}
                </div>
            </Card>

            {/* Paper Title & Snapshot Banner */}
            <div className="space-y-2">
                <h2 className="text-section-title tracking-tight">
                    {resolution.title}
                </h2>
                {resolution.paper_snapshot && (
                    <div className="rounded-lg border border-primary/30 bg-primary/5 p-3 flex items-center justify-between gap-3 text-xs text-foreground">
                        <div className="flex items-center gap-2">
                            <Lock className="h-4 w-4 text-primary shrink-0" />
                            <span>
                                <strong>Frozen Decision Paper:</strong> All votes evaluate these exact terms as frozen upon opening.
                            </span>
                        </div>
                        <Badge variant="outline" className="bg-background text-primary shrink-0">
                            Immutable Snapshot
                        </Badge>
                    </div>
                )}
            </div>

            {/* Exact Motion Card */}
            <Card className="border-primary/30 bg-card">
                <CardHeader className="pb-2">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <Gavel className="h-4 w-4 text-primary" />
                            <CardTitle className="text-base font-semibold">Exact Motion</CardTitle>
                        </div>
                        <div className="flex items-center gap-2">
                            <Badge variant="outline" className="capitalize text-xs">
                                {displayData.purpose ?? 'decision'} paper
                            </Badge>
                            {displayData.decision_type && (
                                <Badge variant="secondary" className="capitalize text-xs">
                                    {displayData.decision_type}
                                </Badge>
                            )}
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <blockquote className="border-l-4 border-primary pl-4 italic text-base font-medium text-foreground py-2.5 bg-primary/5 rounded-r">
                        {displayData.exact_motion || resolution.title}
                    </blockquote>
                </CardContent>
            </Card>

            {/* Context & Background */}
            <Card>
                <CardHeader className="pb-2">
                    <CardTitle className="text-base font-semibold flex items-center gap-2">
                        <FileText className="h-4 w-4 text-primary" />
                        Context & Background (Why now?)
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p className="whitespace-pre-wrap text-sm text-foreground leading-relaxed">
                        {displayData.context || 'No background context provided.'}
                    </p>
                </CardContent>
            </Card>

            {/* Options Evaluated & Recommendation */}
            <Card>
                <CardHeader className="pb-2">
                    <CardTitle className="text-base font-semibold flex items-center gap-2">
                        <Scale className="h-4 w-4 text-primary" />
                        Alternatives Evaluated ({options.length})
                    </CardTitle>
                    <CardDescription className="text-xs">
                        Consequential decisions evaluate alternatives with explicit benefits and drawbacks.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    {options.length > 0 && (
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            {options.map((option, idx) => (
                                <div
                                    key={idx}
                                    className="rounded-lg border p-3.5 bg-muted/15 space-y-2 flex flex-col justify-between text-xs"
                                >
                                    <div>
                                        <div className="flex items-center justify-between mb-1">
                                            <span className="font-semibold text-foreground">
                                                {option.label}
                                            </span>
                                            <Badge variant="outline" className="text-[10px]">
                                                Option {idx + 1}
                                            </Badge>
                                        </div>
                                        {option.description && (
                                            <p className="text-muted-foreground whitespace-pre-wrap">
                                                {option.description}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-1.5 pt-2 border-t">
                                        {option.benefits && (
                                            <div className="rounded bg-status-success-bg/20 border border-status-success/20 p-2">
                                                <p className="font-semibold text-status-success mb-0.5">Benefits:</p>
                                                <p className="text-foreground/90 whitespace-pre-wrap">{option.benefits}</p>
                                            </div>
                                        )}
                                        {option.drawbacks && (
                                            <div className="rounded bg-status-critical-bg/20 border border-status-critical/20 p-2">
                                                <p className="font-semibold text-status-critical mb-0.5">Drawbacks & Costs:</p>
                                                <p className="text-foreground/90 whitespace-pre-wrap">{option.drawbacks}</p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    {options.length < 2 && displayData.single_option_reason && (
                        <div className="rounded-lg border border-status-warning/40 bg-status-warning-bg/15 p-3.5 space-y-1 text-xs">
                            <div className="flex items-center gap-2 text-status-warning font-semibold uppercase tracking-wider">
                                <AlertCircle className="h-4 w-4" />
                                Sole Option Justification
                            </div>
                            <p className="text-foreground whitespace-pre-wrap">
                                {displayData.single_option_reason}
                            </p>
                        </div>
                    )}

                    {displayData.recommendation && (
                        <div className="rounded-lg border border-status-info/30 bg-status-info-bg/25 p-3.5 space-y-1 text-xs">
                            <p className="font-semibold uppercase tracking-wider text-status-info">
                                Management Recommendation
                            </p>
                            <p className="text-sm text-foreground whitespace-pre-wrap leading-relaxed">
                                {displayData.recommendation}
                            </p>
                        </div>
                    )}
                </CardContent>
            </Card>

            {/* Impact & Governance Assessments */}
            <Card>
                <CardHeader className="pb-2">
                    <CardTitle className="text-base font-semibold flex items-center gap-2">
                        <Scale className="h-4 w-4 text-primary" />
                        Impact & Governance Assessments
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        {/* Financial */}
                        <div className="rounded-lg border p-3.5 space-y-1 bg-muted/10 text-xs">
                            <div className="flex items-center gap-1.5 font-semibold text-muted-foreground uppercase tracking-wider">
                                <DollarSign className="h-3.5 w-3.5 text-primary" />
                                Financial Cost
                            </div>
                            {displayData.cost_impact?.has_cost ? (
                                <div className="space-y-1">
                                    <p className="text-base font-bold text-foreground">
                                        {displayData.cost_impact.currency ?? 'NZD'} {displayData.cost_impact.amount}
                                    </p>
                                    {displayData.cost_impact.budget_source && (
                                        <p className="text-muted-foreground">
                                            Fund: {displayData.cost_impact.budget_source}
                                        </p>
                                    )}
                                </div>
                            ) : (
                                <p className="text-muted-foreground">Confirmed: No direct financial cost.</p>
                            )}
                        </div>

                        {/* Service User & Safety */}
                        <div className="rounded-lg border p-3.5 space-y-1 bg-muted/10 text-xs">
                            <div className="flex items-center gap-1.5 font-semibold text-muted-foreground uppercase tracking-wider">
                                <Users className="h-3.5 w-3.5 text-primary" />
                                Service-User & Safety
                            </div>
                            <p className="text-foreground whitespace-pre-wrap">
                                {displayData.service_user_implications || 'None specified.'}
                            </p>
                        </div>

                        {/* Risk & Equity */}
                        <div className="rounded-lg border p-3.5 space-y-1 bg-muted/10 text-xs">
                            <div className="flex items-center gap-1.5 font-semibold text-muted-foreground uppercase tracking-wider">
                                <ShieldAlert className="h-3.5 w-3.5 text-primary" />
                                Risk & Equity
                            </div>
                            <p className="text-foreground whitespace-pre-wrap">
                                {displayData.risk_equity_implications || 'None specified.'}
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/* Voting & Decision Section */}
            {isOpen && resolution.can_vote && !resolution.my_vote && !resolution.my_conflict?.withdrew_from_voting && (
                <Card className="border-status-info/40 bg-card">
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2">
                            <Vote className="h-5 w-5 text-status-info" />
                            Cast Your Vote in Context
                        </CardTitle>
                        <CardDescription>
                            {resolution.deadline
                                ? `Voting closes ${formatDateTimeLong(resolution.deadline)} (NZ time)`
                                : 'Voting is currently open for eligible members.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <RadioGroup
                            value={selectedVote}
                            onValueChange={setSelectedVote}
                            className="grid grid-cols-1 sm:grid-cols-3 gap-3"
                        >
                            <label className="flex cursor-pointer items-center gap-3 rounded-lg border p-3 transition-colors hover:bg-muted [&:has([data-state=checked])]:border-status-success [&:has([data-state=checked])]:bg-status-success-bg/15">
                                <RadioGroupItem value="for" />
                                <span className="flex items-center gap-2 text-sm font-medium">
                                    <CheckCircle className="h-4 w-4 text-status-success" />
                                    For (Yes)
                                </span>
                            </label>
                            <label className="flex cursor-pointer items-center gap-3 rounded-lg border p-3 transition-colors hover:bg-muted [&:has([data-state=checked])]:border-status-critical [&:has([data-state=checked])]:bg-status-critical-bg/15">
                                <RadioGroupItem value="against" />
                                <span className="flex items-center gap-2 text-sm font-medium">
                                    <XCircle className="h-4 w-4 text-status-critical" />
                                    Against (No)
                                </span>
                            </label>
                            <label className="flex cursor-pointer items-center gap-3 rounded-lg border p-3 transition-colors hover:bg-muted [&:has([data-state=checked])]:border-primary [&:has([data-state=checked])]:bg-primary/5">
                                <RadioGroupItem value="abstain" />
                                <span className="flex items-center gap-2 text-sm font-medium">
                                    <MinusCircle className="h-4 w-4 text-muted-foreground" />
                                    Abstain
                                </span>
                            </label>
                        </RadioGroup>

                        <div>
                            <label htmlFor="vote-note" className="text-xs font-medium text-foreground block mb-1">
                                Vote Note (optional)
                            </label>
                            <Textarea
                                id="vote-note"
                                placeholder="Optional context or reason for your vote..."
                                value={conflictNote}
                                onChange={(e) => setConflictNote(e.target.value)}
                                rows={2}
                                className="text-xs"
                            />
                        </div>

                        <div className="flex flex-wrap items-center justify-between gap-3 pt-2 border-t">
                            <Button
                                onClick={submitVote}
                                disabled={!selectedVote || submittingVote}
                            >
                                {submittingVote ? 'Submitting Vote...' : 'Submit Vote'}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setConflictDialogOpen(true)}
                                className="gap-1.5"
                            >
                                <AlertTriangle className="h-4 w-4 text-status-warning" />
                                Declare Conflict...
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* Recusal notice */}
            {resolution.my_conflict?.withdrew_from_voting && (
                <Card className="border-status-warning/40 bg-status-warning-bg/10">
                    <CardHeader className="pb-2">
                        <CardTitle className="flex items-center gap-2 text-status-warning text-base">
                            <AlertTriangle className="h-5 w-5" />
                            Conflict Declared — Recused from Voting
                        </CardTitle>
                        <CardDescription className="text-xs">
                            You declared a conflict on this resolution and withdrew from voting. Your seat is excluded from quorum calculations.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="text-xs space-y-1">
                        <p><strong>Nature:</strong> {resolution.my_conflict.declaration_type}</p>
                        {resolution.my_conflict.declaration_text && (
                            <p className="text-muted-foreground">{resolution.my_conflict.declaration_text}</p>
                        )}
                    </CardContent>
                </Card>
            )}

            {/* Vote Receipt */}
            {resolution.my_vote && (
                <Card className="border-status-success/30 bg-card">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base flex items-center gap-2">
                            <CheckCircle2 className="h-5 w-5 text-status-success" />
                            Your Vote Receipt
                        </CardTitle>
                        <CardDescription className="text-xs">
                            Official tamper-proof record of your vote for {resolution.resolution_reference}.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap items-center gap-3">
                            <StatusBadge variant={voteVariant(resolution.my_vote.vote)}>
                                {resolution.my_vote.vote.toUpperCase()}
                            </StatusBadge>
                            <span className="text-xs text-muted-foreground" data-test="paper-vote-receipt-time">
                                Recorded{' '}
                                <time dateTime={resolution.my_vote.voted_at}>
                                    {formatDateTimeLong(resolution.my_vote.voted_at)}
                                </time>{' '}
                                (NZ time) · Method:{' '}
                                {resolution.my_vote.voting_method
                                    ? resolution.my_vote.voting_method.charAt(0).toUpperCase() +
                                      resolution.my_vote.voting_method.slice(1).replace(/_/g, ' ')
                                    : 'Electronic'}
                            </span>
                            {resolution.my_vote.conflict_declared && (
                                <Badge variant="outline" className="text-status-warning text-xs">
                                    <AlertTriangle className="mr-1 h-3 w-3" />
                                    Conflict Noted
                                </Badge>
                            )}
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* Results breakdown (for closed papers) */}
            {isClosed && resolution.results && (
                <Card>
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base">Official Decision Results</CardTitle>
                            <StatusBadge variant={outcomeVariant(resolution.results.outcome)}>
                                {outcomeLabel(resolution.results.outcome)}
                            </StatusBadge>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid grid-cols-3 gap-3">
                            <div className="rounded-lg bg-status-success-bg p-3 text-center">
                                <p className="text-2xl font-bold text-status-success">
                                    {resolution.results.summary.for}
                                </p>
                                <p className="text-xs text-status-success">For ({resolution.results.percentages.for}%)</p>
                            </div>
                            <div className="rounded-lg bg-status-critical-bg p-3 text-center">
                                <p className="text-2xl font-bold text-status-critical">
                                    {resolution.results.summary.against}
                                </p>
                                <p className="text-xs text-status-critical">Against ({resolution.results.percentages.against}%)</p>
                            </div>
                            <div className="rounded-lg bg-muted p-3 text-center">
                                <p className="text-2xl font-bold text-muted-foreground">
                                    {resolution.results.summary.abstain}
                                </p>
                                <p className="text-xs text-foreground">Abstain</p>
                            </div>
                        </div>

                        {resolution.results.individual_votes.length > 0 && (
                            <div className="space-y-1.5 pt-2">
                                <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    Recorded Member Votes
                                </h4>
                                <div className="space-y-1">
                                    {resolution.results.individual_votes.map((v, i) => (
                                        <div key={i} className="flex items-center justify-between rounded border p-2 text-xs">
                                            <span>{resolveMemberName(v.board_member)}</span>
                                            <StatusBadge variant={voteVariant(v.vote)}>
                                                {v.vote.charAt(0).toUpperCase() + v.vote.slice(1)}
                                            </StatusBadge>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            )}

            {/* Follow-up Action Items */}
            {((resolution.action_items?.length ?? 0) > 0 ||
                (resolution.restricted_action_items_count ?? 0) > 0) && (
                <Card
                    ref={followUpsRef}
                    id={`paper-${resolution.id}-follow-ups`}
                    tabIndex={-1}
                    className="scroll-mt-5 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    data-test="paper-follow-up-actions"
                >
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base flex items-center gap-2">
                            <CheckCircle className="h-4 w-4 text-primary" />
                            Assigned Follow-up Actions ({resolution.action_items?.length ?? 0})
                        </CardTitle>
                        {(resolution.restricted_action_items_count ?? 0) > 0 && (
                            <CardDescription className="flex items-center gap-1.5 text-xs">
                                <Lock className="size-3.5" aria-hidden="true" />
                                {resolution.restricted_action_items_count} further follow-up
                                {resolution.restricted_action_items_count === 1 ? ' action is' : ' actions are'} restricted
                                to their authorised audience.
                            </CardDescription>
                        )}
                    </CardHeader>
                    {(resolution.action_items?.length ?? 0) > 0 && (
                        <CardContent>
                            <ul className="space-y-2">
                                {(resolution.action_items ?? []).map((action) => (
                                    <li
                                        key={action.id}
                                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3 text-xs"
                                        data-test="paper-follow-up-action"
                                    >
                                        <div className="min-w-0 space-y-0.5">
                                            <p className="font-semibold text-foreground">
                                                {action.title}
                                            </p>
                                            <p className="text-muted-foreground">
                                                <span className="font-mono">{action.reference}</span>
                                                {' · '}
                                                {action.is_mine
                                                    ? 'Assigned to you'
                                                    : action.assignee_name
                                                      ? `Assigned: ${action.assignee_name}`
                                                      : 'Unassigned'}
                                                {action.due_label ? ` · Due ${action.due_label}` : ''}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <StatusBadge status={action.status} />
                                            {action.can_open && action.open_url && (
                                                <Button asChild variant="outline" size="sm">
                                                    <Link
                                                        href={actionHrefWithReturn(
                                                            action.open_url,
                                                            meetingId,
                                                            resolution.id,
                                                        )}
                                                        aria-label={`${action.is_mine && action.status !== 'complete' ? 'Update' : 'Open'} follow-up action ${action.reference}: ${action.title}`}
                                                        data-test="paper-follow-up-action-open"
                                                    >
                                                        <ExternalLink className="size-4" aria-hidden="true" />
                                                        {action.is_mine && action.status !== 'complete' ? 'Update' : 'Open'}
                                                    </Link>
                                                </Button>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    )}
                </Card>
            )}

            {/* Supporting Documents / Attachments */}
            {resolution.attachments && resolution.attachments.length > 0 && (
                <Card data-test="paper-supporting-documents">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base flex items-center gap-2">
                            <Paperclip className="h-4 w-4 text-primary" />
                            Supporting Documents ({resolution.attachments.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul className="space-y-2">
                            {resolution.attachments.map((doc, index) => {
                                const size = formatFileSize(doc.size_bytes);

                                return (
                                    <li
                                        key={doc.id ?? `${doc.original_name}-${index}`}
                                        className="flex flex-wrap items-center justify-between gap-3 rounded border p-2 text-xs"
                                        data-test="paper-supporting-document"
                                    >
                                        <div className="flex min-w-0 items-center gap-2">
                                            <FileText className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                            <span className="truncate text-foreground">{doc.original_name}</span>
                                            {size && <span className="shrink-0 text-muted-foreground">{size}</span>}
                                        </div>
                                        {doc.download_url && (
                                            <Button asChild variant="outline" size="sm">
                                                <a
                                                    href={doc.download_url}
                                                    download
                                                    aria-label={`Download ${doc.original_name}`}
                                                >
                                                    <Download className="size-4" aria-hidden="true" />
                                                    Download
                                                </a>
                                            </Button>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </CardContent>
                </Card>
            )}

            {/* Conflict Declaration Dialog */}
            <Dialog open={conflictDialogOpen} onOpenChange={setConflictDialogOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Declare Conflict of Interest</DialogTitle>
                        <DialogDescription className="text-xs">
                            Formally declare an interest in {resolution.resolution_reference}. Withdrawing from voting excludes you from quorum participation without recording an abstention.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-2 text-xs">
                        <div>
                            <label className="font-medium text-foreground block mb-1">
                                Nature of Conflict <span className="text-status-critical">*</span>
                            </label>
                            <select
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-xs"
                                value={conflictType}
                                onChange={(e) => setConflictType(e.target.value)}
                            >
                                <option value="material">Material personal interest</option>
                                <option value="related">Related party transaction</option>
                                <option value="prejudicial">Prejudicial bias or loyalty conflict</option>
                                <option value="other">Other perceived conflict</option>
                            </select>
                        </div>
                        <div>
                            <label className="font-medium text-foreground block mb-1">
                                Affected Matter & Detail <span className="text-status-critical">*</span>
                            </label>
                            <Textarea
                                placeholder="Describe the nature of your interest and affected decisions (minimum 20 characters)..."
                                value={conflictDescription}
                                onChange={(e) => setConflictDescription(e.target.value)}
                                rows={3}
                                className="text-xs"
                            />
                            {conflictDescription.length > 0 && conflictDescription.length < 20 && (
                                <p className="text-[11px] text-status-critical mt-1">
                                    Must be at least 20 characters ({conflictDescription.length}/20).
                                </p>
                            )}
                        </div>
                        <div className="flex justify-end gap-2 pt-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => setConflictDialogOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                size="sm"
                                onClick={submitConflict}
                                disabled={conflictDescription.length < 20 || submittingConflict}
                            >
                                {submittingConflict ? 'Submitting...' : 'Record Declaration & Recuse'}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    );
}
