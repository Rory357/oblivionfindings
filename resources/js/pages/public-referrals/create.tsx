import { ConfirmDialog } from '@/components/confirm-dialog';
import { phoneUrl } from '@/components/public-provider';
import { helpOptions } from '@/components/public-site/content';
import {
    EmergencyNote,
    NoteLine,
    PageIntro,
    PlanPathway,
    RadioTiles,
    type TileOption,
} from '@/components/public-site/primitives';
import { usePublicSite } from '@/components/public-site/site';
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
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { useClipboard } from '@/hooks/use-clipboard';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import PublicSiteLayout from '@/layouts/marketing-layout';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    ArrowRight,
    ClipboardCheck,
    HeartHandshake,
    LockKeyhole,
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

/** Wizards use the 1100 px pop-up width (480 / 720 / 900 / 1100 only). */
const WIZARD_WIDTH = 'min(94vw, 1100px)';

const steps = [
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

const purposes: TileOption<string>[] = [
    ...helpOptions.map((option) => ({
        value: option.value,
        title: option.title,
        body: option.time,
        icon: option.icon,
    })),
];
/** Older links ask for a general enquiry; Contact us now handles those. */
const businessPurpose: TileOption<string> = {
    value: 'business',
    title: 'General enquiry',
    body: 'About 2 minutes',
    icon: 'MessageSquareText',
};
const relationships: TileOption<string>[] = [
    { value: 'self', title: 'Myself', icon: 'User' },
    { value: 'family', title: 'A family or whānau member', icon: 'Users' },
    { value: 'supporter', title: 'Someone I support', icon: 'HandHeart' },
    {
        value: 'professional',
        title: 'A professional or agency referral',
        icon: 'Building2',
    },
];
const generalRelationship: TileOption<string> = {
    value: 'general',
    title: 'A general enquiry',
    icon: 'MessageSquareText',
};
const authorities: TileOption<string>[] = [
    {
        value: 'confirmed',
        title: 'I have their permission',
        body: 'Or another authority to share their details',
        icon: 'ShieldCheck',
    },
    {
        value: 'unsure',
        title: 'I’d like guidance first',
        body: 'Ask about the process without sharing their details',
        icon: 'MessageSquareText',
    },
];
const channels: TileOption<string>[] = [
    { value: 'phone', title: 'Phone call', icon: 'PhoneCall' },
    { value: 'email', title: 'Email', icon: 'Mail' },
];
const funding: TileOption<string>[] = [
    { value: 'need_help', title: 'I’d like help with funding', icon: 'Wallet' },
    {
        value: 'underway',
        title: 'An assessment or application is underway',
        icon: 'Stethoscope',
    },
    { value: 'arranged', title: 'Funding is arranged', icon: 'HandHeart' },
    {
        value: 'private',
        title: 'Considering private payment',
        icon: 'Receipt',
    },
    { value: 'not_explored', title: 'Not explored yet', icon: 'Compass' },
    { value: 'unsure', title: 'Not sure yet', icon: 'User' },
];
const PAGE_FIELDS = [
    'authority_status',
    'relationship',
    'purpose',
    'organisation',
];

/** Which part of the flow shows a field: -1 is the page (the tiles), then
 *  the wizard's steps. */
function errorStep(field: string): number {
    if (PAGE_FIELDS.includes(field)) return -1;
    if (field.startsWith('contact.')) return 0;
    if (
        field.startsWith('answers') ||
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
        return 1;
    return 2;
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
    const { url } = usePage();
    const { name } = usePublicSite();
    // The header's "Make a referral" asks nothing yet: the tiles start empty
    // unless a link chose a purpose (Home's "Help with funding", a footer
    // "Request a callback").
    const purposeAsked =
        initialPurpose !== 'support' ||
        new URLSearchParams(url.split('?')[1] ?? '').has('purpose');
    const [purposeChosen, setPurposeChosen] = useState(purposeAsked);
    const [relationshipChosen, setRelationshipChosen] = useState(false);
    const [open, setOpen] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);
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
    type Form = typeof form;
    type Submission = Omit<Form, 'person'> & {
        person: Partial<Form['person']>;
    };
    const attempted = useRef<Submission | null>(null);
    const [initial] = useState(form);
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
    const update = <K extends keyof Form>(key: K, value: Form[K]) =>
        setForm((previous) => ({ ...previous, [key]: value }));
    const contact = (patch: Partial<Form['contact']>) =>
        setForm((previous) => ({
            ...previous,
            contact: { ...previous.contact, ...patch },
        }));
    const clearError = (key: string) =>
        setErrors((previous) => {
            if (!(key in previous)) return previous;
            const next = { ...previous };
            delete next[key];
            return next;
        });
    const go = (next: number) => {
        setStep(next);
        requestAnimationFrame(() => {
            bodyRef.current?.scrollTo({ top: 0 });
            bodyRef.current
                ?.querySelector<HTMLElement>('[data-step-heading]')
                ?.focus();
        });
    };
    const chosen = helpOptions.find((option) => option.value === form.purpose);
    const showRelationship = form.purpose !== 'callback';
    // Like the purpose, "who it's for" is an explicit choice (mockup v2): a
    // preselected "Myself" is easy to miss when referring someone else.
    const ready =
        purposeChosen &&
        (!showRelationship ||
            (relationshipChosen &&
                (form.relationship === 'self' ||
                    form.authority_status !== 'self')));
    // The wizard's own answers, not the tiles chosen on the page.
    const wizardDirty =
        JSON.stringify({
            ...form,
            purpose: initial.purpose,
            relationship: initial.relationship,
            authority_status: initial.authority_status,
            organisation: initial.organisation,
        }) !== initialSnapshot;

    function openWizard() {
        setErrors({});
        setOpen(true);
    }
    function requestClose() {
        if (busy) return;
        // A receipt, a submission being recovered or nothing typed yet: the
        // answers stay on this page, so closing loses nothing.
        if (receipt || recovering || !wizardDirty) setOpen(false);
        else setConfirmClose(true);
    }
    function discard() {
        setForm((previous) => ({
            ...initial,
            purpose: previous.purpose,
            relationship: previous.relationship,
            authority_status: previous.authority_status,
            organisation: previous.organisation,
        }));
        attempted.current = null;
        setErrors({});
        setFailure('');
        setStep(0);
        setOpen(false);
    }
    function next() {
        const problems: Record<string, string> = {};
        if (step === 0) {
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
        if (step === 1)
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
                const first = Math.min(...Object.keys(problems).map(errorStep));
                if (first < 0) {
                    // The choices on the page: close the wizard over them.
                    setOpen(false);
                    requestAnimationFrame(() =>
                        document
                            .getElementById(
                                Object.keys(problems).find((key) =>
                                    PAGE_FIELDS.includes(key),
                                ) ?? 'purpose',
                            )
                            ?.focus(),
                    );
                } else go(first);
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
                    go(2);
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
        autoComplete?: string,
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
                autoComplete={autoComplete}
                maxLength={key === 'contact.email' ? 254 : 200}
                onChange={(event) => change(event.target.value)}
                aria-invalid={!!errors[key]}
                aria-describedby={errors[key] ? `${key}-error` : undefined}
            />
        </Field>
    );
    const hours = intake.response_hours?.trim();
    const pageErrors = PAGE_FIELDS.filter((key) => errors[key]);

    return (
        <PublicSiteLayout
            title="Make a referral"
            description="Make a referral, ask about support, get help with funding or request a callback."
            current="referral"
            intro={
                <PageIntro
                    eyebrow="Make a referral"
                    title="Make a referral or ask about support"
                    lede="For yourself, someone in your whānau, or someone you work with. You don’t need a diagnosis, NHI number, funding approval or a confirmed place to get in touch."
                />
            }
        >
            <div className="journey-site-container grid items-start gap-6 py-12 lg:grid-cols-[1.6fr_1fr]">
                <section
                    aria-label="Start a referral"
                    className="space-y-8 rounded-3xl border border-border bg-card p-6 md:p-8"
                >
                    {!intake.enabled ? (
                        <div className="space-y-4">
                            <Alert variant="info">
                                <AlertTitle>
                                    Online enquiries are currently unavailable
                                </AlertTitle>
                                <AlertDescription>
                                    Please contact us to talk about support or
                                    to make a referral by phone.
                                </AlertDescription>
                            </Alert>
                            {intake.contact_phone && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="frontline-tap"
                                >
                                    <a href={phoneUrl(intake.contact_phone)}>
                                        <Phone className="size-4" aria-hidden />
                                        {intake.contact_phone}
                                    </a>
                                </Button>
                            )}
                        </div>
                    ) : (
                        <>
                            {pageErrors.length > 0 && (
                                <Alert variant="destructive">
                                    <AlertTitle>
                                        Please check your choices
                                    </AlertTitle>
                                    <AlertDescription>
                                        <ul className="list-disc pl-4">
                                            {pageErrors.map((key) => (
                                                <li key={key}>{errors[key]}</li>
                                            ))}
                                        </ul>
                                    </AlertDescription>
                                </Alert>
                            )}
                            <RadioTiles
                                id="purpose"
                                label="What would you like to do?"
                                value={purposeChosen ? form.purpose : null}
                                onChange={(value) => {
                                    setPurposeChosen(true);
                                    update('purpose', value);
                                    clearError('purpose');
                                }}
                                error={errors.purpose}
                                disabled={busy}
                                options={
                                    initialPurpose === 'business'
                                        ? [...purposes, businessPurpose]
                                        : purposes
                                }
                            />
                            {showRelationship && (
                                <RadioTiles
                                    id="relationship"
                                    label="Who is the support for?"
                                    value={
                                        relationshipChosen
                                            ? form.relationship
                                            : null
                                    }
                                    onChange={(value) => {
                                        setRelationshipChosen(true);
                                        setForm((previous) => ({
                                            ...previous,
                                            relationship: value,
                                            authority_status:
                                                value === 'self'
                                                    ? 'self'
                                                    : previous.authority_status ===
                                                        'self'
                                                      ? 'unsure'
                                                      : previous.authority_status,
                                        }));
                                        clearError('relationship');
                                        clearError('authority_status');
                                    }}
                                    error={errors.relationship}
                                    disabled={busy}
                                    options={
                                        form.purpose === 'business'
                                            ? [
                                                  ...relationships,
                                                  generalRelationship,
                                              ]
                                            : relationships
                                    }
                                />
                            )}
                            {showRelationship &&
                                relationshipChosen &&
                                form.relationship !== 'self' && (
                                    <div className="space-y-4 rounded-2xl bg-muted p-4 md:p-5">
                                        <RadioTiles
                                            id="authority_status"
                                            label="Can you share their details with us?"
                                            value={
                                                form.authority_status === 'self'
                                                    ? null
                                                    : form.authority_status
                                            }
                                            onChange={(value) => {
                                                update(
                                                    'authority_status',
                                                    value,
                                                );
                                                clearError('authority_status');
                                            }}
                                            error={errors.authority_status}
                                            disabled={busy}
                                            options={authorities}
                                        />
                                        {minimal && (
                                            <Alert variant="info">
                                                <AlertDescription>
                                                    We can talk about the
                                                    process first. Please don’t
                                                    include another person’s
                                                    identifying or health
                                                    details. We’ll help work out
                                                    the right authority.
                                                </AlertDescription>
                                            </Alert>
                                        )}
                                        {form.relationship === 'professional' &&
                                            text(
                                                'organisation',
                                                'Organisation',
                                                form.organisation,
                                                (value) => {
                                                    update(
                                                        'organisation',
                                                        value,
                                                    );
                                                    clearError('organisation');
                                                },
                                                false,
                                                'text',
                                                'organization',
                                            )}
                                    </div>
                                )}
                            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-6">
                                <p className="max-w-md text-sm text-muted-foreground">
                                    {receipt
                                        ? `Your reference is ${receipt}.`
                                        : purposeChosen && chosen
                                          ? chosen.body
                                          : 'Choose an option to start. You can change it later.'}
                                </p>
                                <Button
                                    size="lg"
                                    className="frontline-tap"
                                    disabled={!ready && !receipt}
                                    onClick={openWizard}
                                >
                                    {receipt ? 'View your receipt' : 'Continue'}
                                    {!receipt && (
                                        <ArrowRight
                                            className="size-4"
                                            aria-hidden
                                        />
                                    )}
                                </Button>
                            </div>
                        </>
                    )}
                </section>
                <aside className="space-y-4">
                    <div className="rounded-3xl border border-border bg-card p-6">
                        <p className="journey-site-kicker">What happens next</p>
                        <PlanPathway
                            steps={[
                                {
                                    title: 'We save your referral',
                                    body: 'You get a reference straight away.',
                                },
                                {
                                    title: 'An intake coordinator contacts you',
                                    body: `The way you choose.${hours ? ` We reply ${hours}.` : ''}`,
                                },
                                {
                                    title: 'We work out the next step together',
                                    body: 'Including funding, if you’d like help with it.',
                                },
                            ]}
                        />
                    </div>
                    <div className="space-y-3 rounded-3xl border border-border bg-card p-6">
                        <NoteLine icon={LockKeyhole}>
                            Only our intake team sees what you share. You can
                            ask about the process before sharing anyone’s
                            personal details.
                        </NoteLine>
                        {intake.contact_phone && (
                            <NoteLine icon={Phone}>
                                Prefer to talk? Call{' '}
                                <a
                                    href={phoneUrl(intake.contact_phone)}
                                    className="font-semibold text-primary hover:underline"
                                >
                                    {intake.contact_phone}
                                </a>
                                .
                            </NoteLine>
                        )}
                        <EmergencyNote />
                    </div>
                </aside>
            </div>

            <WizardShell
                open={open}
                onClose={requestClose}
                maxWidth={WIZARD_WIDTH}
                title={chosen?.title ?? 'Make a referral'}
                description={`Send a referral safely to the ${name ? `${name} ` : ''}intake team.`}
                railIcon={HeartHandshake}
                railTitle={chosen?.title ?? 'Make a referral'}
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
                        variant="outline"
                        disabled={busy || recovering}
                        onClick={() =>
                            step > 0 ? go(step - 1) : requestClose()
                        }
                    >
                        {step > 0 ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    step < steps.length - 1 ? (
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
                                ? 'Sending…'
                                : recovering
                                  ? 'Retry this submission'
                                  : 'Send referral'}
                        </Button>
                    )
                }
                success={
                    receipt ? (
                        <WizardSuccessPane
                            title="Thanks — your referral is with us"
                            blurb={
                                <>
                                    <span className="block break-all select-all">
                                        Your reference is{' '}
                                        <strong>{receipt}</strong>.
                                    </span>
                                    <span role="status" className="mt-2 block">
                                        {copyFailed
                                            ? 'Copy is unavailable. Select the reference above to keep it.'
                                            : copiedReference === receipt
                                              ? 'Reference copied.'
                                              : 'This reference isn’t a login — keep it if you call us.'}
                                    </span>
                                    <span className="mt-3 block">
                                        An intake coordinator will contact you
                                        by{' '}
                                        {form.contact.channel === 'email'
                                            ? 'email'
                                            : 'phone'}
                                        {hours ? ` (we reply ${hours})` : ''}.
                                        This receipt doesn’t confirm funding or
                                        a place.
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
                <WizardStepPane key={step}>
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
                                <AlertTitle>
                                    Submission not confirmed
                                </AlertTitle>
                                <AlertDescription>{failure}</AlertDescription>
                            </Alert>
                        )}
                        {Object.keys(errors).length > 0 && (
                            <Alert variant="destructive">
                                <AlertTitle>
                                    Please check your answers
                                </AlertTitle>
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
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {text(
                                        'contact.name',
                                        'Your name',
                                        form.contact.name,
                                        (value) => contact({ name: value }),
                                        true,
                                        'text',
                                        'name',
                                    )}
                                    {text(
                                        'contact.phone',
                                        form.contact.channel === 'phone'
                                            ? 'Phone number'
                                            : 'Phone number (optional)',
                                        form.contact.phone,
                                        (value) => contact({ phone: value }),
                                        form.contact.channel === 'phone',
                                        'tel',
                                        'tel',
                                    )}
                                    <div className="sm:col-span-2">
                                        {text(
                                            'contact.email',
                                            form.contact.channel === 'email'
                                                ? 'Email address'
                                                : 'Email address (optional)',
                                            form.contact.email,
                                            (value) =>
                                                contact({ email: value }),
                                            form.contact.channel === 'email',
                                            'email',
                                            'email',
                                        )}
                                    </div>
                                </div>
                                <RadioTiles
                                    id="contact-channel"
                                    label="How should we contact you?"
                                    value={form.contact.channel}
                                    onChange={(value) =>
                                        contact({ channel: value })
                                    }
                                    columns="sm:grid-cols-2"
                                    options={channels}
                                />
                                {text(
                                    'contact.safe_time',
                                    'Best time to reach you',
                                    form.contact.safe_time,
                                    (value) => contact({ safe_time: value }),
                                )}
                                <div className="flex items-center justify-between gap-4 rounded-2xl bg-muted p-4">
                                    <span>
                                        <Label
                                            htmlFor="leave-message"
                                            className="block font-medium"
                                        >
                                            We can leave a message
                                        </Label>
                                        <span className="block text-sm text-muted-foreground">
                                            We’ll only say who we are and ask
                                            you to call back.
                                        </span>
                                    </span>
                                    <span className="flex items-center gap-2 text-sm font-medium">
                                        {form.contact.leave_message
                                            ? 'Yes'
                                            : 'No'}
                                        <Switch
                                            id="leave-message"
                                            checked={form.contact.leave_message}
                                            onCheckedChange={(value) =>
                                                contact({
                                                    leave_message: value,
                                                })
                                            }
                                        />
                                    </span>
                                </div>
                                <Field label="Communication or interpreter needs (optional)">
                                    <Input
                                        id="communication"
                                        maxLength={1000}
                                        placeholder="For example, NZ Sign Language or te reo Māori"
                                        value={form.contact.communication}
                                        onChange={(event) =>
                                            contact({
                                                communication:
                                                    event.target.value,
                                            })
                                        }
                                    />
                                </Field>
                            </div>
                        )}
                        {step === 1 && (
                            <div className="space-y-5">
                                <Field
                                    label={
                                        minimal
                                            ? 'What would you like to ask about the process?'
                                            : 'What would you like support with?'
                                    }
                                >
                                    <Textarea
                                        id="goals"
                                        rows={4}
                                        maxLength={3000}
                                        value={form.goals}
                                        aria-describedby="goals-hint"
                                        onChange={(event) =>
                                            update('goals', event.target.value)
                                        }
                                    />
                                </Field>
                                <p
                                    id="goals-hint"
                                    className="text-caption -mt-3 text-muted-foreground"
                                >
                                    A few words is fine. “Not sure yet” is a
                                    good answer too.
                                </p>
                                {intake.services.length > 0 && (
                                    <RadioTiles
                                        id="requested_service"
                                        label="Support you’re thinking about"
                                        value={form.requested_service}
                                        onChange={(value) =>
                                            update('requested_service', value)
                                        }
                                        options={[
                                            ...intake.services.map(
                                                (service) => ({
                                                    value: service.id,
                                                    title: service.label,
                                                    icon: 'HeartHandshake',
                                                }),
                                            ),
                                            {
                                                value: 'unsure',
                                                title: 'Not sure yet',
                                                icon: 'Compass',
                                            },
                                        ]}
                                    />
                                )}
                                <RadioTiles
                                    id="funding_situation"
                                    label="Where are you with funding?"
                                    value={form.funding_situation}
                                    onChange={(value) =>
                                        update('funding_situation', value)
                                    }
                                    options={funding}
                                />
                                <div className="space-y-4 rounded-2xl bg-muted p-4 md:p-5">
                                    <p className="font-semibold">
                                        More details, if you know them
                                    </p>
                                    <div className="grid gap-4 sm:grid-cols-2">
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
                                                            preferred_name:
                                                                value,
                                                        }),
                                                )}
                                            </>
                                        )}
                                        {text(
                                            'region',
                                            'Region or area (optional)',
                                            form.region,
                                            (value) => update('region', value),
                                        )}
                                        {text(
                                            'timeframe',
                                            'Preferred timeframe (optional)',
                                            form.timeframe,
                                            (value) =>
                                                update('timeframe', value),
                                        )}
                                        {text(
                                            'funding_agency',
                                            'Agency or funding host (optional)',
                                            form.funding_agency,
                                            (value) =>
                                                update('funding_agency', value),
                                        )}
                                        {!minimal && (
                                            <IntakePathwaySelect
                                                options={intake.pathways ?? []}
                                                service={form.requested_service}
                                                value={form.funding_pathway_id}
                                                onChange={(value) =>
                                                    update(
                                                        'funding_pathway_id',
                                                        value,
                                                    )
                                                }
                                            />
                                        )}
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        We help you explore and apply for
                                        funding. The funder makes the decision.
                                        A personal income payment is separate
                                        from funding for support.
                                    </p>
                                </div>
                                {questions.length > 0 && (
                                    <FormQuestions
                                        fields={questions}
                                        answers={form.answers}
                                        onChange={(answers) =>
                                            update('answers', answers)
                                        }
                                        errors={errors}
                                    />
                                )}
                            </div>
                        )}
                        {step === 2 && (
                            <div className="space-y-5">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <ReviewCard
                                        icon={UserRound}
                                        title="Safe contact"
                                        onEdit={
                                            busy || recovering
                                                ? undefined
                                                : () => go(0)
                                        }
                                    >
                                        <ReviewRow
                                            label="Name"
                                            value={form.contact.name}
                                        />
                                        <ReviewRow
                                            label="Contact by"
                                            value={
                                                form.contact.channel === 'phone'
                                                    ? `Phone · ${form.contact.phone}`
                                                    : `Email · ${form.contact.email}`
                                            }
                                        />
                                        <ReviewRow
                                            label="Best time"
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
                                        <ReviewRow
                                            label="Communication needs"
                                            value={form.contact.communication}
                                        />
                                    </ReviewCard>
                                    <ReviewCard
                                        icon={Wallet}
                                        title="Support and funding"
                                        onEdit={
                                            busy || recovering
                                                ? undefined
                                                : () => go(1)
                                        }
                                    >
                                        <ReviewRow
                                            label="Help with"
                                            value={
                                                (form.purpose === 'business'
                                                    ? businessPurpose
                                                    : purposes.find(
                                                          (item) =>
                                                              item.value ===
                                                              form.purpose,
                                                      )
                                                )?.title
                                            }
                                        />
                                        {form.purpose !== 'callback' && (
                                            <ReviewRow
                                                label="Support for"
                                                value={
                                                    [
                                                        ...relationships,
                                                        generalRelationship,
                                                    ].find(
                                                        (item) =>
                                                            item.value ===
                                                            form.relationship,
                                                    )?.title
                                                }
                                            />
                                        )}
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
                                                          form.person
                                                              .first_name,
                                                          form.person.last_name,
                                                      ]
                                                          .filter(Boolean)
                                                          .join(' ')
                                            }
                                        />
                                        {!minimal && (
                                            <ReviewRow
                                                label="Preferred name"
                                                value={
                                                    form.person.preferred_name
                                                }
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
                                            label="Funding"
                                            value={
                                                funding.find(
                                                    (item) =>
                                                        item.value ===
                                                        form.funding_situation,
                                                )?.title
                                            }
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
                                            label="Agency or host"
                                            value={form.funding_agency}
                                        />
                                        <ReviewRow
                                            label="Region"
                                            value={form.region}
                                        />
                                        <ReviewRow
                                            label="Timeframe"
                                            value={form.timeframe}
                                        />
                                        <ReviewRow
                                            label="What matters to you"
                                            value={form.goals}
                                        />
                                    </ReviewCard>
                                    {questions.length > 0 && (
                                        <ReviewCard
                                            icon={ClipboardCheck}
                                            title="Additional information"
                                            span
                                            onEdit={
                                                busy || recovering
                                                    ? undefined
                                                    : () => go(1)
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
                                        icon={ShieldCheck}
                                        title="Your information"
                                        span
                                    >
                                        <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                                            {intake.notice}
                                        </p>
                                        <p className="text-caption mt-3 text-muted-foreground">
                                            Notice version:{' '}
                                            {intake.notice_version}
                                        </p>
                                    </ReviewCard>
                                </div>
                                {(errors.notice_version ||
                                    errors.settings_version_id) && (
                                    <Button
                                        variant="outline"
                                        className="frontline-tap h-auto py-2 whitespace-normal"
                                        disabled={busy}
                                        onClick={refreshNotice}
                                    >
                                        Load the latest notice and keep my
                                        answers
                                    </Button>
                                )}
                                <div className="space-y-1 rounded-2xl bg-muted p-4">
                                    <div className="flex items-start gap-3">
                                        <Checkbox
                                            id="contact-permission"
                                            className="mt-3.5"
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
                                            className="frontline-tap flex items-center leading-snug"
                                        >
                                            You may contact me about this
                                            referral using my safe contact
                                            preferences.
                                        </Label>
                                    </div>
                                    <div className="flex items-start gap-3">
                                        <Checkbox
                                            id="privacy-acknowledged"
                                            className="mt-3.5"
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
                                            className="frontline-tap flex items-center leading-snug"
                                        >
                                            I have read how my information is
                                            used.
                                        </Label>
                                    </div>
                                </div>
                                <div className="hidden" aria-hidden="true">
                                    <Input
                                        name="website"
                                        tabIndex={-1}
                                        autoComplete="off"
                                        value={form.website}
                                        onChange={(event) =>
                                            update(
                                                'website',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>

            <ConfirmDialog
                open={confirmClose}
                onClose={() => setConfirmClose(false)}
                onConfirm={discard}
                variant="destructive"
                frontline
                title="Discard this referral?"
                description="What you’ve entered so far won’t be sent or saved."
                confirmText="Discard referral"
                cancelText="Keep going"
            />
            {leave.confirmation}
        </PublicSiteLayout>
    );
}
