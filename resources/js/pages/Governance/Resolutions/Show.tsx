import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    GovernanceAttachmentsPanel,
    type GovernanceAttachment,
} from '@/components/governance/GovernanceAttachmentsPanel';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
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
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong, formatDateTimeLong } from '@/lib/datetime';
import {
    close as closeResolution,
    open as openResolution,
    vote as voteResolution,
} from '@/routes/governance/resolutions';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    CheckCircle,
    CheckCircle2,
    DollarSign,
    Gavel,
    Link2,
    Lock,
    MinusCircle,
    Paperclip,
    Pencil,
    Scale,
    ShieldAlert,
    Square,
    Users,
    Vote as VoteIcon,
    XCircle,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import {
    DeclareConflictDialog,
    ResolutionWizardDialog,
    type AuthorityBinding,
    type AuthoritySubjectGroup,
    type AuthoritySubjects,
    type CommitteeOption,
    type MeetingOption,
    type ResolutionRecord,
    type UserOption,
} from './_dialogs';
import {
    formatThreshold,
    resolutionOutcomeLabel,
    resolutionOutcomeVariant,
    resolutionStatusLabel,
    resolutionStatusVariant,
} from './_helpers';

interface VoteRecord {
    id: number;
    vote: string;
    voting_method?: string | null;
    conflict_declared: boolean;
    voted_at: string;
}

interface ConflictRecord {
    id: number;
    declaration_type: string;
    declaration_text?: string | null;
    withdrew_from_voting: boolean;
    declared_at?: string | null;
}

type ResultMember =
    | string
    | { user?: { name?: string | null } | null }
    | null
    | undefined;

interface OptionItem {
    label: string;
    description?: string | null;
    benefits?: string | null;
    drawbacks?: string | null;
}

interface CostImpact {
    has_cost?: boolean;
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
    proposed_by?: { name: string } | null;
    votes: VoteRecord[];
    conflict_declarations: ConflictRecord[];
}

interface Props extends PageProps {
    resolution: Resolution;
    results: {
        summary: { for: number; against: number; abstain: number };
        percentages: { for: number; against: number };
        outcome: string;
        quorum_met: boolean;
        individual_votes: Array<{
            board_member: ResultMember;
            vote: string;
            conflict_declared: boolean;
            voted_at: string;
        }>;
        conflicts: Array<{
            board_member: ResultMember;
            type: string;
            description: string;
            withdrew: boolean;
        }>;
        is_frozen?: boolean;
    } | null;
    my_vote: VoteRecord | null;
    my_conflict?: ConflictRecord | null;
    can_vote: boolean;
    can_manage?: boolean;
    can_open_voting?: boolean;
    can_close_voting?: boolean;
    can_finalize?: boolean;
    quorum: { present: number; required: number; met: boolean } | null;
    attachments: GovernanceAttachment[];
    paper_snapshot?: PaperContent | null;
    authority_bindings?: AuthorityBinding[];
    validation_errors?: Record<string, string> | string[];
    meetings?: MeetingOption[];
    committees?: CommitteeOption[];
    users?: UserOption[];
    authoritySubjects?: AuthoritySubjects | null;
    authoritySubjectGroups?: AuthoritySubjectGroup[];
}

const VOTE_CHOICES = [
    {
        value: 'for',
        label: 'For',
        icon: CheckCircle,
        tone: 'text-status-success',
    },
    {
        value: 'against',
        label: 'Against',
        icon: XCircle,
        tone: 'text-status-critical',
    },
    {
        value: 'abstain',
        label: 'Abstain',
        icon: MinusCircle,
        tone: 'text-muted-foreground',
    },
] as const;

function voteVariant(vote: string) {
    return vote === 'for'
        ? 'success'
        : vote === 'against'
          ? 'critical'
          : 'neutral';
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
    title: string;
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

export default function ResolutionShow({
    resolution,
    results,
    my_vote,
    my_conflict,
    can_vote,
    can_manage = false,
    can_open_voting = false,
    can_close_voting = false,
    can_finalize = false,
    quorum,
    attachments,
    paper_snapshot,
    authority_bindings = [],
    validation_errors = [],
    meetings = [],
    committees = [],
    users = [],
    authoritySubjects = null,
    authoritySubjectGroups = [],
}: Props) {
    const isDraft = resolution.status === 'draft';
    const isOpen = resolution.status === 'open';
    const isClosed = ['closed', 'implemented', 'archived'].includes(
        resolution.status,
    );
    const canEdit = isDraft && can_manage;

    const [editOpen, setEditOpen] = useDialogDeepLink('edit', canEdit);
    const [conflictOpen, setConflictOpen] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);
    const [selectedVote, setSelectedVote] = useState('');
    const [voteNote, setVoteNote] = useState('');
    const [busy, setBusy] = useState<string | null>(null);
    const [finalNotes, setFinalNotes] = useState('');
    const [noActionReason, setNoActionReason] = useState('');

    const readinessErrors = Array.isArray(validation_errors)
        ? validation_errors
        : Object.values(validation_errors);
    const isPublishReady = readinessErrors.length === 0;

    // A frozen snapshot is the authoritative text once voting has opened.
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
        router.post(url, data, {
            preserveScroll: true,
            onFinish: () => setBusy(null),
        });
    };

    const memberName = (member: ResultMember) => {
        if (!member) return 'Unknown';
        if (typeof member === 'string') return member;
        return member.user?.name ?? 'Unknown';
    };

    const meetingContextHref = resolution.meeting
        ? `/governance/meetings/${resolution.meeting.id}?tab=resolutions&paper=${resolution.id}`
        : null;

    const votesCast = resolution.votes?.length ?? 0;
    const conflictCount = resolution.conflict_declarations?.length ?? 0;
    const quorumPct =
        quorum && quorum.required > 0
            ? Math.min(
                  100,
                  Math.round((quorum.present / quorum.required) * 100),
              )
            : null;

    const header = (
        <PageHeader
            variant="profile"
            backHref={meetingContextHref ?? '/governance/resolutions'}
            icon={Gavel}
            title={resolution.title}
            wrapTitle
            titleChip={
                <>
                    <PageHeaderStatusChip variant="neutral">
                        v{version}
                    </PageHeaderStatusChip>
                    <PageHeaderStatusChip
                        variant={resolutionStatusVariant(resolution.status)}
                    >
                        {resolutionStatusLabel(resolution.status)}
                    </PageHeaderStatusChip>
                    {resolution.outcome ? (
                        <PageHeaderStatusChip
                            variant={resolutionOutcomeVariant(
                                resolution.outcome,
                            )}
                        >
                            {resolutionOutcomeLabel(resolution.outcome)}
                        </PageHeaderStatusChip>
                    ) : null}
                </>
            }
            subline={[
                resolution.resolution_reference,
                resolution.meeting
                    ? `Meeting: ${resolution.meeting.title}`
                    : 'Standalone paper',
                resolution.committee
                    ? `Committee: ${resolution.committee.name}`
                    : null,
                resolution.proposed_by
                    ? `Proposed by ${resolution.proposed_by.name}`
                    : null,
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
                            Edit paper
                        </PageHeaderGlassButton>
                    ) : null}
                    {can_close_voting ? (
                        <PageHeaderGlassButton
                            icon={Square}
                            onClick={() => setConfirmClose(true)}
                        >
                            Close voting
                        </PageHeaderGlassButton>
                    ) : null}
                    {can_open_voting ? (
                        <PageHeaderPrimaryButton
                            icon={VoteIcon}
                            disabled={!isPublishReady || busy === 'open'}
                            title={
                                isPublishReady
                                    ? undefined
                                    : 'Complete the paper before opening voting'
                            }
                            onClick={() =>
                                post(
                                    'open',
                                    openResolution.url({
                                        resolution: resolution.id,
                                    }),
                                )
                            }
                        >
                            {busy === 'open'
                                ? 'Opening…'
                                : 'Publish & open voting'}
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    {quorum ? (
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
                                    ? 'Quorum met'
                                    : 'Quorum not yet met'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : null}
                    <PageHeaderMeterBlock
                        label="Votes cast"
                        onClick={() => scrollToSection('voting')}
                        ariaLabel="View votes"
                    >
                        <PageHeaderMeterBig>{votesCast}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {results
                                ? `For ${results.summary.for} · Against ${results.summary.against} · Abstain ${results.summary.abstain}`
                                : isOpen
                                  ? 'Ballots recorded so far'
                                  : 'Voting not yet open'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Conflicts"
                        tone={conflictCount > 0 ? 'warning' : 'brand'}
                        onClick={() => scrollToSection('voting')}
                        ariaLabel="View conflict declarations"
                    >
                        <PageHeaderMeterBig>{conflictCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Declared interests
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Deadline"
                        onClick={() => scrollToSection('voting')}
                        ariaLabel="View voting deadline"
                    >
                        <PageHeaderMeterBig>
                            {resolution.deadline
                                ? formatDateLong(resolution.deadline)
                                : '—'}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {resolution.deadline
                                ? formatThreshold(resolution.voting_threshold)
                                : 'Decided at the meeting'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Documents"
                        onClick={() => scrollToSection('documents')}
                        ariaLabel="View supporting documents"
                    >
                        <PageHeaderMeterBig>
                            {attachments.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Supporting papers
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
                    {paper_snapshot ? (
                        <Card className="flex-row items-center gap-3 border-primary/30 px-5 py-4">
                            <Lock className="size-4 shrink-0 text-primary" />
                            <p className="text-sm">
                                <span className="font-semibold">
                                    Frozen paper (v{version}).
                                </span>{' '}
                                This text was locked when voting opened; every
                                vote evaluates these exact terms.
                            </p>
                        </Card>
                    ) : null}

                    {isDraft ? (
                        <Section
                            icon={isPublishReady ? CheckCircle2 : AlertCircle}
                            title={
                                isPublishReady
                                    ? 'Ready to publish'
                                    : 'Not ready to publish'
                            }
                            description={
                                isPublishReady
                                    ? 'Every mandatory element (motion, context, options, recommendation, cost, service-user and risk implications) is present.'
                                    : 'Drafts can be saved freely, but voting can only open once these are complete:'
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
                                        paper
                                    </Button>
                                ) : null
                            }
                        >
                            {isPublishReady ? (
                                <p className="text-subtle">
                                    {can_open_voting
                                        ? 'Use “Publish & open voting” in the header when the board is ready to vote.'
                                        : 'A chair or secretary can open voting.'}
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

                    {authority_bindings.length > 0 || canEdit ? (
                        <Section
                            icon={Link2}
                            title="Record this paper approves"
                            description="A carried resolution applies only to the exact record and revision bound here."
                        >
                            {authority_bindings.length === 0 ? (
                                <p className="text-subtle">
                                    No specific record is bound. Edit the paper
                                    to choose the budget, plan, review or rules
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
                                                    {binding.subject_revision !=
                                                    null
                                                        ? ` · revision ${binding.subject_revision}`
                                                        : ''}
                                                    {binding.bound_at
                                                        ? ` · bound ${formatDateTimeLong(binding.bound_at)}`
                                                        : ''}
                                                </p>
                                            </div>
                                            <StatusBadge
                                                variant={
                                                    binding.consumed_at
                                                        ? 'success'
                                                        : 'info'
                                                }
                                            >
                                                {binding.consumed_at
                                                    ? `Applied ${formatDateLong(binding.consumed_at)}`
                                                    : 'Awaiting decision'}
                                            </StatusBadge>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </Section>
                    ) : null}

                    <Section
                        icon={Gavel}
                        title="Exact motion"
                        action={
                            <div className="flex flex-wrap items-center gap-1.5">
                                <StatusBadge variant="neutral">
                                    {paper.purpose ?? 'decision'} paper
                                </StatusBadge>
                                {paper.decision_type ? (
                                    <StatusBadge variant="neutral">
                                        {paper.decision_type}
                                    </StatusBadge>
                                ) : null}
                            </div>
                        }
                    >
                        <blockquote className="rounded-r-lg border-l-4 border-primary bg-primary/5 py-2.5 pl-4 text-base font-medium">
                            {paper.exact_motion || resolution.title}
                        </blockquote>
                    </Section>

                    <Section
                        title="Context & background"
                        description="Why this is before the board now."
                    >
                        <p className="leading-relaxed whitespace-pre-wrap">
                            {paper.context || (
                                <span className="text-muted-foreground">
                                    No background provided.
                                </span>
                            )}
                        </p>
                    </Section>

                    <Section
                        icon={Scale}
                        title={`Alternatives evaluated (${options.length})`}
                        description="Consequential decisions weigh alternatives with explicit benefits and drawbacks."
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
                                                    Option {index + 1}
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
                                                        Benefits:{' '}
                                                    </span>
                                                    {option.benefits}
                                                </p>
                                            ) : null}
                                            {option.drawbacks ? (
                                                <p className="text-sm whitespace-pre-wrap">
                                                    <span className="font-semibold text-status-critical">
                                                        Drawbacks:{' '}
                                                    </span>
                                                    {option.drawbacks}
                                                </p>
                                            ) : null}
                                        </Card>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-subtle">
                                    No options recorded.
                                </p>
                            )}
                            {options.length < 2 &&
                            paper.single_option_reason ? (
                                <div>
                                    <p className="text-caption font-semibold uppercase">
                                        Single option justification
                                    </p>
                                    <p className="mt-1 text-sm whitespace-pre-wrap">
                                        {paper.single_option_reason}
                                    </p>
                                </div>
                            ) : null}
                            {paper.recommendation ? (
                                <div>
                                    <p className="text-caption font-semibold uppercase">
                                        Management recommendation
                                    </p>
                                    <p className="mt-1 leading-relaxed whitespace-pre-wrap">
                                        {paper.recommendation}
                                    </p>
                                </div>
                            ) : null}
                        </div>
                    </Section>

                    <Section icon={ShieldAlert} title="Implications">
                        <div className="grid gap-5 md:grid-cols-3">
                            <div>
                                <p className="text-caption flex items-center gap-1.5 font-semibold uppercase">
                                    <DollarSign className="size-3.5" />{' '}
                                    Financial cost
                                </p>
                                {paper.cost_impact?.has_cost ? (
                                    <>
                                        <p className="text-section-title mt-1">
                                            {paper.cost_impact.currency ??
                                                'NZD'}{' '}
                                            {paper.cost_impact.amount}
                                        </p>
                                        {paper.cost_impact.budget_source ||
                                        paper.cost_impact.funding_source ? (
                                            <p className="text-subtle">
                                                Fund:{' '}
                                                {paper.cost_impact
                                                    .budget_source ??
                                                    paper.cost_impact
                                                        .funding_source}
                                            </p>
                                        ) : null}
                                    </>
                                ) : (
                                    <p className="text-subtle mt-1">
                                        Explicitly confirmed: no direct cost.
                                    </p>
                                )}
                            </div>
                            <div>
                                <p className="text-caption flex items-center gap-1.5 font-semibold uppercase">
                                    <Users className="size-3.5" /> Service-user
                                    & safety
                                </p>
                                <p className="mt-1 text-sm whitespace-pre-wrap">
                                    {paper.service_user_implications || (
                                        <span className="text-muted-foreground">
                                            None specified.
                                        </span>
                                    )}
                                </p>
                            </div>
                            <div>
                                <p className="text-caption flex items-center gap-1.5 font-semibold uppercase">
                                    <ShieldAlert className="size-3.5" /> Risk &
                                    equity
                                </p>
                                <p className="mt-1 text-sm whitespace-pre-wrap">
                                    {paper.risk_equity_implications || (
                                        <span className="text-muted-foreground">
                                            None specified.
                                        </span>
                                    )}
                                </p>
                            </div>
                        </div>
                    </Section>

                    <div id="voting" className="flex flex-col gap-5">
                        {isOpen &&
                        can_vote &&
                        !my_vote &&
                        !my_conflict?.withdrew_from_voting ? (
                            <Section
                                icon={VoteIcon}
                                title="Cast your vote"
                                description={
                                    resolution.deadline
                                        ? `Voting closes ${formatDateTimeLong(resolution.deadline)} · ${formatThreshold(resolution.voting_threshold)}`
                                        : formatThreshold(
                                              resolution.voting_threshold,
                                          )
                                }
                            >
                                <div className="flex flex-col gap-4">
                                    <RadioGroup
                                        value={selectedVote}
                                        onValueChange={setSelectedVote}
                                        className="grid gap-2 sm:grid-cols-3"
                                        aria-label="Your vote"
                                    >
                                        {VOTE_CHOICES.map((choice) => (
                                            <Label
                                                key={choice.value}
                                                className="flex cursor-pointer items-center gap-3 rounded-lg border p-3 font-normal transition-colors hover:bg-accent has-[[data-state=checked]]:border-primary has-[[data-state=checked]]:bg-primary/5"
                                            >
                                                <RadioGroupItem
                                                    value={choice.value}
                                                />
                                                <choice.icon
                                                    className={`size-5 ${choice.tone}`}
                                                />
                                                {choice.label}
                                            </Label>
                                        ))}
                                    </RadioGroup>
                                    <div>
                                        <Label htmlFor="vote-note">
                                            Vote note (optional)
                                        </Label>
                                        <Textarea
                                            id="vote-note"
                                            className="mt-1.5"
                                            value={voteNote}
                                            onChange={(e) =>
                                                setVoteNote(e.target.value)
                                            }
                                            placeholder="An optional explanation for your vote…"
                                        />
                                    </div>
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <Button
                                            disabled={
                                                !selectedVote || busy === 'vote'
                                            }
                                            onClick={() =>
                                                post(
                                                    'vote',
                                                    voteResolution.url({
                                                        resolution:
                                                            resolution.id,
                                                    }),
                                                    {
                                                        vote: selectedVote,
                                                        conflict_note:
                                                            voteNote ||
                                                            undefined,
                                                    },
                                                )
                                            }
                                        >
                                            {busy === 'vote'
                                                ? 'Recording…'
                                                : 'Submit vote'}
                                        </Button>
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                setConflictOpen(true)
                                            }
                                        >
                                            <AlertTriangle className="h-4 w-4 text-status-warning" />
                                            Declare a conflict
                                        </Button>
                                    </div>
                                </div>
                            </Section>
                        ) : null}

                        {my_conflict?.withdrew_from_voting ? (
                            <Section
                                icon={AlertTriangle}
                                title="Conflict declared — recused from voting"
                                description="You declared an interest in this paper and withdrew from voting. Your seat is excluded from participation."
                                className="border-status-warning/40"
                            >
                                <p className="text-sm">
                                    <span className="font-medium">Nature:</span>{' '}
                                    {my_conflict.declaration_type}
                                </p>
                                {my_conflict.declaration_text ? (
                                    <p className="text-subtle mt-1">
                                        {my_conflict.declaration_text}
                                    </p>
                                ) : null}
                            </Section>
                        ) : null}

                        {my_vote ? (
                            <Section
                                icon={CheckCircle2}
                                title="Your vote receipt"
                                description={`Official record of your vote on ${resolution.resolution_reference} (paper v${version}).`}
                            >
                                <div className="flex flex-wrap items-center gap-3">
                                    <StatusBadge
                                        variant={voteVariant(my_vote.vote)}
                                    >
                                        {my_vote.vote.toUpperCase()}
                                    </StatusBadge>
                                    <span className="text-subtle">
                                        Recorded{' '}
                                        {formatDateTimeLong(my_vote.voted_at)} ·
                                        Method:{' '}
                                        {my_vote.voting_method ?? 'electronic'}
                                    </span>
                                    {my_vote.conflict_declared ? (
                                        <StatusBadge variant="warning">
                                            <AlertTriangle className="size-3" />{' '}
                                            Conflict noted
                                        </StatusBadge>
                                    ) : null}
                                </div>
                            </Section>
                        ) : null}

                        {isClosed && results ? (
                            <Section
                                icon={VoteIcon}
                                title="Voting results"
                                description={
                                    results.is_frozen
                                        ? 'Immutable decision snapshot captured at closure.'
                                        : 'Official voting tally.'
                                }
                                action={
                                    <StatusBadge
                                        variant={resolutionOutcomeVariant(
                                            results.outcome,
                                        )}
                                    >
                                        {resolutionOutcomeLabel(
                                            results.outcome,
                                        )}
                                    </StatusBadge>
                                }
                            >
                                <div className="flex flex-col gap-5">
                                    {results.outcome === 'no_quorum' ? (
                                        <p className="text-sm text-status-warning">
                                            <span className="font-semibold">
                                                No valid decision:
                                            </span>{' '}
                                            quorum was not met, so the required
                                            participation was not reached.
                                        </p>
                                    ) : null}
                                    <div className="grid grid-cols-3 gap-5">
                                        {[
                                            {
                                                label: `For (${results.percentages.for}%)`,
                                                value: results.summary.for,
                                                variant: 'success' as const,
                                            },
                                            {
                                                label: `Against (${results.percentages.against}%)`,
                                                value: results.summary.against,
                                                variant: 'critical' as const,
                                            },
                                            {
                                                label: 'Abstain',
                                                value: results.summary.abstain,
                                                variant: 'neutral' as const,
                                            },
                                        ].map((tile) => (
                                            <Card
                                                key={tile.label}
                                                className="items-center gap-1 p-4 text-center shadow-none"
                                            >
                                                <span className="text-page-title">
                                                    {tile.value}
                                                </span>
                                                <StatusBadge
                                                    variant={tile.variant}
                                                >
                                                    {tile.label}
                                                </StatusBadge>
                                            </Card>
                                        ))}
                                    </div>
                                    <div>
                                        <p className="mb-2 text-sm font-semibold">
                                            Individual votes
                                        </p>
                                        {results.individual_votes.length ===
                                        0 ? (
                                            <p className="text-subtle">
                                                No votes were recorded.
                                            </p>
                                        ) : (
                                            <div className="flex flex-col divide-y divide-border">
                                                {results.individual_votes.map(
                                                    (vote, index) => (
                                                        <div
                                                            key={`${memberName(vote.board_member)}-${index}`}
                                                            className="flex items-center justify-between gap-3 py-2"
                                                        >
                                                            <span className="text-sm">
                                                                {memberName(
                                                                    vote.board_member,
                                                                )}
                                                            </span>
                                                            <StatusBadge
                                                                variant={voteVariant(
                                                                    vote.vote,
                                                                )}
                                                            >
                                                                {vote.vote}
                                                            </StatusBadge>
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        )}
                                    </div>
                                    {results.conflicts.length > 0 ? (
                                        <div>
                                            <p className="mb-2 text-sm font-semibold">
                                                Conflict declarations
                                            </p>
                                            <ul className="flex flex-col gap-1 text-sm">
                                                {results.conflicts.map(
                                                    (conflict, index) => (
                                                        <li key={index}>
                                                            {memberName(
                                                                conflict.board_member,
                                                            )}{' '}
                                                            — {conflict.type}
                                                            {conflict.withdrew
                                                                ? ' (withdrew from voting)'
                                                                : ''}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        </div>
                                    ) : null}
                                </div>
                            </Section>
                        ) : null}

                        {isClosed ? (
                            <Section
                                title="Decision summary"
                                description="Finalise the resolution once its outcome has been actioned."
                            >
                                <div className="flex flex-col gap-3">
                                    {resolution.outcome_notes ? (
                                        <p className="text-subtle whitespace-pre-wrap">
                                            Recorded notes:{' '}
                                            {resolution.outcome_notes}
                                        </p>
                                    ) : null}
                                    {can_finalize ? (
                                        <>
                                            <div>
                                                <Label htmlFor="final-notes">
                                                    Outcome notes
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
                                                    placeholder="Outcome notes or implementation summary…"
                                                />
                                            </div>
                                            {resolution.outcome ===
                                            'carried' ? (
                                                <div>
                                                    <Label htmlFor="no-action-reason">
                                                        Reason remaining
                                                        follow-up actions are
                                                        not required (optional)
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
                                                        placeholder="Only if the decision is implemented while follow-up actions remain open."
                                                    />
                                                </div>
                                            ) : null}
                                            <div className="flex flex-wrap gap-2">
                                                <Button
                                                    disabled={
                                                        resolution.outcome !==
                                                            'carried' ||
                                                        busy === 'finalize'
                                                    }
                                                    title={
                                                        resolution.outcome ===
                                                        'carried'
                                                            ? undefined
                                                            : 'Only carried resolutions can be marked implemented'
                                                    }
                                                    onClick={() =>
                                                        post(
                                                            'finalize',
                                                            `/governance/resolutions/${resolution.id}/finalize`,
                                                            {
                                                                status: 'implemented',
                                                                notes:
                                                                    finalNotes ||
                                                                    undefined,
                                                                no_action_reason:
                                                                    noActionReason ||
                                                                    undefined,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Mark implemented
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    disabled={
                                                        busy === 'finalize'
                                                    }
                                                    onClick={() =>
                                                        post(
                                                            'finalize',
                                                            `/governance/resolutions/${resolution.id}/finalize`,
                                                            {
                                                                status: 'archived',
                                                                notes:
                                                                    finalNotes ||
                                                                    undefined,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Archive
                                                </Button>
                                            </div>
                                        </>
                                    ) : (
                                        <p className="text-subtle">
                                            {resolution.status === 'closed'
                                                ? 'Awaiting finalisation by the chair or board secretary.'
                                                : `This resolution is ${resolutionStatusLabel(resolution.status).toLowerCase()}.`}
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
                        description="Analyses, draft contracts, legal opinions and other papers to read alongside this resolution."
                    >
                        {attachments.length === 0 && !canEdit ? (
                            <EmptyState
                                icon={Paperclip}
                                title="No supporting documents"
                                description="No documents have been attached to this resolution."
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
                                helperText="PDF, Office, images, CSV / TXT — up to 20 MB each."
                                emptyText={{
                                    managed:
                                        'No supporting documents yet. Drop files above to attach one.',
                                    readOnly:
                                        'No supporting documents have been attached to this resolution.',
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
                reference={resolution.resolution_reference}
            />

            <ConfirmDialog
                open={confirmClose}
                onClose={() => setConfirmClose(false)}
                onConfirm={() => {
                    setConfirmClose(false);
                    post(
                        'close',
                        closeResolution.url({ resolution: resolution.id }),
                    );
                }}
                title="Close voting?"
                description="The tally and outcome are frozen when voting closes. Members who have not voted can no longer vote."
                confirmText="Close voting"
                variant="destructive"
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
                />
            ) : null}
        </AppLayout>
    );
}
