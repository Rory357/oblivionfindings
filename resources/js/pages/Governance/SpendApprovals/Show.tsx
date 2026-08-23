import {
    GovernanceAttachmentsPanel,
    type GovernanceAttachment,
} from '@/components/governance/GovernanceAttachmentsPanel';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { statusColors } from '@/lib/status-colors';
import { PageProps } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, HandCoins, Paperclip, Send, X } from 'lucide-react';
import { useState } from 'react';

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
    submitted_at: string | null;
    decided_at: string | null;
    decision_notes: string | null;
    valid_until: string | null;
    requestedBy: { id: number; name: string; email: string } | null;
    decidedBy: { id: number; name: string; email: string } | null;
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
}

const formatNzd = (amount: number) =>
    new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(amount || 0);

export default function ShowSpendApproval({
    auth,
    approval,
    categories,
    threshold,
    attachments,
    authority,
}: Props) {
    const canManageAttachments = authority.manage_attachments;
    const [showApprove, setShowApprove] = useState(false);
    const [showReject, setShowReject] = useState(false);

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

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                {
                    title: 'Spend Approvals',
                    href: '/governance/spend-approvals',
                },
                {
                    title: approval.reference,
                    href: `/governance/spend-approvals/${approval.id}`,
                },
            ]}
        >
            <Head title={`Spend Approval — ${approval.reference}`} />

            <PageLayout
                hero={
                    <PageHero
                        icon={HandCoins}
                        category="governance"
                        title={approval.title}
                        description={`${approval.reference} · ${categories[approval.category] ?? approval.category}`}
                        stats={[
                            {
                                label: 'Amount',
                                value: formatNzd(approval.amount),
                            },
                            { label: 'Threshold', value: formatNzd(threshold) },
                            { label: 'Status', value: approval.status },
                        ]}
                        badges={
                            approval.requires_board
                                ? [{ label: 'Board sign-off' }]
                                : undefined
                        }
                        actions={
                            <Button asChild variant="outline">
                                <Link href="/governance/spend-approvals">
                                    <ArrowLeft className="mr-2 h-4 w-4" /> Back
                                </Link>
                            </Button>
                        }
                    />
                }
            >
                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="space-y-4 lg:col-span-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Request</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                {approval.description && (
                                    <div>
                                        <p className="text-xs text-muted-foreground">
                                            Description
                                        </p>
                                        <p className="mt-1 whitespace-pre-wrap">
                                            {approval.description}
                                        </p>
                                    </div>
                                )}
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <p className="text-xs text-muted-foreground">
                                            Requested by
                                        </p>
                                        <p className="mt-1 font-medium">
                                            {approval.requestedBy?.name ?? '—'}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-xs text-muted-foreground">
                                            Submitted at
                                        </p>
                                        <p className="mt-1 font-medium">
                                            {approval.submitted_at ??
                                                'Not yet submitted'}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-xs text-muted-foreground">
                                            Valid until
                                        </p>
                                        <p className="mt-1 font-medium">
                                            {approval.valid_until ?? '—'}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-xs text-muted-foreground">
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
                                    <CardTitle>Decision</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-3 text-sm">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge
                                            className={
                                                statusColors[approval.status] ??
                                                ''
                                            }
                                        >
                                            {approval.status}
                                        </Badge>
                                        <span className="text-muted-foreground">
                                            {approval.decided_at} by{' '}
                                            {approval.decidedBy?.name ??
                                                'unknown'}
                                        </span>
                                    </div>
                                    {approval.decision_notes && (
                                        <p className="text-sm whitespace-pre-wrap">
                                            {approval.decision_notes}
                                        </p>
                                    )}
                                    {approval.resolution && (
                                        <p className="text-xs text-muted-foreground">
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
                                        <CardTitle>
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
                                                        setShowApprove(false);
                                                        setShowReject(false);
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

                    <div className="space-y-3">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
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
                                        <Send className="mr-2 h-4 w-4" /> Submit
                                        for sign-off
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
                                {!isDraft && !isSubmitted && !isDecided && (
                                    <p className="text-xs text-muted-foreground">
                                        No actions available.
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        {approval.budget && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Linked Budget
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="text-sm">
                                    <p className="font-medium">
                                        {approval.budget.title}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        FY {approval.budget.fiscal_year}
                                    </p>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>

                <Card className="mt-6">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Paperclip className="h-5 w-5" />
                            Supporting documents
                            <span className="ml-1 text-sm font-normal text-muted-foreground">
                                ({attachments.length})
                            </span>
                        </CardTitle>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Quotes, contracts, invoices, vendor due diligence —
                            the documentary trail behind the spend decision.
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
            </PageLayout>
        </AppLayout>
    );
}
