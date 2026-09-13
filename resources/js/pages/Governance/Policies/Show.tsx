import { Head, router, useForm } from '@inertiajs/react';
import { BookOpen, CheckCircle, Pencil, Shield } from 'lucide-react';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import { ProgressValue } from '@/components/lists';
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
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateLong } from '@/lib/datetime';
import { PageProps } from '@/types';

import {
    POLICY_STATUS_VARIANT,
    PolicyWizardDialog,
    policyCategoryLabel,
    policyStatusLabel,
    type PolicyWizardRecord,
} from './_dialogs';

interface Attestation {
    id: number;
    user: { id: number | null; name: string };
    version: number;
    attested_at: string | null;
    notes: string | null;
}

interface Policy extends PolicyWizardRecord {
    version: number;
    approved_by_user: { name: string } | null;
    approved_at: string | null;
    attestations: Attestation[];
}

interface Props extends PageProps {
    policy: Policy;
    attestationStats: { total_required: number; completed: number };
    canEdit: boolean;
}

function DetailRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between gap-4">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right">{value}</span>
        </div>
    );
}

export default function PolicyShow({
    auth,
    policy,
    attestationStats,
    canEdit,
}: Props) {
    const [editOpen, setEditOpen] = useDialogDeepLink('edit', canEdit);
    const attestForm = useForm({ acknowledged: false, notes: '' });
    const today = new Date().toISOString().split('T')[0];

    const isActive = policy.status === 'active';
    const reviewOverdue = Boolean(
        isActive && policy.review_date && policy.review_date < today,
    );
    const myAttestation = policy.attestations.find(
        (a) => a.user.id === auth.user.id,
    );
    const attestPercent =
        attestationStats.total_required > 0
            ? (attestationStats.completed / attestationStats.total_required) *
              100
            : 0;

    const handleAttest = (e: React.FormEvent) => {
        e.preventDefault();
        attestForm.post(`/governance/policies/${policy.id}/attest`, {
            preserveScroll: true,
            onSuccess: () => attestForm.reset(),
        });
    };

    const handleApprove = () =>
        router.post(
            `/governance/policies/${policy.id}/approve`,
            {},
            { preserveScroll: true },
        );

    const closeEdit = () => setEditOpen(false);

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Policies', href: '/governance/policies' },
                {
                    title: policy.title,
                    href: `/governance/policies/${policy.id}`,
                },
            ]}
        >
            <Head title={policy.title} />
            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref="/governance/policies"
                        icon={BookOpen}
                        title={policy.title}
                        titleDusk="policy-heading"
                        wrapTitle
                        titleChip={
                            <PageHeaderStatusChip
                                variant={
                                    POLICY_STATUS_VARIANT[policy.status] ??
                                    'neutral'
                                }
                            >
                                {policyStatusLabel(policy.status)}
                            </PageHeaderStatusChip>
                        }
                        subline={`${policyCategoryLabel(policy.category)} policy · Version ${policy.version} · Review ${formatDateOnly(policy.review_date)}`}
                        actions={
                            <>
                                {canEdit ? (
                                    <PageHeaderGlassButton
                                        icon={Pencil}
                                        onClick={() => setEditOpen(true)}
                                    >
                                        Edit
                                    </PageHeaderGlassButton>
                                ) : null}
                                {canEdit && policy.status === 'draft' ? (
                                    <PageHeaderPrimaryButton
                                        icon={Shield}
                                        onClick={handleApprove}
                                    >
                                        Approve
                                    </PageHeaderPrimaryButton>
                                ) : null}
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Status"
                                    ariaLabel={`View ${policyStatusLabel(policy.status).toLowerCase()} policies`}
                                    href={`/governance/policies?status=${policy.status}`}
                                >
                                    <PageHeaderMeterBig>
                                        {policyStatusLabel(policy.status)}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {policy.approved_by_user
                                            ? `Approved by ${policy.approved_by_user.name}`
                                            : 'Not yet approved'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                {policy.requires_attestation ? (
                                    <PageHeaderMeterBlock
                                        label="Attestations"
                                        value={`${attestationStats.completed}/${attestationStats.total_required}`}
                                        ariaLabel="View policy attestations"
                                        href="/governance/policies/attestations"
                                    >
                                        <PageHeaderMeterBar
                                            percent={attestPercent}
                                        />
                                        <PageHeaderMeterCaption>
                                            {Math.round(attestPercent)}% of
                                            active board members
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                                <PageHeaderMeterBlock
                                    label="Effective"
                                    ariaLabel="View active policies"
                                    href="/governance/policies?status=active"
                                >
                                    <PageHeaderMeterBig>
                                        {formatDateOnly(policy.effective_date)}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Version {policy.version}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Next review"
                                    tone={reviewOverdue ? 'critical' : 'brand'}
                                    ariaLabel="View policies overdue for review"
                                    href="/governance/policies?review=overdue"
                                >
                                    <PageHeaderMeterBig>
                                        {formatDateOnly(policy.review_date)}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {reviewOverdue
                                            ? 'Review overdue'
                                            : 'Scheduled review'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                    />
                }
            >
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                    <div className="flex flex-col gap-5 lg:col-span-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Policy content</CardTitle>
                                {policy.description ? (
                                    <CardDescription>
                                        {policy.description}
                                    </CardDescription>
                                ) : null}
                            </CardHeader>
                            <CardContent>
                                {/* Policy wording is authored as plain text — render it as text, never HTML. */}
                                <div className="prose max-w-none whitespace-pre-wrap">
                                    {policy.content}
                                </div>
                            </CardContent>
                        </Card>

                        {policy.requires_attestation && isActive ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Your attestation</CardTitle>
                                    <CardDescription>
                                        {myAttestation?.attested_at
                                            ? `You attested to version ${policy.version} on ${formatDateLong(myAttestation.attested_at)}.`
                                            : 'Acknowledge that you have read and understood this policy.'}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <form
                                        onSubmit={handleAttest}
                                        className="flex flex-col gap-4"
                                    >
                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                id="acknowledged"
                                                checked={
                                                    attestForm.data.acknowledged
                                                }
                                                onCheckedChange={(val) =>
                                                    attestForm.setData(
                                                        'acknowledged',
                                                        val === true,
                                                    )
                                                }
                                            />
                                            <label
                                                htmlFor="acknowledged"
                                                className="text-sm font-medium"
                                            >
                                                I have read and understood this
                                                policy (v{policy.version})
                                            </label>
                                        </div>
                                        <Textarea
                                            aria-label="Attestation notes"
                                            placeholder="Optional notes (e.g. queries or clarifications)"
                                            value={attestForm.data.notes}
                                            onChange={(e) =>
                                                attestForm.setData(
                                                    'notes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <div>
                                            <Button
                                                type="submit"
                                                disabled={
                                                    !attestForm.data
                                                        .acknowledged ||
                                                    attestForm.processing
                                                }
                                            >
                                                <CheckCircle className="h-4 w-4" />
                                                {myAttestation
                                                    ? 'Re-confirm attestation'
                                                    : 'Submit attestation'}
                                            </Button>
                                        </div>
                                    </form>
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>

                    <div className="flex flex-col gap-5">
                        <Card>
                            <CardHeader>
                                <CardTitle>Details</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3 text-sm">
                                <DetailRow
                                    label="Category"
                                    value={policyCategoryLabel(policy.category)}
                                />
                                <DetailRow
                                    label="Version"
                                    value={String(policy.version)}
                                />
                                <DetailRow
                                    label="Effective date"
                                    value={formatDateOnly(policy.effective_date)}
                                />
                                <DetailRow
                                    label="Review date"
                                    value={formatDateOnly(policy.review_date)}
                                />
                                <DetailRow
                                    label="Attestation"
                                    value={
                                        policy.requires_attestation
                                            ? 'Required'
                                            : 'Not required'
                                    }
                                />
                                {policy.approved_by_user ? (
                                    <DetailRow
                                        label="Approved by"
                                        value={`${policy.approved_by_user.name}${policy.approved_at ? ` · ${formatDateLong(policy.approved_at)}` : ''}`}
                                    />
                                ) : null}
                            </CardContent>
                        </Card>

                        {policy.requires_attestation ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Attestation progress</CardTitle>
                                    <CardDescription>
                                        {attestationStats.completed} of{' '}
                                        {attestationStats.total_required} active
                                        board members
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-4">
                                    <ProgressValue
                                        percent={attestPercent}
                                        tone="success"
                                    >
                                        {Math.round(attestPercent)}% attested
                                    </ProgressValue>
                                    {policy.attestations.length > 0 ? (
                                        <ul className="flex flex-col gap-2">
                                            {policy.attestations.map((att) => (
                                                <li
                                                    key={att.id}
                                                    className="flex items-center gap-2 text-sm"
                                                >
                                                    <CheckCircle className="h-4 w-4 text-status-success" />
                                                    <span>{att.user.name}</span>
                                                    <span className="ml-auto text-caption">
                                                        {formatDateLong(
                                                            att.attested_at,
                                                        )}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <EmptyState
                                            variant="inline"
                                            icon={CheckCircle}
                                            title="No attestations recorded yet"
                                        />
                                    )}
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>
                </div>
            </PageLayout>

            {canEdit ? (
                <PolicyWizardDialog
                    open={editOpen}
                    onClose={closeEdit}
                    policy={policy}
                />
            ) : null}
        </AppLayout>
    );
}
