import { GovernanceTermHint } from '@/components/governance/GovernanceTermHint';
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
import { Progress } from '@/components/ui/progress';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import {
    formatDateLong,
    formatDateOnly,
    formatDateTimeLong,
} from '@/lib/datetime';
import {
    actionSourceLabel,
    governanceStatus,
    refSuffix,
    resolutionOutcomeLabel,
} from '@/lib/governance-labels';
import { unblock as unblockAction } from '@/routes/governance/actions';
import { PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowLeft,
    Check,
    CheckCircle,
    ClipboardList,
    Copy,
    ExternalLink,
    FileText,
    Flag,
    Gauge,
    Lock,
    Paperclip,
    PauseCircle,
    Play,
    UserRound,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import {
    BlockActionDialog,
    CompleteActionDialog,
    EscalateActionDialog,
    ReassignActionDialog,
    UpdateProgressDialog,
    type AssigneeOption,
} from './_dialogs';
import {
    ActionEvidencePanel,
    type ActionEvidenceFile,
    type EarlierEvidenceFile,
} from './_evidence';
import {
    actionChip,
    dueDateOnly,
    dueWording,
    evidenceState,
    isActionOverdue,
} from './_helpers';

interface UserRef {
    id: number;
    name: string;
}

interface ActionItem {
    id: number;
    action_reference: string;
    title?: string | null;
    description: string;
    due_date: string;
    status: string;
    priority: string;
    source_type: string | null;
    source_id: number | null;
    evidence_required: boolean;
    evidence?: ActionEvidenceFile[];
    legacy_evidence?: EarlierEvidenceFile[];
    completion_notes?: string | null;
    completion_receipt?: string | null;
    completed_at?: string | null;
    progress_pct?: number | null;
    progress_notes?: string | null;
    version_number?: number | null;
    blocked_at?: string | null;
    blocked_reason?: string | null;
    escalated_at?: string | null;
    escalation_reason?: string | null;
    escalated_automatically?: boolean;
    assigned_to?: UserRef | null;
    completed_by?: UserRef | null;
    created_by?: UserRef | null;
    escalated_by?: UserRef | null;
}

interface SourceDetails {
    type: 'resolution' | 'meeting';
    id: number;
    reference?: string;
    title?: string;
    status?: string;
    outcome?: string | null;
    scheduled_at?: string;
    url?: string;
    is_restricted?: boolean;
}

interface Props extends PageProps {
    action: ActionItem;
    source_details?: SourceDetails | null;
    assignees: AssigneeOption[];
    can_update: boolean;
    /** Validated server-side: a relative /governance/ path or null. */
    return_to?: string | null;
}

function scrollToSection(id: string) {
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function returnLabel(path: string): string {
    if (path.startsWith('/governance/meetings/')) return 'Back to meeting';
    if (path.startsWith('/governance/my-work')) return 'Back to My work';
    if (path.startsWith('/governance/resolutions/')) return 'Back to resolution';
    if (path.startsWith('/governance/dashboard')) return 'Back to Governance';
    return 'Back';
}

function Section({
    id,
    icon: Icon,
    title,
    description,
    action,
    children,
}: {
    id?: string;
    icon?: typeof FileText;
    title: string;
    description?: ReactNode;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Card id={id} className="scroll-mt-5">
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

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <p className="text-caption">{label}</p>
            <div className="mt-0.5 text-sm font-medium">{children}</div>
        </div>
    );
}

export default function ActionItemShow({
    action,
    source_details,
    assignees,
    can_update,
    return_to = null,
}: Props) {
    const [dialog, setDialog] = useState<
        'progress' | 'block' | 'escalate' | 'reassign' | 'complete' | null
    >(null);
    const [copiedReceipt, setCopiedReceipt] = useState(false);
    const [unblocking, setUnblocking] = useState(false);

    const title = action.title?.trim() || 'Action';
    const isCompleted = action.status === 'complete';
    const isBlocked = action.status === 'blocked';
    const progress = action.progress_pct ?? 0;
    const dueDate = dueDateOnly(action.due_date);
    const isOverdue = isActionOverdue(action.status, action.due_date);
    const chip = actionChip(action.status, action.due_date);
    const priority = governanceStatus('priority', action.priority);
    const evidenceFiles = action.evidence ?? [];
    const earlierFiles = action.legacy_evidence ?? [];
    const evidenceCount = evidenceFiles.length + earlierFiles.length;
    const evidence = evidenceState(action.evidence_required, evidenceCount);
    const canAct = can_update && !isCompleted;
    const close = () => setDialog(null);

    const copyReceipt = () => {
        if (!action.completion_receipt) return;
        void navigator.clipboard.writeText(action.completion_receipt);
        setCopiedReceipt(true);
        setTimeout(() => setCopiedReceipt(false), 2000);
    };

    const unblock = () => {
        setUnblocking(true);
        router.post(
            unblockAction.url({ action: action.id }),
            { expected_version: action.version_number ?? 1 },
            { preserveScroll: true, onFinish: () => setUnblocking(false) },
        );
    };

    const source =
        source_details && !source_details.is_restricted ? source_details : null;
    const sourceHref = source?.url;
    const sourceKindLabel = source_details
        ? actionSourceLabel(source_details.type)
        : action.source_type
          ? actionSourceLabel(action.source_type)
          : 'Added directly';

    const header = (
        <PageHeader
            variant="profile"
            backHref={return_to ?? '/governance/actions'}
            icon={ClipboardList}
            title={title}
            titleDusk="action-heading"
            wrapTitle
            titleChip={
                <PageHeaderStatusChip variant={chip.variant}>
                    {chip.label}
                </PageHeaderStatusChip>
            }
            subline={[
                `Owner: ${action.assigned_to?.name ?? 'nobody yet'}`,
                dueDate ? `Due ${formatDateOnly(dueDate)}` : null,
                `${priority.label} priority`,
                refSuffix(action.action_reference),
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                <>
                    {return_to ? (
                        <PageHeaderGlassButton
                            icon={ArrowLeft}
                            onClick={() => router.visit(return_to)}
                        >
                            {returnLabel(return_to)}
                        </PageHeaderGlassButton>
                    ) : null}
                    {canAct ? (
                        <PageHeaderPrimaryButton
                            icon={CheckCircle}
                            onClick={() => setDialog('complete')}
                            dusk="complete-action-button"
                        >
                            Mark as done
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Progress"
                        value={`${progress}%`}
                        tone={isCompleted ? 'success' : 'brand'}
                        onClick={() => scrollToSection('progress')}
                        ariaLabel="View progress"
                    >
                        <PageHeaderMeterBar percent={progress} />
                        <PageHeaderMeterCaption>
                            {isCompleted
                                ? 'Done'
                                : action.progress_notes
                                  ? 'Latest update saved'
                                  : 'No update yet'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Due"
                        tone={isOverdue ? 'critical' : 'brand'}
                        href={`/governance/actions?status=${isOverdue ? 'overdue' : 'active'}`}
                        ariaLabel={
                            isOverdue
                                ? 'View overdue actions'
                                : 'View actions still to do'
                        }
                    >
                        <PageHeaderMeterBig>
                            {formatDateOnly(dueDate)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {isCompleted
                                ? 'Due date'
                                : (dueWording(action.due_date) ?? 'Due date')}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Evidence"
                        tone={evidence.variant === 'warning' && !isCompleted ? 'warning' : 'brand'}
                        onClick={() => scrollToSection('evidence')}
                        ariaLabel="View evidence"
                    >
                        <PageHeaderMeterBig>{evidenceCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {evidence.label}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Came from"
                        href={sourceHref ?? '/governance/actions'}
                        ariaLabel={
                            sourceHref
                                ? `Open the ${sourceKindLabel.toLowerCase()} this action came from`
                                : 'View all actions'
                        }
                    >
                        <PageHeaderMeterBig>
                            {source_details?.is_restricted
                                ? 'Private record'
                                : sourceKindLabel}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {source?.title ??
                                (source_details?.is_restricted
                                    ? "You can't open it"
                                    : 'Not linked to a meeting or resolution')}
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
                { title: 'Actions', href: '/governance/actions' },
                {
                    title,
                    href: `/governance/actions/${action.id}`,
                },
            ]}
        >
            <Head title={`${title} — Actions`} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {isBlocked ? (
                        <Section
                            icon={AlertCircle}
                            title="Blocked"
                            description={
                                action.blocked_at
                                    ? `Marked as blocked on ${formatDateTimeLong(action.blocked_at)}`
                                    : undefined
                            }
                        >
                            <p className="text-sm whitespace-pre-line">
                                {action.blocked_reason}
                            </p>
                        </Section>
                    ) : null}

                    {action.escalated_at ? (
                        <Section
                            icon={Flag}
                            title="Raised with the board"
                            description={
                                action.escalated_automatically
                                    ? `Escalated automatically because it was overdue · ${formatDateTimeLong(action.escalated_at)}`
                                    : action.escalated_by
                                      ? `Raised by ${action.escalated_by.name} on ${formatDateTimeLong(action.escalated_at)}`
                                      : `Raised on ${formatDateTimeLong(action.escalated_at)}`
                            }
                        >
                            {action.escalated_automatically ? (
                                <p className="text-subtle">
                                    The due date passed while the work was still
                                    open, so the app raised it with the board and
                                    told the owner.
                                </p>
                            ) : (
                                <p className="text-sm whitespace-pre-line">
                                    {action.escalation_reason}
                                </p>
                            )}
                        </Section>
                    ) : null}

                    <div className="grid grid-cols-1 items-start gap-5 lg:grid-cols-3">
                        <div className="flex flex-col gap-5 lg:col-span-2">
                            <Section icon={FileText} title="What needs doing">
                                <div className="flex flex-col gap-4">
                                    <p className="leading-relaxed whitespace-pre-line">
                                        {action.description}
                                    </p>
                                    <div className="grid gap-4 border-t border-border pt-4 sm:grid-cols-2">
                                        <Fact label="Added by">
                                            {action.created_by?.name ?? (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </Fact>
                                        <Fact label="Evidence needed">
                                            <span className="inline-flex items-center gap-1">
                                                {action.evidence_required
                                                    ? 'Yes'
                                                    : 'No'}
                                                <GovernanceTermHint term="evidence" />
                                            </span>
                                        </Fact>
                                    </div>
                                </div>
                            </Section>

                            <Section
                                icon={ClipboardList}
                                title="Where this came from"
                            >
                                {source_details?.is_restricted ? (
                                    <p className="text-subtle flex items-center gap-2.5">
                                        <Lock className="size-4 shrink-0" />
                                        This action came from a record only its
                                        own audience can open.
                                    </p>
                                ) : source ? (
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-1.5">
                                                <StatusBadge variant="neutral">
                                                    {actionSourceLabel(
                                                        source.type,
                                                    )}
                                                </StatusBadge>
                                                {source.outcome ? (
                                                    <StatusBadge
                                                        variant={
                                                            governanceStatus(
                                                                'resolution_outcome',
                                                                source.outcome,
                                                            ).variant
                                                        }
                                                    >
                                                        {resolutionOutcomeLabel(
                                                            source.outcome,
                                                        )}
                                                    </StatusBadge>
                                                ) : null}
                                            </div>
                                            <p className="mt-1 text-sm font-medium">
                                                {source.title}
                                            </p>
                                            <p className="text-caption">
                                                {[
                                                    source.scheduled_at
                                                        ? `Meeting on ${formatDateLong(source.scheduled_at)}`
                                                        : null,
                                                    refSuffix(source.reference),
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </div>
                                        {source.url ? (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link href={source.url}>
                                                    Open{' '}
                                                    {actionSourceLabel(
                                                        source.type,
                                                    ).toLowerCase()}
                                                    <ExternalLink className="h-3.5 w-3.5" />
                                                </Link>
                                            </Button>
                                        ) : null}
                                    </div>
                                ) : (
                                    <p className="text-subtle">
                                        {action.source_type
                                            ? `Added from: ${actionSourceLabel(action.source_type)}.`
                                            : 'This action was added directly, not from a meeting or resolution.'}
                                    </p>
                                )}
                            </Section>

                            <Section
                                id="evidence"
                                icon={Paperclip}
                                title="Evidence"
                                action={
                                    <StatusBadge variant={evidence.variant}>
                                        {evidence.label}
                                    </StatusBadge>
                                }
                            >
                                <ActionEvidencePanel
                                    actionId={action.id}
                                    evidence={evidenceFiles}
                                    earlierEvidence={earlierFiles}
                                    canUpload={canAct}
                                    required={action.evidence_required}
                                />
                            </Section>

                            <Section
                                id="completion"
                                icon={CheckCircle}
                                title={isCompleted ? 'Done — receipt' : 'Marking it as done'}
                                action={
                                    isCompleted ? (
                                        <StatusBadge variant="success">
                                            Done
                                        </StatusBadge>
                                    ) : null
                                }
                            >
                                {isCompleted ? (
                                    <div className="flex flex-col gap-4">
                                        {action.completion_receipt ? (
                                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-muted px-3 py-2.5">
                                                <div className="min-w-0">
                                                    <p className="text-caption">
                                                        Receipt
                                                    </p>
                                                    <p className="truncate font-mono text-sm">
                                                        {
                                                            action.completion_receipt
                                                        }
                                                    </p>
                                                </div>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={copyReceipt}
                                                >
                                                    {copiedReceipt ? (
                                                        <Check className="h-3.5 w-3.5 text-status-success" />
                                                    ) : (
                                                        <Copy className="h-3.5 w-3.5" />
                                                    )}
                                                    {copiedReceipt
                                                        ? 'Copied'
                                                        : 'Copy receipt'}
                                                </Button>
                                            </div>
                                        ) : null}
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <Fact label="Done on">
                                                {action.completed_at
                                                    ? formatDateTimeLong(
                                                          action.completed_at,
                                                      )
                                                    : '—'}
                                            </Fact>
                                            <Fact label="Marked as done by">
                                                {action.completed_by?.name ??
                                                    '—'}
                                            </Fact>
                                        </div>
                                        {action.completion_notes ? (
                                            <Fact label="What was done">
                                                <p className="font-normal whitespace-pre-line">
                                                    {action.completion_notes}
                                                </p>
                                            </Fact>
                                        ) : null}
                                    </div>
                                ) : (
                                    <div className="flex flex-col items-start gap-3">
                                        <p className="text-subtle">
                                            When the work is finished, mark it
                                            done — progress alone doesn&apos;t
                                            close it.
                                            {action.evidence_required
                                                ? ' This action also needs evidence.'
                                                : ''}
                                        </p>
                                        {canAct ? (
                                            <Button
                                                onClick={() =>
                                                    setDialog('complete')
                                                }
                                            >
                                                <CheckCircle className="h-4 w-4" />
                                                Mark as done
                                            </Button>
                                        ) : !can_update ? (
                                            <p className="text-caption">
                                                Only the owner or someone who
                                                manages actions can mark it as
                                                done.
                                            </p>
                                        ) : null}
                                    </div>
                                )}
                            </Section>
                        </div>

                        <div className="flex flex-col gap-5">
                            <Section id="progress" icon={Gauge} title="Progress">
                                <div className="flex flex-col gap-4">
                                    <div>
                                        <div className="mb-1.5 flex items-center justify-between text-sm">
                                            <span className="text-muted-foreground">
                                                How far along
                                            </span>
                                            <span className="font-semibold tabular-nums">
                                                {progress}%
                                            </span>
                                        </div>
                                        <Progress
                                            value={progress}
                                            role="progressbar"
                                            aria-valuenow={progress}
                                            aria-valuemin={0}
                                            aria-valuemax={100}
                                            aria-label="How far along"
                                        />
                                    </div>
                                    {action.progress_notes ? (
                                        <Fact label="Latest update">
                                            <p className="font-normal whitespace-pre-line">
                                                {action.progress_notes}
                                            </p>
                                        </Fact>
                                    ) : null}
                                    {canAct ? (
                                        <div className="flex flex-col gap-2">
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    setDialog('progress')
                                                }
                                            >
                                                <Gauge className="h-4 w-4" />
                                                Update progress
                                            </Button>
                                            {isBlocked ? (
                                                <Button
                                                    variant="outline"
                                                    onClick={unblock}
                                                    disabled={unblocking}
                                                >
                                                    <Play className="h-4 w-4" />
                                                    {unblocking
                                                        ? 'Removing blocker…'
                                                        : 'Remove blocker'}
                                                </Button>
                                            ) : (
                                                <Button
                                                    variant="outline"
                                                    onClick={() =>
                                                        setDialog('block')
                                                    }
                                                >
                                                    <PauseCircle className="h-4 w-4" />
                                                    Mark as blocked
                                                </Button>
                                            )}
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    setDialog('escalate')
                                                }
                                            >
                                                <Flag className="h-4 w-4" />
                                                Raise with the board
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                onClick={() =>
                                                    setDialog('reassign')
                                                }
                                            >
                                                <UserRound className="h-4 w-4" />
                                                Change owner
                                            </Button>
                                        </div>
                                    ) : null}
                                </div>
                            </Section>

                            <Section icon={UserRound} title="Owner and due date">
                                <div className="flex flex-col gap-4">
                                    <Fact label="Owner">
                                        {action.assigned_to?.name ?? (
                                            <span className="text-muted-foreground">
                                                Nobody yet
                                            </span>
                                        )}
                                    </Fact>
                                    <Fact label="Due">
                                        <span
                                            className={
                                                isOverdue
                                                    ? 'text-status-critical'
                                                    : undefined
                                            }
                                        >
                                            {formatDateOnly(dueDate)}
                                            {isOverdue
                                                ? ` · ${dueWording(action.due_date)}`
                                                : ''}
                                        </span>
                                    </Fact>
                                    <Fact label="Priority">
                                        <StatusBadge variant={priority.variant}>
                                            {priority.label}
                                        </StatusBadge>
                                    </Fact>
                                </div>
                            </Section>
                        </div>
                    </div>
                </div>
            </PageLayout>

            {canAct ? (
                <>
                    <UpdateProgressDialog
                        open={dialog === 'progress'}
                        onClose={close}
                        action={action}
                        returnTo={return_to}
                    />
                    <CompleteActionDialog
                        open={dialog === 'complete'}
                        onClose={close}
                        action={action}
                        returnTo={return_to}
                    />
                    <BlockActionDialog
                        open={dialog === 'block'}
                        onClose={close}
                        action={action}
                    />
                    <EscalateActionDialog
                        open={dialog === 'escalate'}
                        onClose={close}
                        action={action}
                    />
                    <ReassignActionDialog
                        open={dialog === 'reassign'}
                        onClose={close}
                        action={action}
                        assignees={assignees}
                    />
                </>
            ) : null}
        </AppLayout>
    );
}
