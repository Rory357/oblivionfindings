import { ConfirmDialog } from '@/components/confirm-dialog';
import { DeclareConflictDialog } from '@/components/governance/DeclareConflictDialog';
import {
    GovernanceAttachmentsPanel,
    type GovernanceAttachment,
} from '@/components/governance/GovernanceAttachmentsPanel';
import { GovernanceTermHint } from '@/components/governance/GovernanceTermHint';
import {
    ResolutionBallot,
    type BallotConflict,
    type BallotVote,
} from '@/components/governance/ResolutionBallot';
import {
    ResolutionResultCard,
    type ResolutionResultData,
} from '@/components/governance/ResolutionResultCard';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import { isDecisionPurpose } from '@/components/governance/resolution-voting';
import { VotingSwitchedOffBanner } from '@/components/governance/VotingSwitchedOffBanner';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong, formatDateTimeLong } from '@/lib/datetime';
import {
    decisionTypeLabel,
    formatNzd,
    governanceStatus,
    refSuffix,
    resolutionChip,
    resolutionPurposeLabel,
    votingThresholdLabel,
} from '@/lib/governance-labels';
import { PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    CheckCircle2,
    DollarSign,
    ExternalLink,
    FileText,
    Gavel,
    Link2,
    ListChecks,
    Lock,
    Paperclip,
    Pencil,
    Scale,
    Send,
    ShieldAlert,
    Square,
    Users,
    Vote as VoteIcon,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import {
    ResolutionWizardDialog,
    type AuthorityBinding,
    type AuthoritySubjectGroup,
    type AuthoritySubjects,
    type CommitteeOption,
    type MeetingOption,
    type ResolutionRecord,
    type UserOption,
    type WizardVotingRules,
} from './_dialogs';
import { resolutionWorkspaceHref } from './_helpers';

interface OptionItem {
    label: string;
    description?: string | null;
    benefits?: string | null;
    drawbacks?: string | null;
}

interface CostImpact {
    has_cost?: boolean;
    is_none?: boolean;
    amount?: string | number | null;
    currency?: string | null;
    budget_source?: string | null;
    funding_source?: string | null;
}

interface PaperContent {
    exact_motion?: string | null;
    purpose?: string | null;
    decision_type?: string | null;
    context?: string | null;
    options?: OptionItem[] | null;
    single_option_reason?: string | null;
    recommendation?: string | null;
    cost_impact?: CostImpact | null;
    service_user_implications?: string | null;
    risk_equity_implications?: string | null;
    version_number?: number | null;
}

interface Resolution extends ResolutionRecord {
    resolution_reference: string;
    status: string;
    outcome: string | null;
    outcome_notes?: string | null;
    voting_threshold: string;
    quorum_required?: boolean;
    closed_at?: string | null;
    proposed_by?: { name: string } | null;
    votes?: Array<{ id: number }>;
    conflict_declarations?: Array<{ id: number }>;
}

interface Results extends ResolutionResultData {
    quorum_met: boolean;
}

interface FollowUpAction {
    id: number;
    reference: string;
    title: string;
    status: string;
    due_date?: string | null;
    assignee_name?: string | null;
    is_mine: boolean;
    can_open: boolean;
    open_url: string | null;
}

interface Props extends PageProps {
    resolution: Resolution;
    applied_threshold?: string | null;
    results: Results | null;
    my_vote: BallotVote | null;
    my_conflict?: BallotConflict | null;
    can_vote: boolean;
    ineligible_reason?: string | null;
    can_declare_conflict?: boolean;
    can_manage?: boolean;
    can_open_voting?: boolean;
    can_publish_to_members?: boolean;
    can_close_voting?: boolean;
    can_finalize?: boolean;
    voting_rules?: { switched_on: boolean; can_switch_on: boolean };
    quorum: {
        present: number;
        required: number;
        met: boolean;
        total_eligible?: number;
    } | null;
    attachments: GovernanceAttachment[];
    paper_snapshot?: PaperContent | null;
    authority_bindings?: AuthorityBinding[];
    validation_errors?: Record<string, string> | string[];
    action_items?: FollowUpAction[];
    restricted_action_items_count?: number;
    next_pending_vote?: { id: number; title: string; href: string } | null;
    meetings?: MeetingOption[];
    committees?: CommitteeOption[];
    users?: UserOption[];
    authoritySubjects?: AuthoritySubjects | null;
    authoritySubjectGroups?: AuthoritySubjectGroup[];
    votingRules?: WizardVotingRules | null;
}

function scrollToSection(id: string) {
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function Section({
    id,
    icon: Icon,
    title,
    description,
    action,
    className,
    children,
}: {
    id?: string;
    icon?: typeof Gavel;
    title: ReactNode;
    description?: ReactNode;
    action?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <Card id={id} className={className}>
            <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <CardTitle className="text-section-title flex items-center gap-2">
                            {Icon ? (
                                <Icon className="size-4 text-primary" />
                            ) : null}
                            {title}
                        </CardTitle>
                        {description ? (
                            <CardDescription>{description}</CardDescription>
                        ) : null}
                    </div>
                    {action}
                </div>
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

function SubHeading({ children }: { children: ReactNode }) {
    return <p className="text-caption font-semibold">{children}</p>;
}

export default function ResolutionShow({
    resolution,
    applied_threshold = null,
    results,
    my_vote,
    my_conflict = null,
    can_vote,
    ineligible_reason = null,
    can_declare_conflict = false,
    can_manage = false,
    can_open_voting = false,
    can_publish_to_members = false,
    can_close_voting = false,
    can_finalize = false,
    voting_rules,
    quorum,
    attachments,
    paper_snapshot,
    authority_bindings = [],
    validation_errors = [],
    action_items = [],
    restricted_action_items_count = 0,
    next_pending_vote = null,
    meetings = [],
    committees = [],
    users = [],
    authoritySubjects = null,
    authoritySubjectGroups = [],
    votingRules = null,
}: Props) {
    const isDraft = resolution.status === 'draft';
    const isOpen = resolution.status === 'open';
    const isClosed = ['closed', 'implemented', 'archived'].includes(
        resolution.status,
    );
    const canEdit = isDraft && can_manage;
    const isDecision = isDecisionPurpose(resolution.purpose);
    const votingSwitchedOff = voting_rules ? !voting_rules.switched_on : false;
    const appliedThreshold =
        results?.applied_threshold ??
        applied_threshold ??
        resolution.voting_threshold;

    const [editOpen, setEditOpen] = useDialogDeepLink('edit', canEdit);
    const [conflictOpen, setConflictOpen] = useState(false);
    const [confirm, setConfirm] = useState<
        null | 'open' | 'publish' | 'close' | 'done' | 'archive'
    >(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [commandError, setCommandError] = useState<string | null>(null);
    const [finalNotes, setFinalNotes] = useState('');
    const [noActionReason, setNoActionReason] = useState('');

    const readinessErrors = Array.isArray(validation_errors)
        ? validation_errors
        : Object.values(validation_errors);
    const isPublishReady = readinessErrors.length === 0;

    // The saved copy is the authoritative wording once it's published.
    const paper: PaperContent = paper_snapshot ?? {
        exact_motion: resolution.exact_motion,
        purpose: resolution.purpose,
        decision_type: resolution.decision_type,
        context: resolution.context,
        options: resolution.options as OptionItem[] | null,
        single_option_reason: resolution.single_option_reason,
        recommendation: resolution.recommendation,
        cost_impact: resolution.cost_impact,
        service_user_implications: resolution.service_user_implications,
        risk_equity_implications: resolution.risk_equity_implications,
        version_number: resolution.version_number,
    };
    const options = paper.options ?? [];
    const version = paper.version_number ?? resolution.version_number ?? 1;

    const post = (
        key: string,
        url: string,
        data: Record<string, string | undefined> = {},
    ) => {
        setBusy(key);
        setCommandError(null);
        router.post(url, data, {
            preserveScroll: true,
            onSuccess: (page) => {
                const flashError = (
                    page as { props?: { flash?: { error?: unknown } } }
                )?.props?.flash?.error;
                if (flashError) setCommandError(String(flashError));
            },
            onFinish: () => setBusy(null),
        });
    };

    const meetingContextHref = resolution.meeting
        ? resolutionWorkspaceHref(resolution)
        : null;

    const votesCast = resolution.votes?.length ?? 0;
    const conflictCount = resolution.conflict_declarations?.length ?? 0;
    const votingMembers = quorum?.total_eligible ?? null;
    const quorumPct =
        quorum && quorum.required > 0
            ? Math.min(
                  100,
                  Math.round((quorum.present / quorum.required) * 100),
              )
            : null;

    const cost = paper.cost_impact ?? null;
    const costText = cost?.has_cost
        ? formatNzd(cost.amount ?? null)
        : cost && (cost.has_cost === false || cost.is_none)
          ? 'No cost'
          : 'Cost not stated';
    const costSource = cost?.has_cost
        ? (cost.budget_source ?? cost.funding_source ?? null)
        : null;

    const statusChip = resolutionChip(resolution.status, resolution.outcome);
    const carried = resolution.outcome === 'carried';
    const sharedPaper = resolution.status === 'proposed' && !isDecision;
    const openActions = action_items.filter(
        (action) => !['complete', 'completed', 'cancelled'].includes(action.status),
    );

    const openVotingDescription = `Open voting on "${resolution.title}"? The wording can't be changed after this${
        votingMembers !== null
            ? `, and ${votingMembers} voting member${votingMembers === 1 ? '' : 's'} will be asked to vote`
            : ''
    }${
        resolution.deadline
            ? ` by ${formatDateTimeLong(resolution.deadline)}`
            : resolution.meeting
              ? ' at the meeting'
              : ''
    }.`;

    const header = (
        <PageHeader
            variant="profile"
            backHref={meetingContextHref ?? '/governance/resolutions'}
            icon={Gavel}
            title={resolution.title}
            wrapTitle
            titleChip={
                <PageHeaderStatusChip variant={statusChip.variant}>
                    {statusChip.label}
                </PageHeaderStatusChip>
            }
            subline={[
                resolution.meeting
                    ? `Meeting: ${resolution.meeting.title}`
                    : isDecision
                      ? 'Vote outside a meeting (written resolution)'
                      : 'Not linked to a meeting',
                resolution.committee
                    ? `Committee: ${resolution.committee.name}`
                    : null,
                resolution.proposed_by
                    ? `Written by ${resolution.proposed_by.name}`
                    : null,
                refSuffix(resolution.resolution_reference),
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                <>
                    {canEdit ? (
                        <PageHeaderGlassButton
                            icon={Pencil}
                            onClick={() => setEditOpen(true)}
                        >
                            Edit resolution
                        </PageHeaderGlassButton>
                    ) : null}
                    {can_declare_conflict ? (
                        <PageHeaderGlassButton
                            icon={AlertTriangle}
                            onClick={() => setConflictOpen(true)}
                        >
                            {my_conflict
                                ? 'Update conflict declaration'
                                : 'Declare a conflict'}
                        </PageHeaderGlassButton>
                    ) : null}
                    {can_close_voting ? (
                        <PageHeaderGlassButton
                            icon={Square}
                            disabled={busy === 'close'}
                            onClick={() => setConfirm('close')}
                        >
                            Close voting
                        </PageHeaderGlassButton>
                    ) : null}
                    {can_open_voting ? (
                        <PageHeaderPrimaryButton
                            icon={VoteIcon}
                            disabled={
                                !isPublishReady ||
                                votingSwitchedOff ||
                                busy === 'open'
                            }
                            onClick={() => setConfirm('open')}
                        >
                            {busy === 'open'
                                ? 'Opening…'
                                : 'Publish & open voting'}
                        </PageHeaderPrimaryButton>
                    ) : null}
                    {can_publish_to_members ? (
                        <PageHeaderPrimaryButton
                            icon={Send}
                            disabled={!isPublishReady || busy === 'publish'}
                            onClick={() => setConfirm('publish')}
                        >
                            {busy === 'publish'
                                ? 'Publishing…'
                                : 'Publish to members'}
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    {isDecision && quorum ? (
                        <PageHeaderMeterBlock
                            label="Quorum"
                            value={`${quorum.present}/${quorum.required}`}
                            tone={quorum.met ? 'success' : 'warning'}
                            onClick={() => scrollToSection('voting')}
                            ariaLabel="View voting and quorum"
                        >
                            <PageHeaderMeterBar percent={quorumPct ?? 0} />
                            <PageHeaderMeterCaption>
                                {quorum.met
                                    ? 'Enough members taking part'
                                    : 'Not enough members taking part yet'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : null}
                    {isDecision ? (
                        <PageHeaderMeterBlock
                            label="Votes"
                            onClick={() => scrollToSection('voting')}
                            ariaLabel="View votes"
                        >
                            <PageHeaderMeterBig>
                                {results
                                    ? results.summary.for +
                                      results.summary.against +
                                      results.summary.abstain
                                    : votesCast}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {results
                                    ? `For ${results.summary.for} · Against ${results.summary.against} · Abstain ${results.summary.abstain}`
                                    : isOpen
                                      ? 'Recorded so far'
                                      : "Voting hasn't opened"}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : (
                        <PageHeaderMeterBlock
                            label="Purpose"
                            onClick={() => scrollToSection('voting')}
                            ariaLabel="View why there is no vote"
                        >
                            <PageHeaderMeterBig>No vote</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {resolutionPurposeLabel(resolution.purpose)}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    )}
                    <PageHeaderMeterBlock
                        label="Conflicts"
                        tone={conflictCount > 0 ? 'warning' : 'brand'}
                        onClick={() => scrollToSection('voting')}
                        ariaLabel="View conflicts of interest"
                    >
                        <PageHeaderMeterBig>{conflictCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {conflictCount === 1
                                ? 'Conflict of interest declared'
                                : 'Conflicts of interest declared'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {isDecision ? (
                        <PageHeaderMeterBlock
                            label="Voting closes"
                            onClick={() => scrollToSection('voting')}
                            ariaLabel="View when voting closes"
                        >
                            <PageHeaderMeterBig>
                                {resolution.deadline
                                    ? formatDateLong(resolution.deadline)
                                    : resolution.meeting
                                      ? 'At the meeting'
                                      : 'Not set'}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {votingThresholdLabel(appliedThreshold)}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : null}
                    <PageHeaderMeterBlock
                        label="Documents"
                        onClick={() => scrollToSection('documents')}
                        ariaLabel="View supporting documents"
                    >
                        <PageHeaderMeterBig>
                            {attachments.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Supporting documents
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Resolutions', href: '/governance/resolutions' },
                {
                    title: resolution.title,
                    href: `/governance/resolutions/${resolution.id}`,
                },
            ]}
        >
            <Head title={resolution.title} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {votingSwitchedOff && isDecision && (isDraft || isOpen) ? (
                        <VotingSwitchedOffBanner
                            canSwitchOn={Boolean(voting_rules?.can_switch_on)}
                        />
                    ) : null}

                    {commandError ? (
                        <div
                            role="alert"
                            className="flex items-start gap-3 rounded-xl border border-status-critical/40 bg-status-critical-bg p-4 text-sm"
                        >
                            <AlertCircle className="mt-0.5 size-4 shrink-0 text-status-critical" />
                            <span className="text-foreground">
                                {commandError}
                            </span>
                        </div>
                    ) : null}

                    {paper_snapshot ? (
                        <Card className="flex-row items-center gap-3 border-primary/30 px-5 py-4">
                            <Lock className="size-4 shrink-0 text-primary" />
                            <p className="text-sm">
                                <span className="font-semibold">
                                    {`This is the final wording (version ${version}).`}
                                </span>{' '}
                                {isOpen
                                    ? "It can't change while voting is open."
                                    : isClosed && isDecision
                                      ? 'It is the wording the board voted on.'
                                      : "It can't be changed now it's published."}
                            </p>
                        </Card>
                    ) : null}

                    {isDraft ? (
                        <Section
                            icon={isPublishReady ? CheckCircle2 : AlertCircle}
                            title={
                                isPublishReady
                                    ? 'Ready to publish'
                                    : 'Not ready to publish yet'
                            }
                            description={
                                isPublishReady
                                    ? 'Everything the board needs is in place.'
                                    : 'You can keep saving the draft. It can be published once these are done:'
                            }
                            className={
                                isPublishReady
                                    ? 'border-status-success/40'
                                    : 'border-status-warning/40'
                            }
                            action={
                                canEdit ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setEditOpen(true)}
                                    >
                                        <Pencil className="h-3.5 w-3.5" /> Edit
                                        resolution
                                    </Button>
                                ) : null
                            }
                        >
                            {isPublishReady ? (
                                <p className="text-subtle">
                                    {can_open_voting
                                        ? votingSwitchedOff
                                            ? "Voting can't open until the board's voting rules are confirmed."
                                            : 'Use “Publish & open voting” at the top of the page when the board is ready to vote.'
                                        : can_publish_to_members
                                          ? 'Use “Publish to members” at the top of the page to share it with the board.'
                                          : 'The chair or board secretary can publish it.'}
                                </p>
                            ) : (
                                <ul className="list-inside list-disc space-y-1 text-sm text-status-critical">
                                    {readinessErrors.map((error) => (
                                        <li key={error}>{error}</li>
                                    ))}
                                </ul>
                            )}
                        </Section>
                    ) : null}

                    {isDecision && (authority_bindings.length > 0 || canEdit) ? (
                        <Section
                            icon={Link2}
                            title="This resolution approves"
                            description="If it passes, the app applies it to this exact version."
                        >
                            {authority_bindings.length === 0 ? (
                                <p className="text-subtle">
                                    Nothing specific — a general resolution.
                                    Edit the resolution to choose a budget,
                                    budget change, plan, review or voting rules
                                    it approves.
                                </p>
                            ) : (
                                <div className="flex flex-col divide-y divide-border">
                                    {authority_bindings.map((binding) => (
                                        <div
                                            key={binding.id}
                                            className="flex flex-wrap items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0"
                                        >
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium">
                                                    {binding.subject_label}
                                                </p>
                                                <p className="text-caption">
                                                    {binding.subject_type_label}
                                                    {binding.subject_version
                                                        ? ` · version ${binding.subject_version}`
                                                        : ''}
                                                </p>
                                            </div>
                                            <StatusBadge
                                                variant={
                                                    binding.consumed_at
                                                        ? 'success'
                                                        : 'neutral'
                                                }
                                            >
                                                {binding.consumed_at
                                                    ? `Applied on ${formatDateLong(binding.consumed_at)}`
                                                    : 'Not yet applied'}
                                            </StatusBadge>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </Section>
                    ) : null}

                    <Section
                        icon={Gavel}
                        title={
                            <span className="flex items-center gap-1">
                                Resolution wording
                                <GovernanceTermHint term="resolution_wording" />
                            </span>
                        }
                        action={
                            <div className="flex flex-wrap items-center gap-1.5">
                                <StatusBadge variant="neutral">
                                    {resolutionPurposeLabel(
                                        paper.purpose ?? 'decision',
                                    )}
                                </StatusBadge>
                                {paper.decision_type ? (
                                    <StatusBadge variant="neutral">
                                        {decisionTypeLabel(paper.decision_type)}
                                    </StatusBadge>
                                ) : null}
                            </div>
                        }
                    >
                        <blockquote className="rounded-r-lg border-l-4 border-primary bg-primary/5 py-2.5 pl-4 text-base font-medium whitespace-pre-wrap">
                            {paper.exact_motion || (
                                <span className="text-muted-foreground">
                                    The wording hasn’t been written yet.
                                </span>
                            )}
                        </blockquote>
                    </Section>

                    <Section title="Why this is before the board">
                        <p className="leading-relaxed whitespace-pre-wrap">
                            {paper.context || (
                                <span className="text-muted-foreground">
                                    No background given.
                                </span>
                            )}
                        </p>
                    </Section>

                    <Section
                        icon={Scale}
                        title={`Options considered (${options.length})`}
                        description="The choices the board could make, with what's good and bad about each."
                    >
                        <div className="flex flex-col gap-5">
                            {options.length > 0 ? (
                                <div className="grid gap-5 md:grid-cols-2">
                                    {options.map((option, index) => (
                                        <Card
                                            key={index}
                                            className="gap-3 p-4 shadow-none"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <p className="text-sm font-semibold">
                                                    {option.label}
                                                </p>
                                                <StatusBadge
                                                    variant="neutral"
                                                    size="sm"
                                                >
                                                    {`Option ${index + 1}`}
                                                </StatusBadge>
                                            </div>
                                            {option.description ? (
                                                <p className="text-subtle whitespace-pre-wrap">
                                                    {option.description}
                                                </p>
                                            ) : null}
                                            {option.benefits ? (
                                                <p className="text-sm whitespace-pre-wrap">
                                                    <span className="font-semibold text-status-success">
                                                        Good:{' '}
                                                    </span>
                                                    {option.benefits}
                                                </p>
                                            ) : null}
                                            {option.drawbacks ? (
                                                <p className="text-sm whitespace-pre-wrap">
                                                    <span className="font-semibold text-status-critical">
                                                        Downsides:{' '}
                                                    </span>
                                                    {option.drawbacks}
                                                </p>
                                            ) : null}
                                        </Card>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-subtle">
                                    No options described.
                                </p>
                            )}
                            {options.length < 2 &&
                            paper.single_option_reason ? (
                                <div>
                                    <SubHeading>
                                        Why there’s only one option
                                    </SubHeading>
                                    <p className="mt-1 text-sm whitespace-pre-wrap">
                                        {paper.single_option_reason}
                                    </p>
                                </div>
                            ) : null}
                            {paper.recommendation ? (
                                <div>
                                    <SubHeading>
                                        Management’s recommendation
                                    </SubHeading>
                                    <p className="mt-1 leading-relaxed whitespace-pre-wrap">
                                        {paper.recommendation}
                                    </p>
                                </div>
                            ) : null}
                        </div>
                    </Section>

                    <Section icon={ShieldAlert} title="Effects">
                        <div className="grid gap-5 md:grid-cols-3">
                            <div>
                                <p className="text-caption flex items-center gap-1.5 font-semibold">
                                    <DollarSign className="size-3.5" /> Cost
                                </p>
                                <p className="text-section-title mt-1">
                                    {costText}
                                </p>
                                {costSource ? (
                                    <p className="text-subtle">
                                        {`Paid from: ${costSource}`}
                                    </p>
                                ) : null}
                            </div>
                            <div>
                                <p className="text-caption flex items-center gap-1.5 font-semibold">
                                    <Users className="size-3.5" /> Effect on the
                                    people we support and safety
                                </p>
                                <p className="mt-1 text-sm whitespace-pre-wrap">
                                    {paper.service_user_implications || (
                                        <span className="text-muted-foreground">
                                            Not stated.
                                        </span>
                                    )}
                                </p>
                            </div>
                            <div>
                                <p className="text-caption flex items-center gap-1.5 font-semibold">
                                    <ShieldAlert className="size-3.5" /> Risks
                                    and fairness
                                </p>
                                <p className="mt-1 text-sm whitespace-pre-wrap">
                                    {paper.risk_equity_implications || (
                                        <span className="text-muted-foreground">
                                            Not stated.
                                        </span>
                                    )}
                                </p>
                            </div>
                        </div>
                    </Section>

                    <div id="voting" className="flex flex-col gap-5">
                        <ResolutionBallot
                            resolution={{
                                id: resolution.id,
                                title: resolution.title,
                                status: resolution.status,
                                purpose: resolution.purpose,
                                deadline: resolution.deadline,
                                closed_at: resolution.closed_at ?? null,
                                voting_threshold: resolution.voting_threshold,
                                applied_threshold: appliedThreshold,
                                governance_meeting_id:
                                    resolution.governance_meeting_id,
                            }}
                            version={version}
                            canVote={can_vote}
                            myVote={my_vote}
                            myConflict={my_conflict}
                            canDeclareConflict={can_declare_conflict}
                            onDeclareConflict={() => setConflictOpen(true)}
                            votingSwitchedOff={votingSwitchedOff}
                            ineligibleReason={ineligible_reason}
                            next={
                                next_pending_vote
                                    ? {
                                          label: `Next resolution: ${next_pending_vote.title}`,
                                          href: next_pending_vote.href,
                                      }
                                    : null
                            }
                            back={{
                                label: 'Back to resolutions',
                                href: '/governance/resolutions',
                            }}
                        />

                        {isClosed && results ? (
                            <ResolutionResultCard
                                result={results}
                                votingThreshold={resolution.voting_threshold}
                                quorumRequired={resolution.quorum_required !== false}
                                followUpCount={
                                    action_items.length +
                                    restricted_action_items_count
                                }
                                onViewFollowUps={() =>
                                    scrollToSection('follow-up-actions')
                                }
                            />
                        ) : null}

                        {action_items.length > 0 ||
                        restricted_action_items_count > 0 ? (
                            <Section
                                id="follow-up-actions"
                                icon={ListChecks}
                                title={`Follow-up actions (${action_items.length + restricted_action_items_count})`}
                                description={
                                    restricted_action_items_count > 0
                                        ? `${restricted_action_items_count} more ${restricted_action_items_count === 1 ? "isn't" : "aren't"} shown because you don't have access to ${restricted_action_items_count === 1 ? 'it' : 'them'}.`
                                        : 'Work the board asked for when this resolution passed.'
                                }
                            >
                                {action_items.length === 0 ? (
                                    <p className="text-subtle">
                                        None you have access to.
                                    </p>
                                ) : (
                                    <ul className="flex flex-col divide-y divide-border">
                                        {action_items.map((action) => {
                                            const chip = governanceStatus(
                                                'action_status',
                                                action.status,
                                            );
                                            return (
                                                <li
                                                    key={action.id}
                                                    className="flex flex-wrap items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0"
                                                >
                                                    <div className="min-w-0">
                                                        <p className="text-sm font-medium">
                                                            {action.title}
                                                        </p>
                                                        <p className="text-caption">
                                                            {[
                                                                action.is_mine
                                                                    ? 'Yours'
                                                                    : action.assignee_name
                                                                      ? `For ${action.assignee_name}`
                                                                      : 'Nobody responsible yet',
                                                                action.due_date
                                                                    ? `Due ${formatDateLong(action.due_date)}`
                                                                    : null,
                                                                refSuffix(
                                                                    action.reference,
                                                                ),
                                                            ]
                                                                .filter(Boolean)
                                                                .join(' · ')}
                                                        </p>
                                                    </div>
                                                    <div className="flex shrink-0 items-center gap-2">
                                                        <StatusBadge
                                                            variant={
                                                                chip.variant
                                                            }
                                                        >
                                                            {chip.label}
                                                        </StatusBadge>
                                                        {action.can_open &&
                                                        action.open_url ? (
                                                            <Button
                                                                asChild
                                                                variant="outline"
                                                                size="sm"
                                                            >
                                                                <Link
                                                                    href={
                                                                        action.open_url
                                                                    }
                                                                    aria-label={`Open action: ${action.title}`}
                                                                >
                                                                    <ExternalLink className="size-4" />
                                                                    Open
                                                                </Link>
                                                            </Button>
                                                        ) : null}
                                                    </div>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                            </Section>
                        ) : null}

                        {isClosed || sharedPaper ? (
                            <Section
                                icon={FileText}
                                title="What happens next"
                                description={
                                    sharedPaper
                                        ? 'Once the board has discussed or noted this paper, it can be marked as done.'
                                        : 'Once the board’s decision has been carried out, mark the resolution as done.'
                                }
                            >
                                <div className="flex flex-col gap-3">
                                    {resolution.outcome_notes ? (
                                        <p className="text-subtle whitespace-pre-wrap">
                                            {`Notes: ${resolution.outcome_notes}`}
                                        </p>
                                    ) : null}
                                    {can_finalize ? (
                                        <>
                                            <div>
                                                <Label htmlFor="final-notes">
                                                    Notes (optional)
                                                </Label>
                                                <Textarea
                                                    id="final-notes"
                                                    className="mt-1.5"
                                                    value={finalNotes}
                                                    onChange={(e) =>
                                                        setFinalNotes(
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="What was done, or anything the minutes should record."
                                                />
                                            </div>
                                            {carried && openActions.length > 0 ? (
                                                <div>
                                                    <Label htmlFor="no-action-reason">
                                                        Follow-up actions are
                                                        still open — say why the
                                                        resolution is done
                                                        anyway
                                                    </Label>
                                                    <Textarea
                                                        id="no-action-reason"
                                                        className="mt-1.5"
                                                        value={noActionReason}
                                                        onChange={(e) =>
                                                            setNoActionReason(
                                                                e.target.value,
                                                            )
                                                        }
                                                        placeholder="e.g. The remaining action was replaced by a new resolution."
                                                    />
                                                </div>
                                            ) : null}
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Button
                                                    disabled={
                                                        (!sharedPaper &&
                                                            !carried) ||
                                                        busy === 'finalize'
                                                    }
                                                    onClick={() =>
                                                        setConfirm('done')
                                                    }
                                                >
                                                    Mark as done
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    disabled={
                                                        busy === 'finalize'
                                                    }
                                                    onClick={() =>
                                                        setConfirm('archive')
                                                    }
                                                >
                                                    Archive
                                                </Button>
                                                {!sharedPaper && !carried ? (
                                                    <p className="text-caption">
                                                        Only resolutions that
                                                        passed can be marked as
                                                        done. You can archive
                                                        this one.
                                                    </p>
                                                ) : null}
                                            </div>
                                        </>
                                    ) : (
                                        <p className="text-subtle">
                                            {resolution.status === 'closed' ||
                                            sharedPaper
                                                ? 'Waiting for the chair or board secretary to mark it as done.'
                                                : `This resolution is ${statusChip.label.toLowerCase()}.`}
                                        </p>
                                    )}
                                </div>
                            </Section>
                        ) : null}
                    </div>

                    <Section
                        id="documents"
                        icon={Paperclip}
                        title={`Supporting documents (${attachments.length})`}
                        description="Reports, quotes, advice and other papers to read alongside this resolution."
                    >
                        {attachments.length === 0 && !canEdit ? (
                            <EmptyState
                                icon={Paperclip}
                                title="No supporting documents"
                                description="Nobody has added documents to this resolution."
                            />
                        ) : (
                            <GovernanceAttachmentsPanel
                                canManage={canEdit}
                                attachments={attachments}
                                urls={{
                                    upload: `/governance/resolutions/${resolution.id}/attachments`,
                                    delete: (id) =>
                                        `/governance/resolutions/${resolution.id}/attachments/${id}`,
                                }}
                                reloadProp="attachments"
                                helperText="PDF, Word, Excel, PowerPoint, images, CSV or text — up to 20 MB each."
                                emptyText={{
                                    managed:
                                        'No supporting documents yet. Drop files above to add one.',
                                    readOnly:
                                        'Nobody has added documents to this resolution.',
                                }}
                            />
                        )}
                    </Section>
                </div>
            </PageLayout>

            <DeclareConflictDialog
                isOpen={conflictOpen}
                onClose={() => setConflictOpen(false)}
                resolutionId={resolution.id}
                resolutionTitle={resolution.title}
                existing={my_conflict}
                hasVoted={Boolean(my_vote)}
                appliedThreshold={appliedThreshold}
            />

            <ConfirmDialog
                open={confirm === 'open'}
                onClose={() => setConfirm(null)}
                onConfirm={() =>
                    post('open', `/governance/resolutions/${resolution.id}/open`)
                }
                title="Open voting?"
                description={openVotingDescription}
                confirmText="Open voting"
                variant="default"
            />

            <ConfirmDialog
                open={confirm === 'publish'}
                onClose={() => setConfirm(null)}
                onConfirm={() =>
                    post(
                        'publish',
                        `/governance/resolutions/${resolution.id}/publish`,
                    )
                }
                title="Publish to board members?"
                description={`Board members will be able to read "${resolution.title}". The wording can't be changed after this. It's ${resolutionPurposeLabel(resolution.purpose).toLowerCase()}, so there's no vote.`}
                confirmText="Publish to members"
                variant="default"
            />

            <ConfirmDialog
                open={confirm === 'close'}
                onClose={() => setConfirm(null)}
                onConfirm={() =>
                    post(
                        'close',
                        `/governance/resolutions/${resolution.id}/close`,
                    )
                }
                title="Close voting?"
                description={`Voting on "${resolution.title}" will close and the result will be recorded. Members who haven't voted can no longer vote. This can't be undone.`}
                confirmText="Close voting"
                variant="default"
            />

            <ConfirmDialog
                open={confirm === 'done'}
                onClose={() => setConfirm(null)}
                onConfirm={() =>
                    post(
                        'finalize',
                        `/governance/resolutions/${resolution.id}/finalize`,
                        {
                            status: 'implemented',
                            notes: finalNotes || undefined,
                            no_action_reason: noActionReason || undefined,
                        },
                    )
                }
                title="Mark as done?"
                description={`"${resolution.title}" will be marked as done. This can't be undone.`}
                confirmText="Mark as done"
                variant="default"
            />

            <ConfirmDialog
                open={confirm === 'archive'}
                onClose={() => setConfirm(null)}
                onConfirm={() =>
                    post(
                        'finalize',
                        `/governance/resolutions/${resolution.id}/finalize`,
                        {
                            status: 'archived',
                            notes: finalNotes || undefined,
                        },
                    )
                }
                title="Archive this resolution?"
                description={`"${resolution.title}" will move out of the active list. You can still find it in Records. This can't be undone.`}
                confirmText="Archive"
                variant="default"
            />

            {canEdit ? (
                <ResolutionWizardDialog
                    isOpen={editOpen}
                    onClose={() => setEditOpen(false)}
                    meetings={meetings}
                    committees={committees}
                    users={users}
                    resolution={resolution}
                    authoritySubjects={authoritySubjects}
                    authoritySubjectGroups={authoritySubjectGroups}
                    authorityBindings={authority_bindings}
                    votingRules={votingRules}
                />
            ) : null}
        </AppLayout>
    );
}
