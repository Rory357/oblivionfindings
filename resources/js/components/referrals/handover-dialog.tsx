import {
    ContactReviewFields,
    contactDraft,
    contactReviewPayload,
    contactReviewValid,
} from '@/components/clients/contact-review-fields';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import { ClipboardCheck, FileText, UserRound, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    DocumentHandoverFields,
    DocumentHandoverSummary,
    useDocumentHandovers,
} from './document-handover-fields';
import { EvidencePicker } from './evidence-picker';
import type { HandoverPreview } from './handover-panel';
import {
    ProfileHandoverFields,
    profileHandoverPayload,
    profileHandoverValid,
    type ProfileHandoverDraft,
} from './profile-handover-fields';
import {
    SupportContactHandoverFields,
    SupportContactHandoverSummary,
    useSupportContactHandovers,
} from './support-contact-handover';
import { referralHref, type ReferralDetail } from './types';

type Receipt = {
    saved: boolean;
    person: { id: number; name: string } | null;
    created_person: boolean;
    workflow_id?: number | null;
    service_commenced: false;
};

export function HandoverDialog({
    referral,
    preview,
    onClose,
}: {
    referral: ReferralDetail;
    preview: HandoverPreview;
    onClose: () => void;
}) {
    const [step, setStep] = useState(0);
    const [identity, setIdentity] = useState('');
    const [contactEnabled, setContactEnabled] = useState(false);
    const [contact, setContact] = useState(() =>
        contactDraft(preview.contact_profile),
    );
    const [profileDraft, setProfileDraft] = useState<ProfileHandoverDraft>({
        values: {},
        note: '',
        confirmed: false,
    });
    const [decisions, setDecisions] = useState<
        Record<number, { conclusion: string; reason: string }>
    >({});
    const [context, setContext] = useState('');
    const [owner, setOwner] = useState('');
    const [selectedOwner, setSelectedOwner] = useState<{
        id: number;
        name: string;
    } | null>(null);
    const [search, setSearch] = useState('');
    const [staff, setStaff] = useState<{ id: number; name: string }[]>([]);
    const [staffError, setStaffError] = useState('');
    const [note, setNote] = useState('');
    const [evidence, setEvidence] = useState<number[]>([]);
    const documents = useDocumentHandovers();
    const supportContacts = useSupportContactHandovers();
    const [careHandover, setCareHandover] = useState(false);
    const [pendingWork, setPendingWork] = useState(false);
    const [separateStart, setSeparateStart] = useState(false);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [conflict, setConflict] = useState(false);
    const [receipt, setReceipt] = useState<Receipt | null>(null);
    const [discard, setDiscard] = useState(false);
    // Matches the reviewer cannot open (or beyond the listed 25) are never described; an
    // identity reviewer continues only with a recorded reason (kept on the handover receipt).
    const hiddenMatches =
        preview.matches.restricted_review_required ||
        preview.matches.more_results;
    const [hiddenReason, setHiddenReason] = useState('');
    const [hiddenAcknowledged, setHiddenAcknowledged] = useState(false);
    const body = useRef<HTMLDivElement>(null);
    const issued = useRef<Record<string, unknown> | null>(null);
    const sending = useRef(false);
    const steps = [
        {
            key: 'identity',
            label: 'Identity review',
            blurb: 'Person and possible matches',
            icon: UserRound,
        },
        {
            key: 'handover',
            label: 'Service and owner',
            blurb: 'Named onboarding responsibility',
            icon: Users,
        },
        {
            key: 'evidence',
            label: 'Handover evidence',
            blurb: 'Current reviewed documents',
            icon: FileText,
        },
        {
            key: 'confirm',
            label: 'Confirm handover',
            blurb: 'Keep open work and separate start',
            icon: ClipboardCheck,
        },
    ];
    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            axios
                .get<{ staff: { id: number; name: string }[] }>(
                    '/operations/referrals/staff-options',
                    {
                        params: {
                            reference: referral.reference,
                            site_id: referral.site_id,
                            purpose: 'onboarding',
                            onboarding_target_id: preview.target?.id,
                            q: search,
                        },
                        signal: controller.signal,
                    },
                )
                .then(({ data }) => {
                    if (!controller.signal.aborted) {
                        setStaff(data.staff);
                        setStaffError('');
                    }
                })
                .catch(() => {
                    if (!controller.signal.aborted) {
                        setStaff([]);
                        setStaffError(
                            'Eligible staff could not be loaded. Change the search to try again.',
                        );
                    }
                });
        }, 250);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [referral.reference, referral.site_id, search, preview.target?.id]);
    function go(index: number) {
        setStep(index);
        requestAnimationFrame(() => {
            body.current?.scrollTo({ top: 0 });
            body.current?.querySelector<HTMLElement>('[data-heading]')?.focus();
        });
    }
    function valid(index: number) {
        const invalid =
            index === 0
                ? identity.trim().length < 10 ||
                  !profileHandoverValid(profileDraft) ||
                  (contactEnabled && !contactReviewValid(contact)) ||
                  (hiddenMatches &&
                      (hiddenReason.trim().length < 20 ||
                          !hiddenAcknowledged)) ||
                  preview.matches.candidates.some(
                      (person) =>
                          !decisions[person.id]?.conclusion ||
                          (decisions[person.id]?.reason.trim().length ?? 0) <
                              10 ||
                          (decisions[person.id].conclusion ===
                              'same_person') !==
                              (preview.target?.id === person.id),
                  )
                : index === 1
                  ? !context || !owner || note.trim().length < 10
                  : index === 2
                    ? !evidence.length ||
                      !careHandover ||
                      !documents.valid ||
                      !supportContacts.valid
                    : !pendingWork || !separateStart;
        if (invalid) {
            setError(
                index === 0
                    ? 'Record the identity review and a reason for every possible match, including the matches you cannot open. Only the selected existing person can be confirmed as the same person. Verify and confirm any optional profile details you are adding.'
                    : 'Complete the required details and confirmations before continuing.',
            );
            go(index);
            return false;
        }
        setError('');
        return true;
    }
    async function save() {
        if (sending.current || conflict) return;
        if (!uncertain) {
            for (let index = 0; index < 4; index++) if (!valid(index)) return;
            issued.current = {
                version: preview.version,
                target_id: preview.target?.id ?? null,
                target_fingerprint: preview.target?.fingerprint ?? null,
                service_context_id: Number(context),
                onboarding_owner_id: Number(owner),
                identity_review: identity,
                handover_note: note,
                evidence_ids: evidence,
                acknowledge_care_handover: careHandover,
                duplicate_decisions: preview.matches.candidates.map(
                    (person) => ({
                        client_id: person.id,
                        ...decisions[person.id],
                    }),
                ),
                acknowledge_pending_work: pendingWork,
                acknowledge_separate_start: separateStart,
                ...(hiddenMatches
                    ? {
                          restricted_match_review: {
                              reason: hiddenReason,
                              acknowledged: hiddenAcknowledged,
                          },
                      }
                    : {}),
                ...(documents.reviews.length
                    ? { document_handover: documents.reviews }
                    : {}),
                ...(supportContacts.reviews.length
                    ? { support_contacts: supportContacts.reviews }
                    : {}),
                ...(contactEnabled
                    ? { contact_handover: contactReviewPayload(contact) }
                    : {}),
                ...(profileHandoverPayload(profileDraft)
                    ? { profile_handover: profileHandoverPayload(profileDraft) }
                    : {}),
            };
        }
        sending.current = true;
        setBusy(true);
        setError('');
        try {
            const response = await axios.post<Receipt>(
                `${referralHref(referral.reference)}/handover`,
                issued.current,
            );
            setReceipt(response.data);
            router.reload({ only: ['referral'], preserveScroll: true });
        } catch (caught) {
            const status = axios.isAxiosError(caught)
                ? caught.response?.status
                : undefined;
            setUncertain(!status || status >= 500);
            setConflict(status === 409 || status === 403 || status === 404);
            setError(
                axios.isAxiosError(caught)
                    ? (caught.response?.data?.message ??
                          'The handover result was not confirmed. Retry the same handover or close and check its receipt.')
                    : 'The handover result was not confirmed. Retry the same handover or check its receipt.',
            );
        } finally {
            sending.current = false;
            setBusy(false);
        }
    }
    return (
        <>
            <WizardShell
                open
                onClose={() =>
                    !busy && (receipt ? onClose() : setDiscard(true))
                }
                title="Prepare supported-person onboarding"
                description={referral.name}
                railIcon={UserRound}
                railTitle="Onboarding handover"
                railSub="Prepare the person’s record and carry continuing work forward. Starting services is a separate approved action."
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!busy && !uncertain && (index < step || valid(step)))
                        go(index);
                }}
                bodyRef={body}
                footerStart={
                    <Button
                        variant="ghost"
                        disabled={busy}
                        onClick={() =>
                            step && !uncertain ? go(step - 1) : setDiscard(true)
                        }
                    >
                        {step && !uncertain ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    step < 3 ? (
                        <Button
                            disabled={busy || conflict || uncertain}
                            onClick={() => valid(step) && go(step + 1)}
                        >
                            Continue
                        </Button>
                    ) : (
                        <Button
                            disabled={busy || conflict}
                            onClick={() => void save()}
                        >
                            {busy
                                ? 'Preparing onboarding…'
                                : uncertain
                                  ? 'Retry same handover'
                                  : 'Prepare onboarding'}
                        </Button>
                    )
                }
                success={
                    receipt ? (
                        <WizardSuccessPane
                            title="Onboarding prepared"
                            blurb="The handover receipt is saved. Outstanding funding assistance and follow-ups remain open. Services have not been started."
                            actions={
                                <>
                                    <Button onClick={onClose}>
                                        View handover
                                    </Button>
                                    {receipt.workflow_id && (
                                        <Button variant="outline" asChild>
                                            <Link
                                                href={`/operations/onboarding/${receipt.workflow_id}`}
                                            >
                                                Open onboarding
                                            </Link>
                                        </Button>
                                    )}
                                    {receipt.person && (
                                        <Button variant="outline" asChild>
                                            <Link
                                                href={`/operations/clients/${receipt.person.id}`}
                                            >
                                                Open {receipt.person.name}
                                            </Link>
                                        </Button>
                                    )}
                                </>
                            }
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    <h2
                        data-heading
                        tabIndex={-1}
                        className="text-section-title"
                    >
                        {steps[step].label}
                    </h2>
                    {error && (
                        <Alert variant="warning">
                            <AlertDescription>
                                {error}
                                {conflict && (
                                    <p>
                                        Close and refresh the handover preview
                                        before making another decision.
                                    </p>
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
                    <fieldset
                        disabled={busy || uncertain || conflict}
                        className="space-y-5"
                    >
                        {step === 0 && (
                            <>
                                <ReviewCard
                                    title="Person being prepared"
                                    icon={UserRound}
                                >
                                    <ReviewRow
                                        label="Action"
                                        value={
                                            preview.target
                                                ? `Link ${preview.target.name}; preserve existing details`
                                                : 'Create a person in onboarding'
                                        }
                                    />
                                    <ReviewRow
                                        label="Identity"
                                        value={referral.name}
                                    />
                                </ReviewCard>
                                <Field label="How was identity verified?">
                                    <Textarea
                                        value={identity}
                                        onChange={(event) =>
                                            setIdentity(event.target.value)
                                        }
                                        maxLength={3000}
                                    />
                                </Field>
                                {preview.matches.candidates.map((person) => (
                                    <ReviewCard
                                        key={person.id}
                                        title={person.name}
                                        icon={UserRound}
                                    >
                                        <Field label="Identity conclusion">
                                            <SelectInput
                                                value={
                                                    decisions[person.id]
                                                        ?.conclusion ?? ''
                                                }
                                                placeholder="Choose a reviewed conclusion"
                                                options={[
                                                    {
                                                        value: 'different_person',
                                                        label: 'Different person',
                                                    },
                                                    ...(preview.target?.id ===
                                                    person.id
                                                        ? [
                                                              {
                                                                  value: 'same_person',
                                                                  label: 'Same person — link this record',
                                                              },
                                                          ]
                                                        : []),
                                                ]}
                                                onChange={(value) =>
                                                    setDecisions((current) => ({
                                                        ...current,
                                                        [person.id]: {
                                                            reason:
                                                                current[
                                                                    person.id
                                                                ]?.reason ?? '',
                                                            conclusion: value,
                                                        },
                                                    }))
                                                }
                                            />
                                        </Field>
                                        <Field label="Evidence supporting this conclusion">
                                            <Textarea
                                                value={
                                                    decisions[person.id]
                                                        ?.reason ?? ''
                                                }
                                                maxLength={2000}
                                                onChange={(event) =>
                                                    setDecisions((current) => ({
                                                        ...current,
                                                        [person.id]: {
                                                            conclusion:
                                                                current[
                                                                    person.id
                                                                ]?.conclusion ??
                                                                '',
                                                            reason: event.target
                                                                .value,
                                                        },
                                                    }))
                                                }
                                            />
                                        </Field>
                                    </ReviewCard>
                                ))}
                                {hiddenMatches && (
                                    <section
                                        aria-labelledby="hidden-matches-heading"
                                        className="space-y-3 rounded-lg bg-muted p-4"
                                    >
                                        <h3
                                            id="hidden-matches-heading"
                                            className="font-medium"
                                        >
                                            Possible matches you cannot open
                                        </h3>
                                        <p className="text-subtle">
                                            {preview.matches
                                                .restricted_review_required
                                                ? 'At least one person with the same name is outside your access.'
                                                : 'More people share this name than can be listed.'}{' '}
                                            Their details are not shown. Record
                                            why this is a new person; the reason
                                            is kept on the handover receipt.
                                        </p>
                                        <Field label="Why this is not one of those people">
                                            <Textarea
                                                value={hiddenReason}
                                                maxLength={2000}
                                                onChange={(event) =>
                                                    setHiddenReason(
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                        <p className="text-caption text-muted-foreground">
                                            At least 20 characters.
                                        </p>
                                        <label className="flex items-center gap-3 text-sm">
                                            <Checkbox
                                                checked={hiddenAcknowledged}
                                                onCheckedChange={(value) =>
                                                    setHiddenAcknowledged(
                                                        value === true,
                                                    )
                                                }
                                            />
                                            I have reviewed the possible matches
                                            I cannot open and accept
                                            responsibility for continuing.
                                        </label>
                                    </section>
                                )}
                                {preview.contact_profile && (
                                    <details className="rounded-lg bg-muted p-4">
                                        <summary className="min-h-[44px] cursor-pointer font-medium">
                                            Personal contact · optional verified
                                            handover
                                        </summary>
                                        <div className="space-y-4 pt-3">
                                            {preview.contact_profile.can_add ? (
                                                <>
                                                    <label className="flex min-h-[44px] items-center gap-3 text-sm">
                                                        <Checkbox
                                                            checked={
                                                                contactEnabled
                                                            }
                                                            onCheckedChange={(
                                                                value,
                                                            ) => {
                                                                setContactEnabled(
                                                                    value ===
                                                                        true,
                                                                );
                                                                setContact(
                                                                    (
                                                                        current,
                                                                    ) => ({
                                                                        ...current,
                                                                        confirmed: false,
                                                                    }),
                                                                );
                                                            }}
                                                        />
                                                        Include verified
                                                        personal contact details
                                                        and instructions
                                                    </label>
                                                    {contactEnabled && (
                                                        <ContactReviewFields
                                                            draft={contact}
                                                            onChange={
                                                                setContact
                                                            }
                                                            existing={
                                                                preview.contact_profile
                                                            }
                                                        />
                                                    )}
                                                </>
                                            ) : (
                                                <p className="text-subtle">
                                                    Keep the existing contact
                                                    review. Open the person’s
                                                    profile to review or correct
                                                    their contact details and
                                                    instructions.
                                                </p>
                                            )}
                                        </div>
                                    </details>
                                )}
                                <ProfileHandoverFields
                                    fields={preview.profile_fields ?? []}
                                    draft={profileDraft}
                                    onChange={setProfileDraft}
                                />
                            </>
                        )}
                        {step === 1 && (
                            <>
                                <Field label="Service setting">
                                    <SelectInput
                                        value={context}
                                        onChange={setContext}
                                        placeholder="Choose the service setting"
                                        options={preview.service_contexts.map(
                                            (item) => ({
                                                value: String(item.id),
                                                label: `${item.name} · ${item.type}`,
                                            }),
                                        )}
                                    />
                                </Field>
                                <Field label="Find an onboarding owner">
                                    <Input
                                        value={search}
                                        onChange={(event) =>
                                            setSearch(event.target.value)
                                        }
                                        maxLength={80}
                                        placeholder="Search by staff name"
                                    />
                                </Field>
                                <Field label="Onboarding owner">
                                    <SelectInput
                                        value={owner}
                                        onChange={(value) => {
                                            setOwner(value);
                                            setSelectedOwner(
                                                staff.find(
                                                    (person) =>
                                                        String(person.id) ===
                                                        value,
                                                ) ?? selectedOwner,
                                            );
                                        }}
                                        placeholder="Choose eligible staff"
                                        options={(selectedOwner &&
                                        !staff.some(
                                            (person) =>
                                                person.id === selectedOwner.id,
                                        )
                                            ? [selectedOwner, ...staff]
                                            : staff
                                        ).map((person) => ({
                                            value: String(person.id),
                                            label: person.name,
                                        }))}
                                    />
                                </Field>
                                {staffError && (
                                    <p role="status" className="text-subtle">
                                        {staffError}
                                    </p>
                                )}
                                <Field label="Handover instructions and outstanding needs">
                                    <Textarea
                                        value={note}
                                        onChange={(event) =>
                                            setNote(event.target.value)
                                        }
                                        maxLength={4000}
                                    />
                                </Field>
                            </>
                        )}
                        {step === 2 && (
                            <>
                                <ReviewCard
                                    title="Care information for onboarding"
                                    icon={ClipboardCheck}
                                >
                                    {preview.fields
                                        .filter((field) =>
                                            [
                                                'goals',
                                                'participation',
                                                'safety_needs',
                                            ].includes(field.key),
                                        )
                                        .map((field) => (
                                            <ReviewRow
                                                key={field.key}
                                                label={field.label}
                                                value={
                                                    typeof field.submitted ===
                                                    'string'
                                                        ? field.submitted
                                                        : 'Not recorded — review the assessment'
                                                }
                                            />
                                        ))}
                                </ReviewCard>
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="handover-care"
                                        checked={careHandover}
                                        onCheckedChange={(value) =>
                                            setCareHandover(value === true)
                                        }
                                    />
                                    <Label htmlFor="handover-care">
                                        Share these reviewed goals,
                                        participation and safety needs with
                                        authorized onboarding staff for care
                                        planning.
                                    </Label>
                                </div>
                                <p className="text-subtle">
                                    Select the reviewed assessment and authority
                                    evidence for this handover. Unavailable,
                                    replaced or expired documents cannot support
                                    it.
                                </p>
                                <EvidencePicker
                                    reference={referral.reference}
                                    audience="assessment"
                                    selected={evidence}
                                    onChange={(ids, changed) => {
                                        setEvidence(ids);
                                        documents.select(ids, changed);
                                    }}
                                />
                                {preview.can_release_documents && (
                                    <DocumentHandoverFields
                                        documents={documents.selected}
                                        reviews={documents.reviews}
                                        onChange={documents.setReviews}
                                    />
                                )}
                                {preview.can_release_contacts && (
                                    <SupportContactHandoverFields
                                        contacts={preview.people_contacts ?? []}
                                        reviews={supportContacts.reviews}
                                        onChange={supportContacts.setReviews}
                                    />
                                )}
                            </>
                        )}
                        {step === 3 && (
                            <>
                                <DocumentHandoverSummary
                                    reviews={documents.reviews}
                                />
                                <SupportContactHandoverSummary
                                    contacts={preview.people_contacts ?? []}
                                    reviews={supportContacts.reviews}
                                />
                                {contactEnabled && (
                                    <ReviewCard
                                        title="Verified personal contact"
                                        icon={UserRound}
                                    >
                                        <ReviewRow
                                            label="Phone"
                                            value={
                                                contact.phone || 'Not recorded'
                                            }
                                        />
                                        <ReviewRow
                                            label="Email"
                                            value={
                                                contact.email || 'Not recorded'
                                            }
                                        />
                                        <ReviewRow
                                            label="Preferred channel"
                                            value={
                                                contact.channel === 'none'
                                                    ? 'No direct contact'
                                                    : contact.channel
                                            }
                                        />
                                        <ReviewRow
                                            label="Messages"
                                            value={
                                                contact.leave_message === 'yes'
                                                    ? 'Messages permitted'
                                                    : 'Do not leave a message'
                                            }
                                        />
                                        <ReviewRow
                                            label="Safe contact"
                                            value={
                                                contact.safe_time ||
                                                'Not yet recorded; check before contacting'
                                            }
                                        />
                                        <ReviewRow
                                            label="Communication needs"
                                            value={
                                                contact.communication ||
                                                'Not recorded'
                                            }
                                        />
                                        <ReviewRow
                                            label="Verification"
                                            value={contact.verification_note}
                                        />
                                    </ReviewCard>
                                )}
                                {profileHandoverPayload(profileDraft) && (
                                    <ReviewCard
                                        title="Verified profile additions"
                                        icon={UserRound}
                                    >
                                        {Object.entries(
                                            profileHandoverPayload(
                                                profileDraft,
                                            )!.fields,
                                        ).map(([key, value]) => (
                                            <ReviewRow
                                                key={key}
                                                label={
                                                    preview.profile_fields?.find(
                                                        (field) =>
                                                            field.key === key,
                                                    )?.label ?? key
                                                }
                                                value={
                                                    Array.isArray(value)
                                                        ? value.join(', ')
                                                        : value
                                                }
                                            />
                                        ))}
                                        <ReviewRow
                                            label="Verification"
                                            value={profileDraft.note}
                                        />
                                    </ReviewCard>
                                )}
                                <ReviewCard
                                    title="Handover summary"
                                    icon={ClipboardCheck}
                                >
                                    <ReviewRow
                                        label="Person"
                                        value={
                                            preview.target
                                                ? `Link existing: ${preview.target.name}`
                                                : `Create in onboarding: ${referral.name}`
                                        }
                                    />
                                    <ReviewRow
                                        label="Service setting"
                                        value={
                                            preview.service_contexts.find(
                                                (item) =>
                                                    String(item.id) === context,
                                            )?.name
                                        }
                                    />
                                    <ReviewRow
                                        label="Onboarding owner"
                                        value={
                                            selectedOwner?.name ??
                                            'Choose an onboarding owner'
                                        }
                                    />
                                    <ReviewRow
                                        label="Funding position"
                                        value={
                                            preview.funding.position ??
                                            'Not recorded'
                                        }
                                    />
                                    <ReviewRow
                                        label="Continuing follow-ups"
                                        value={String(preview.open_work)}
                                    />
                                    <ReviewRow
                                        label="Identity review"
                                        value={identity}
                                    />
                                    <ReviewRow
                                        label="Handover instructions and outstanding needs"
                                        value={note}
                                    />
                                    <ReviewRow
                                        label="Care information reviewed for handover"
                                        value={
                                            careHandover
                                                ? 'Confirmed'
                                                : 'Not confirmed'
                                        }
                                    />
                                    <ReviewRow
                                        label="Evidence documents"
                                        value={String(evidence.length)}
                                    />
                                </ReviewCard>
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="handover-pending-work"
                                        checked={pendingWork}
                                        onCheckedChange={(value) =>
                                            setPendingWork(value === true)
                                        }
                                    />
                                    <Label htmlFor="handover-pending-work">
                                        Outstanding funding assistance and
                                        follow-ups remain assigned and open
                                        after handover.
                                    </Label>
                                </div>
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="handover-separate-start"
                                        checked={separateStart}
                                        onCheckedChange={(value) =>
                                            setSeparateStart(value === true)
                                        }
                                    />
                                    <Label htmlFor="handover-separate-start">
                                        This prepares onboarding. An approved
                                        agreement, current funding and care
                                        checks, staffing and a separate manager
                                        decision are needed before services
                                        start.
                                    </Label>
                                </div>
                            </>
                        )}
                    </fieldset>
                </div>
            </WizardShell>
            <ConfirmDialog
                variant="destructive"
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Close this handover review?"
                description="Unsaved details will be cleared. If a save was not confirmed, check the handover receipt before preparing another action."
                confirmText="Close review"
            />
        </>
    );
}
