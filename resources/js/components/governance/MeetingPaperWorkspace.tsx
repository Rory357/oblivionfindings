import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateLong } from '@/lib/datetime';
import {
    decisionTypeLabel,
    formatNzd,
    governanceStatus,
    refSuffix,
    resolutionChip,
    resolutionPurposeLabel,
} from '@/lib/governance-labels';
import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    CheckCircle,
    DollarSign,
    Download,
    ExternalLink,
    FileText,
    Gavel,
    Lock,
    Paperclip,
    Scale,
    ShieldAlert,
    Users,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { DeclareConflictDialog } from './DeclareConflictDialog';
import { GovernanceTermHint } from './GovernanceTermHint';
import {
    actionHrefWithReturn,
    type MeetingWorkspaceFocus,
} from './meeting-workspace-links';
import {
    ResolutionBallot,
    type BallotConflict,
    type BallotVote,
} from './ResolutionBallot';
import {
    ResolutionResultCard,
    type ResolutionResultData,
} from './ResolutionResultCard';
import { canDeclareConflictOnStatus } from './resolution-voting';

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
        is_none?: boolean;
        amount?: string | number;
        currency?: string;
        budget_source?: string;
        funding_source?: string;
    } | null;
    service_user_implications?: string | null;
    risk_equity_implications?: string | null;
    status: string;
    outcome?: string | null;
    deadline?: string | null;
    closed_at?: string | null;
    voting_threshold?: string | null;
    quorum_required?: boolean | null;
    version_number?: number | null;
    governance_meeting_id?: number | null;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    paper_snapshot?: Record<string, any> | null;
    my_vote?: BallotVote | null;
    my_conflict?: BallotConflict | null;
    can_vote?: boolean;
    can_manage?: boolean;
    /** Optional server hints (the meeting payload may add them). */
    can_declare_conflict?: boolean;
    ineligible_reason?: string | null;
    applied_threshold?: string | null;
    voting_rules_switched_on?: boolean;
    results?: ResolutionResultData | null;
    quorum?: {
        met: boolean;
        required: number;
        present: number;
        voted?: number;
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

/** The rule the engine applies when the payload doesn't say (`special` is two-thirds). */
function appliedThresholdFor(resolution: PaperResolution): string | null {
    if (resolution.results?.applied_threshold) return resolution.results.applied_threshold;
    if (resolution.applied_threshold) return resolution.applied_threshold;
    return resolution.voting_threshold === 'special'
        ? 'two_thirds'
        : (resolution.voting_threshold ?? null);
}

type SharedAuth = {
    auth?: {
        can?: { governance?: { resolutions?: { vote?: boolean } } };
    };
};

interface Props {
    resolution: PaperResolution;
    meetingId: number;
    meetingTitle?: string;
    onClose: () => void;
    /** The next resolution in agenda order, so a member can move on after voting. */
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
    const [conflictOpen, setConflictOpen] = useState(false);
    const page = usePage<SharedAuth>();

    // Keep the member's place: opening a resolution (or returning from one of
    // its follow-up actions) scrolls to it, or straight to its follow-ups.
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

    // The saved copy is the authoritative wording once it's published.
    const displayData = (resolution.paper_snapshot ?? resolution) as PaperResolution;
    const options: OptionItem[] = Array.isArray(displayData.options) ? displayData.options : [];
    const isOpen = resolution.status === 'open';
    const isClosed = ['closed', 'implemented', 'archived'].includes(resolution.status);
    const version =
        (resolution.paper_snapshot?.version_number as number | undefined) ??
        resolution.version_number ??
        null;
    const appliedThreshold = appliedThresholdFor(resolution);
    const chip = resolutionChip(resolution.status, resolution.outcome);

    // Declaring needs the vote permission; the server also checks the viewer
    // has a board seat and shows its reason inline if not.
    const canDeclareConflict =
        (resolution.can_declare_conflict ??
            Boolean(page?.props?.auth?.can?.governance?.resolutions?.vote)) &&
        canDeclareConflictOnStatus(resolution.status);

    const cost = displayData.cost_impact ?? null;
    const costText = cost?.has_cost
        ? formatNzd(cost.amount ?? null)
        : cost && (cost.has_cost === false || cost.is_none)
          ? 'No cost'
          : 'Cost not stated';
    const costSource = cost?.has_cost ? (cost.budget_source ?? cost.funding_source ?? null) : null;

    const followUpCount =
        (resolution.action_items?.length ?? 0) + (resolution.restricted_action_items_count ?? 0);

    const openNext =
        nextPaper && onOpenPaper
            ? {
                  label: `Next resolution: ${nextPaper.title}`,
                  onClick: () => onOpenPaper(nextPaper.id),
              }
            : null;

    return (
        <div
            ref={rootRef}
            className="flex scroll-mt-5 flex-col gap-5"
            data-test="meeting-paper-workspace"
            data-paper-id={resolution.id}
        >
            {/* Where you are, and where to go next */}
            <Card className="flex-row flex-wrap items-center justify-between gap-3 p-4">
                <div className="flex min-w-0 items-center gap-3">
                    <Button variant="outline" size="sm" onClick={onClose}>
                        <ArrowLeft className="h-4 w-4" />
                        Back to resolutions
                    </Button>
                    {meetingTitle ? (
                        <span className="hidden truncate text-caption sm:block">
                            {`Meeting: ${meetingTitle}`}
                        </span>
                    ) : null}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge variant={chip.variant}>{chip.label}</StatusBadge>
                    {nextPaper && onOpenPaper ? (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => onOpenPaper(nextPaper.id)}
                            aria-label={`Next resolution: ${nextPaper.title}`}
                            data-test="meeting-paper-next"
                        >
                            Next resolution
                            <ArrowRight className="h-4 w-4" aria-hidden="true" />
                        </Button>
                    ) : null}
                </div>
            </Card>

            <div className="flex flex-col gap-2">
                <h2 className="text-section-title">{resolution.title}</h2>
                {refSuffix(resolution.resolution_reference) ? (
                    <p className="text-caption">{refSuffix(resolution.resolution_reference)}</p>
                ) : null}
                {resolution.paper_snapshot ? (
                    <div className="flex items-center gap-2 rounded-lg border border-primary/30 bg-primary/5 p-3 text-sm">
                        <Lock className="h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                        <span>
                            <span className="font-semibold">
                                {version ? `This is the final wording (version ${version}).` : 'This is the final wording.'}
                            </span>{' '}
                            {isOpen
                                ? "It can't change while voting is open — everyone votes on these exact words."
                                : "It can't be changed now it's published."}
                        </span>
                    </div>
                ) : null}
            </div>

            {/* Resolution wording */}
            <Card className="border-primary/30">
                <CardHeader>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <CardTitle className="text-section-title flex items-center gap-2">
                            <Gavel className="h-4 w-4 text-primary" aria-hidden="true" />
                            What the board is asked to decide
                            <GovernanceTermHint term="resolution_wording" />
                        </CardTitle>
                        <div className="flex flex-wrap items-center gap-1.5">
                            <StatusBadge variant="neutral">
                                {resolutionPurposeLabel(displayData.purpose ?? 'decision')}
                            </StatusBadge>
                            {displayData.decision_type ? (
                                <StatusBadge variant="neutral">
                                    {decisionTypeLabel(displayData.decision_type)}
                                </StatusBadge>
                            ) : null}
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <blockquote className="rounded-r border-l-4 border-primary bg-primary/5 py-2.5 pl-4 text-base font-medium whitespace-pre-wrap text-foreground">
                        {displayData.exact_motion || resolution.title}
                    </blockquote>
                </CardContent>
            </Card>

            {/* Why it's before the board */}
            <Card>
                <CardHeader>
                    <CardTitle className="text-section-title flex items-center gap-2">
                        <FileText className="h-4 w-4 text-primary" aria-hidden="true" />
                        Why this is before the board
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p className="text-sm leading-relaxed whitespace-pre-wrap text-foreground">
                        {displayData.context || 'No background given.'}
                    </p>
                </CardContent>
            </Card>

            {/* Options and recommendation */}
            <Card>
                <CardHeader>
                    <CardTitle className="text-section-title flex items-center gap-2">
                        <Scale className="h-4 w-4 text-primary" aria-hidden="true" />
                        {`Options considered (${options.length})`}
                    </CardTitle>
                    <CardDescription>
                        The choices the board could make, with what’s good and bad about each.
                    </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                    {options.length > 0 ? (
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            {options.map((option, idx) => (
                                <div
                                    key={idx}
                                    className="flex flex-col gap-2 rounded-lg border border-border p-3.5 text-sm"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="font-semibold text-foreground">{option.label}</span>
                                        <StatusBadge variant="neutral" size="sm">
                                            {`Option ${idx + 1}`}
                                        </StatusBadge>
                                    </div>
                                    {option.description ? (
                                        <p className="text-subtle whitespace-pre-wrap">{option.description}</p>
                                    ) : null}
                                    {option.benefits ? (
                                        <p className="whitespace-pre-wrap">
                                            <span className="font-semibold text-status-success">Good: </span>
                                            {option.benefits}
                                        </p>
                                    ) : null}
                                    {option.drawbacks ? (
                                        <p className="whitespace-pre-wrap">
                                            <span className="font-semibold text-status-critical">Downsides: </span>
                                            {option.drawbacks}
                                        </p>
                                    ) : null}
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-subtle">No options described.</p>
                    )}

                    {options.length < 2 && displayData.single_option_reason ? (
                        <div>
                            <p className="text-caption font-semibold">Why there’s only one option</p>
                            <p className="mt-1 text-sm whitespace-pre-wrap text-foreground">
                                {displayData.single_option_reason}
                            </p>
                        </div>
                    ) : null}

                    {displayData.recommendation ? (
                        <div>
                            <p className="text-caption font-semibold">Management’s recommendation</p>
                            <p className="mt-1 text-sm leading-relaxed whitespace-pre-wrap text-foreground">
                                {displayData.recommendation}
                            </p>
                        </div>
                    ) : null}
                </CardContent>
            </Card>

            {/* Effects */}
            <Card>
                <CardHeader>
                    <CardTitle className="text-section-title flex items-center gap-2">
                        <ShieldAlert className="h-4 w-4 text-primary" aria-hidden="true" />
                        Effects
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div className="flex flex-col gap-1 text-sm">
                            <p className="text-caption flex items-center gap-1.5 font-semibold">
                                <DollarSign className="h-3.5 w-3.5" aria-hidden="true" />
                                Cost
                            </p>
                            <p className="text-section-title">{costText}</p>
                            {costSource ? <p className="text-subtle">{`Paid from: ${costSource}`}</p> : null}
                        </div>
                        <div className="flex flex-col gap-1 text-sm">
                            <p className="text-caption flex items-center gap-1.5 font-semibold">
                                <Users className="h-3.5 w-3.5" aria-hidden="true" />
                                Effect on the people we support and safety
                            </p>
                            <p className="whitespace-pre-wrap text-foreground">
                                {displayData.service_user_implications || 'Not stated.'}
                            </p>
                        </div>
                        <div className="flex flex-col gap-1 text-sm">
                            <p className="text-caption flex items-center gap-1.5 font-semibold">
                                <ShieldAlert className="h-3.5 w-3.5" aria-hidden="true" />
                                Risks and fairness
                            </p>
                            <p className="whitespace-pre-wrap text-foreground">
                                {displayData.risk_equity_implications || 'Not stated.'}
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/* Voting: always shown, with a plain state, the ballot or your receipt */}
            <ResolutionBallot
                id={`paper-${resolution.id}-voting`}
                resolution={{
                    id: resolution.id,
                    title: resolution.title,
                    status: resolution.status,
                    purpose: resolution.purpose,
                    deadline: resolution.deadline,
                    closed_at: resolution.closed_at ?? null,
                    voting_threshold: resolution.voting_threshold,
                    applied_threshold: appliedThreshold,
                    governance_meeting_id: resolution.governance_meeting_id ?? meetingId,
                }}
                version={version}
                canVote={Boolean(resolution.can_vote)}
                myVote={resolution.my_vote ?? null}
                myConflict={resolution.my_conflict ?? null}
                canDeclareConflict={canDeclareConflict}
                onDeclareConflict={() => setConflictOpen(true)}
                votingSwitchedOff={resolution.voting_rules_switched_on === false}
                ineligibleReason={resolution.ineligible_reason ?? null}
                next={openNext}
                back={{ label: 'Back to resolutions', onClick: onClose }}
            />

            {isClosed && resolution.results ? (
                <ResolutionResultCard
                    result={resolution.results}
                    votingThreshold={resolution.voting_threshold}
                    quorumRequired={resolution.quorum_required !== false}
                    followUpCount={followUpCount}
                    onViewFollowUps={
                        followUpCount > 0
                            ? () => followUpsRef.current?.scrollIntoView({ block: 'start' })
                            : undefined
                    }
                />
            ) : null}

            {/* Follow-up actions */}
            {followUpCount > 0 ? (
                <Card
                    ref={followUpsRef}
                    id={`paper-${resolution.id}-follow-ups`}
                    tabIndex={-1}
                    className="scroll-mt-5 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    data-test="paper-follow-up-actions"
                >
                    <CardHeader>
                        <CardTitle className="text-section-title flex items-center gap-2">
                            <CheckCircle className="h-4 w-4 text-primary" aria-hidden="true" />
                            {`Follow-up actions (${followUpCount})`}
                        </CardTitle>
                        {(resolution.restricted_action_items_count ?? 0) > 0 ? (
                            <CardDescription className="flex items-center gap-1.5">
                                <Lock className="size-3.5" aria-hidden="true" />
                                {`${resolution.restricted_action_items_count} more ${
                                    resolution.restricted_action_items_count === 1 ? "isn't" : "aren't"
                                } shown because you don't have access to ${
                                    resolution.restricted_action_items_count === 1 ? 'it' : 'them'
                                }.`}
                            </CardDescription>
                        ) : null}
                    </CardHeader>
                    {(resolution.action_items?.length ?? 0) > 0 ? (
                        <CardContent>
                            <ul className="flex flex-col gap-2">
                                {(resolution.action_items ?? []).map((action) => {
                                    const actionChip = governanceStatus('action_status', action.status);
                                    const verb =
                                        action.is_mine && !['complete', 'completed'].includes(action.status)
                                            ? 'Update'
                                            : 'Open';
                                    return (
                                        <li
                                            key={action.id}
                                            className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-3 text-sm"
                                            data-test="paper-follow-up-action"
                                        >
                                            <div className="min-w-0">
                                                <p className="font-semibold text-foreground">{action.title}</p>
                                                <p className="text-caption">
                                                    {[
                                                        action.is_mine
                                                            ? 'Yours'
                                                            : action.assignee_name
                                                              ? `For ${action.assignee_name}`
                                                              : 'Nobody responsible yet',
                                                        action.due_date
                                                            ? `Due ${formatDateLong(action.due_date)}`
                                                            : action.due_label
                                                              ? `Due ${action.due_label}`
                                                              : null,
                                                        refSuffix(action.reference),
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-2">
                                                <StatusBadge variant={actionChip.variant}>{actionChip.label}</StatusBadge>
                                                {action.can_open && action.open_url ? (
                                                    <Button asChild variant="outline" size="sm">
                                                        <Link
                                                            href={actionHrefWithReturn(
                                                                action.open_url,
                                                                meetingId,
                                                                resolution.id,
                                                            )}
                                                            aria-label={`${verb} follow-up action: ${action.title}`}
                                                            data-test="paper-follow-up-action-open"
                                                        >
                                                            <ExternalLink className="size-4" aria-hidden="true" />
                                                            {verb}
                                                        </Link>
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        </CardContent>
                    ) : null}
                </Card>
            ) : null}

            {/* Supporting documents */}
            {resolution.attachments && resolution.attachments.length > 0 ? (
                <Card data-test="paper-supporting-documents">
                    <CardHeader>
                        <CardTitle className="text-section-title flex items-center gap-2">
                            <Paperclip className="h-4 w-4 text-primary" aria-hidden="true" />
                            {`Supporting documents (${resolution.attachments.length})`}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul className="flex flex-col gap-2">
                            {resolution.attachments.map((doc, index) => {
                                const size = formatFileSize(doc.size_bytes);

                                return (
                                    <li
                                        key={doc.id ?? `${doc.original_name}-${index}`}
                                        className="flex flex-wrap items-center justify-between gap-3 rounded border border-border p-2 text-sm"
                                        data-test="paper-supporting-document"
                                    >
                                        <div className="flex min-w-0 items-center gap-2">
                                            <FileText className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                            <span className="truncate text-foreground">{doc.original_name}</span>
                                            {size ? <span className="shrink-0 text-caption">{size}</span> : null}
                                        </div>
                                        {doc.download_url ? (
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
                                        ) : null}
                                    </li>
                                );
                            })}
                        </ul>
                    </CardContent>
                </Card>
            ) : null}

            <DeclareConflictDialog
                isOpen={conflictOpen}
                onClose={() => setConflictOpen(false)}
                resolutionId={resolution.id}
                resolutionTitle={resolution.title}
                existing={resolution.my_conflict ?? null}
                hasVoted={Boolean(resolution.my_vote)}
                appliedThreshold={appliedThreshold}
            />
        </div>
    );
}
