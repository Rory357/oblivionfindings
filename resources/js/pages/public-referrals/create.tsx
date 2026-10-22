import { FormQuestions } from '@/components/referrals/form-questions';
import {
    answerText,
    applicableAnswers,
    applicableQuestions,
    requiredQuestionErrors,
    type Answers,
    type FormQuestion,
} from '@/components/referrals/form-types';
import {
    IntakePathwaySelect,
    reportedPathway,
    type IntakePathway,
} from '@/components/referrals/intake-funding';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
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
import { useClipboard } from '@/hooks/use-clipboard';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import MarketingLayout from '@/layouts/marketing-layout';
import { router } from '@inertiajs/react';
import axios from 'axios';
import {
    ClipboardCheck,
    HeartHandshake,
    Phone,
    ShieldCheck,
    UserRound,
    Wallet,
} from 'lucide-react';
import { useRef, useState } from 'react';

type Intake = {
    pathways?: IntakePathway[];
    fields?: FormQuestion[];
    enabled: boolean;
    provider_name: string;
    contact_phone: string;
    response_hours: string;
    notice: string;
    notice_version: string;
    settings_version_id: number | null;
    services: { id: string; label: string }[];
};
const steps = [
    {
        key: 'purpose',
        label: 'Getting started',
        blurb: 'What would you like help with?',
        icon: HeartHandshake,
    },
    {
        key: 'contact',
        label: 'Safe contact',
        blurb: 'How and when to reach you',
        icon: Phone,
    },
    {
        key: 'support',
        label: 'Support and funding',
        blurb: 'Share what you know so far',
        icon: Wallet,
    },
    {
        key: 'review',
        label: 'Review and send',
        blurb: 'Privacy and your next step',
        icon: ClipboardCheck,
    },
] as const;
const purposes = [
    { value: 'support', label: 'Explore support' },
    { value: 'referral', label: 'Make a referral' },
    { value: 'funding', label: 'Help with funding' },
    { value: 'callback', label: 'Request a callback' },
    { value: 'business', label: 'General business enquiry' },
];
const relationships = [
    { value: 'self', label: 'Myself' },
    { value: 'family', label: 'A family or whānau member' },
    { value: 'supporter', label: 'Someone I support' },
    { value: 'professional', label: 'Professional or agency referral' },
    { value: 'general', label: 'A general enquiry' },
];
const funding = [
    { value: 'unsure', label: 'Not sure yet' },
    { value: 'not_explored', label: 'Not explored yet' },
    { value: 'need_help', label: 'I need help getting funding' },
    { value: 'underway', label: 'Assessment or application underway' },
    { value: 'arranged', label: 'Funding arranged' },
    { value: 'private', label: 'Considering private payment' },
];
function errorStep(field: string): number {
    if (field.startsWith('answers')) return 2;
    if (field.startsWith('contact.')) return 1;
    if (
        [
            'authority_status',
            'relationship',
            'purpose',
            'organisation',
        ].includes(field)
    )
        return 0;
    if (
        field.startsWith('person') ||
        [
            'region',
            'goals',
            'requested_service',
            'timeframe',
            'funding_situation',
            'funding_agency',
            'funding_pathway_id',
        ].includes(field)
    )
        return 2;
    return 3;
}

export default function PublicReferral({
    intake,
    submissionToken,
    initialPurpose = 'support',
    initialService = 'unsure',
}: {
    intake: Intake;
    submissionToken: string;
    initialPurpose?: string;
    initialService?: string;
}) {
    const [open, setOpen] = useState(false);
    const [step, setStep] = useState(0);
    const [busy, setBusy] = useState(false);
    const [recovering, setRecovering] = useState(false);
    const [receipt, setReceipt] = useState<string | null>(null);
    const [copiedReference, copyReference] = useClipboard();
    const [copyFailed, setCopyFailed] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [failure, setFailure] = useState('');
    const bodyRef = useRef<HTMLDivElement>(null);
    const pending = useRef(false);
    const [form, setForm] = useState({
        answers: {} as Answers,
        submission_token: submissionToken,
        settings_version_id: intake.settings_version_id,
        notice_version: intake.notice_version,
        purpose: initialPurpose,
        relationship: 'self',
        authority_status: 'self',
        organisation: '',
        contact: {
            name: '',
            email: '',
            phone: '',
            channel: 'phone',
            safe_time: '',
            leave_message: false,
            communication: '',
        },
        person: { first_name: '', last_name: '', preferred_name: '' },
        region: '',
        requested_service: initialService,
        goals: '',
        timeframe: '',
        funding_situation: 'unsure',
        funding_agency: '',
        funding_pathway_id: '',
        contact_permission: false,
        privacy_acknowledged: false,
        website: '',
    });
    type Submission = Omit<typeof form, 'person'> & {
        person: Partial<typeof form.person>;
    };
    const attempted = useRef<Submission | null>(null);
    const [initialSnapshot] = useState(() => JSON.stringify(form));
    const leave = useSettingsLeaveConfirmation(
        !receipt && JSON.stringify(form) !== initialSnapshot,
        'Leave this enquiry?',
        recovering
            ? 'Your submission may already be saved. Leaving clears the answers kept in this page. Stay here to retry the same submission and recover its receipt.'
            : 'Your answers have not been sent. Leaving clears the answers kept in this page. Cancel to continue your enquiry.',
        undefined,
        busy,
    );
    const minimal =
        form.authority_status === 'unsure' || form.purpose === 'business';
    const questions = applicableQuestions(
        intake.fields ?? [],
        form.requested_service,
        form.purpose,
        minimal,
    );
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => setForm((previous) => ({ ...previous, [key]: value }));
    const go = (next: number) => {
        setStep(next);
        requestAnimationFrame(() => {
            bodyRef.current?.scrollTo({ top: 0 });
            bodyRef.current
                ?.querySelector<HTMLElement>('[data-step-heading]')
                ?.focus();
        });
    };
    function next() {
        const problems: Record<string, string> = {};
        if (step === 1) {
            if (!form.contact.name.trim())
                problems['contact.name'] = 'Enter your name.';
            if (
                form.contact.channel === 'phone' &&
                form.contact.phone.trim().length < 5
            )
                problems['contact.phone'] =
                    'Enter a phone number we can use safely.';
            if (
                form.contact.channel === 'email' &&
                !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.contact.email)
            )
                problems['contact.email'] =
                    'Enter an email address we can use safely.';
        }
        if (step === 2)
            Object.assign(
                problems,
                requiredQuestionErrors(questions, form.answers),
            );
        setErrors(problems);
        if (!Object.keys(problems).length) go(step + 1);
    }
    async function submit() {
        if (pending.current) return;
        pending.current = true;
        setBusy(true);
        setErrors({});
        setFailure('');
        const payload = attempted.current ?? {
            ...form,
            answers: applicableAnswers(questions, form.answers),
            funding_pathway_id:
                reportedPathway(
                    intake.pathways ?? [],
                    form.requested_service,
                    form.funding_pathway_id,
                    minimal,
                )?.id ?? '',
            person: minimal ? {} : form.person,
        };
        attempted.current = payload;
        try {
            const response = await axios.post<{
                reference: string;
                status: string;
            }>('/referrals', payload, {
                headers: { Accept: 'application/json' },
            });
            if (response.data.status !== 'received' || !response.data.reference)
                throw new Error('Receipt unavailable');
            setReceipt(response.data.reference);
        } catch (error) {
            if (
                axios.isAxiosError(error) &&
                error.response?.status === 422 &&
                error.response.data?.errors
            ) {
                attempted.current = null;
                setRecovering(false);
                const problems: Record<string, string> = {};
                for (const [key, messages] of Object.entries(
                    error.response.data.errors as Record<string, string[]>,
                ))
                    problems[key.replace(/^form\./, '')] = messages[0];
                setErrors(problems);
                go(Math.min(...Object.keys(problems).map(errorStep)));
            } else {
                setRecovering(true);
                const status = axios.isAxiosError(error)
                    ? error.response?.status
                    : null;
                setFailure(
                    status === 429
                        ? 'Please wait a minute before trying again. Your answers are still here.'
                        : status === 409
                          ? 'This receipt reference was already used with different information. Check your earlier confirmation before starting a new enquiry.'
                          : 'We could not confirm your submission. Your answers are still here. Try sending again; the same enquiry will not be created twice.',
                );
            }
        } finally {
            setBusy(false);
            pending.current = false;
        }
    }
    function refreshNotice() {
        setBusy(true);
        leave.preserveAndRun(() =>
            router.reload({
                only: ['intake', 'provider'],
                onSuccess: (page) => {
                    const latest = page.props.intake as Intake;
                    attempted.current = null;
                    setRecovering(false);
                    setForm((previous) => ({
                        ...previous,
                        settings_version_id: latest.settings_version_id,
                        notice_version: latest.notice_version,
                        privacy_acknowledged: false,
                        requested_service: latest.services.some(
                            (service) =>
                                service.id === previous.requested_service,
                        )
                            ? previous.requested_service
                            : 'unsure',
                    }));
                    setErrors({});
                    go(3);
                },
                onFinish: () => setBusy(false),
            }),
        );
    }
    const text = (
        key: string,
        label: string,
        value: string,
        change: (value: string) => void,
        required = false,
        type = 'text',
    ) => (
        <Field
            label={label}
            required={required}
            error={errors[key]}
            errorId={`${key}-error`}
        >
            <Input
                id={key}
                value={value}
                type={type}
                maxLength={key === 'contact.email' ? 254 : 200}
                onChange={(event) => change(event.target.value)}
                aria-invalid={!!errors[key]}
                aria-describedby={errors[key] ? `${key}-error` : undefined}
            />
        </Field>
    );
    return (
        <MarketingLayout
            title="Ask about support"
            description="Explore supported living, make a referral or ask for help with funding."
        >
            <section className="mx-auto max-w-3xl space-y-6">
                <div className="space-y-3">
                    <p className="text-caption text-primary">
                        {intake.provider_name}
                    </p>
                    <h1 className="text-page-title">
                        A conversation is a good place to start
                    </h1>
                    <p className="text-subtle">
                        Ask about supported living, refer someone or get help
                        exploring funding. You do not need a diagnosis, NHI,
                        funding approval or a confirmed placement to contact us.
                    </p>
                </div>
                <Card>
                    <CardHeader>
                        <h2 className="text-section-title">
                            Tell us what you need
                        </h2>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <p>
                            We’ll review your enquiry and contact you using the
                            safe contact preferences you choose.
                        </p>
                        <p className="text-subtle">
                            Staffed response hours: {intake.response_hours}.
                            This form is not monitored as an emergency service.
                        </p>
                        {intake.enabled ? (
                            <Button
                                className="frontline-tap"
                                onClick={() => setOpen(true)}
                            >
                                <HeartHandshake className="size-4" />
                                {receipt
                                    ? 'View your receipt'
                                    : 'Start an enquiry'}
                            </Button>
                        ) : (
                            <Alert variant="info">
                                <AlertTitle>
                                    Online enquiries are currently unavailable
                                </AlertTitle>
                                <AlertDescription>
                                    Please contact us to discuss support or
                                    arrange a phone-assisted referral.
                                </AlertDescription>
                            </Alert>
                        )}
                        {intake.contact_phone && (
                            <Button
                                variant="outline"
                                className="frontline-tap"
                                asChild
                            >
                                <a
                                    href={`tel:${intake.contact_phone.replace(/[^+\d]/g, '')}`}
                                >
                                    <Phone className="size-4" />
                                    {intake.contact_phone}
                                </a>
                            </Button>
                        )}
                    </CardContent>
                </Card>
            </section>
            <WizardShell
                open={open}
                onClose={() => {
                    if (!busy) setOpen(false);
                }}
                title="Ask about support"
                description="Send an enquiry safely to the intake team."
                railIcon={HeartHandshake}
                railTitle="Your next step"
                railSub="Share what you know. We can work through the rest together."
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!busy && !recovering && index < step) go(index);
                }}
                bodyRef={bodyRef}
                frontline
                footerStart={
                    <Button
                        variant="ghost"
                        disabled={busy || recovering}
                        onClick={() =>
                            step > 0 ? go(step - 1) : setOpen(false)
                        }
                    >
                        {step > 0 ? 'Back' : 'Close'}
                    </Button>
                }
                footerEnd={
                    step < 3 ? (
                        <Button disabled={busy} onClick={next}>
                            Continue
                        </Button>
                    ) : (
                        <Button
                            disabled={
                                busy ||
                                !intake.enabled ||
                                !form.contact_permission ||
                                !form.privacy_acknowledged
                            }
                            onClick={() => void submit()}
                        >
                            {busy
                                ? 'Saving your enquiry…'
                                : recovering
                                  ? 'Retry this submission'
                                  : 'Send enquiry'}
                        </Button>
                    )
                }
                success={
                    receipt ? (
                        <WizardSuccessPane
                            title="Your enquiry is saved"
                            blurb={
                                <>
                                    <span className="block break-all select-all">
                                        Reference: {receipt}
                                    </span>
                                    <span role="status" className="mt-2 block">
                                        {copyFailed
                                            ? 'Copy is unavailable. Select the reference above to keep it.'
                                            : copiedReference === receipt
                                              ? 'Reference copied.'
                                              : 'Keep this reference if you need to contact us.'}
                                    </span>
                                    <span className="mt-3 block">
                                        Our intake team will review it and
                                        follow your contact preferences. This
                                        receipt does not confirm funding or a
                                        place. Staffed response hours:{' '}
                                        {intake.response_hours}.
                                    </span>
                                </>
                            }
                            actions={
                                <>
                                    <Button
                                        variant="outline"
                                        onClick={async () =>
                                            setCopyFailed(
                                                !(await copyReference(receipt)),
                                            )
                                        }
                                    >
                                        Copy reference
                                    </Button>
                                    <Button onClick={() => setOpen(false)}>
                                        Done
                                    </Button>
                                </>
                            }
                        />
                    ) : undefined
                }
            >
                <div className="space-y-5">
                    <h2
                        tabIndex={-1}
                        data-step-heading
                        className="text-section-title focus-visible:outline-none"
                    >
                        {steps[step].label}
                    </h2>
                    {failure && (
                        <Alert variant="warning">
                            <AlertTitle>Submission not confirmed</AlertTitle>
                            <AlertDescription>{failure}</AlertDescription>
                        </Alert>
                    )}
                    {Object.keys(errors).length > 0 && (
                        <Alert variant="destructive">
                            <AlertTitle>Please check your answers</AlertTitle>
                            <AlertDescription>
                                <ul className="list-disc pl-4">
                                    {Object.entries(errors).map(
                                        ([key, message]) => (
                                            <li key={key}>{message}</li>
                                        ),
                                    )}
                                </ul>
                            </AlertDescription>
                        </Alert>
                    )}
                    {step === 0 && (
                        <div className="space-y-5">
                            <Field label="What would you like help with?">
                                <SelectInput
                                    ariaLabel="What would you like help with?"
                                    placeholder="Choose an option"
                                    value={form.purpose}
                                    options={purposes}
                                    onChange={(value) =>
                                        update('purpose', value)
                                    }
                                />
                            </Field>
                            <Field label="Who are you contacting us for?">
                                <SelectInput
                                    ariaLabel="Who are you contacting us for?"
                                    placeholder="Choose an option"
                                    value={form.relationship}
                                    options={relationships}
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
                                />
                            </Field>
                            {form.relationship !== 'self' && (
                                <Field label="Authority to share information">
                                    <SelectInput
                                        ariaLabel="Authority to share information"
                                        placeholder="Choose an option"
                                        value={form.authority_status}
                                        options={[
                                            {
                                                value: 'unsure',
                                                label: 'I would like guidance before sharing their details',
                                            },
                                            {
                                                value: 'confirmed',
                                                label: 'I have their permission or another applicable authority',
                                            },
                                        ]}
                                        onChange={(value) =>
                                            update('authority_status', value)
                                        }
                                    />
                                </Field>
                            )}
                            {minimal && (
                                <Alert variant="info">
                                    <AlertDescription>
                                        We can discuss the process first. Please
                                        do not include another person’s
                                        identifying or health details. Staff
                                        will help establish the appropriate
                                        authority.
                                    </AlertDescription>
                                </Alert>
                            )}
                            {form.relationship === 'professional' &&
                                text(
                                    'organisation',
                                    'Organisation',
                                    form.organisation,
                                    (value) => update('organisation', value),
                                )}
                        </div>
                    )}
                    {step === 1 && (
                        <div className="grid gap-5 sm:grid-cols-2">
                            {text(
                                'contact.name',
                                'Your name',
                                form.contact.name,
                                (value) =>
                                    update('contact', {
                                        ...form.contact,
                                        name: value,
                                    }),
                                true,
                            )}
                            <Field label="Safest way to contact you" required>
                                <SelectInput
                                    ariaLabel="Safest way to contact you"
                                    placeholder="Choose a channel"
                                    value={form.contact.channel}
                                    options={[
                                        { value: 'phone', label: 'Phone call' },
                                        { value: 'email', label: 'Email' },
                                    ]}
                                    onChange={(value) =>
                                        update('contact', {
                                            ...form.contact,
                                            channel: value,
                                        })
                                    }
                                />
                            </Field>
                            {text(
                                'contact.phone',
                                'Phone number',
                                form.contact.phone,
                                (value) =>
                                    update('contact', {
                                        ...form.contact,
                                        phone: value,
                                    }),
                                form.contact.channel === 'phone',
                                'tel',
                            )}
                            {text(
                                'contact.email',
                                'Email address',
                                form.contact.email,
                                (value) =>
                                    update('contact', {
                                        ...form.contact,
                                        email: value,
                                    }),
                                form.contact.channel === 'email',
                                'email',
                            )}
                            {text(
                                'contact.safe_time',
                                'Safe time to contact you',
                                form.contact.safe_time,
                                (value) =>
                                    update('contact', {
                                        ...form.contact,
                                        safe_time: value,
                                    }),
                            )}
                            <div className="flex items-center gap-3">
                                <Checkbox
                                    id="leave-message"
                                    checked={form.contact.leave_message}
                                    onCheckedChange={(value) =>
                                        update('contact', {
                                            ...form.contact,
                                            leave_message: value === true,
                                        })
                                    }
                                />
                                <Label
                                    htmlFor="leave-message"
                                    className="frontline-tap flex items-center"
                                >
                                    It is safe to leave a message
                                </Label>
                            </div>
                            <Field
                                span
                                label="Communication or interpreter needs"
                            >
                                <Textarea
                                    id="communication"
                                    maxLength={1000}
                                    value={form.contact.communication}
                                    onChange={(event) =>
                                        update('contact', {
                                            ...form.contact,
                                            communication: event.target.value,
                                        })
                                    }
                                />
                            </Field>
                        </div>
                    )}
                    {step === 2 && (
                        <div className="grid gap-5 sm:grid-cols-2">
                            {!minimal && (
                                <>
                                    {text(
                                        'person.first_name',
                                        'Person’s first name (optional)',
                                        form.person.first_name,
                                        (value) =>
                                            update('person', {
                                                ...form.person,
                                                first_name: value,
                                            }),
                                    )}
                                    {text(
                                        'person.last_name',
                                        'Person’s last name (optional)',
                                        form.person.last_name,
                                        (value) =>
                                            update('person', {
                                                ...form.person,
                                                last_name: value,
                                            }),
                                    )}
                                    {text(
                                        'person.preferred_name',
                                        'Preferred name (optional)',
                                        form.person.preferred_name,
                                        (value) =>
                                            update('person', {
                                                ...form.person,
                                                preferred_name: value,
                                            }),
                                    )}
                                </>
                            )}
                            {text(
                                'region',
                                'Region or area',
                                form.region,
                                (value) => update('region', value),
                            )}
                            <Field label="Support being considered">
                                <SelectInput
                                    ariaLabel="Support being considered"
                                    placeholder="Choose support"
                                    value={form.requested_service}
                                    options={[
                                        {
                                            value: 'unsure',
                                            label: 'Not sure yet',
                                        },
                                        ...intake.services.map((service) => ({
                                            value: service.id,
                                            label: service.label,
                                        })),
                                    ]}
                                    onChange={(value) =>
                                        update('requested_service', value)
                                    }
                                />
                            </Field>
                            <Field label="Funding situation">
                                <SelectInput
                                    ariaLabel="Funding situation"
                                    placeholder="Choose funding situation"
                                    value={form.funding_situation}
                                    options={funding}
                                    onChange={(value) =>
                                        update('funding_situation', value)
                                    }
                                />
                            </Field>
                            {text(
                                'funding_agency',
                                'Agency or funding host (optional)',
                                form.funding_agency,
                                (value) => update('funding_agency', value),
                            )}
                            {!minimal && (
                                <IntakePathwaySelect
                                    options={intake.pathways ?? []}
                                    service={form.requested_service}
                                    value={form.funding_pathway_id}
                                    onChange={(value) =>
                                        update('funding_pathway_id', value)
                                    }
                                />
                            )}
                            {text(
                                'timeframe',
                                'Preferred timeframe (optional)',
                                form.timeframe,
                                (value) => update('timeframe', value),
                            )}
                            <Field
                                span
                                label={
                                    minimal
                                        ? 'What would you like to ask about the process?'
                                        : 'What would you like support with?'
                                }
                            >
                                <Textarea
                                    id="goals"
                                    maxLength={3000}
                                    value={form.goals}
                                    onChange={(event) =>
                                        update('goals', event.target.value)
                                    }
                                />
                            </Field>
                            <p className="text-subtle sm:col-span-2">
                                Funding assistance helps you explore and apply
                                for support. Funding decisions are made by the
                                relevant agency. A personal income payment is
                                separate from provider service funding.
                            </p>
                            {questions.length > 0 && (
                                <div className="sm:col-span-2">
                                    <FormQuestions
                                        fields={questions}
                                        answers={form.answers}
                                        onChange={(answers) =>
                                            update('answers', answers)
                                        }
                                        errors={errors}
                                    />
                                </div>
                            )}
                        </div>
                    )}
                    {step === 3 && (
                        <div className="space-y-5">
                            {questions.length > 0 && (
                                <ReviewCard
                                    icon={ClipboardCheck}
                                    title="Additional information"
                                    onEdit={
                                        busy || recovering
                                            ? undefined
                                            : () => go(2)
                                    }
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
                                icon={UserRound}
                                title="Your contact preferences"
                                onEdit={
                                    busy || recovering ? undefined : () => go(1)
                                }
                            >
                                <ReviewRow
                                    label="Name"
                                    value={form.contact.name}
                                />
                                <ReviewRow
                                    label="Safe contact"
                                    value={
                                        form.contact.channel === 'phone'
                                            ? form.contact.phone
                                            : form.contact.email
                                    }
                                />
                                <ReviewRow
                                    label="Safe time"
                                    value={form.contact.safe_time}
                                />
                                <ReviewRow
                                    label="Leave a message"
                                    value={
                                        form.contact.leave_message
                                            ? 'Yes'
                                            : 'No'
                                    }
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={Wallet}
                                title="Your enquiry"
                                onEdit={
                                    busy || recovering ? undefined : () => go(2)
                                }
                            >
                                <ReviewRow
                                    label="Purpose"
                                    value={
                                        purposes.find(
                                            (item) =>
                                                item.value === form.purpose,
                                        )?.label
                                    }
                                />
                                <ReviewRow label="Region" value={form.region} />
                                <ReviewRow
                                    label="Contacting us for"
                                    value={
                                        relationships.find(
                                            (item) =>
                                                item.value ===
                                                form.relationship,
                                        )?.label
                                    }
                                />
                                <ReviewRow
                                    label="Organisation"
                                    value={form.organisation}
                                />
                                <ReviewRow
                                    label="Person"
                                    value={
                                        minimal
                                            ? 'Process enquiry — no person details shared'
                                            : [
                                                  form.person.first_name,
                                                  form.person.last_name,
                                              ]
                                                  .filter(Boolean)
                                                  .join(' ')
                                    }
                                />
                                {!minimal && (
                                    <ReviewRow
                                        label="Preferred name"
                                        value={form.person.preferred_name}
                                    />
                                )}
                                <ReviewRow
                                    label="Support"
                                    value={
                                        intake.services.find(
                                            (service) =>
                                                service.id ===
                                                form.requested_service,
                                        )?.label ?? 'Not sure yet'
                                    }
                                />
                                <ReviewRow
                                    label="Timeframe"
                                    value={form.timeframe}
                                />
                                <ReviewRow
                                    label="Agency or host"
                                    value={form.funding_agency}
                                />
                                {!minimal && (
                                    <ReviewRow
                                        label="Reported pathway (unverified)"
                                        value={
                                            reportedPathway(
                                                intake.pathways ?? [],
                                                form.requested_service,
                                                form.funding_pathway_id,
                                            )?.label ?? 'Not sure yet'
                                        }
                                    />
                                )}
                                <ReviewRow
                                    label="Communication needs"
                                    value={form.contact.communication}
                                />
                                <ReviewRow
                                    label="Funding"
                                    value={
                                        funding.find(
                                            (item) =>
                                                item.value ===
                                                form.funding_situation,
                                        )?.label
                                    }
                                />
                                <ReviewRow
                                    label="What matters to you"
                                    value={form.goals}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={ShieldCheck}
                                title="How we use your information"
                            >
                                <p className="text-subtle whitespace-pre-wrap">
                                    {intake.notice}
                                </p>
                                <p className="text-caption mt-3">
                                    Notice version: {intake.notice_version}
                                </p>
                            </ReviewCard>
                            {(errors.notice_version ||
                                errors.settings_version_id) && (
                                <Button
                                    variant="outline"
                                    className="frontline-tap h-auto py-2 whitespace-normal"
                                    disabled={busy}
                                    onClick={refreshNotice}
                                >
                                    Load the latest notice and keep my answers
                                </Button>
                            )}
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="contact-permission"
                                    disabled={busy || recovering}
                                    checked={form.contact_permission}
                                    onCheckedChange={(value) =>
                                        update(
                                            'contact_permission',
                                            value === true,
                                        )
                                    }
                                />
                                <Label
                                    htmlFor="contact-permission"
                                    className="frontline-tap flex items-start"
                                >
                                    You may contact me about this enquiry using
                                    my safe contact preferences.
                                </Label>
                            </div>
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="privacy-acknowledged"
                                    disabled={busy || recovering}
                                    checked={form.privacy_acknowledged}
                                    onCheckedChange={(value) =>
                                        update(
                                            'privacy_acknowledged',
                                            value === true,
                                        )
                                    }
                                />
                                <Label
                                    htmlFor="privacy-acknowledged"
                                    className="frontline-tap flex items-start"
                                >
                                    I have read the information collection
                                    notice.
                                </Label>
                            </div>
                            <div className="hidden" aria-hidden="true">
                                <Input
                                    name="website"
                                    tabIndex={-1}
                                    autoComplete="off"
                                    value={form.website}
                                    onChange={(event) =>
                                        update('website', event.target.value)
                                    }
                                />
                            </div>
                        </div>
                    )}
                </div>
            </WizardShell>
            {leave.confirmation}
        </MarketingLayout>
    );
}
