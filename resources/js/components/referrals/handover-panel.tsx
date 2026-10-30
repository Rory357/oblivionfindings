import type { ContactProfile } from '@/components/clients/contact-review-fields';
import {
    EntityCard,
    EntityCardGrid,
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { LoadingState } from '@/components/ui/loading-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateLong, formatDateTimeLong } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import {
    CheckCircle2,
    ClipboardCheck,
    RefreshCw,
    Scale,
    UserRound,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { CareHandoverDialog } from './care-handover-dialog';
import { HandoverDialog } from './handover-dialog';
import type { ProfileHandoverField } from './profile-handover-fields';
import { ProposalAgreementCheck } from './proposal-agreement';
import type { ProposalData } from './proposal-types';
import { referralHref, type ReferralDetail } from './types';

export type HandoverPreview = {
    reference: string;
    version: number;
    checked_at: string;
    preparation_checks_passed: boolean;
    target_bound?: boolean;
    checks: {
        key: string;
        label: string;
        passed: boolean;
        detail: string;
        section: string;
    }[];
    matches: {
        checked: boolean;
        review_required: boolean;
        restricted_review_required: boolean;
        more_results: boolean;
        /** An identity reviewer (duplicates.manage) may continue past hidden matches with a recorded reason. */
        can_record_restricted_review?: boolean;
        candidates: {
            id: number;
            name: string;
            date_of_birth: string | null;
            /** Archived people are shown so they are not recreated; restore before linking. */
            archived?: boolean;
            can_link: boolean;
        }[];
    };
    target: {
        id: number;
        name: string;
        status: string;
        existing_details_preserved: boolean;
        fingerprint: string;
    } | null;
    fields: {
        key: string;
        label: string;
        submitted: string | Record<string, string | boolean | null> | null;
        current: string | null;
        destination: string;
    }[];
    profile_fields?: ProfileHandoverField[];
    contact_profile?: ContactProfile;
    can_release_documents?: boolean;
    can_release_contacts?: boolean;
    people_contacts?: import('./people-contacts').PeopleContact[];
    service_contexts: { id: number; name: string; type: string }[];
    funding: {
        position: string | null;
        proposal_version: number | null;
        open_cases: number;
        personal_income_cases: number;
        proposal_readiness: ProposalData['readiness'];
    };
    open_work: number;
    conversion: {
        can_refresh_care?: boolean;
        workflow_id?: number | null;
        converted_at: string;
        created_person: boolean;
        person: { id: number; name: string } | null;
    } | null;
};

const contactLabels: Record<string, string> = {
    name: 'Contact name',
    phone: 'Phone',
    email: 'Email',
    channel: 'Preferred channel',
    safe_time: 'Safe time',
    leave_message: 'May leave a message',
    communication: 'Communication preferences',
};
function SourceValue({
    value,
    date = false,
}: {
    value: HandoverPreview['fields'][number]['submitted'];
    date?: boolean;
}) {
    if (value === null || value === '')
        return <span className="text-muted-foreground">Not recorded</span>;
    if (typeof value === 'string')
        return <span>{date ? formatDateLong(value) : value}</span>;
    return (
        <dl className="space-y-2">
            {Object.entries(value).map(([key, item]) => (
                <div key={key}>
                    <dt className="text-caption text-muted-foreground">
                        {contactLabels[key] ?? key}
                    </dt>
                    <dd>
                        {typeof item === 'boolean'
                            ? item
                                ? 'Yes'
                                : 'No'
                            : item || 'Not recorded'}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

export function HandoverPanel({
    referral,
    onSection,
}: {
    referral: ReferralDetail;
    onSection: (section: string) => void;
}) {
    const [preview, setPreview] = useState<HandoverPreview | null>(null);
    const [target, setTarget] = useState<number | null>(null);
    const [retry, setRetry] = useState(0);
    const [busy, setBusy] = useState(true);
    const [error, setError] = useState('');
    const [reviewing, setReviewing] = useState<HandoverPreview | null>(null);
    const [careReview, setCareReview] = useState(false);
    type Candidate = HandoverPreview['matches']['candidates'][number];
    const candidateMenu = useEntityContextMenu<Candidate>();
    /** One MenuItem[] for each possible match's kebab and right-click menu. */
    const candidateActions = (person: Candidate): MenuItem[] =>
        preview?.target?.id === person.id && !preview.target_bound
            ? [
                  {
                      label: 'Return to new-person preview',
                      icon: UserRound,
                      onClick: () => setTarget(null),
                  },
              ]
            : [
                  {
                      label: 'Compare with this person',
                      icon: Scale,
                      onClick: () => setTarget(person.id),
                      disabled: preview?.target_bound
                          ? 'This case is already linked to its existing person'
                          : person.archived
                            ? 'This person is archived. Restore their record before linking'
                            : person.can_link
                              ? undefined
                              : 'You need permission to update this person’s record',
                  },
              ];
    /** The "Service agreement" handover check, read from the real agreement (Lane G). */
    const agreementCheck = (
        <Card>
            <CardHeader>
                <CardTitle>Service agreement</CardTitle>
                <p className="text-subtle">
                    Made from the approved and accepted proposal after the
                    handover. It starts as a draft; starting support needs it
                    approved and active.
                </p>
            </CardHeader>
            <CardContent>
                <ProposalAgreementCheck referral={referral} />
            </CardContent>
        </Card>
    );
    // Hidden matches block handover unless the viewer is an identity reviewer, who records a reason.
    const hiddenMatchesResolvable = (matches: HandoverPreview['matches']) =>
        (!matches.restricted_review_required && !matches.more_results) ||
        matches.can_record_restricted_review === true;
    useEffect(() => {
        const controller = new AbortController();
        setBusy(true);
        setError('');
        setPreview(null);
        axios
            .get<HandoverPreview>(
                `${referralHref(referral.reference)}/handover`,
                {
                    params: target ? { target_id: target } : {},
                    signal: controller.signal,
                },
            )
            .then(({ data }) => {
                if (!controller.signal.aborted) setPreview(data);
            })
            .catch((caught) => {
                if (!controller.signal.aborted && !axios.isCancel(caught))
                    setError(
                        'The handover preview could not be loaded. Your access or the person’s details may have changed. Refresh to check again.',
                    );
            })
            .finally(() => {
                if (!controller.signal.aborted) setBusy(false);
            });
        return () => controller.abort();
    }, [referral.reference, referral.version, retry, target]);
    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-section-title">
                        {preview?.conversion || referral.stage === 'converted'
                            ? 'Onboarding handover'
                            : 'Prepare onboarding'}
                    </h2>
                    <p className="text-subtle">
                        {preview?.conversion || referral.stage === 'converted'
                            ? 'Keep the onboarding team’s reviewed care information current.'
                            : 'Review the handover before creating or linking a supported person.'}
                    </p>
                </div>
                {preview &&
                    !preview.conversion &&
                    preview.preparation_checks_passed &&
                    hiddenMatchesResolvable(preview.matches) &&
                    preview.version === referral.version && (
                        <Button
                            disabled={busy}
                            onClick={() => setReviewing(preview)}
                        >
                            <ClipboardCheck className="size-4" />
                            Review handover
                        </Button>
                    )}
                {preview?.conversion?.can_refresh_care && (
                    <Button disabled={busy} onClick={() => setCareReview(true)}>
                        Update care handover
                    </Button>
                )}
                {preview?.conversion?.workflow_id && (
                    <Button asChild variant="outline">
                        <Link
                            href={`/operations/onboarding/${preview.conversion.workflow_id}`}
                        >
                            Open onboarding
                        </Link>
                    </Button>
                )}
                <Button
                    variant="outline"
                    disabled={busy}
                    onClick={() => setRetry((value) => value + 1)}
                >
                    <RefreshCw className="size-4" />
                    Refresh checks
                </Button>
            </div>
            {busy && (
                <div role="status">
                    <LoadingState message="Checking the referral and permitted person records…" />
                </div>
            )}
            {error && (
                <ErrorState
                    title="Handover preview unavailable"
                    message={error}
                    onRetry={() => setRetry((value) => value + 1)}
                />
            )}
            {!busy && preview && (
                <>
                    {preview.conversion ? (
                        <Alert variant="success">
                            <AlertTitle>Handover already recorded</AlertTitle>
                            <AlertDescription>
                                Completed{' '}
                                {formatDateTimeLong(
                                    preview.conversion.converted_at,
                                )}
                                .{' '}
                                {preview.conversion.person ? (
                                    <Button variant="link" asChild>
                                        <Link
                                            href={`/operations/clients/${preview.conversion.person.id}`}
                                        >
                                            Open{' '}
                                            {preview.conversion.person.name}
                                        </Link>
                                    </Button>
                                ) : (
                                    'A person record is linked. Your current permissions do not allow opening it.'
                                )}
                            </AlertDescription>
                        </Alert>
                    ) : (
                        <p className="text-subtle">
                            Onboarding and starting support are separate. This
                            preview does not create or change a person. Funding
                            may still be pending when onboarding is prepared.
                            Starting support needs a separate approved
                            agreement, funding or payer arrangement, current
                            care and safety checks, staffing and manager
                            approval.
                        </p>
                    )}
                    {preview.version !== referral.version && (
                        <Alert variant="warning">
                            <AlertDescription>
                                The referral changed since this page opened.
                                Refresh the referral before making any handover
                                decision.
                            </AlertDescription>
                        </Alert>
                    )}
                    {preview.conversion && agreementCheck}
                    <Card>
                        <CardHeader>
                            <CardTitle>Preparation checks</CardTitle>
                            <p className="text-subtle">
                                Checked {formatDateTimeLong(preview.checked_at)}
                                . These checks do not replace the final evidence
                                and duplicate review.
                            </p>
                        </CardHeader>
                        <CardContent>
                            <ul className="divide-y">
                                {preview.checks.map((check) => (
                                    <li
                                        key={check.key}
                                        className="flex flex-wrap items-start gap-3 py-3"
                                    >
                                        {check.passed ? (
                                            <CheckCircle2
                                                className="mt-0.5 size-4 shrink-0 text-status-success"
                                                aria-hidden
                                            />
                                        ) : (
                                            <ClipboardCheck
                                                className="mt-0.5 size-4 shrink-0 text-status-warning"
                                                aria-hidden
                                            />
                                        )}
                                        <div className="min-w-0 flex-1">
                                            <p className="flex flex-wrap items-center gap-2 font-medium">
                                                {check.label}
                                                <StatusBadge
                                                    size="sm"
                                                    variant={
                                                        check.passed
                                                            ? 'success'
                                                            : 'warning'
                                                    }
                                                >
                                                    {check.passed
                                                        ? 'Checked'
                                                        : 'Needs review'}
                                                </StatusBadge>
                                            </p>
                                            <p className="text-subtle">
                                                {check.detail}
                                            </p>
                                        </div>
                                        {!check.passed && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    onSection(check.section)
                                                }
                                                aria-label={`Review ${check.label}`}
                                            >
                                                Review
                                                <span className="sr-only">
                                                    {' '}
                                                    {check.label}
                                                </span>
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                    <section
                        className="space-y-4"
                        aria-labelledby="handover-existing-person"
                    >
                        <h3
                            id="handover-existing-person"
                            className="text-section-title"
                        >
                            Existing person review
                        </h3>
                        <p className="text-subtle">
                            {preview.target_bound
                                ? 'This internal support case belongs to the existing person below. Review their identity and current information; this handover cannot create another person or change that link.'
                                : 'Names identify possible matches only. Review identity before linking; a shared family phone or email does not identify the person.'}
                        </p>
                        {!preview.matches.checked && (
                            <EmptyState
                                variant="inline"
                                icon={UserRound}
                                title="Complete the person’s names before checking possible matches."
                            />
                        )}
                        {preview.matches.checked &&
                            !preview.target_bound &&
                            !preview.matches.review_required && (
                                <EmptyState
                                    variant="inline"
                                    icon={UserRound}
                                    title="No exact-name candidates were found. Complete the identity review before choosing a new person."
                                />
                            )}
                        {(preview.matches.restricted_review_required ||
                            preview.matches.more_results) && (
                            <Alert variant="warning">
                                <AlertTitle>
                                    {preview.matches
                                        .can_record_restricted_review
                                        ? 'Possible matches you cannot open'
                                        : 'Authorized identity review needed'}
                                </AlertTitle>
                                <AlertDescription>
                                    {preview.matches.restricted_review_required
                                        ? 'A possible match cannot be shown with your current access.'
                                        : 'More people share this name than can be listed.'}{' '}
                                    {preview.matches
                                        .can_record_restricted_review
                                        ? 'As an identity reviewer you can continue: the handover review asks why this is a new person, and the reason is kept on the receipt.'
                                        : 'Ask an authorized identity reviewer to resolve it before creating another person.'}
                                </AlertDescription>
                            </Alert>
                        )}
                        <EntityCardGrid>
                            {preview.matches.candidates.map((person) => (
                                <EntityCard
                                    key={person.id}
                                    name={person.name}
                                    icon={UserRound}
                                    meridian="neutral"
                                    subline={
                                        person.date_of_birth
                                            ? `Born ${formatDateLong(person.date_of_birth)}`
                                            : 'Birth date not recorded'
                                    }
                                    actions={candidateActions(person)}
                                    onContextMenu={(event) =>
                                        candidateMenu.open(event, person)
                                    }
                                    onOpen={
                                        person.can_link && !preview.target_bound
                                            ? () => setTarget(person.id)
                                            : undefined
                                    }
                                    linkLabel="Compare"
                                    footer={{
                                        primary:
                                            preview.target?.id === person.id
                                                ? 'Previewing this person'
                                                : preview.target_bound
                                                  ? 'Identity review only'
                                                  : person.archived
                                                    ? 'Archived · restore before linking'
                                                    : person.can_link
                                                      ? 'Review identity before linking'
                                                      : 'Link permission required',
                                    }}
                                />
                            ))}
                        </EntityCardGrid>
                        {preview.target && (
                            <div className="space-y-2">
                                <p>
                                    Comparing with{' '}
                                    <strong>{preview.target.name}</strong>.
                                    Their existing details and current status
                                    will be retained.
                                </p>
                                {!preview.target_bound && (
                                    <Button
                                        variant="outline"
                                        onClick={() => setTarget(null)}
                                    >
                                        Return to new-person preview
                                    </Button>
                                )}
                            </div>
                        )}
                    </section>
                    <Card>
                        <CardHeader>
                            <CardTitle>Information to review</CardTitle>
                            <p className="text-subtle">
                                Submitted information stays distinct from
                                verified facts. Contact details may belong to a
                                referrer.
                            </p>
                        </CardHeader>
                        <CardContent>
                            <dl className="divide-y">
                                {preview.fields.map((field) => (
                                    <div
                                        key={field.key}
                                        className="space-y-2 py-4"
                                    >
                                        <dt className="font-medium">
                                            {field.label}
                                        </dt>
                                        <dd className="grid gap-4 text-sm break-words whitespace-pre-wrap sm:grid-cols-2">
                                            <div>
                                                <p className="text-caption mb-1 text-muted-foreground">
                                                    Referral information
                                                </p>
                                                <SourceValue
                                                    value={field.submitted}
                                                    date={
                                                        field.key ===
                                                        'date_of_birth'
                                                    }
                                                />
                                            </div>
                                            {preview.target && (
                                                <div>
                                                    <p className="text-caption mb-1 text-muted-foreground">
                                                        Existing person
                                                    </p>
                                                    <SourceValue
                                                        value={field.current}
                                                        date={
                                                            field.key ===
                                                            'date_of_birth'
                                                        }
                                                    />
                                                </div>
                                            )}
                                        </dd>
                                        <dd className="text-subtle">
                                            {field.destination}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </CardContent>
                    </Card>
                    <div className="grid gap-5 lg:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    Funding and continuing work
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <p className="text-sm whitespace-pre-wrap">
                                    {preview.funding.position ||
                                        'Record the current funding position in a support proposal.'}
                                </p>
                                <p className="text-subtle">
                                    {preview.funding.open_cases} open funding
                                    cases · {preview.open_work} open follow-ups.
                                    These remain owned after handover.
                                </p>
                                {preview.funding.personal_income_cases > 0 && (
                                    <p className="text-subtle">
                                        Personal income assistance remains
                                        separate from the provider’s service
                                        funding.
                                    </p>
                                )}
                                <Button
                                    variant="outline"
                                    onClick={() => onSection('funding')}
                                >
                                    Review funding assistance
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => onSection('work')}
                                >
                                    Review continuing work
                                </Button>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>Support arrangement</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <p>
                                    {preview.funding.proposal_readiness?.ready
                                        ? 'The current proposal checks pass. This does not authorize starting support.'
                                        : 'The support proposal still needs review before support can start.'}
                                </p>
                                <Button
                                    variant="outline"
                                    onClick={() => onSection('proposals')}
                                >
                                    Review proposal and responses
                                </Button>
                                <p className="text-subtle">
                                    Available service settings for this site:
                                </p>
                                <ul className="space-y-1 text-sm">
                                    {preview.service_contexts.map((context) => (
                                        <li key={context.id}>
                                            {context.name} · {context.type}
                                        </li>
                                    ))}
                                </ul>
                                {!preview.service_contexts.length && (
                                    <EmptyState
                                        variant="inline"
                                        icon={ClipboardCheck}
                                        title="No active service setting is configured for this site yet."
                                    />
                                )}
                                <Button
                                    variant="outline"
                                    onClick={() => onSection('documents')}
                                >
                                    Review handover documents
                                </Button>
                            </CardContent>
                        </Card>
                    </div>
                    {!preview.conversion && agreementCheck}
                </>
            )}
            {candidateMenu.ctx ? (
                <EntityContextMenu
                    x={candidateMenu.ctx.x}
                    y={candidateMenu.ctx.y}
                    icon={UserRound}
                    title={candidateMenu.ctx.record.name}
                    items={candidateActions(candidateMenu.ctx.record)}
                    onClose={candidateMenu.close}
                />
            ) : null}
            {reviewing && (
                <HandoverDialog
                    referral={referral}
                    preview={reviewing}
                    onClose={() => {
                        setReviewing(null);
                        setRetry((value) => value + 1);
                    }}
                />
            )}
            {careReview && (
                <CareHandoverDialog
                    reference={referral.reference}
                    onClose={() => {
                        setCareReview(false);
                        setRetry((value) => value + 1);
                    }}
                />
            )}
        </div>
    );
}
