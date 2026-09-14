import {
    GovernanceAttachmentsPanel,
    type GovernanceAttachment,
} from '@/components/governance/GovernanceAttachmentsPanel';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong, formatDateTimeLong } from '@/lib/datetime';
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { Check, HandCoins, Paperclip, Pencil, Send, X } from 'lucide-react';
import { useState } from 'react';
import {
    SpendApprovalWizardDialog,
    formatNzd,
    spendStatusLabel as statusLabel,
    spendStatusVariant,
    type SpendApprovalFormOptions,
} from './_dialogs';

type Person = { id: number; name: string; email: string };

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
    resolution: { id: number; title: string; outcome: string } | null;
    budget: { id: number; fiscal_year: string; title: string } | null;
}

interface Props extends PageProps {
    approval: Approval;
    categories: Record<string, string>;
    threshold: number;
    attachments: GovernanceAttachment[];
    authority: {
        update: boolean;
        submit: boolean;
        decide: boolean;
        manage_attachments: boolean;
    };
    form_options?: Pick<SpendApprovalFormOptions, 'sites' | 'thresholds'> | null;
}

const personFrom = (
    camel: Person | null | undefined,
    snake: Person | number | null | undefined,
): Person | null =>
    camel ?? (snake && typeof snake === 'object' ? snake : null);

export default function ShowSpendApproval({
    approval,
    categories,
    threshold,
    attachments,
    authority,
    form_options = null,
}: Props) {
    const canManageAttachments = authority.manage_attachments;
    const canEdit = authority.update && Boolean(form_options);
    const [showApprove, setShowApprove] = useState(false);
    const [showReject, setShowReject] = useState(false);
    const [editOpen, setEditOpen] = useDialogDeepLink('edit', canEdit);

    const submitForm = useForm({ expected_version: approval.version });

    const approveForm = useForm({
        decision_key: crypto.randomUUID(),
        expected_version: approval.version,
        expected_content_digest: approval.content_digest ?? '',
        decision_notes: '',
        resolution_id: '' as string | number | null,
    });

    const rejectForm = useForm({
        decision_key: crypto.randomUUID(),
        expected_version: approval.version,
        expected_content_digest: approval.content_digest ?? '',
        decision_notes: '',
    });

    const handleSubmit = () => {
        submitForm.transform((current) => ({
            ...current,
            expected_version: approval.version,
        }));
        submitForm.post(`/governance/spend-approvals/${approval.id}/submit`, {
            onFinish: () => submitForm.transform((current) => current),
        });
    };

    const handleApprove = (e: React.FormEvent) => {
        e.preventDefault();
        approveForm.transform((current) => ({
            ...current,
            expected_version: approval.version,
            expected_content_digest: approval.content_digest ?? '',
        }));
        approveForm.post(`/governance/spend-approvals/${approval.id}/approve`, {
            onSuccess: () => setShowApprove(false),
            onFinish: () => approveForm.transform((current) => current),
        });
    };

    const handleReject = (e: React.FormEvent) => {
        e.preventDefault();
        rejectForm.transform((current) => ({
            ...current,
            expected_version: approval.version,
            expected_content_digest: approval.content_digest ?? '',
        }));
        rejectForm.post(`/governance/spend-approvals/${approval.id}/reject`, {
            onSuccess: () => setShowReject(false),
            onFinish: () => rejectForm.transform((current) => current),
        });
    };

    const isDraft = approval.status === 'draft';
    const isSubmitted = approval.status === 'submitted';
    const isDecided = ['approved', 'rejected'].includes(approval.status);
    const requestedBy = personFrom(approval.requestedBy, approval.requested_by);
    const decidedBy = personFrom(approval.decidedBy, approval.decided_by);
    const categoryLabel = categories[approval.category] ?? approval.category;

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
                    title: approval.reference,
                    href: `/governance/spend-approvals/${approval.id}`,
                },
            ]}
        >
            <Head title={`Spend approval — ${approval.reference}`} />

            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/governance/spend-approvals"
                        icon={HandCoins}
                        title={approval.title}
                        titleDusk="spend-approval-heading"
                        titleChip={
                            <PageHeaderStatusChip
                                variant={spendStatusVariant(approval.status)}
                            >
                                {statusLabel(approval.status)}
                            </PageHeaderStatusChip>
                        }
                        subline={[
                            approval.reference,
                            categoryLabel,
                            approval.requires_board ? 'Board sign-off' : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                        actions={
                            canEdit ? (
                                <PageHeaderGlassButton
                                    icon={Pencil}
                                    onClick={() => setEditOpen(true)}
                                >
                                    Edit request
                                </PageHeaderGlassButton>
                            ) : undefined
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Amount"
                                    href={`/governance/spend-approvals/${approval.id}`}
                                >
                                    <PageHeaderMeterBig>
                                        {formatNzd(approval.amount)}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Requested spend
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Threshold"
                                    href={`/governance/spend-approvals?category=${approval.category}`}
                                >
                                    <PageHeaderMeterBig>
                                        {formatNzd(threshold)}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {categoryLabel} policy limit
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
                                              : 'warning'
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {statusLabel(approval.status)}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Version {approval.version}
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
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-section-title">
                                        Request
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3 text-sm">
                                    {approval.description && (
                                        <div>
                                            <p className="text-caption">
                                                Description
                                            </p>
                                            <p className="mt-1 whitespace-pre-wrap">
                                                {approval.description}
                                            </p>
                                        </div>
                                    )}
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <p className="text-caption">
                                                Requested by
                                            </p>
                                            <p className="mt-1 font-medium">
                                                {requestedBy?.name ?? '—'}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-caption">
                                                Submitted at
                                            </p>
                                            <p className="mt-1 font-medium">
                                                {approval.submitted_at
                                                    ? formatDateTimeLong(
                                                          approval.submitted_at,
                                                      )
                                                    : 'Not yet submitted'}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-caption">
                                                Valid until
                                            </p>
                                            <p className="mt-1 font-medium">
                                                {approval.valid_until
                                                    ? formatDateLong(
                                                          approval.valid_until,
                                                      )
                                                    : '—'}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-caption">
                                                Currency
                                            </p>
                                            <p className="mt-1 font-medium">
                                                {approval.currency}
                                            </p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>

                            {isDecided && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-section-title">
                                            Decision
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-3 text-sm">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <StatusBadge
                                                variant={spendStatusVariant(
                                                    approval.status,
                                                )}
                                            >
                                                {statusLabel(approval.status)}
                                            </StatusBadge>
                                            <span className="text-muted-foreground">
                                                {approval.decided_at
                                                    ? formatDateTimeLong(
                                                          approval.decided_at,
                                                      )
                                                    : '—'}{' '}
                                                by {decidedBy?.name ?? 'unknown'}
                                            </span>
                                        </div>
                                        {approval.decision_notes && (
                                            <p className="text-sm whitespace-pre-wrap">
                                                {approval.decision_notes}
                                            </p>
                                        )}
                                        {approval.resolution && (
                                            <p className="text-caption">
                                                Linked resolution:{' '}
                                                {approval.resolution.title} (
                                                {approval.resolution.outcome})
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>
                            )}

                            {isSubmitted &&
                                authority.decide &&
                                (showApprove || showReject) && (
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-section-title">
                                                {showApprove
                                                    ? 'Approve request'
                                                    : 'Reject request'}
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <form
                                                onSubmit={
                                                    showApprove
                                                        ? handleApprove
                                                        : handleReject
                                                }
                                                className="space-y-3"
                                            >
                                                <Textarea
                                                    rows={4}
                                                    aria-label={
                                                        showApprove
                                                            ? 'Reason for approval'
                                                            : 'Reason for rejection'
                                                    }
                                                    placeholder={
                                                        showApprove
                                                            ? 'Reason for approval (required)'
                                                            : 'Reason for rejection (required)'
                                                    }
                                                    value={
                                                        showApprove
                                                            ? approveForm.data
                                                                  .decision_notes
                                                            : rejectForm.data
                                                                  .decision_notes
                                                    }
                                                    onChange={(e) =>
                                                        showApprove
                                                            ? approveForm.setData(
                                                                  'decision_notes',
                                                                  e.target.value,
                                                              )
                                                            : rejectForm.setData(
                                                                  'decision_notes',
                                                                  e.target.value,
                                                              )
                                                    }
                                                    required
                                                />
                                                <div className="flex items-center justify-end gap-2">
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        onClick={() => {
                                                            setShowApprove(
                                                                false,
                                                            );
                                                            setShowReject(
                                                                false,
                                                            );
                                                        }}
                                                    >
                                                        Cancel
                                                    </Button>
                                                    <Button
                                                        type="submit"
                                                        disabled={
                                                            showApprove
                                                                ? approveForm.processing
                                                                : rejectForm.processing
                                                        }
                                                    >
                                                        {showApprove
                                                            ? 'Confirm approval'
                                                            : 'Confirm rejection'}
                                                    </Button>
                                                </div>
                                            </form>
                                        </CardContent>
                                    </Card>
                                )}
                        </div>

                        <div className="flex flex-col gap-5">
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-section-title">
                                        Actions
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {isDraft && authority.submit && (
                                        <Button
                                            onClick={handleSubmit}
                                            disabled={submitForm.processing}
                                            className="w-full"
                                        >
                                            <Send className="mr-2 h-4 w-4" />{' '}
                                            Submit for sign-off
                                        </Button>
                                    )}
                                    {canEdit && (
                                        <Button
                                            variant="outline"
                                            onClick={() => setEditOpen(true)}
                                            className="w-full"
                                        >
                                            <Pencil className="mr-2 h-4 w-4" />{' '}
                                            Edit request
                                        </Button>
                                    )}
                                    {isSubmitted &&
                                        authority.decide &&
                                        !showApprove &&
                                        !showReject && (
                                            <>
                                                <Button
                                                    onClick={() =>
                                                        setShowApprove(true)
                                                    }
                                                    className="w-full"
                                                >
                                                    <Check className="mr-2 h-4 w-4" />{' '}
                                                    Approve
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    onClick={() =>
                                                        setShowReject(true)
                                                    }
                                                    className="w-full"
                                                >
                                                    <X className="mr-2 h-4 w-4" />{' '}
                                                    Reject
                                                </Button>
                                            </>
                                        )}
                                    {!(isDraft && authority.submit) &&
                                        !canEdit &&
                                        !(isSubmitted && authority.decide) && (
                                            <p className="text-caption">
                                                No actions available to you
                                                for this request.
                                            </p>
                                        )}
                                </CardContent>
                            </Card>

                            {approval.budget && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-section-title">
                                            Linked budget
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="text-sm">
                                        <p className="font-medium">
                                            {approval.budget.title}
                                        </p>
                                        <p className="text-caption">
                                            FY {approval.budget.fiscal_year}
                                        </p>
                                    </CardContent>
                                </Card>
                            )}
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
                                Quotes, contracts, invoices, vendor due
                                diligence — the documentary trail behind the
                                spend decision.
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
                                helperText="PDF, Office, images, CSV / TXT — up to 20 MB each."
                                emptyText={{
                                    managed:
                                        'No supporting documents yet. Drop files above to attach one.',
                                    readOnly:
                                        'No supporting documents have been attached to this approval.',
                                }}
                            />
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>

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
