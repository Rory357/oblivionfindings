import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    GovernanceAttachmentsPanel,
    type GovernanceAttachment,
} from '@/components/governance/GovernanceAttachmentsPanel';
import {
    pageHasFlashError,
    useDialogDeepLink,
} from '@/components/governance/governance-dialog-deep-link';
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
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard, SelectInput } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong, formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import { formatNzd, governanceStatus, refSuffix } from '@/lib/governance-labels';
import { PageProps } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    Gavel,
    HandCoins,
    Info,
    Paperclip,
    Pencil,
    Send,
    X,
} from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import {
    SpendApprovalWizardDialog,
    type SpendApprovalFormOptions,
} from './_dialogs';

type Person = { id: number; name: string; email?: string };

interface Approval {
    id: number;
    reference: string;
    title: string;
    description: string | null;
    category: string;
    amount: number;
    currency: string;
    status: string;
    version: number;
    content_digest: string | null;
    requires_board: boolean;
    site_id: number | null;
    submitted_at: string | null;
    decided_at: string | null;
    decision_notes: string | null;
    valid_until: string | null;
    requestedBy?: Person | null;
    requested_by?: Person | number | null;
    decidedBy?: Person | null;
    decided_by?: Person | number | null;
    budget: { id: number; fiscal_year: string; title: string | null } | null;
}

interface ResolutionOption {
    id: number;
    title: string;
    reference: string | null;
    amount: number;
    closed_at: string | null;
}

interface Props extends PageProps {
    approval: Approval;
    linked_resolution: {
        id: number;
        title: string;
        reference: string | null;
        outcome: string | null;
    } | null;
    site_name: string | null;
    financial_year_label: string | null;
    categories: Record<string, string>;
    threshold: number;
    who_approves: string;
    attachments: GovernanceAttachment[];
    authority: {
        update: boolean;
        submit: boolean;
        decide: boolean;
        manage_attachments: boolean;
    };
    decision_blocked_reason: string | null;
    board_resolution_options: ResolutionOption[];
    can_view_resolutions: boolean;
    form_options?: Pick<SpendApprovalFormOptions, 'sites' | 'thresholds'> | null;
}

const personFrom = (
    camel: Person | null | undefined,
    snake: Person | number | null | undefined,
): Person | null =>
    camel ?? (snake && typeof snake === 'object' ? snake : null);

const NONE = '__none';

export default function ShowSpendApproval({
    approval,
    linked_resolution,
    site_name,
    financial_year_label,
    categories,
    threshold,
    who_approves,
    attachments,
    authority,
    decision_blocked_reason,
    board_resolution_options,
    can_view_resolutions,
    form_options = null,
}: Props) {
    const canManageAttachments = authority.manage_attachments;
    const canEdit = authority.update && Boolean(form_options);
    const [decision, setDecision] = useState<'approve' | 'reject' | null>(
        null,
    );
    const [confirmSubmit, setConfirmSubmit] = useState(false);
    const [editOpen, setEditOpen] = useDialogDeepLink(
        'edit',
        canEdit,
        'This spend request has been sent for a decision, so it can no longer be edited.',
    );

    const isDraft = approval.status === 'draft';
    const isSubmitted = approval.status === 'submitted';
    const isDecided = ['approved', 'rejected'].includes(approval.status);
    const canDecide = isSubmitted && authority.decide;
    const requestedBy = personFrom(approval.requestedBy, approval.requested_by);
    const decidedBy = personFrom(approval.decidedBy, approval.decided_by);
    const categoryLabel = categories[approval.category] ?? approval.category;
    const chip = governanceStatus('spend_status', approval.status);

    const submitForDecision = () =>
        router.post(
            `/governance/spend-approvals/${approval.id}/submit`,
            { expected_version: approval.version },
            { preserveScroll: true },
        );

    const scrollToRequest = () =>
        document
            .getElementById('request')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                {
                    title: 'Spend approvals',
                    href: '/governance/spend-approvals',
                },
                {
                    title: approval.title,
                    href: `/governance/spend-approvals/${approval.id}`,
                },
            ]}
        >
            <Head title={`${approval.title} — Spend approvals`} />

            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/governance/spend-approvals"
                        icon={HandCoins}
                        title={approval.title}
                        titleDusk="spend-approval-heading"
                        titleChip={
                            <PageHeaderStatusChip variant={chip.variant}>
                                {chip.label}
                            </PageHeaderStatusChip>
                        }
                        subline={[
                            categoryLabel,
                            site_name,
                            refSuffix(approval.reference),
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
                                        Edit request
                                    </PageHeaderGlassButton>
                                ) : null}
                                {canDecide ? (
                                    <>
                                        <PageHeaderGlassButton
                                            icon={X}
                                            onClick={() => setDecision('reject')}
                                        >
                                            Decline
                                        </PageHeaderGlassButton>
                                        <PageHeaderPrimaryButton
                                            icon={Check}
                                            onClick={() =>
                                                setDecision('approve')
                                            }
                                        >
                                            Approve
                                        </PageHeaderPrimaryButton>
                                    </>
                                ) : isDraft && authority.submit ? (
                                    <PageHeaderPrimaryButton
                                        icon={Send}
                                        onClick={() => setConfirmSubmit(true)}
                                    >
                                        Send for a decision
                                    </PageHeaderPrimaryButton>
                                ) : null}
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Amount"
                                    onClick={scrollToRequest}
                                    ariaLabel="View the request details"
                                >
                                    <PageHeaderMeterBig>
                                        {formatNzd(approval.amount)}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {approval.requires_board
                                            ? 'Needs a board resolution'
                                            : 'A finance approver decides'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Board resolution from"
                                    href={`/governance/spend-approvals?category=${approval.category}`}
                                    ariaLabel={`View ${categoryLabel.toLowerCase()} requests`}
                                >
                                    <PageHeaderMeterBig>
                                        {formatNzd(threshold)}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        For {categoryLabel.toLowerCase()}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Status"
                                    href={`/governance/spend-approvals?status=${approval.status}`}
                                    tone={
                                        approval.status === 'approved'
                                            ? 'success'
                                            : approval.status === 'rejected'
                                              ? 'critical'
                                              : isSubmitted
                                                ? 'warning'
                                                : 'brand'
                                    }
                                    ariaLabel={`View requests that are ${chip.label.toLowerCase()}`}
                                >
                                    <PageHeaderMeterBig>
                                        {chip.label}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {isDecided && approval.decided_at
                                            ? `Decided ${formatDateLong(approval.decided_at)}`
                                            : approval.submitted_at
                                              ? `Sent ${formatDateLong(approval.submitted_at)}`
                                              : 'Not sent for a decision yet'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                    />
                }
            >
                <div className="flex flex-col gap-5">
                    <div className="grid gap-5 lg:grid-cols-3">
                        <div className="flex flex-col gap-5 lg:col-span-2">
                            <Card id="request" className="scroll-mt-5">
                                <CardHeader>
                                    <CardTitle className="text-section-title">
                                        Request
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-4 text-sm">
                                    <div>
                                        <p className="text-caption">
                                            Why it&apos;s needed
                                        </p>
                                        <p className="mt-1 whitespace-pre-wrap">
                                            {approval.description ||
                                                'No reason given.'}
                                        </p>
                                    </div>
                                    <dl className="grid gap-3 sm:grid-cols-2">
                                        <Fact label="Site">
                                            {site_name ?? 'Not available'}
                                        </Fact>
                                        <Fact label="Requested by">
                                            {requestedBy?.name ??
                                                'A former user'}
                                        </Fact>
                                        <Fact label="Sent for a decision">
                                            {approval.submitted_at
                                                ? formatDateTimeLong(
                                                      approval.submitted_at,
                                                  )
                                                : 'Not sent yet'}
                                        </Fact>
                                        <Fact label="Approval needed by">
                                            {approval.valid_until
                                                ? formatDateOnly(
                                                      approval.valid_until.slice(
                                                          0,
                                                          10,
                                                      ),
                                                  )
                                                : 'No date given'}
                                        </Fact>
                                        {approval.budget ? (
                                            <Fact label="Budget">
                                                {approval.budget.title ??
                                                    'Budget'}
                                                {financial_year_label
                                                    ? ` (financial year ${financial_year_label})`
                                                    : ''}
                                            </Fact>
                                        ) : null}
                                    </dl>
                                </CardContent>
                            </Card>

                            {isDecided ? (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-section-title">
                                            Decision
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="flex flex-col gap-3 text-sm">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <StatusBadge variant={chip.variant}>
                                                {chip.label}
                                            </StatusBadge>
                                            <span className="text-subtle">
                                                {approval.decided_at
                                                    ? `On ${formatDateTimeLong(approval.decided_at)}`
                                                    : 'Date not recorded'}{' '}
                                                by{' '}
                                                {decidedBy?.name ??
                                                    'a former user'}
                                            </span>
                                        </div>
                                        {approval.decision_notes ? (
                                            <p className="whitespace-pre-wrap">
                                                {approval.decision_notes}
                                            </p>
                                        ) : null}
                                        {linked_resolution ? (
                                            <p className="text-subtle">
                                                Board resolution:{' '}
                                                <Link
                                                    href={`/governance/resolutions/${linked_resolution.id}`}
                                                    className="font-medium text-primary hover:underline"
                                                >
                                                    {linked_resolution.title}
                                                </Link>
                                                {linked_resolution.reference
                                                    ? ` · ${refSuffix(linked_resolution.reference)}`
                                                    : ''}
                                            </p>
                                        ) : null}
                                    </CardContent>
                                </Card>
                            ) : null}
                        </div>

                        <div className="flex flex-col gap-5">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-section-title">
                                        What happens next
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-3 text-sm">
                                    <p>
                                        {isDraft
                                            ? authority.submit
                                                ? 'This request is a draft. Attach any quotes, then send it for a decision. Once sent it can’t be edited.'
                                                : 'This request is a draft. The person who asked for it sends it for a decision.'
                                            : isSubmitted
                                              ? canDecide
                                                  ? 'This request is waiting for your decision.'
                                                  : (decision_blocked_reason ??
                                                    'This request is waiting for a decision.')
                                              : 'This request has been decided.'}
                                    </p>
                                    {approval.requires_board && isSubmitted ? (
                                        <InfoCard icon={Gavel} tone="warn">
                                            Needs a board decision — link the
                                            resolution the board passed before
                                            approving.
                                            {can_view_resolutions ? (
                                                <>
                                                    {' '}
                                                    <Link
                                                        href="/governance/resolutions"
                                                        className="font-medium text-primary hover:underline"
                                                    >
                                                        Go to Resolutions
                                                    </Link>
                                                </>
                                            ) : null}
                                        </InfoCard>
                                    ) : null}
                                    <InfoCard icon={Info}>
                                        <strong>Who approves what</strong> —{' '}
                                        {who_approves}
                                    </InfoCard>
                                </CardContent>
                            </Card>
                        </div>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-section-title flex items-center gap-2">
                                <Paperclip className="h-4 w-4" />
                                Supporting documents
                                <span className="text-caption ml-1 font-normal">
                                    ({attachments.length})
                                </span>
                            </CardTitle>
                            <p className="text-subtle mt-1">
                                Quotes, contracts and invoices that back up the
                                request.
                            </p>
                        </CardHeader>
                        <CardContent>
                            <GovernanceAttachmentsPanel
                                canManage={canManageAttachments}
                                attachments={attachments}
                                urls={{
                                    upload: `/governance/spend-approvals/${approval.id}/attachments`,
                                    delete: (id) =>
                                        `/governance/spend-approvals/${approval.id}/attachments/${id}`,
                                }}
                                reloadProp="attachments"
                                helperText="PDF, Office documents, images, CSV or text — up to 20 MB each."
                                emptyText={{
                                    managed:
                                        'No supporting documents yet. Drop files above to attach one.',
                                    readOnly:
                                        'No supporting documents have been attached to this request.',
                                }}
                            />
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>

            <ConfirmDialog
                open={confirmSubmit}
                onClose={() => setConfirmSubmit(false)}
                onConfirm={submitForDecision}
                title="Send this request for a decision?"
                description={`${formatNzd(approval.amount)} for "${approval.title}". ${
                    approval.requires_board
                        ? 'This amount needs a board resolution before it can be approved.'
                        : 'A finance approver decides it.'
                } Once sent, the request can't be edited.`}
                confirmText="Send for a decision"
                variant="default"
            />

            {decision ? (
                <DecisionDialog
                    approval={approval}
                    mode={decision}
                    resolutionOptions={board_resolution_options}
                    canViewResolutions={can_view_resolutions}
                    onClose={() => setDecision(null)}
                />
            ) : null}

            {canEdit && form_options ? (
                <SpendApprovalWizardDialog
                    isOpen={editOpen}
                    onClose={() => setEditOpen(false)}
                    options={{
                        categories,
                        thresholds: form_options.thresholds,
                        sites: form_options.sites,
                    }}
                    approval={{
                        id: approval.id,
                        reference: approval.reference,
                        title: approval.title,
                        description: approval.description,
                        category: approval.category,
                        amount: approval.amount,
                        currency: approval.currency,
                        site_id: approval.site_id,
                        valid_until: approval.valid_until,
                        version: approval.version,
                    }}
                />
            ) : null}
        </AppLayout>
    );
}

function Fact({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div>
            <dt className="text-caption">{label}</dt>
            <dd className="mt-1 font-medium">{children}</dd>
        </div>
    );
}

function DecisionDialog({
    approval,
    mode,
    resolutionOptions,
    canViewResolutions,
    onClose,
}: {
    approval: Approval;
    mode: 'approve' | 'reject';
    resolutionOptions: ResolutionOption[];
    canViewResolutions: boolean;
    onClose: () => void;
}) {
    const approving = mode === 'approve';
    const needsResolution = approving && approval.requires_board;
    const form = useForm({
        decision_key: crypto.randomUUID(),
        expected_version: approval.version,
        expected_content_digest: approval.content_digest ?? '',
        decision_notes: '',
        resolution_id: NONE,
    });
    const errors = form.errors as Record<string, string>;
    const general =
        errors.decision ??
        errors.expected_content_digest ??
        errors.expected_version ??
        errors.decision_key;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            decision_key: data.decision_key,
            expected_version: data.expected_version,
            expected_content_digest: data.expected_content_digest,
            decision_notes: data.decision_notes,
            ...(needsResolution && data.resolution_id !== NONE
                ? { resolution_id: Number(data.resolution_id) }
                : {}),
        }));
        form.post(
            `/governance/spend-approvals/${approval.id}/${approving ? 'approve' : 'reject'}`,
            {
                preserveScroll: true,
                onSuccess: (page: unknown) => {
                    if (!pageHasFlashError(page)) onClose();
                },
            },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 560px)' }}>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>
                            {approving
                                ? 'Approve this spend request?'
                                : 'Decline this spend request?'}
                        </DialogTitle>
                        <DialogDescription>
                            {approving
                                ? `This gives permission to spend ${formatNzd(approval.amount)} on "${approval.title}". The requester is told, and the decision can't be changed.`
                                : `The spend of ${formatNzd(approval.amount)} on "${approval.title}" is not approved. The requester sees your reason, and the decision can't be changed.`}
                        </DialogDescription>
                    </DialogHeader>

                    {needsResolution ? (
                        resolutionOptions.length > 0 ? (
                            <Field
                                label="Board resolution"
                                required
                                error={errors.resolution_id}
                            >
                                <SelectInput
                                    value={form.data.resolution_id}
                                    onChange={(value) =>
                                        form.setData('resolution_id', value)
                                    }
                                    placeholder="Choose the resolution the board passed"
                                    ariaLabel="Board resolution"
                                    options={[
                                        {
                                            value: NONE,
                                            label: 'Choose the resolution the board passed',
                                        },
                                        ...resolutionOptions.map((option) => ({
                                            value: String(option.id),
                                            label: `${option.title} — approves up to ${formatNzd(option.amount)}${option.reference ? ` · ${refSuffix(option.reference)}` : ''}`,
                                        })),
                                    ]}
                                />
                            </Field>
                        ) : (
                            <InfoCard icon={AlertTriangle} tone="warn">
                                This request needs a board decision, and no
                                passed resolution approving{' '}
                                {formatNzd(approval.amount)} is available yet.
                                The secretary prepares one from Resolutions;
                                once the board passes it, you can approve this
                                request.
                                {canViewResolutions ? (
                                    <>
                                        {' '}
                                        <Link
                                            href="/governance/resolutions"
                                            className="font-medium text-primary hover:underline"
                                        >
                                            Go to Resolutions
                                        </Link>
                                    </>
                                ) : null}
                            </InfoCard>
                        )
                    ) : null}

                    <Field
                        label="Reason for your decision"
                        required
                        error={errors.decision_notes}
                    >
                        <Textarea
                            id="spend-decision-reason"
                            rows={4}
                            value={form.data.decision_notes}
                            onChange={(e) =>
                                form.setData('decision_notes', e.target.value)
                            }
                        />
                    </Field>

                    {general ? (
                        <InfoCard icon={AlertTriangle} tone="crit">
                            {general}{' '}
                            <Button
                                type="button"
                                variant="link"
                                className="h-auto p-0 font-medium"
                                onClick={() => router.reload()}
                            >
                                Refresh the page
                            </Button>
                        </InfoCard>
                    ) : null}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant={approving ? 'default' : 'destructive'}
                            disabled={
                                form.processing ||
                                form.data.decision_notes.trim() === '' ||
                                (needsResolution &&
                                    form.data.resolution_id === NONE)
                            }
                        >
                            {approving ? 'Approve request' : 'Decline request'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
