import {
    EntityCard,
    EntityCardGrid,
    EntityChip,
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { LoadingState } from '@/components/ui/loading-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import { formatDateTimeLong } from '@/lib/datetime';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    ClipboardList,
    Eye,
    History,
    Pencil,
    Plus,
    ShieldCheck,
    UserRound,
    Wallet,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { InternalFundingDialog } from './internal-funding-dialog';
import { ProposalAgreementCheck } from './proposal-agreement';
import { ProposalEditDialog } from './proposal-edit-dialog';
import { ProposalReviewDialog } from './proposal-review-dialog';
import type {
    ProposalData,
    ProposalReview,
    SupportProposal,
} from './proposal-types';
import { ProposalValues } from './proposal-values';
import {
    label,
    referralHref,
    type Options,
    type Page,
    type Permissions,
    type ReferralDetail,
} from './types';

function ProposalDetail({
    proposal,
    referral,
    current,
    readiness,
    onClose,
}: {
    proposal: SupportProposal;
    referral: ReferralDetail;
    current: boolean;
    readiness: ProposalData['readiness'];
    onClose: () => void;
}) {
    const [section, setSection] = useState(0);
    const [reviews, setReviews] = useState<Page<ProposalReview> | null>(null);
    const [page, setPage] = useState(1);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [retry, setRetry] = useState(0);
    const body = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (section !== 2) return;
        const controller = new AbortController();
        setBusy(true);
        setError('');
        setReviews(null);
        axios
            .get<{ reviews: Page<ProposalReview> }>(
                `${referralHref(referral.reference)}/proposals/${proposal.id}/reviews`,
                { params: { page }, signal: controller.signal },
            )
            .then((response) => setReviews(response.data.reviews))
            .catch((caught) => {
                if (!axios.isCancel(caught))
                    setError(
                        'Proposal responses could not be loaded. Try again.',
                    );
            })
            .finally(() => {
                if (!controller.signal.aborted) setBusy(false);
            });
        return () => controller.abort();
    }, [section, page, proposal.id, referral.reference, retry]);
    const steps = [
        {
            key: 'terms',
            label: 'Terms and estimates',
            blurb: 'Preserved proposal version',
            icon: ClipboardList,
        },
        {
            key: 'coverage',
            label: 'Funding and readiness',
            blurb: 'Current checks and allocations',
            icon: Wallet,
        },
        {
            key: 'responses',
            label: 'Responses and approval',
            blurb: 'Separate immutable decisions',
            icon: History,
        },
    ];
    return (
        <WizardShell
            open
            onClose={onClose}
            title={proposal.terms.title}
            description={`${referral.name} · version ${proposal.version}`}
            railTitle="Support proposal"
            railIcon={ClipboardList}
            railSub={
                current
                    ? 'Current version. Proposal checks do not replace onboarding and service-start checks.'
                    : 'Historical version. Responses to this version cannot approve changed terms.'
            }
            steps={steps}
            stepIndex={section}
            headerLabel={steps[section].label}
            bodyRef={body}
            onStepClick={(index) => {
                setSection(index);
                requestAnimationFrame(() => {
                    body.current?.scrollTo({ top: 0 });
                    body.current
                        ?.querySelector<HTMLElement>('[data-heading]')
                        ?.focus();
                });
            }}
            footerEnd={
                <Button variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
        >
            <div className="space-y-5">
                <h2 data-heading tabIndex={-1} className="text-section-title">
                    {steps[section].label}
                </h2>
                {!current && (
                    <p className="text-subtle">
                        This version is retained as history. Use the current
                        proposal for new acceptance, approval and agreement
                        preparation.
                    </p>
                )}
                {section === 0 && <ProposalValues proposal={proposal} />}
                {section === 1 && (
                    <>
                        {current && readiness && (
                            <>
                                <StatusBadge
                                    variant={
                                        readiness.ready ? 'success' : 'warning'
                                    }
                                >
                                    {readiness.ready
                                        ? 'Proposal checks satisfied'
                                        : 'Proposal review needed'}
                                </StatusBadge>
                                {readiness.issues.length > 0 && (
                                    <Alert variant="warning">
                                        <AlertDescription>
                                            <ul className="list-disc space-y-2 pl-4">
                                                {readiness.issues.map(
                                                    (issue) => (
                                                        <li key={issue}>
                                                            {issue}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        </AlertDescription>
                                    </Alert>
                                )}
                                <ReviewCard
                                    title="Separate decisions"
                                    icon={ClipboardCheck}
                                >
                                    <ReviewRow
                                        label="Internal approval"
                                        value={
                                            readiness.internal_approval_current
                                                ? 'Current'
                                                : 'Required / needs review'
                                        }
                                    />
                                    <ReviewRow
                                        label="Person’s acceptance"
                                        value={
                                            readiness.person_acceptance_current
                                                ? 'Current'
                                                : 'Required / needs review'
                                        }
                                    />
                                    {readiness.private_payers.map((payer) => (
                                        <ReviewRow
                                            key={payer.funding_case_id}
                                            label={`Private payer: ${payer.payer}`}
                                            value={
                                                payer.accepted
                                                    ? 'Accepted'
                                                    : 'Response required'
                                            }
                                        />
                                    ))}
                                </ReviewCard>
                            </>
                        )}
                        {proposal.terms.allocations.length === 0 && (
                            <EmptyState
                                icon={Wallet}
                                title="No funding allocations"
                                description="Funding may still be pending. An onboarding plan can retain the outstanding work; service commencement requires valid funding or an explicitly approved alternative arrangement."
                            />
                        )}
                        {proposal.terms.allocations.map((allocation, index) => {
                            const line = proposal.terms.lines.find(
                                (entry) => entry.key === allocation.line_key,
                            );
                            const funding = referral.funding?.cases.data.find(
                                (entry) =>
                                    entry.id === allocation.funding_case_id,
                            );
                            const award =
                                funding?.latest_decision?.id ===
                                allocation.decision_id
                                    ? funding.latest_decision.terms.lines[
                                          allocation.decision_line
                                      ]
                                    : null;
                            return (
                                <ReviewCard
                                    key={index}
                                    title={
                                        line?.description ??
                                        'Support allocation'
                                    }
                                    icon={Wallet}
                                >
                                    <ReviewRow
                                        label="Funding pathway"
                                        value={
                                            funding?.pathway_label ??
                                            'Recorded funding source · see funding history'
                                        }
                                    />
                                    <ReviewRow
                                        label="Original source quantity"
                                        value={`${allocation.quantity}${award ? ` ${award.unit === 'money' ? award.currency : award.unit === 'other' ? award.unit_label : award.unit} / ${label(award.period)}` : ' in its recorded authorization units'}`}
                                    />
                                    <ReviewRow
                                        label="Support covered"
                                        value={`${allocation.support_quantity} ${line?.unit === 'other' ? line.unit_label : (line?.unit ?? 'units')} / ${label(line?.period ?? 'recorded_period')}`}
                                    />
                                </ReviewCard>
                            );
                        })}
                    </>
                )}
                {section === 2 && (
                    <>
                        {busy && (
                            <div role="status">
                                <LoadingState message="Loading recorded responses…" />
                            </div>
                        )}
                        {error && (
                            <ErrorState
                                title="Responses unavailable"
                                message={error}
                                onRetry={() => setRetry((value) => value + 1)}
                            />
                        )}
                        {reviews?.total === 0 && (
                            <EmptyState
                                icon={History}
                                title="No responses to this version"
                                description="Record the person’s response, any private-payer acceptance and independent internal approval separately."
                            />
                        )}
                        {reviews?.data.map((review) => (
                            <ReviewCard
                                key={review.id}
                                title={`${label(review.kind)} · ${label(review.result)}`}
                                icon={
                                    review.kind === 'internal_approval'
                                        ? ShieldCheck
                                        : UserRound
                                }
                            >
                                <ReviewRow
                                    label="Recorded"
                                    value={formatDateTimeLong(
                                        review.recorded_at,
                                    )}
                                />
                                {[
                                    'signatory',
                                    'capacity',
                                    'authority_note',
                                    'participation',
                                    'note',
                                    'conditions',
                                    'payer_snapshot',
                                ]
                                    .filter(
                                        (key) =>
                                            typeof review.details[key] ===
                                            'string',
                                    )
                                    .map((key) => (
                                        <ReviewRow
                                            key={key}
                                            label={label(key)}
                                            value={String(review.details[key])}
                                        />
                                    ))}
                                {typeof review.details.review_due_at ===
                                    'string' && (
                                    <ReviewRow
                                        label="Review due"
                                        value={formatDateTimeLong(
                                            review.details.review_due_at,
                                        )}
                                    />
                                )}
                            </ReviewCard>
                        ))}
                        {reviews && reviews.last_page > 1 && (
                            <div className="flex items-center justify-between gap-3">
                                <Button
                                    variant="outline"
                                    disabled={busy || page <= 1}
                                    onClick={() =>
                                        setPage((value) => value - 1)
                                    }
                                >
                                    Previous
                                </Button>
                                <span className="text-subtle">
                                    Page {page} of {reviews.last_page}
                                </span>
                                <Button
                                    variant="outline"
                                    disabled={busy || page >= reviews.last_page}
                                    onClick={() =>
                                        setPage((value) => value + 1)
                                    }
                                >
                                    Next
                                </Button>
                            </div>
                        )}
                    </>
                )}
            </div>
        </WizardShell>
    );
}

export function ProposalsPanel({
    referral,
    permissions,
    options,
}: {
    referral: ReferralDetail;
    permissions: Permissions;
    options: Options;
}) {
    const auth = usePage().props.auth as { user?: { id: number } } | undefined;
    const [data, setData] = useState<ProposalData | null>(null);
    const [page, setPage] = useState(1);
    const [refresh, setRefresh] = useState(0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [editing, setEditing] = useState(false);
    const [review, setReview] = useState<'acceptance' | 'approval' | null>(
        null,
    );
    const [view, setView] = useState<SupportProposal | null>(null);
    const [internal, setInternal] = useState<SupportProposal | null>(null);
    const versionMenu = useEntityContextMenu<SupportProposal>();
    /** One MenuItem[] feeds each version card's kebab and right-click menu. */
    const versionActions = (proposal: SupportProposal): MenuItem[] => [
        {
            label: 'View proposal and responses',
            icon: Eye,
            onClick: () => setView(proposal),
        },
        {
            label: 'Company funding and decisions',
            icon: Wallet,
            onClick: () => setInternal(proposal),
        },
    ];
    useEffect(() => {
        const controller = new AbortController();
        setBusy(true);
        setError('');
        axios
            .get<ProposalData>(
                `${referralHref(referral.reference)}/proposals`,
                { params: { page }, signal: controller.signal },
            )
            .then((response) => setData(response.data))
            .catch((caught) => {
                if (!axios.isCancel(caught)) {
                    setData(null);
                    setError(
                        'Proposals could not be loaded. Check current access or try again.',
                    );
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) setBusy(false);
            });
        return () => controller.abort();
    }, [referral.reference, referral.version, page, refresh]);
    const canEdit =
        permissions['proposals.manage'] &&
        referral.stage !== 'closed' &&
        !referral.general_enquiry;
    const canApprove =
        permissions['proposals.approve'] &&
        referral.stage !== 'closed' &&
        data?.current &&
        data.current.authored_by !== auth?.user?.id;
    const reload = () => {
        setEditing(false);
        setReview(null);
        setPage(1);
        setRefresh((value) => value + 1);
    };
    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-section-title">Support proposals</h2>
                    <p className="text-subtle">
                        Versioned terms, separate acceptance and independent
                        approval
                    </p>
                </div>
                {canEdit && (
                    <Button
                        disabled={busy || !data}
                        onClick={() => setEditing(true)}
                    >
                        {data?.current ? (
                            <Pencil className="size-4" />
                        ) : (
                            <Plus className="size-4" />
                        )}
                        {data?.current ? 'Revise proposal' : 'Prepare proposal'}
                    </Button>
                )}
            </div>
            {busy && (
                <div role="status">
                    <LoadingState message="Loading proposal checks…" />
                </div>
            )}
            {error && (
                <ErrorState
                    title="Proposals unavailable"
                    message={error}
                    onRetry={() => setRefresh((value) => value + 1)}
                />
            )}
            {data && !data.current && (
                <EmptyState
                    icon={ClipboardList}
                    title="No support proposal yet"
                    description="Prepare the support lines and funding position with the person. Unknown costs can remain blank while information is being gathered."
                />
            )}
            {data?.current && (
                <ReviewCard
                    title={`Current proposal · version ${data.current.version}`}
                    icon={ClipboardList}
                >
                    <ReviewRow label="Title" value={data.current.terms.title} />
                    <ReviewRow
                        label="Proposal checks"
                        value={
                            data.readiness?.ready
                                ? 'Satisfied — agreement and service-start checks still apply'
                                : `${data.readiness?.issues.length ?? 0} ${(data.readiness?.issues.length ?? 0) === 1 ? 'item needs' : 'items need'} review`
                        }
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => setInternal(data.current)}
                        >
                            <Wallet className="size-4" />
                            Temporary company funding
                        </Button>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => setView(data.current)}
                        >
                            <Eye className="size-4" />
                            Review current proposal
                        </Button>
                        {canEdit && (
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => setReview('acceptance')}
                            >
                                <UserRound className="size-4" />
                                Record acceptance
                            </Button>
                        )}
                        {canApprove && (
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => setReview('approval')}
                            >
                                <ShieldCheck className="size-4" />
                                Independent approval
                            </Button>
                        )}
                    </div>
                    {permissions['proposals.approve'] &&
                        data.current.authored_by === auth?.user?.id && (
                            <p className="text-subtle">
                                Another authorized colleague must approve the
                                proposal you prepared.
                            </p>
                        )}
                </ReviewCard>
            )}
            {data?.current && (
                <Card>
                    <CardHeader>
                        <CardTitle>Service agreement</CardTitle>
                        <p className="text-subtle">
                            The approved and accepted proposal becomes the
                            person’s draft agreement. Finance approval makes it
                            active.
                        </p>
                    </CardHeader>
                    <CardContent>
                        <ProposalAgreementCheck
                            referral={referral}
                            refreshKey={refresh}
                        />
                    </CardContent>
                </Card>
            )}
            {data?.proposals.data.length ? (
                <>
                    <h3 className="text-section-title">Recorded versions</h3>
                    <EntityCardGrid>
                        {data.proposals.data.map((proposal) => (
                            <EntityCard
                                key={proposal.id}
                                name={proposal.terms.title}
                                meridian="neutral"
                                icon={ClipboardList}
                                subline={`Version ${proposal.version}`}
                                onOpen={() => setView(proposal)}
                                onContextMenu={(event) =>
                                    versionMenu.open(event, proposal)
                                }
                                actions={versionActions(proposal)}
                                chips={
                                    <EntityChip>
                                        {proposal.id === data.latest_id
                                            ? 'Current version'
                                            : 'Historical version'}
                                    </EntityChip>
                                }
                                footer={{
                                    primary: `${proposal.terms.lines.length} support ${proposal.terms.lines.length === 1 ? 'line' : 'lines'}`,
                                    secondary: formatDateTimeLong(
                                        proposal.authored_at,
                                    ),
                                }}
                            />
                        ))}
                    </EntityCardGrid>
                    {data.proposals.last_page > 1 && (
                        <div className="flex items-center justify-between gap-3">
                            <Button
                                variant="outline"
                                disabled={busy || page <= 1}
                                onClick={() => setPage((value) => value - 1)}
                            >
                                <ChevronLeft className="size-4" />
                                Previous
                            </Button>
                            <span className="text-subtle">
                                Page {page} of {data.proposals.last_page}
                            </span>
                            <Button
                                variant="outline"
                                disabled={
                                    busy || page >= data.proposals.last_page
                                }
                                onClick={() => setPage((value) => value + 1)}
                            >
                                Next
                                <ChevronRight className="size-4" />
                            </Button>
                        </div>
                    )}
                </>
            ) : null}
            {versionMenu.ctx ? (
                <EntityContextMenu
                    x={versionMenu.ctx.x}
                    y={versionMenu.ctx.y}
                    icon={ClipboardList}
                    title={versionMenu.ctx.record.terms.title}
                    items={versionActions(versionMenu.ctx.record)}
                    onClose={versionMenu.close}
                />
            ) : null}
            {editing && data && (
                <ProposalEditDialog
                    referral={referral}
                    current={data.current}
                    taxes={data.tax_rates}
                    options={options}
                    onClose={reload}
                />
            )}
            {internal && (
                <InternalFundingDialog
                    referral={referral}
                    proposal={internal}
                    permissions={permissions}
                    actorId={auth?.user?.id}
                    onClose={() => {
                        setInternal(null);
                        reload();
                    }}
                />
            )}
            {review && data?.current && (
                <ProposalReviewDialog
                    mode={review}
                    referral={referral}
                    proposal={data.current}
                    readiness={data.readiness}
                    onClose={reload}
                />
            )}
            {view && data && (
                <ProposalDetail
                    proposal={view}
                    referral={referral}
                    current={view.id === data.latest_id}
                    readiness={data.readiness}
                    onClose={() => setView(null)}
                />
            )}
        </div>
    );
}
