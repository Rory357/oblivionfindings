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
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime, toDateInput } from '@/lib/datetime';
import { index as complianceIndex } from '@/routes/governance/compliance';
import { PageProps } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle,
    Clock,
    FileCheck,
    Pencil,
    Upload,
    User,
} from 'lucide-react';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import { useState } from 'react';

import {
    CompleteObligationDialog,
    frameworkIcon,
    FREQUENCY_OPTIONS,
    isEvidenceExpired,
    ObligationWizardDialog,
    UploadEvidenceDialog,
    type ObligationFormOptions,
} from './_dialogs';
import {
    daysUntil,
    dueLabel,
    obligationStatusLabel,
    obligationStatusVariant,
    priorityVariant,
} from './_shared';

interface Evidence {
    id: number;
    evidence_type: string;
    title: string;
    file_path: string;
    valid_until: string | null;
    uploaded_by: { name: string } | null;
    uploaded_at: string;
}

interface Reminder {
    id: number;
    days_before_due: number;
    scheduled_at: string;
    status: string;
    sent_at: string | null;
}

interface Obligation {
    id: number;
    framework: string;
    obligation_code: string | null;
    obligation_title: string;
    description: string;
    requirements?: string | null;
    frequency: string;
    priority?: string | null;
    due_date: string;
    next_due_date: string | null;
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

const FRAMEWORK_LABELS: Record<string, string> = {
    charities: 'Charities Services',
    nga_paerewa: 'Ngā Paerewa NZS 8134:2021',
    hdsa_safety: 'H&D Services (Safety) Act',
    privacy_act: 'Privacy Act 2020',
    hip_code: 'Health Information Privacy Code',
    hswa: 'Health and Safety at Work Act',
    employment: 'Employment Relations',
    funding_moh: 'MoH/Health NZ Funding',
    funding_msd: 'MSD Funding',
    funding_acc: 'ACC Funding',
};

function dateOnly(value: string | null | undefined): string {
    return formatDateOnly(value ? value.slice(0, 10) : null);
}

function humanise(value: string | null | undefined): string {
    if (!value) return '—';
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

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
        Boolean(abilities.complete) && obligation.status !== 'complete';
    const canUpload = Boolean(abilities.uploadEvidence);
    // Retired /compliance/{id}/edit deep links arrive as ?edit=1.
    const [editOpen, setEditOpen] = useDialogDeepLink('edit', canEdit);

    const frameworkLabel =
        FRAMEWORK_LABELS[obligation.framework] ?? humanise(obligation.framework);
    const days = daysUntil(obligation.due_date, toDateInput(new Date()));
    const validEvidence = evidenceItems.filter((ev) => !isEvidenceExpired(ev));
    const sentReminders = reminderItems.filter((r) => r.status === 'sent').length;
    const isComplete = obligation.status === 'complete';

    const header = (
        <PageHeader
            variant="profile"
            backHref={complianceIndex.url()}
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
                obligation.obligation_code,
                `Due ${dateOnly(obligation.due_date)}`,
                obligation.owner?.name
                    ? `Owner ${obligation.owner.name}`
                    : 'No owner',
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
                            Mark complete
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
                        tone={
                            obligation.status === 'overdue'
                                ? 'critical'
                                : obligation.status === 'due_soon'
                                  ? 'warning'
                                  : isComplete
                                    ? 'success'
                                    : 'brand'
                        }
                    >
                        <PageHeaderMeterBig>
                            <span className="text-base">
                                {dateOnly(obligation.due_date)}
                            </span>
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {isComplete
                                ? `Completed ${dateOnly(obligation.completed_at)}`
                                : dueLabel(days)}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Evidence"
                        ariaLabel="View evidence"
                        onClick={() => scrollTo('obligation-evidence')}
                        tone={
                            obligation.evidence_required &&
                            validEvidence.length === 0
                                ? 'warning'
                                : 'brand'
                        }
                    >
                        <PageHeaderMeterBig>
                            {evidenceItems.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {validEvidence.length} valid ·{' '}
                            {obligation.evidence_required
                                ? 'required'
                                : 'optional'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Reminders"
                        ariaLabel="View scheduled reminders"
                        onClick={() => scrollTo('obligation-reminders')}
                    >
                        <PageHeaderMeterBig>
                            {reminderItems.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {sentReminders} sent ·{' '}
                            {reminderItems.length - sentReminders} pending
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Status"
                        ariaLabel={`View ${obligationStatusLabel(obligation.status).toLowerCase()} obligations`}
                        href={`/governance/compliance?status=${obligation.status}`}
                        tone={
                            obligation.status === 'overdue'
                                ? 'critical'
                                : obligation.status === 'due_soon'
                                  ? 'warning'
                                  : isComplete
                                    ? 'success'
                                    : 'brand'
                        }
                    >
                        <PageHeaderMeterBig>
                            <span className="text-base">
                                {obligationStatusLabel(obligation.status)}
                            </span>
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {humanise(obligation.frequency)} obligation
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
                                <CardTitle>Description</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-4">
                                <p className="whitespace-pre-wrap text-foreground">
                                    {obligation.description}
                                </p>
                                {obligation.requirements ? (
                                    <div className="rounded-lg bg-muted p-4">
                                        <p className="text-sm font-medium text-foreground">
                                            Requirements
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
                                <CardTitle>Evidence</CardTitle>
                                <CardDescription>
                                    {obligation.evidence_required
                                        ? 'Evidence is required for this obligation'
                                        : 'Evidence is optional'}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {evidenceItems.length > 0 ? (
                                    <div className="flex flex-col gap-3">
                                        {evidenceItems.map((ev) => {
                                            const expired = isEvidenceExpired(ev);
                                            return (
                                                <div
                                                    key={ev.id}
                                                    className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border p-4"
                                                >
                                                    <div className="flex min-w-0 items-center gap-3">
                                                        <FileCheck className="h-6 w-6 shrink-0 text-status-success" />
                                                        <div className="min-w-0">
                                                            <p className="truncate font-medium">
                                                                {ev.title}
                                                            </p>
                                                            <div className="text-subtle flex flex-wrap items-center gap-3">
                                                                <StatusBadge
                                                                    variant="neutral"
                                                                    size="sm"
                                                                >
                                                                    {humanise(
                                                                        ev.evidence_type,
                                                                    )}
                                                                </StatusBadge>
                                                                <span>
                                                                    by{' '}
                                                                    {ev
                                                                        .uploaded_by
                                                                        ?.name ??
                                                                        'Unknown'}
                                                                </span>
                                                                <span>
                                                                    {formatDateTime(
                                                                        ev.uploaded_at,
                                                                    )}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    {ev.valid_until ? (
                                                        <StatusBadge
                                                            variant={
                                                                expired
                                                                    ? 'critical'
                                                                    : 'success'
                                                            }
                                                        >
                                                            {expired
                                                                ? 'Expired'
                                                                : 'Valid until'}{' '}
                                                            {dateOnly(
                                                                ev.valid_until,
                                                            )}
                                                        </StatusBadge>
                                                    ) : null}
                                                </div>
                                            );
                                        })}
                                    </div>
                                ) : (
                                    <EmptyState
                                        variant="compact"
                                        icon={Upload}
                                        title="No evidence uploaded yet"
                                        description={
                                            canUpload
                                                ? 'Upload documents, audit reports or attestations that prove this obligation is met.'
                                                : undefined
                                        }
                                    />
                                )}
                            </CardContent>
                        </Card>

                        <Card id="obligation-reminders">
                            <CardHeader>
                                <CardTitle>Scheduled reminders</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {reminderItems.length > 0 ? (
                                    <div className="flex flex-col gap-2">
                                        {reminderItems.map((reminder) => (
                                            <div
                                                key={reminder.id}
                                                className="flex items-center justify-between rounded-lg border border-border p-3"
                                            >
                                                <div className="flex items-center gap-2">
                                                    <Clock className="h-4 w-4 text-muted-foreground" />
                                                    <span className="text-sm">
                                                        {reminder.days_before_due}{' '}
                                                        days before due
                                                    </span>
                                                </div>
                                                <StatusBadge
                                                    variant={
                                                        reminder.status ===
                                                        'sent'
                                                            ? 'success'
                                                            : 'neutral'
                                                    }
                                                >
                                                    {humanise(reminder.status)}
                                                </StatusBadge>
                                            </div>
                                        ))}
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
                                        ? 'Completed'
                                        : dueLabel(days)}
                                </StatusBadge>
                                {isComplete ? (
                                    <>
                                        <p className="text-subtle">
                                            {dateOnly(obligation.completed_at)}{' '}
                                            by{' '}
                                            {obligation.completed_by?.name ||
                                                'Authorised staff'}
                                        </p>
                                        {obligation.completion_notes ? (
                                            <div className="rounded-md bg-muted p-2 text-xs">
                                                <p className="font-semibold text-muted-foreground">
                                                    Completion notes
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
                                    <p className="text-subtle">Owner</p>
                                    <p className="flex items-center gap-2 font-medium">
                                        <User className="h-4 w-4" />
                                        {obligation.owner?.name ||
                                            'Not assigned'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-subtle">Frequency</p>
                                    <p className="font-medium">
                                        {FREQUENCY_OPTIONS.find(
                                            (f) =>
                                                f.value === obligation.frequency,
                                        )?.label ??
                                            humanise(obligation.frequency)}
                                    </p>
                                </div>
                                {obligation.priority ? (
                                    <div>
                                        <p className="text-subtle">Priority</p>
                                        <StatusBadge
                                            variant={priorityVariant(
                                                obligation.priority,
                                            )}
                                        >
                                            {humanise(obligation.priority)}
                                        </StatusBadge>
                                    </div>
                                ) : null}
                                {obligation.parent_obligation ? (
                                    <div>
                                        <p className="text-subtle">
                                            Prior cycle
                                        </p>
                                        <Link
                                            href={`/governance/compliance/${obligation.parent_obligation.id}`}
                                            className="text-sm font-medium text-primary hover:underline"
                                        >
                                            {
                                                obligation.parent_obligation
                                                    .obligation_title
                                            }{' '}
                                            (
                                            {dateOnly(
                                                obligation.parent_obligation
                                                    .due_date,
                                            )}
                                            )
                                        </Link>
                                    </div>
                                ) : null}
                                {obligation.recurrences &&
                                obligation.recurrences.length > 0 ? (
                                    <div>
                                        <p className="text-subtle">
                                            Next cycle
                                        </p>
                                        <div className="mt-0.5 flex flex-col gap-1">
                                            {obligation.recurrences.map(
                                                (rec) => (
                                                    <Link
                                                        key={rec.id}
                                                        href={`/governance/compliance/${rec.id}`}
                                                        className="text-sm font-medium text-primary hover:underline"
                                                    >
                                                        Due{' '}
                                                        {dateOnly(rec.due_date)}{' '}
                                                        (
                                                        {obligationStatusLabel(
                                                            rec.status,
                                                        )}
                                                        )
                                                    </Link>
                                                ),
                                            )}
                                        </div>
                                    </div>
                                ) : null}
                                {obligation.next_due_date ? (
                                    <div>
                                        <p className="text-subtle">
                                            Next due date
                                        </p>
                                        <p className="font-medium">
                                            {dateOnly(obligation.next_due_date)}
                                        </p>
                                    </div>
                                ) : null}
                                <div>
                                    <p className="text-subtle">Evidence</p>
                                    {obligation.evidence_provided ? (
                                        <StatusBadge variant="success">
                                            <CheckCircle className="h-3.5 w-3.5" />
                                            Provided
                                        </StatusBadge>
                                    ) : obligation.evidence_required ? (
                                        <StatusBadge variant="critical">
                                            Required — not provided
                                        </StatusBadge>
                                    ) : (
                                        <p className="font-medium text-muted-foreground">
                                            Not required
                                        </p>
                                    )}
                                </div>
                                {obligation.sign_off_required ? (
                                    <div>
                                        <p className="text-subtle">Sign-off</p>
                                        {obligation.signed_off_at ? (
                                            <StatusBadge variant="success">
                                                Signed by{' '}
                                                {obligation.signed_off_by?.name}{' '}
                                                on{' '}
                                                {dateOnly(
                                                    obligation.signed_off_at,
                                                )}
                                            </StatusBadge>
                                        ) : (
                                            <StatusBadge variant="warning">
                                                Pending sign-off
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
