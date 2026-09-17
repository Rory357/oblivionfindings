import {
    PageHeader,
    PageHeaderGlassButton,
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
import { formatFileSize } from '@/components/ui/file-dropzone';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import {
    complianceEvidenceTypeLabel,
    frequencyLabel,
    governanceStatus,
    refSuffix,
} from '@/lib/governance-labels';
import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle,
    Clock,
    Download,
    ExternalLink,
    FileCheck,
    Pencil,
    Upload,
    User,
} from 'lucide-react';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import { GovernanceTermHint } from '@/components/governance/GovernanceTermHint';
import { useState } from 'react';

import {
    CompleteObligationDialog,
    frameworkIcon,
    ObligationWizardDialog,
    UploadEvidenceDialog,
    type ObligationFormOptions,
} from './_dialogs';
import {
    dueLabel,
    obligationStatusLabel,
    obligationStatusVariant,
    plural,
} from './_shared';

interface Evidence {
    id: number;
    evidence_type: string;
    title: string;
    description: string | null;
    file_name: string | null;
    file_size: number | null;
    valid_until: string | null;
    expired: boolean;
    uploaded_by: { name: string } | null;
    uploaded_at: string | null;
    open_url: string | null;
    download_url: string | null;
}

interface Reminder {
    id: number;
    days_before_due: number;
    scheduled_at: string | null;
    status: string;
    sent_at: string | null;
    is_escalation: boolean;
}

interface Obligation {
    id: number;
    framework: string;
    framework_label: string;
    obligation_code: string | null;
    obligation_title: string;
    description: string;
    requirements?: string | null;
    frequency: string;
    priority?: string | null;
    due_date: string;
    next_due_date: string | null;
    days_until_due: number | null;
    status: string;
    owner_id?: number | null;
    owner: { id: number; name: string } | null;
    completed_at: string | null;
    completed_by: { name: string } | null;
    completion_notes?: string | null;
    version_number?: number;
    parent_obligation_id?: number | null;
    parent_obligation?: {
        id: number;
        obligation_title: string;
        due_date: string;
    } | null;
    recurrences?: Array<{
        id: number;
        obligation_title: string;
        due_date: string;
        status: string;
    }>;
    evidence_required: boolean;
    evidence_provided: boolean;
    sign_off_required: boolean;
    signed_off_at: string | null;
    signed_off_by: { name: string } | null;
    notes: string | null;
    evidence: Evidence[];
    reminders: Reminder[];
}

interface Props extends PageProps {
    obligation: Obligation;
    abilities?: {
        update?: boolean;
        complete?: boolean;
        uploadEvidence?: boolean;
    };
    formOptions?: ObligationFormOptions | null;
}

function dateOnly(value: string | null | undefined): string {
    return formatDateOnly(value ? value.slice(0, 10) : null);
}

const REMINDER_STATUS: Record<string, { label: string; variant: 'success' | 'neutral' | 'critical' }> = {
    pending: { label: 'Scheduled', variant: 'neutral' },
    sent: { label: 'Sent', variant: 'success' },
    failed: { label: "Couldn't send", variant: 'critical' },
};

function scrollTo(id: string) {
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

export default function ComplianceShow({
    obligation,
    abilities = {},
    formOptions = null,
}: Props) {
    const evidenceItems = obligation.evidence ?? [];
    const reminderItems = obligation.reminders ?? [];
    const [uploadOpen, setUploadOpen] = useState(false);
    const [completeOpen, setCompleteOpen] = useState(false);
    const canEdit = Boolean(abilities.update) && formOptions != null;
    const canComplete =
        Boolean(abilities.complete) &&
        obligation.status !== 'complete' &&
        obligation.status !== 'cancelled';
    const canUpload = Boolean(abilities.uploadEvidence);
    // Retired /compliance/{id}/edit deep links arrive as ?edit=1.
    const [editOpen, setEditOpen] = useDialogDeepLink('edit', canEdit);

    const frameworkLabel = obligation.framework_label;
    const currentEvidence = evidenceItems.filter((ev) => !ev.expired);
    const sentReminders = reminderItems.filter((r) => r.status === 'sent').length;
    const isComplete = obligation.status === 'complete';
    const statusTone =
        obligation.status === 'overdue'
            ? 'critical'
            : obligation.status === 'due_soon'
              ? 'warning'
              : isComplete
                ? 'success'
                : 'brand';

    const header = (
        <PageHeader
            variant="profile"
            backHref="/governance/compliance"
            icon={frameworkIcon(obligation.framework)}
            title={obligation.obligation_title}
            titleDusk="compliance-heading"
            wrapTitle
            titleChip={
                <PageHeaderStatusChip
                    variant={obligationStatusVariant(obligation.status)}
                >
                    {obligationStatusLabel(obligation.status)}
                </PageHeaderStatusChip>
            }
            subline={[
                frameworkLabel,
                `Due ${dateOnly(obligation.due_date)}`,
                obligation.owner?.name
                    ? `Owner ${obligation.owner.name}`
                    : 'No owner',
                refSuffix(obligation.obligation_code),
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                <>
                    {canUpload ? (
                        <PageHeaderGlassButton
                            icon={Upload}
                            onClick={() => setUploadOpen(true)}
                        >
                            Upload evidence
                        </PageHeaderGlassButton>
                    ) : null}
                    {canEdit ? (
                        <PageHeaderGlassButton
                            icon={Pencil}
                            onClick={() => setEditOpen(true)}
                        >
                            Edit
                        </PageHeaderGlassButton>
                    ) : null}
                    {canComplete ? (
                        <PageHeaderPrimaryButton
                            icon={CheckCircle}
                            onClick={() => setCompleteOpen(true)}
                            data-dusk="open-complete-dialog-button"
                        >
                            Mark as done
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Due date"
                        ariaLabel="View due date and status"
                        onClick={() => scrollTo('obligation-status')}
                        tone={statusTone}
                    >
                        <PageHeaderMeterBig>
                            <span className="text-base">
                                {dateOnly(obligation.due_date)}
                            </span>
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {isComplete
                                ? `Done ${dateOnly(obligation.completed_at)}`
                                : dueLabel(obligation.days_until_due)}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Evidence"
                        ariaLabel="View evidence"
                        onClick={() => scrollTo('obligation-evidence')}
                        tone={
                            obligation.evidence_required &&
                            currentEvidence.length === 0 &&
                            !isComplete
                                ? 'warning'
                                : 'brand'
                        }
                    >
                        <PageHeaderMeterBig>
                            {evidenceItems.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {currentEvidence.length} current ·{' '}
                            {obligation.evidence_required
                                ? 'needed to mark done'
                                : 'not needed to mark done'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Reminders"
                        ariaLabel="View reminders"
                        onClick={() => scrollTo('obligation-reminders')}
                    >
                        <PageHeaderMeterBig>
                            {reminderItems.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {sentReminders} sent ·{' '}
                            {reminderItems.length - sentReminders} still to send
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Status"
                        ariaLabel={`View requirements that are ${obligationStatusLabel(obligation.status).toLowerCase()}`}
                        href={`/governance/compliance?status=${obligation.status}`}
                        tone={statusTone}
                    >
                        <PageHeaderMeterBig>
                            <span className="text-base">
                                {obligationStatusLabel(obligation.status)}
                            </span>
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Due {frequencyLabel(obligation.frequency).toLowerCase()}
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
                { title: 'Compliance', href: '/governance/compliance' },
                {
                    title: obligation.obligation_title,
                    href: `/governance/compliance/${obligation.id}`,
                },
            ]}
        >
            <Head title={obligation.obligation_title} />

            <PageLayout hero={header}>
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                    <div className="flex flex-col gap-5 lg:col-span-2">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-1.5">
                                    About this requirement
                                    <GovernanceTermHint term="requirement" />
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-4">
                                <p className="whitespace-pre-wrap text-foreground">
                                    {obligation.description}
                                </p>
                                {obligation.requirements ? (
                                    <div className="rounded-lg bg-muted p-4">
                                        <p className="text-sm font-medium text-foreground">
                                            What must be done
                                        </p>
                                        <p className="text-subtle whitespace-pre-wrap">
                                            {obligation.requirements}
                                        </p>
                                    </div>
                                ) : null}
                                {obligation.notes ? (
                                    <div className="rounded-lg bg-muted p-4">
                                        <p className="text-sm font-medium text-foreground">
                                            Notes
                                        </p>
                                        <p className="text-subtle whitespace-pre-wrap">
                                            {obligation.notes}
                                        </p>
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>

                        <Card id="obligation-evidence">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-1.5">
                                    Evidence
                                    <GovernanceTermHint term="evidence" />
                                </CardTitle>
                                <CardDescription>
                                    {obligation.evidence_required
                                        ? 'Evidence that hasn’t expired is needed before this can be marked done.'
                                        : 'Evidence is optional for this requirement.'}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {evidenceItems.length > 0 ? (
                                    <div className="flex flex-col gap-3">
                                        {evidenceItems.map((ev) => (
                                            <div
                                                key={ev.id}
                                                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-4"
                                            >
                                                <div className="flex min-w-0 items-center gap-3">
                                                    {ev.expired ? (
                                                        <AlertTriangle
                                                            className="h-6 w-6 shrink-0 text-status-warning"
                                                            aria-label="Expired"
                                                        />
                                                    ) : (
                                                        <FileCheck
                                                            className="h-6 w-6 shrink-0 text-status-success"
                                                            aria-label="Current"
                                                        />
                                                    )}
                                                    <div className="min-w-0">
                                                        <p className="truncate font-medium">
                                                            {ev.title}
                                                        </p>
                                                        <div className="text-subtle flex flex-wrap items-center gap-x-3 gap-y-1">
                                                            <StatusBadge
                                                                variant="neutral"
                                                                size="sm"
                                                            >
                                                                {complianceEvidenceTypeLabel(
                                                                    ev.evidence_type,
                                                                )}
                                                            </StatusBadge>
                                                            {ev.file_name ? (
                                                                <span className="truncate">
                                                                    {ev.file_name}
                                                                    {ev.file_size
                                                                        ? ` · ${formatFileSize(ev.file_size)}`
                                                                        : ''}
                                                                </span>
                                                            ) : (
                                                                <span className="text-status-warning">
                                                                    File missing
                                                                </span>
                                                            )}
                                                            <span>
                                                                Uploaded by{' '}
                                                                {ev.uploaded_by?.name ??
                                                                    'someone no longer listed'}
                                                                {ev.uploaded_at
                                                                    ? ` · ${formatDateTime(ev.uploaded_at)}`
                                                                    : ''}
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    {ev.valid_until ? (
                                                        <StatusBadge
                                                            variant={
                                                                ev.expired
                                                                    ? 'warning'
                                                                    : 'success'
                                                            }
                                                        >
                                                            {ev.expired
                                                                ? 'Expired'
                                                                : 'Valid until'}{' '}
                                                            {dateOnly(ev.valid_until)}
                                                        </StatusBadge>
                                                    ) : null}
                                                    {ev.open_url ? (
                                                        <Button
                                                            asChild
                                                            variant="outline"
                                                            size="sm"
                                                        >
                                                            <a
                                                                href={ev.open_url}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                aria-label={`Open ${ev.title} in a new tab`}
                                                            >
                                                                <ExternalLink className="h-4 w-4" />
                                                                Open
                                                            </a>
                                                        </Button>
                                                    ) : null}
                                                    {ev.download_url ? (
                                                        <Button
                                                            asChild
                                                            variant="outline"
                                                            size="sm"
                                                        >
                                                            <a
                                                                href={ev.download_url}
                                                                aria-label={`Download ${ev.title}`}
                                                            >
                                                                <Download className="h-4 w-4" />
                                                                Download
                                                            </a>
                                                        </Button>
                                                    ) : null}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <EmptyState
                                        variant="compact"
                                        icon={Upload}
                                        title="No evidence uploaded yet"
                                        description={
                                            canUpload
                                                ? 'Upload a document, audit report or certificate showing this requirement is met.'
                                                : 'Evidence the owner uploads appears here.'
                                        }
                                    />
                                )}
                            </CardContent>
                        </Card>

                        <Card id="obligation-reminders">
                            <CardHeader>
                                <CardTitle>Reminders</CardTitle>
                                <CardDescription>
                                    Emails to the owner before the due date.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {reminderItems.length > 0 ? (
                                    <div className="flex flex-col gap-2">
                                        {reminderItems.map((reminder) => {
                                            const chip =
                                                REMINDER_STATUS[reminder.status] ?? {
                                                    label: governanceStatus(
                                                        'compliance_status',
                                                        reminder.status,
                                                    ).label,
                                                    variant: 'neutral' as const,
                                                };
                                            return (
                                                <div
                                                    key={reminder.id}
                                                    className="flex items-center justify-between rounded-lg border border-border p-3"
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <Clock className="h-4 w-4 text-muted-foreground" />
                                                        <span className="text-sm">
                                                            {reminder.is_escalation
                                                                ? 'Follow-up because it is overdue'
                                                                : reminder.days_before_due === 0
                                                                  ? 'On the due date'
                                                                  : `${plural(reminder.days_before_due, 'day')} before it is due`}
                                                        </span>
                                                    </div>
                                                    <StatusBadge variant={chip.variant}>
                                                        {chip.label}
                                                    </StatusBadge>
                                                </div>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <EmptyState
                                        variant="compact"
                                        icon={Clock}
                                        title="No reminders scheduled"
                                    />
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <div className="flex flex-col gap-5">
                        <Card id="obligation-status">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    {isComplete ? (
                                        <CheckCircle className="h-5 w-5 text-status-success" />
                                    ) : obligation.status === 'overdue' ? (
                                        <AlertTriangle className="h-5 w-5 text-status-critical" />
                                    ) : (
                                        <Clock className="h-5 w-5 text-status-warning" />
                                    )}
                                    Status
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2">
                                <StatusBadge
                                    variant={obligationStatusVariant(
                                        obligation.status,
                                    )}
                                    className="w-fit"
                                >
                                    {isComplete
                                        ? 'Done'
                                        : obligation.status === 'cancelled'
                                          ? 'Cancelled'
                                          : dueLabel(obligation.days_until_due)}
                                </StatusBadge>
                                {isComplete ? (
                                    <>
                                        <p className="text-subtle">
                                            Marked done on{' '}
                                            {dateOnly(obligation.completed_at)} by{' '}
                                            {obligation.completed_by?.name ||
                                                'someone no longer listed'}
                                        </p>
                                        {obligation.completion_notes ? (
                                            <div className="rounded-md bg-muted p-2 text-xs">
                                                <p className="font-semibold text-muted-foreground">
                                                    How it was met
                                                </p>
                                                <p className="whitespace-pre-wrap text-foreground">
                                                    {obligation.completion_notes}
                                                </p>
                                            </div>
                                        ) : null}
                                    </>
                                ) : (
                                    <p className="text-subtle">
                                        Due {dateOnly(obligation.due_date)}
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Details</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-4">
                                <div>
                                    <p className="text-subtle">Comes from</p>
                                    <p className="font-medium">{frameworkLabel}</p>
                                </div>
                                <div>
                                    <p className="text-subtle">Owner</p>
                                    <p className="flex items-center gap-2 font-medium">
                                        <User className="h-4 w-4" />
                                        {obligation.owner?.name || 'No owner'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-subtle">How often</p>
                                    <p className="font-medium">
                                        {frequencyLabel(obligation.frequency)}
                                    </p>
                                </div>
                                {obligation.priority ? (
                                    <div>
                                        <p className="text-subtle">Priority</p>
                                        <StatusBadge
                                            variant={
                                                governanceStatus(
                                                    'priority',
                                                    obligation.priority,
                                                ).variant
                                            }
                                        >
                                            {
                                                governanceStatus(
                                                    'priority',
                                                    obligation.priority,
                                                ).label
                                            }
                                        </StatusBadge>
                                    </div>
                                ) : null}
                                {obligation.parent_obligation ? (
                                    <div>
                                        <p className="text-subtle">Previous time</p>
                                        <Link
                                            href={`/governance/compliance/${obligation.parent_obligation.id}`}
                                            className="text-sm font-medium text-primary hover:underline"
                                        >
                                            Due{' '}
                                            {dateOnly(
                                                obligation.parent_obligation.due_date,
                                            )}
                                        </Link>
                                    </div>
                                ) : null}
                                {obligation.recurrences &&
                                obligation.recurrences.length > 0 ? (
                                    <div>
                                        <p className="text-subtle">Next time</p>
                                        <div className="mt-0.5 flex flex-col gap-1">
                                            {obligation.recurrences.map((rec) => (
                                                <Link
                                                    key={rec.id}
                                                    href={`/governance/compliance/${rec.id}`}
                                                    className="text-sm font-medium text-primary hover:underline"
                                                >
                                                    Due {dateOnly(rec.due_date)} (
                                                    {obligationStatusLabel(rec.status)})
                                                </Link>
                                            ))}
                                        </div>
                                    </div>
                                ) : null}
                                <div>
                                    <p className="text-subtle">
                                        Evidence needed to mark done
                                    </p>
                                    {obligation.evidence_provided ? (
                                        <StatusBadge variant="success">
                                            <CheckCircle className="h-3.5 w-3.5" />
                                            Provided
                                        </StatusBadge>
                                    ) : obligation.evidence_required ? (
                                        <StatusBadge variant="warning">
                                            Needed — not provided yet
                                        </StatusBadge>
                                    ) : (
                                        <p className="font-medium text-muted-foreground">
                                            Not needed
                                        </p>
                                    )}
                                </div>
                                {obligation.sign_off_required ? (
                                    <div>
                                        <p className="text-subtle">Signed off</p>
                                        {obligation.signed_off_at ? (
                                            <StatusBadge variant="success">
                                                By {obligation.signed_off_by?.name}{' '}
                                                on {dateOnly(obligation.signed_off_at)}
                                            </StatusBadge>
                                        ) : (
                                            <StatusBadge variant="warning">
                                                Waiting to be signed off
                                            </StatusBadge>
                                        )}
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PageLayout>

            {canUpload ? (
                <UploadEvidenceDialog
                    open={uploadOpen}
                    onClose={() => setUploadOpen(false)}
                    obligationId={obligation.id}
                />
            ) : null}
            {canComplete ? (
                <CompleteObligationDialog
                    open={completeOpen}
                    onClose={() => setCompleteOpen(false)}
                    onUploadFirst={
                        canUpload
                            ? () => {
                                  setCompleteOpen(false);
                                  setUploadOpen(true);
                              }
                            : undefined
                    }
                    obligation={obligation}
                    frameworkLabel={frameworkLabel}
                />
            ) : null}
            {canEdit && formOptions ? (
                <ObligationWizardDialog
                    open={editOpen}
                    onClose={() => setEditOpen(false)}
                    options={formOptions}
                    obligation={obligation}
                />
            ) : null}
        </AppLayout>
    );
}
