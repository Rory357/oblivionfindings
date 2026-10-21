import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { ClipboardCheck, FileText, HeartHandshake } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    DocumentHandoverFields,
    DocumentHandoverSummary,
    useDocumentHandovers,
} from './document-handover-fields';
import { EvidencePicker } from './evidence-picker';
import {
    SupportContactHandoverFields,
    SupportContactHandoverSummary,
    useSupportContactHandovers,
} from './support-contact-handover';
import { referralHref } from './types';

type Preview = {
    referral_version: number;
    published_version: number;
    assessment_version: number | null;
    authority_version: number | null;
    current_review_ready: boolean;
    can_release_documents?: boolean;
    can_release_contacts?: boolean;
    people_contacts?: import('./people-contacts').PeopleContact[];
    person: { id: number; name: string; fingerprint: string };
    care: { goals?: string; participation?: string; safety_needs?: string };
};

export function CareHandoverDialog({
    reference,
    onClose,
}: {
    reference: string;
    onClose: () => void;
}) {
    const [preview, setPreview] = useState<Preview | null>(null);
    const [loading, setLoading] = useState(true);
    const [retry, setRetry] = useState(0);
    const [step, setStep] = useState(0);
    const [reason, setReason] = useState('');
    const [evidence, setEvidence] = useState<number[]>([]);
    const documents = useDocumentHandovers();
    const supportContacts = useSupportContactHandovers();
    const [acknowledged, setAcknowledged] = useState(false);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [uncertain, setUncertain] = useState(false);
    const [conflict, setConflict] = useState(false);
    const [saved, setSaved] = useState<number | null>(null);
    const [discard, setDiscard] = useState(false);
    const body = useRef<HTMLDivElement>(null);
    const issued = useRef<Record<string, unknown> | null>(null);
    const sending = useRef(false);
    const steps = [
        {
            key: 'care',
            label: 'Review care information',
            blurb: 'Current reviewed facts',
            icon: HeartHandshake,
        },
        {
            key: 'evidence',
            label: 'Reason and evidence',
            blurb: 'Support this publication',
            icon: FileText,
        },
        {
            key: 'confirm',
            label: 'Confirm sharing',
            blurb: 'Authorized onboarding staff',
            icon: ClipboardCheck,
        },
    ];
    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setPreview(null);
        setError('');
        axios
            .get<Preview>(`${referralHref(reference)}/handover/care`, {
                signal: controller.signal,
            })
            .then(({ data }) => {
                if (!controller.signal.aborted) setPreview(data);
            })
            .catch(() => {
                if (!controller.signal.aborted)
                    setError(
                        'Current care information could not be loaded. Refresh to check your access and the latest review.',
                    );
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [reference, retry]);

    function go(next: number) {
        setStep(next);
        requestAnimationFrame(() => {
            body.current?.scrollTo({ top: 0 });
            body.current?.querySelector<HTMLElement>('[data-heading]')?.focus();
        });
    }
    function valid(index: number) {
        if (
            !preview?.current_review_ready ||
            (index === 1 &&
                (reason.trim().length < 10 ||
                    !evidence.length ||
                    !documents.valid ||
                    !supportContacts.valid)) ||
            (index === 2 && !acknowledged)
        ) {
            setError(
                'Complete the current review, reason, evidence and sharing confirmation before publishing.',
            );
            go(index);
            return false;
        }
        setError('');
        return true;
    }
    async function publish() {
        if (sending.current || conflict || !preview) return;
        if (!uncertain) {
            for (let index = 0; index < 3; index++) if (!valid(index)) return;
            issued.current = {
                version: preview.referral_version,
                submission_key: crypto.randomUUID(),
                assessment_version: preview.assessment_version,
                authority_version: preview.authority_version,
                person_fingerprint: preview.person.fingerprint,
                evidence_ids: evidence,
                ...(documents.reviews.length
                    ? { document_handover: documents.reviews }
                    : {}),
                ...(supportContacts.reviews.length
                    ? { support_contacts: supportContacts.reviews }
                    : {}),
                reason,
                acknowledge_care_handover: acknowledged,
            };
        }
        sending.current = true;
        setBusy(true);
        setError('');
        try {
            const { data } = await axios.post<{
                saved: boolean;
                published_version: number;
            }>(`${referralHref(reference)}/handover/care`, issued.current);
            if (!data.saved || !data.published_version)
                throw new Error('No publication receipt');
            setSaved(data.published_version);
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
                          'Publication was not confirmed. Retry the same request to check its receipt.')
                    : 'Publication was not confirmed. Retry the same request to check its receipt.',
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
                onClose={() => !busy && (saved ? onClose() : setDiscard(true))}
                title="Update care handover"
                description="Publish a reviewed care summary for onboarding."
                railIcon={HeartHandshake}
                railTitle="Care handover"
                railSub="Earlier versions remain recorded. This action does not change the person’s care plan or start services."
                steps={steps}
                stepIndex={step}
                bodyRef={body}
                onStepClick={(next) => {
                    if (
                        !busy &&
                        !uncertain &&
                        !conflict &&
                        (next < step || valid(step))
                    )
                        go(Math.min(next, step + 1));
                }}
                footerStart={
                    <Button
                        variant="ghost"
                        disabled={busy}
                        onClick={() =>
                            step && !uncertain && !conflict
                                ? go(step - 1)
                                : setDiscard(true)
                        }
                    >
                        {step && !uncertain && !conflict ? 'Back' : 'Close'}
                    </Button>
                }
                footerEnd={
                    <Button
                        disabled={
                            busy ||
                            loading ||
                            conflict ||
                            !preview?.current_review_ready
                        }
                        onClick={() =>
                            uncertain || step === 2
                                ? void publish()
                                : valid(step) && go(step + 1)
                        }
                    >
                        {busy
                            ? 'Saving…'
                            : uncertain
                              ? 'Retry same publication'
                              : step === 2
                                ? 'Publish care handover'
                                : 'Continue'}
                    </Button>
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title={`Care handover version ${saved} saved`}
                            blurb="The reviewed care summary is available to authorized onboarding staff while its assessment and authority remain current. Earlier versions are preserved."
                            actions={
                                <Button onClick={onClose}>
                                    Return to referral
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    <h2
                        tabIndex={-1}
                        data-heading
                        className="text-section-title"
                    >
                        {steps[step].label}
                    </h2>
                    {loading && (
                        <p role="status">
                            Loading the current assessment and authority…
                        </p>
                    )}
                    {error && (
                        <Alert variant="warning">
                            <AlertDescription>
                                {error}
                                {conflict && (
                                    <p>
                                        Close and reload the care review before
                                        making another decision.
                                    </p>
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
                    {!loading && !preview && (
                        <Button
                            variant="outline"
                            onClick={() => setRetry((value) => value + 1)}
                        >
                            Refresh care review
                        </Button>
                    )}
                    {preview && !preview.current_review_ready && (
                        <Alert variant="warning">
                            <AlertDescription>
                                Review the assessment, authority and onboarding
                                destination first. Identity, goals,
                                participation and safety needs must be reviewed
                                and current before sharing.
                            </AlertDescription>
                        </Alert>
                    )}
                    <fieldset
                        disabled={busy || uncertain || conflict}
                        className="space-y-5"
                    >
                        {preview && step === 0 && (
                            <ReviewCard
                                title="Care information to share"
                                icon={HeartHandshake}
                            >
                                <ReviewRow
                                    label="Linked person"
                                    value={preview.person.name}
                                />
                                <ReviewRow
                                    label="Goals and priorities"
                                    value={preview.care.goals ?? 'Not recorded'}
                                />
                                <ReviewRow
                                    label="Participation and communication"
                                    value={
                                        preview.care.participation ??
                                        'Not recorded'
                                    }
                                />
                                <ReviewRow
                                    label="Safety and support needs"
                                    value={
                                        preview.care.safety_needs ??
                                        'Not recorded'
                                    }
                                />
                            </ReviewCard>
                        )}
                        {step === 1 && (
                            <>
                                <Field label="Why is an updated handover needed?">
                                    <Textarea
                                        value={reason}
                                        onChange={(event) =>
                                            setReason(event.target.value)
                                        }
                                        maxLength={3000}
                                    />
                                </Field>
                                <EvidencePicker
                                    reference={reference}
                                    audience="assessment"
                                    selected={evidence}
                                    onChange={(ids, changed) => {
                                        setEvidence(ids);
                                        documents.select(ids, changed);
                                    }}
                                />
                                {preview?.can_release_documents && (
                                    <DocumentHandoverFields
                                        documents={documents.selected}
                                        reviews={documents.reviews}
                                        onChange={documents.setReviews}
                                    />
                                )}
                                {preview?.can_release_contacts && (
                                    <SupportContactHandoverFields
                                        contacts={preview.people_contacts ?? []}
                                        reviews={supportContacts.reviews}
                                        onChange={supportContacts.setReviews}
                                    />
                                )}
                            </>
                        )}
                        {preview && step === 2 && (
                            <>
                                <DocumentHandoverSummary
                                    reviews={documents.reviews}
                                />
                                <SupportContactHandoverSummary
                                    contacts={preview.people_contacts ?? []}
                                    reviews={supportContacts.reviews}
                                />
                                <ReviewCard
                                    title="Publication summary"
                                    icon={ClipboardCheck}
                                >
                                    <ReviewRow
                                        label="Care handover"
                                        value={`New version ${preview.published_version + 1}`}
                                    />
                                    <ReviewRow
                                        label="Assessment"
                                        value={`Version ${preview.assessment_version}`}
                                    />
                                    <ReviewRow
                                        label="Authority"
                                        value={`Version ${preview.authority_version}`}
                                    />
                                    <ReviewRow
                                        label="Evidence documents"
                                        value={String(evidence.length)}
                                    />
                                    <ReviewRow
                                        label="Review reason"
                                        value={reason}
                                    />
                                </ReviewCard>
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="care-release-confirm"
                                        checked={acknowledged}
                                        onCheckedChange={(value) =>
                                            setAcknowledged(value === true)
                                        }
                                    />
                                    <Label htmlFor="care-release-confirm">
                                        I checked this information belongs to{' '}
                                        {preview.person.name}. Share the
                                        reviewed goals, participation and safety
                                        needs with authorized onboarding staff
                                        for care planning.
                                    </Label>
                                </div>
                            </>
                        )}
                    </fieldset>
                </div>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                variant="destructive"
                onClose={() => setDiscard(false)}
                title="Close this care review?"
                description={
                    uncertain
                        ? 'Publication may already have succeeded. Check the current handover version before beginning another review.'
                        : 'Unsaved review details will be discarded.'
                }
                confirmText="Close review"
                onConfirm={onClose}
            />
        </>
    );
}
