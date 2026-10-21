import { ConfirmDialog } from '@/components/confirm-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { formatDateTimeLong, toDatetimeLocal } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import axios from 'axios';
import {
    ClipboardCheck,
    Inbox,
    Phone,
    ShieldCheck,
    UserRound,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { ReferralDateTime } from './date-time';
import { FormQuestions } from './form-questions';
import {
    answerText,
    applicableAnswers,
    applicableQuestions,
    requiredQuestionErrors,
    type Answers,
} from './form-types';
import { IntakePathwaySelect, reportedPathway } from './intake-funding';
import {
    label,
    referralHref,
    type IntakeConfiguration,
    type Permissions,
} from './types';

const steps = [
    {
        key: 'intake',
        label: 'Intake',
        blurb: 'Source and owning site',
        icon: Inbox,
    },
    {
        key: 'contact',
        label: 'Person and contact',
        blurb: 'Identity and safe contact',
        icon: UserRound,
    },
    {
        key: 'notice',
        label: 'Notice and next action',
        blurb: 'Record what was actually agreed',
        icon: ShieldCheck,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check before saving',
        icon: ClipboardCheck,
    },
];
const optionsFor = (items: string[]) =>
    items.map((value) => ({ value, label: label(value) }));

type IntakeForm = ReturnType<typeof blankForm>;
const blankForm = (configuration: IntakeConfiguration, token: string) => ({
    answers: {} as Answers,
    submission_token: token,
    settings_version_id: configuration.settings_version_id,
    notice_version: configuration.notice_version,
    purpose: 'support',
    relationship: 'self',
    authority_status: 'self',
    organisation: '',
    region: '',
    requested_service: 'unsure',
    goals: '',
    timeframe: '',
    funding_situation: 'unsure',
    funding_agency: '',
    funding_pathway_id: '',
    contact_permission: false,
    privacy_acknowledged: false,
    person: { first_name: '', last_name: '', preferred_name: '' },
    contact: {
        name: '',
        email: '',
        phone: '',
        channel: 'phone',
        safe_time: '',
        leave_message: false,
        communication: '',
    },
});

/** What a saved intake draft holds; encrypted server-side and private to its author. */
export type IntakeDraftPayload = {
    step?: number;
    source?: string;
    site?: string;
    successful_contact?: boolean;
    notice_method?: string;
    notice_reason?: string;
    next_action?: string;
    next_due?: string;
    form?: Partial<IntakeForm>;
};
export type IntakeDraft = {
    reference: string;
    saved_at: string | null;
    expires_at: string;
    payload: IntakeDraftPayload;
};

export function StaffIntakeDialog({
    configuration,
    sites,
    permissions,
    token,
    draft = null,
    onClose,
}: {
    configuration: IntakeConfiguration;
    sites: { id: number; name: string }[];
    permissions: Permissions;
    token: string;
    /** Resume a saved draft (phone intake must survive the dialog closing). */
    draft?: IntakeDraft | null;
    onClose: () => void;
}) {
    const saved = draft?.payload ?? {};
    const [step, setStep] = useState(Math.min(saved.step ?? 0, 2));
    const [busy, setBusy] = useState(false);
    const [recovering, setRecovering] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [receipt, setReceipt] = useState<string | null>(null);
    const [error, setError] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [draftRef, setDraftRef] = useState(draft?.reference ?? null);
    const [drafting, setDrafting] = useState(false);
    const [draftNote, setDraftNote] = useState('');
    const changedConfiguration =
        !!draft &&
        saved.form?.settings_version_id !== configuration.settings_version_id;
    const [source, setSource] = useState(saved.source ?? 'phone');
    const [site, setSite] = useState(
        saved.site ?? (sites.length === 1 ? String(sites[0].id) : ''),
    );
    const [successfulContact, setSuccessfulContact] = useState(
        saved.successful_contact ?? false,
    );
    // A changed collection notice must be confirmed again, never carried over.
    const [noticeMethod, setNoticeMethod] = useState(
        changedConfiguration ? 'pending' : (saved.notice_method ?? 'pending'),
    );
    const [noticeReason, setNoticeReason] = useState(saved.notice_reason ?? '');
    const [nextAction, setNextAction] = useState(
        saved.next_action ?? 'Review the enquiry and confirm next steps',
    );
    const [nextDue, setNextDue] = useState(
        saved.next_due ?? toDatetimeLocal(Date.now() + 86400000),
    );
    const [form, setForm] = useState<IntakeForm>(() => {
        const blank = blankForm(configuration, token);
        if (!saved.form) return blank;
        return {
            ...blank,
            ...saved.form,
            // A resumed draft keeps its own submission identity (no duplicate on retry)
            // but always submits against the configuration published now.
            submission_token: saved.form.submission_token || token,
            settings_version_id: configuration.settings_version_id,
            notice_version: configuration.notice_version,
            privacy_acknowledged: false,
            person: { ...blank.person, ...saved.form.person },
            contact: { ...blank.contact, ...saved.form.contact },
        };
    });
    const pending = useRef(false);
    const attempted = useRef<Record<string, unknown> | null>(null);
    const body = useRef<HTMLDivElement>(null);
    const minimal =
        form.authority_status === 'unsure' || form.purpose === 'business';
    const questions = applicableQuestions(
        configuration.fields ?? [],
        form.requested_service,
        form.purpose,
        minimal,
    );
    const change = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => setForm((previous) => ({ ...previous, [key]: value }));
    const go = (value: number) => {
        setStep(value);
        requestAnimationFrame(() => {
            body.current?.scrollTo({ top: 0 });
            body.current?.querySelector<HTMLElement>('[data-heading]')?.focus();
        });
    };
    function close() {
        if (busy || drafting) return;
        if (receipt) onClose();
        else setDiscard(true);
    }
    /** Save the call so far as the author's private draft (kept 7 days, removed on submit). */
    async function saveDraft(closeAfter: boolean) {
        if (drafting || busy) return;
        setDrafting(true);
        setDraftNote('');
        const payload: IntakeDraftPayload = {
            step,
            source,
            site,
            successful_contact: successfulContact,
            notice_method: noticeMethod,
            notice_reason: noticeReason,
            next_action: nextAction,
            next_due: nextDue,
            form,
        };
        try {
            const response = await axios.request<{
                saved: boolean;
                draft: { reference: string; expires_at: string };
            }>({
                url: draftRef
                    ? `/operations/referrals/drafts/${draftRef}`
                    : '/operations/referrals/drafts',
                method: draftRef ? 'put' : 'post',
                data: { payload },
            });
            if (!response.data.saved) throw new Error('Draft not confirmed');
            setDraftRef(response.data.draft.reference);
            if (closeAfter) {
                setDiscard(false);
                onClose();
                return;
            }
            setDraftNote(
                `Draft saved. Only you can resume it, until ${formatDateTimeLong(response.data.draft.expires_at)}.`,
            );
        } catch (caught) {
            setDiscard(false);
            setDraftNote(
                axios.isAxiosError(caught) && caught.response?.status === 422
                    ? (caught.response.data?.message ??
                          'The draft could not be saved.')
                    : 'The draft could not be saved. Your entries are still here — keep this form open or save the referral.',
            );
        } finally {
            setDrafting(false);
        }
    }
    function next() {
        if (step === 1) {
            const problems = requiredQuestionErrors(questions, form.answers);
            setErrors(problems);
            if (Object.keys(problems).length) {
                setError('Complete the required intake questions.');
                return;
            }
        }
        if (step === 0 && !site) {
            setError('Choose the owning site or central intake.');
            return;
        }
        if (
            step === 1 &&
            (!form.contact.name.trim() ||
                !(form.contact.channel === 'phone'
                    ? form.contact.phone.trim()
                    : form.contact.email.trim()))
        ) {
            setError(
                'Enter a contact name and the selected safe contact method.',
            );
            return;
        }
        setError('');
        go(step + 1);
    }
    async function save() {
        if (pending.current) return;
        pending.current = true;
        setBusy(true);
        setError('');
        setErrors({});
        const payload = attempted.current ?? {
            source,
            site_id: site === 'central' ? null : Number(site),
            successful_contact: successfulContact,
            notice_method: noticeMethod,
            notice_reason: noticeReason,
            next_action: nextAction,
            next_due_at: nextDue,
            ...(draftRef ? { draft_reference: draftRef } : {}),
            form: {
                ...form,
                answers: applicableAnswers(questions, form.answers),
                funding_pathway_id: reportedPathway(
                    configuration.pathways ?? [],
                    form.requested_service,
                    form.funding_pathway_id,
                    minimal,
                )?.id,
                privacy_acknowledged: noticeMethod !== 'pending',
                person: minimal ? {} : form.person,
            },
        };
        attempted.current = payload;
        try {
            const response = await axios.post<{
                saved: boolean;
                reference: string;
            }>('/operations/referrals', payload);
            if (!response.data.saved || !response.data.reference)
                throw new Error('Save not confirmed');
            setReceipt(response.data.reference);
        } catch (caught) {
            const status = axios.isAxiosError(caught)
                ? caught.response?.status
                : null;
            if (status === 422 && axios.isAxiosError(caught)) {
                attempted.current = null;
                setRecovering(false);
                const fields = caught.response?.data?.errors as
                    | Record<string, string[]>
                    | undefined;
                if (fields)
                    setErrors(
                        Object.fromEntries(
                            Object.entries(fields).map(([key, messages]) => [
                                key.replace(/^form\./, ''),
                                messages[0],
                            ]),
                        ),
                    );
                setError(
                    caught.response?.data?.message ??
                        'Check the intake details and try again.',
                );
                if (
                    fields &&
                    Object.keys(fields).some((key) =>
                        key.replace(/^form\./, '').startsWith('answers'),
                    )
                )
                    go(1);
            } else {
                setRecovering(true);
                setError(
                    status === 403
                        ? 'Your permissions or site access have changed. Close this form and reload the workspace.'
                        : status === 409
                          ? 'This intake reference has already been used. Check the existing referral before recording another.'
                          : 'Save was not confirmed. Retry this same submission; it will not create a second referral.',
                );
            }
        } finally {
            pending.current = false;
            setBusy(false);
        }
    }
    const text = (
        key: string,
        title: string,
        value: string,
        update: (value: string) => void,
        type = 'text',
    ) => (
        <Field label={title} error={errors[key]}>
            <Input
                value={value}
                onChange={(event) => update(event.target.value)}
                type={type}
                maxLength={type === 'email' ? 254 : 160}
            />
        </Field>
    );
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title="Record referral"
                description="Record phone, email or in-person intake with a named owner and next action."
                railIcon={Inbox}
                railTitle="Record referral"
                railSub="You become the initial owner. Assignment can be changed after intake."
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!busy && !recovering && index < step) go(index);
                }}
                bodyRef={body}
                footerStart={
                    <>
                        <Button
                            variant="ghost"
                            disabled={busy || recovering || drafting}
                            onClick={() => (step > 0 ? go(step - 1) : close())}
                        >
                            {step > 0 ? 'Back' : 'Cancel'}
                        </Button>
                        {!recovering && (
                            <Button
                                variant="ghost"
                                disabled={busy || drafting}
                                onClick={() => void saveDraft(true)}
                            >
                                {drafting
                                    ? 'Saving draft…'
                                    : 'Save draft & close'}
                            </Button>
                        )}
                    </>
                }
                footerEnd={
                    step < 3 ? (
                        <Button onClick={next}>Continue</Button>
                    ) : (
                        <Button disabled={busy} onClick={() => void save()}>
                            {busy
                                ? 'Saving…'
                                : recovering
                                  ? 'Retry same intake'
                                  : 'Save referral'}
                        </Button>
                    )
                }
                success={
                    receipt ? (
                        <WizardSuccessPane
                            title="Referral saved"
                            blurb="The referral is assigned to you. Assessment, consent and funding approval remain separate workflow steps."
                            actions={
                                <Button
                                    onClick={() =>
                                        router.visit(referralHref(receipt))
                                    }
                                >
                                    Open referral
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    <h2
                        className="text-section-title"
                        data-heading
                        tabIndex={-1}
                    >
                        {steps[step].label}
                    </h2>
                    {changedConfiguration && (
                        <Alert variant="warning">
                            <AlertTitle>
                                The referral form has changed
                            </AlertTitle>
                            <AlertDescription>
                                This draft was saved against an earlier
                                configuration. Check each answer and confirm the
                                current collection notice again before saving.
                            </AlertDescription>
                        </Alert>
                    )}
                    {draftNote && (
                        <p role="status" className="text-subtle">
                            {draftNote}
                        </p>
                    )}
                    {error && (
                        <Alert variant="warning">
                            <AlertTitle>Check before continuing</AlertTitle>
                            <AlertDescription>
                                {error}
                                {Object.entries(errors).map(
                                    ([key, message]) => (
                                        <p key={key}>{message}</p>
                                    ),
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
                    {step === 0 && (
                        <div className="grid gap-5 sm:grid-cols-2">
                            <Field label="Received through">
                                <SelectInput
                                    value={source}
                                    onChange={setSource}
                                    placeholder="Source"
                                    options={optionsFor([
                                        'phone',
                                        'email',
                                        'in_person',
                                        'agency',
                                    ])}
                                />
                            </Field>
                            <Field label="Owning site" required>
                                <SelectInput
                                    value={site}
                                    onChange={setSite}
                                    placeholder="Choose a site"
                                    options={[
                                        ...(permissions.centralIntake
                                            ? [
                                                  {
                                                      value: 'central',
                                                      label: 'Central intake — not allocated to a site',
                                                  },
                                              ]
                                            : []),
                                        ...sites.map((item) => ({
                                            value: String(item.id),
                                            label: item.name,
                                        })),
                                    ]}
                                />
                            </Field>
                            <Field label="Purpose">
                                <SelectInput
                                    value={form.purpose}
                                    onChange={(value) =>
                                        change('purpose', value)
                                    }
                                    placeholder="Purpose"
                                    options={optionsFor([
                                        'support',
                                        'referral',
                                        'funding',
                                        'callback',
                                        'business',
                                    ])}
                                />
                            </Field>
                            <Field label="Contacting us for">
                                <SelectInput
                                    value={form.relationship}
                                    onChange={(value) =>
                                        setForm((previous) => ({
                                            ...previous,
                                            relationship: value,
                                            authority_status:
                                                value === 'self'
                                                    ? 'self'
                                                    : 'unsure',
                                        }))
                                    }
                                    placeholder="Relationship"
                                    options={optionsFor([
                                        'self',
                                        'family',
                                        'supporter',
                                        'professional',
                                        'general',
                                    ])}
                                />
                            </Field>
                            {form.relationship !== 'self' && (
                                <Field label="Authority to share information">
                                    <SelectInput
                                        value={form.authority_status}
                                        onChange={(value) =>
                                            change('authority_status', value)
                                        }
                                        placeholder="Authority"
                                        options={[
                                            {
                                                value: 'unsure',
                                                label: 'Not established — process enquiry only',
                                            },
                                            {
                                                value: 'confirmed',
                                                label: 'Person or referrer declares authority',
                                            },
                                        ]}
                                    />
                                </Field>
                            )}
                            {text(
                                'organisation',
                                'Referring organisation (optional)',
                                form.organisation,
                                (value) => change('organisation', value),
                            )}
                            {permissions.contact && (
                                <div className="flex items-center gap-3 sm:col-span-2">
                                    <Checkbox
                                        id="intake-connected"
                                        checked={successfulContact}
                                        onCheckedChange={(value) =>
                                            setSuccessfulContact(value === true)
                                        }
                                    />
                                    <Label
                                        className="flex items-center"
                                        htmlFor="intake-connected"
                                    >
                                        A successful human conversation has
                                        already taken place
                                    </Label>
                                </div>
                            )}
                        </div>
                    )}
                    {step === 1 && (
                        <div className="grid gap-5 sm:grid-cols-2">
                            {minimal ? (
                                <Alert className="sm:col-span-2" variant="info">
                                    <AlertDescription>
                                        Record a minimal process enquiry. Do not
                                        include another person’s identity or
                                        health details until authority is
                                        established.
                                    </AlertDescription>
                                </Alert>
                            ) : (
                                <>
                                    {text(
                                        'person.first_name',
                                        'Person’s first name',
                                        form.person.first_name,
                                        (value) =>
                                            change('person', {
                                                ...form.person,
                                                first_name: value,
                                            }),
                                    )}
                                    {text(
                                        'person.last_name',
                                        'Person’s last name',
                                        form.person.last_name,
                                        (value) =>
                                            change('person', {
                                                ...form.person,
                                                last_name: value,
                                            }),
                                    )}
                                    {text(
                                        'person.preferred_name',
                                        'Preferred name',
                                        form.person.preferred_name,
                                        (value) =>
                                            change('person', {
                                                ...form.person,
                                                preferred_name: value,
                                            }),
                                    )}
                                </>
                            )}
                            {text(
                                'contact.name',
                                'Contact’s name',
                                form.contact.name,
                                (value) =>
                                    change('contact', {
                                        ...form.contact,
                                        name: value,
                                    }),
                            )}
                            <Field label="Safe contact method">
                                <SelectInput
                                    value={form.contact.channel}
                                    onChange={(value) =>
                                        change('contact', {
                                            ...form.contact,
                                            channel: value,
                                        })
                                    }
                                    placeholder="Contact method"
                                    options={optionsFor(['phone', 'email'])}
                                />
                            </Field>
                            {text(
                                'contact.phone',
                                'Phone',
                                form.contact.phone,
                                (value) =>
                                    change('contact', {
                                        ...form.contact,
                                        phone: value,
                                    }),
                                'tel',
                            )}
                            {text(
                                'contact.email',
                                'Email',
                                form.contact.email,
                                (value) =>
                                    change('contact', {
                                        ...form.contact,
                                        email: value,
                                    }),
                                'email',
                            )}
                            {text(
                                'contact.safe_time',
                                'Safe time to contact',
                                form.contact.safe_time,
                                (value) =>
                                    change('contact', {
                                        ...form.contact,
                                        safe_time: value,
                                    }),
                            )}
                            {text(
                                'contact.communication',
                                'Communication or interpreter needs',
                                form.contact.communication,
                                (value) =>
                                    change('contact', {
                                        ...form.contact,
                                        communication: value,
                                    }),
                            )}
                            <div className="flex items-center gap-3">
                                <Checkbox
                                    id="staff-leave-message"
                                    checked={form.contact.leave_message}
                                    onCheckedChange={(value) =>
                                        change('contact', {
                                            ...form.contact,
                                            leave_message: value === true,
                                        })
                                    }
                                />
                                <Label
                                    className="flex items-center"
                                    htmlFor="staff-leave-message"
                                >
                                    Messages are safe
                                </Label>
                            </div>
                            <Field label="Service interest">
                                <SelectInput
                                    value={form.requested_service}
                                    onChange={(value) =>
                                        change('requested_service', value)
                                    }
                                    placeholder="Service"
                                    options={[
                                        {
                                            value: 'unsure',
                                            label: 'Not sure yet',
                                        },
                                        ...configuration.services.map(
                                            (service) => ({
                                                value: service.id,
                                                label: service.label,
                                            }),
                                        ),
                                    ]}
                                />
                            </Field>
                            <Field label="Funding situation">
                                <SelectInput
                                    value={form.funding_situation}
                                    onChange={(value) =>
                                        change('funding_situation', value)
                                    }
                                    placeholder="Funding situation"
                                    options={optionsFor([
                                        'unsure',
                                        'not_explored',
                                        'need_help',
                                        'underway',
                                        'arranged',
                                        'private',
                                    ])}
                                />
                            </Field>
                            {text(
                                'region',
                                'Region or area',
                                form.region,
                                (value) => change('region', value),
                            )}
                            {text(
                                'funding_agency',
                                'Agency or funding host',
                                form.funding_agency,
                                (value) => change('funding_agency', value),
                            )}
                            {!minimal && (
                                <IntakePathwaySelect
                                    options={configuration.pathways ?? []}
                                    service={form.requested_service}
                                    value={form.funding_pathway_id}
                                    onChange={(value) =>
                                        change('funding_pathway_id', value)
                                    }
                                />
                            )}
                            {text(
                                'timeframe',
                                'Preferred timeframe',
                                form.timeframe,
                                (value) => change('timeframe', value),
                            )}
                            <Field
                                span
                                label={
                                    minimal
                                        ? 'Question about the process'
                                        : 'Goals or support requested'
                                }
                            >
                                <Textarea
                                    value={form.goals}
                                    onChange={(event) =>
                                        change('goals', event.target.value)
                                    }
                                    maxLength={3000}
                                />
                            </Field>
                            {questions.length > 0 && (
                                <div className="sm:col-span-2">
                                    <FormQuestions
                                        fields={questions}
                                        answers={form.answers}
                                        onChange={(answers) =>
                                            change('answers', answers)
                                        }
                                        errors={errors}
                                    />
                                </div>
                            )}
                        </div>
                    )}
                    {step === 2 && (
                        <div className="space-y-5">
                            <ReviewCard
                                icon={ShieldCheck}
                                title={`Collection notice · ${configuration.notice_version}`}
                            >
                                <p className="text-subtle whitespace-pre-wrap">
                                    {configuration.notice ||
                                        'No notice text has been published. Record delivery as pending.'}
                                </p>
                            </ReviewCard>
                            <Field label="Notice delivery">
                                <SelectInput
                                    value={noticeMethod}
                                    onChange={setNoticeMethod}
                                    placeholder="Notice delivery"
                                    options={[
                                        {
                                            value: 'pending',
                                            label: 'Not yet provided — follow-up required',
                                        },
                                        {
                                            value: 'verbal',
                                            label: 'Provided verbally',
                                            disabled: !configuration.notice,
                                        },
                                        {
                                            value: 'written',
                                            label: 'Provided in writing',
                                            disabled: !configuration.notice,
                                        },
                                    ]}
                                />
                            </Field>
                            {noticeMethod === 'pending' &&
                                text(
                                    'notice_reason',
                                    'Reason and planned notice delivery',
                                    noticeReason,
                                    setNoticeReason,
                                )}
                            <div className="flex items-center gap-3">
                                <Checkbox
                                    id="staff-contact-permission"
                                    checked={form.contact_permission}
                                    onCheckedChange={(value) =>
                                        change(
                                            'contact_permission',
                                            value === true,
                                        )
                                    }
                                />
                                <Label
                                    className="flex items-center"
                                    htmlFor="staff-contact-permission"
                                >
                                    Permission for follow-up contact was
                                    obtained
                                </Label>
                            </div>
                            {text(
                                'next_action',
                                'Next action',
                                nextAction,
                                setNextAction,
                            )}
                            <ReferralDateTime
                                id="intake-next-due"
                                label="Next action due"
                                value={nextDue}
                                onChange={setNextDue}
                                error={errors.next_due_at}
                            />
                        </div>
                    )}
                    {step === 3 && (
                        <div className="space-y-4">
                            {questions.length > 0 && (
                                <ReviewCard
                                    icon={ClipboardCheck}
                                    title="Additional information"
                                >
                                    {questions.map((field) => (
                                        <ReviewRow
                                            key={field.id}
                                            label={field.label}
                                            value={answerText(
                                                form.answers[field.id],
                                                field.type,
                                            )}
                                        />
                                    ))}
                                </ReviewCard>
                            )}
                            <ReviewCard
                                icon={Inbox}
                                title="Referral"
                                onEdit={recovering ? undefined : () => go(0)}
                            >
                                <ReviewRow
                                    label="Source"
                                    value={label(source)}
                                />
                                <ReviewRow
                                    label="Site"
                                    value={
                                        site === 'central'
                                            ? 'Central intake'
                                            : sites.find(
                                                  (item) =>
                                                      String(item.id) === site,
                                              )?.name
                                    }
                                />
                                <ReviewRow
                                    label="Purpose"
                                    value={label(form.purpose)}
                                />
                                <ReviewRow label="Initial owner" value="You" />
                                <ReviewRow
                                    label="Successful contact"
                                    value={
                                        successfulContact
                                            ? 'Recorded'
                                            : 'Not recorded'
                                    }
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={Phone}
                                title="Person and contact"
                                onEdit={recovering ? undefined : () => go(1)}
                            >
                                <ReviewRow
                                    label="Person"
                                    value={
                                        minimal
                                            ? 'Minimal process enquiry'
                                            : `${form.person.first_name} ${form.person.last_name}`
                                    }
                                />
                                <ReviewRow
                                    label="Contact"
                                    value={`${form.contact.name} · ${form.contact.channel === 'phone' ? form.contact.phone : form.contact.email}`}
                                />
                                <ReviewRow
                                    label="Safe time"
                                    value={form.contact.safe_time}
                                />
                                <ReviewRow
                                    label="Messages"
                                    value={
                                        form.contact.leave_message
                                            ? 'Safe'
                                            : 'Do not leave a message'
                                    }
                                />
                                <ReviewRow
                                    label="Support requested"
                                    value={form.goals}
                                />
                                <ReviewRow
                                    label="Funding situation"
                                    value={label(form.funding_situation)}
                                />
                                {!minimal && (
                                    <ReviewRow
                                        label="Reported pathway (unverified)"
                                        value={
                                            reportedPathway(
                                                configuration.pathways ?? [],
                                                form.requested_service,
                                                form.funding_pathway_id,
                                            )?.label ?? 'Not sure yet'
                                        }
                                    />
                                )}
                            </ReviewCard>
                            <ReviewCard
                                icon={ShieldCheck}
                                title="Notice and follow-up"
                                onEdit={recovering ? undefined : () => go(2)}
                            >
                                <ReviewRow
                                    label="Notice"
                                    value={label(noticeMethod)}
                                />
                                <ReviewRow
                                    label="Contact permission"
                                    value={
                                        form.contact_permission
                                            ? 'Obtained'
                                            : 'Not obtained'
                                    }
                                />
                                <ReviewRow
                                    label="Next action"
                                    value={nextAction}
                                />
                                <ReviewRow
                                    label="Due (NZ time)"
                                    value={nextDue.replace('T', ' ')}
                                />
                            </ReviewCard>
                        </div>
                    )}
                </div>
            </WizardShell>
            {recovering ? (
                <ConfirmDialog
                    open={discard}
                    onClose={() => setDiscard(false)}
                    onConfirm={onClose}
                    variant="destructive"
                    title="Close an unconfirmed submission?"
                    description="The save may have succeeded. Check the referral list before creating another intake. Closing clears these local answers."
                    confirmText="Close draft"
                />
            ) : (
                <Dialog
                    open={discard}
                    onOpenChange={(open) => !open && setDiscard(false)}
                >
                    <DialogContent
                        style={{
                            width: 'min(92vw, 480px)',
                            maxWidth: 'min(92vw, 480px)',
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>Unsaved intake</DialogTitle>
                            <DialogDescription>
                                Save this call as a draft to resume it later
                                (only you can see it; it is kept for 7 days), or
                                discard the answers.
                                {draftRef
                                    ? ' Discarding keeps the last saved draft.'
                                    : ''}
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => setDiscard(false)}
                            >
                                Keep editing
                            </Button>
                            <Button
                                variant="destructive"
                                disabled={drafting}
                                onClick={onClose}
                            >
                                Discard changes
                            </Button>
                            <Button
                                disabled={drafting}
                                onClick={() => void saveDraft(true)}
                            >
                                {drafting ? 'Saving draft…' : 'Save draft'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}
        </>
    );
}
