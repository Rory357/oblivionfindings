import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    BookOpen,
    CheckCircle2,
    ClipboardCheck,
    FilePlus2,
    History,
    Pencil,
    Shield,
    UsersRound,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import { GovernanceTermHint } from '@/components/governance/GovernanceTermHint';
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
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { FieldErr } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong, formatDateOnly, toDateInput } from '@/lib/datetime';
import {
    frequencyLabel,
    governanceStatus,
    refSuffix,
} from '@/lib/governance-labels';
import { PageProps } from '@/types';

import {
    PolicyWizardDialog,
    policyCategoryLabel,
    type PolicyWizardRecord,
} from './_dialogs';
import {
    confirmationChip,
    confirmationReceipt,
    confirmedOf,
    type ConfirmationState,
    type MyConfirmation,
} from './_shared';

interface Policy extends PolicyWizardRecord {
    version: number;
    reference: string | null;
    change_summary: string | null;
    approved_by_user: { name: string } | null;
    approved_at: string | null;
}

interface Confirmation {
    required: boolean;
    state: ConfirmationState;
    effective_from: string | null;
    frequency: string | null;
    my_confirmation: MyConfirmation | null;
    can_confirm: boolean;
    board_confirmed: number;
    board_total: number;
}

interface BoardConfirmation {
    user_id: number;
    name: string;
    confirmed: boolean;
    confirmed_at: string | null;
}

interface VersionLink {
    id: number;
    version: number;
    status: string;
}

interface Props extends PageProps {
    policy: Policy;
    confirmation: Confirmation;
    confirmations: BoardConfirmation[] | null;
    versions: { previous: VersionLink | null; newer: VersionLink | null };
    canEdit: boolean;
    canApprove: boolean;
    canStartVersion: boolean;
}

function DetailRow({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex justify-between gap-4">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right">{value}</span>
        </div>
    );
}

function scrollTo(id: string) {
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function confirmationRule(policy: Policy): string {
    if (!policy.requires_attestation) return 'Not needed';
    return policy.attestation_frequency
        ? `Yes — again ${frequencyLabel(policy.attestation_frequency).toLowerCase()}`
        : 'Yes — once for each version';
}

/** The confirm box: a receipt once confirmed, otherwise the unticked form. */
function ReadAndConfirmCard({
    policy,
    confirmation,
    newer,
}: {
    policy: Policy;
    confirmation: Confirmation;
    newer: VersionLink | null;
}) {
    const form = useForm({ acknowledged: false, notes: '' });
    const page = usePage<{ flash?: { error?: string | null } }>();
    const chip = confirmationChip(confirmation.state, confirmation.effective_from);
    const mine = confirmation.my_confirmation;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/governance/policies/${policy.id}/attest`, {
            preserveScroll: true,
            onSuccess: (response) => {
                const flash = (response.props as { flash?: { error?: string | null } })
                    .flash;
                if (!flash?.error) form.reset();
            },
        });
    };

    let body: React.ReactNode;
    if (confirmation.state === 'confirmed' && mine) {
        body = (
            <div className="flex flex-col gap-2" data-testid="policy-confirmation-receipt">
                <p className="flex items-center gap-2 text-sm font-medium">
                    <CheckCircle2 className="h-4 w-4 text-status-success" />
                    {confirmationReceipt(mine)}
                </p>
                {mine.due_again_on ? (
                    <p className="text-subtle">
                        You will be asked to confirm it again on{' '}
                        {formatDateLong(mine.due_again_on)}.
                    </p>
                ) : null}
            </div>
        );
    } else if (
        (confirmation.state === 'to_confirm' || confirmation.state === 'due_again') &&
        confirmation.can_confirm
    ) {
        body = (
            <form onSubmit={submit} className="flex flex-col gap-4">
                {confirmation.state === 'due_again' && mine ? (
                    <p className="text-subtle">
                        {confirmationReceipt(mine)} This policy asks members to
                        confirm again{' '}
                        {confirmation.frequency
                            ? frequencyLabel(confirmation.frequency).toLowerCase()
                            : ''}
                        , so please read it and confirm again.
                    </p>
                ) : (
                    <p className="text-subtle">
                        Read the policy wording, then confirm you have read
                        version {policy.version}.
                    </p>
                )}
                <div className="flex items-start gap-2">
                    <Checkbox
                        id="policy-acknowledged"
                        checked={form.data.acknowledged}
                        onCheckedChange={(val) =>
                            form.setData('acknowledged', val === true)
                        }
                    />
                    <label htmlFor="policy-acknowledged" className="text-sm font-medium">
                        I have read version {policy.version} of this policy
                    </label>
                </div>
                <FieldErr>{form.errors.acknowledged}</FieldErr>
                <div>
                    <label
                        htmlFor="policy-confirmation-note"
                        className="text-caption mb-1 block"
                    >
                        Note (optional)
                    </label>
                    <Textarea
                        id="policy-confirmation-note"
                        rows={2}
                        maxLength={500}
                        placeholder="e.g. a question for the secretary"
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                    />
                    <FieldErr>{form.errors.notes}</FieldErr>
                </div>
                {page.props.flash?.error ? (
                    <p role="alert" className="text-sm text-status-critical">
                        {page.props.flash.error}
                    </p>
                ) : null}
                <div>
                    <Button
                        type="submit"
                        disabled={!form.data.acknowledged || form.processing}
                    >
                        <ClipboardCheck className="h-4 w-4" />
                        Confirm I've read this policy
                    </Button>
                </div>
            </form>
        );
    } else if (confirmation.state === 'not_yet_in_effect') {
        body = (
            <p className="text-subtle">
                This policy comes into effect on{' '}
                {formatDateLong(confirmation.effective_from)}. You can confirm you
                have read it from then.
            </p>
        );
    } else if (confirmation.state === 'replaced') {
        body = (
            <p className="text-subtle">
                A newer version has replaced this policy.{' '}
                {newer ? (
                    <Link
                        href={`/governance/policies/${newer.id}`}
                        className="font-medium text-primary underline-offset-4 hover:underline"
                    >
                        Open version {newer.version}
                    </Link>
                ) : null}
            </p>
        );
    } else if (confirmation.state === 'not_approved') {
        body = (
            <p className="text-subtle">
                Board members are asked to confirm once the policy is approved.
            </p>
        );
    } else {
        body = (
            <p className="text-subtle">
                You can&apos;t confirm this policy from your account.
            </p>
        );
    }

    return (
        <Card id="confirm" className="scroll-mt-5">
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <CardTitle className="flex items-center gap-1.5">
                        Read and confirm
                        <GovernanceTermHint term="read_and_confirm" />
                    </CardTitle>
                    <StatusBadge variant={chip.variant}>{chip.label}</StatusBadge>
                </div>
                <CardDescription>
                    Your confirmation is recorded with the version and the date.
                </CardDescription>
            </CardHeader>
            <CardContent>{body}</CardContent>
        </Card>
    );
}

export default function PolicyShow({
    auth,
    policy,
    confirmation,
    confirmations,
    versions,
    canEdit,
    canApprove,
    canStartVersion,
}: Props) {
    const [editOpen, setEditOpen] = useDialogDeepLink('edit', canEdit);
    const [versionOpen, setVersionOpen] = useState(false);
    const [approveOpen, setApproveOpen] = useState(false);
    // The NZ calendar date, not the UTC one (a day out on NZ mornings).
    const today = toDateInput(new Date());

    useEffect(() => {
        // Rows on other pages link straight to #confirm / #confirmations.
        const hash = window.location.hash.replace('#', '');
        if (hash === 'confirm' || hash === 'confirmations') {
            window.setTimeout(() => scrollTo(hash), 50);
        }
    }, []);

    const isActive = policy.status === 'active';
    const statusChip = governanceStatus('policy_status', policy.status);
    const reviewOverdue = Boolean(
        isActive && policy.review_date && policy.review_date < today,
    );
    const confirmPercent =
        confirmation.board_total > 0
            ? Math.min(
                  100,
                  (confirmation.board_confirmed / confirmation.board_total) * 100,
              )
            : 0;
    const showConfirmMeter = confirmation.required && isActive;

    const handleApprove = () =>
        router.post(
            `/governance/policies/${policy.id}/approve`,
            {},
            { preserveScroll: true },
        );

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
                            <PageHeaderStatusChip variant={statusChip.variant}>
                                {statusChip.label}
                            </PageHeaderStatusChip>
                        }
                        subline={[
                            `${policyCategoryLabel(policy.category)} policy`,
                            `Version ${policy.version}`,
                            refSuffix(policy.reference),
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
                                        Edit
                                    </PageHeaderGlassButton>
                                ) : null}
                                {canStartVersion ? (
                                    <PageHeaderGlassButton
                                        icon={FilePlus2}
                                        onClick={() => setVersionOpen(true)}
                                    >
                                        Start new version
                                    </PageHeaderGlassButton>
                                ) : null}
                                {canApprove ? (
                                    <PageHeaderPrimaryButton
                                        icon={Shield}
                                        onClick={() => setApproveOpen(true)}
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
                                    ariaLabel={`View ${statusChip.label.toLowerCase()} policies`}
                                    href={`/governance/policies?status=${policy.status === 'superseded' ? 'archived' : policy.status}`}
                                >
                                    <PageHeaderMeterBig>
                                        <span className="text-base">
                                            {statusChip.label}
                                        </span>
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {policy.approved_by_user
                                            ? `Approved by ${policy.approved_by_user.name}`
                                            : 'Not approved yet'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                {showConfirmMeter ? (
                                    <PageHeaderMeterBlock
                                        label="Read and confirmed"
                                        value={confirmedOf(
                                            confirmation.board_confirmed,
                                            confirmation.board_total,
                                        )}
                                        ariaLabel="View who has confirmed this policy"
                                        onClick={() =>
                                            scrollTo(
                                                confirmations
                                                    ? 'confirmations'
                                                    : 'confirm',
                                            )
                                        }
                                        tone={
                                            confirmation.board_total > 0 &&
                                            confirmation.board_confirmed >=
                                                confirmation.board_total
                                                ? 'success'
                                                : 'brand'
                                        }
                                    >
                                        <PageHeaderMeterBar percent={confirmPercent} />
                                        <PageHeaderMeterCaption>
                                            board members, this version
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ) : null}
                                <PageHeaderMeterBlock
                                    label="Comes into effect"
                                    ariaLabel="View policy details"
                                    onClick={() => scrollTo('policy-details')}
                                >
                                    <PageHeaderMeterBig>
                                        <span className="text-base">
                                            {policy.effective_date
                                                ? formatDateOnly(policy.effective_date)
                                                : 'When approved'}
                                        </span>
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Version {policy.version}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Next review"
                                    tone={reviewOverdue ? 'critical' : 'brand'}
                                    ariaLabel={
                                        reviewOverdue
                                            ? 'View policies overdue for review'
                                            : 'View policy details'
                                    }
                                    href={
                                        reviewOverdue
                                            ? '/governance/policies?review=overdue'
                                            : undefined
                                    }
                                    onClick={
                                        reviewOverdue
                                            ? undefined
                                            : () => scrollTo('policy-details')
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        <span className="text-base">
                                            {formatDateOnly(policy.review_date, 'Not set')}
                                        </span>
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {reviewOverdue
                                            ? 'Review overdue'
                                            : 'Next scheduled review'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                    />
                }
            >
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                    <div className="flex flex-col gap-5 lg:col-span-2">
                        {versions.newer && canEdit ? (
                            <Card>
                                <CardContent className="flex flex-wrap items-center justify-between gap-3 py-4">
                                    <p className="flex items-center gap-2 text-sm">
                                        <History className="h-4 w-4 text-primary" />
                                        {versions.newer.status === 'active'
                                            ? `Version ${versions.newer.version} has replaced this policy.`
                                            : `Version ${versions.newer.version} is being drafted. This version stays in effect until it is approved.`}
                                    </p>
                                    <Button asChild variant="outline" size="sm">
                                        <Link
                                            href={`/governance/policies/${versions.newer.id}`}
                                        >
                                            Open version {versions.newer.version}
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        ) : null}

                        {policy.change_summary ? (
                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        What changed in version {policy.version}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="whitespace-pre-wrap text-sm">
                                        {policy.change_summary}
                                    </p>
                                </CardContent>
                            </Card>
                        ) : null}

                        <Card>
                            <CardHeader>
                                <CardTitle>Policy wording</CardTitle>
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

                        {confirmation.required ? (
                            <ReadAndConfirmCard
                                policy={policy}
                                confirmation={confirmation}
                                newer={versions.newer}
                            />
                        ) : null}
                    </div>

                    <div className="flex flex-col gap-5">
                        <Card id="policy-details" className="scroll-mt-5">
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
                                    value={`Version ${policy.version}`}
                                />
                                <DetailRow
                                    label="Comes into effect"
                                    value={
                                        policy.effective_date
                                            ? formatDateLong(policy.effective_date)
                                            : 'When approved'
                                    }
                                />
                                <DetailRow
                                    label="Next review"
                                    value={formatDateLong(policy.review_date, 'Not set')}
                                />
                                <DetailRow
                                    label="Read and confirm"
                                    value={confirmationRule(policy)}
                                />
                                {policy.approved_by_user ? (
                                    <DetailRow
                                        label="Approved by"
                                        value={`${policy.approved_by_user.name}${policy.approved_at ? ` · ${formatDateLong(policy.approved_at)}` : ''}`}
                                    />
                                ) : null}
                                {versions.previous ? (
                                    <DetailRow
                                        label="Previous version"
                                        value={
                                            <Link
                                                href={`/governance/policies/${versions.previous.id}`}
                                                className="font-medium text-primary underline-offset-4 hover:underline"
                                            >
                                                Version {versions.previous.version}
                                            </Link>
                                        }
                                    />
                                ) : null}
                            </CardContent>
                        </Card>

                        {confirmations ? (
                            <Card id="confirmations" className="scroll-mt-5">
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <UsersRound className="h-4 w-4 text-primary" />
                                        Who has confirmed
                                    </CardTitle>
                                    <CardDescription>
                                        {confirmedOf(
                                            confirmation.board_confirmed,
                                            confirmation.board_total,
                                        )}{' '}
                                        board members have confirmed version{' '}
                                        {policy.version}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-4">
                                    <ProgressValue
                                        percent={isActive ? confirmPercent : null}
                                        tone="success"
                                    />
                                    {confirmations.length > 0 ? (
                                        <ul className="flex flex-col gap-2">
                                            {confirmations.map((member) => (
                                                <li
                                                    key={member.user_id}
                                                    className="flex items-center gap-2 text-sm"
                                                >
                                                    <span className="min-w-0 flex-1 truncate">
                                                        {member.name}
                                                    </span>
                                                    {member.confirmed ? (
                                                        <StatusBadge variant="success">
                                                            {member.confirmed_at
                                                                ? `Confirmed ${formatDateOnly(toDateInput(member.confirmed_at))}`
                                                                : 'Confirmed'}
                                                        </StatusBadge>
                                                    ) : (
                                                        <StatusBadge variant="neutral">
                                                            Not yet
                                                        </StatusBadge>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <EmptyState
                                            variant="inline"
                                            icon={UsersRound}
                                            title="There are no current board members to confirm this policy"
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
                    onClose={() => setEditOpen(false)}
                    policy={policy}
                    mode="edit"
                />
            ) : null}
            {canStartVersion ? (
                <PolicyWizardDialog
                    open={versionOpen}
                    onClose={() => setVersionOpen(false)}
                    policy={policy}
                    mode="version"
                />
            ) : null}
            <ConfirmDialog
                open={approveOpen}
                onClose={() => setApproveOpen(false)}
                onConfirm={handleApprove}
                variant="default"
                title="Approve and publish this policy?"
                description={
                    policy.requires_attestation
                        ? `Version ${policy.version} becomes the policy in effect${versions.previous ? ` and replaces version ${versions.previous.version}` : ''}. Board members will be asked to confirm they've read it. This can't be undone — later changes need a new version.`
                        : `Version ${policy.version} becomes the policy in effect${versions.previous ? ` and replaces version ${versions.previous.version}` : ''} for everyone. This can't be undone — later changes need a new version.`
                }
                confirmText="Approve and publish"
            />
        </AppLayout>
    );
}
