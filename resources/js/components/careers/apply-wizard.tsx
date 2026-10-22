// The careers apply flow in the shared WizardShell (mockup v2, approved by
// Stephan 23 Oct 2026): About you → Experience → Right to work (with the
// police vetting note) → Review and send. The field set is the real careers
// form; experience, right to work and driver licence are new Recruitment
// fields. It posts to the existing apply endpoint.
import {
    applyUrl,
    employmentType,
    type PublicJob,
} from '@/components/careers/careers-parts';
import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import {
    Lens,
    RadioTiles,
    type TileOption,
} from '@/components/public-site/primitives';
import { siteLinks } from '@/components/public-site/site';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    sourceChannelOptions,
    type DriverLicenceClass,
    type RightToWorkStatus,
    type YearsOfExperience,
} from '@/lib/job-posting-constants';
import { Link, router } from '@inertiajs/react';
import {
    Briefcase,
    ClipboardCheck,
    ShieldCheck,
    TriangleAlert,
    User,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

/** The public wizard width (mockup v2): wider than the 980 px default. */
export const APPLY_WIZARD_WIDTH = 'min(92vw, 1100px)';

const CV_TYPES = ['pdf', 'doc', 'docx'];
const CV_MAX_BYTES = 10 * 1024 * 1024;

const steps = [
    {
        key: 'about',
        label: 'About you',
        blurb: 'Your name and how to reach you',
        icon: User,
    },
    {
        key: 'experience',
        label: 'Experience',
        blurb: 'Your work so far and a CV',
        icon: Briefcase,
    },
    {
        key: 'checks',
        label: 'Right to work',
        blurb: 'Work rights and police vetting',
        icon: ShieldCheck,
    },
    {
        key: 'review',
        label: 'Review and send',
        blurb: 'Check your application',
        icon: ClipboardCheck,
    },
] as const;

const REVIEW_STEP = steps.length - 1;

/** Applicant-facing tiles; the values (and staff labels) are shared. */
const experienceTiles: readonly TileOption<YearsOfExperience>[] = [
    {
        value: 'none',
        title: 'None yet',
        body: 'That’s fine. We’ll train you',
        icon: 'User',
    },
    {
        value: 'under_1',
        title: 'Less than a year',
        body: 'Paid work, caring for whānau or volunteering',
        icon: 'HeartHandshake',
    },
    {
        value: '1_to_3',
        title: '1 to 3 years',
        body: 'Disability, aged care, health or similar',
        icon: 'Briefcase',
    },
    {
        value: '3_plus',
        title: 'More than 3 years',
        body: 'We’d love to hear what you’ve learned',
        icon: 'Users',
    },
];

const rightToWorkTiles: readonly TileOption<RightToWorkStatus>[] = [
    {
        value: 'citizen_or_resident',
        title: 'Yes, citizen or resident',
        body: 'New Zealand or Australian',
        icon: 'ShieldCheck',
    },
    {
        value: 'work_visa',
        title: 'Yes, on a work visa',
        body: 'We’ll check it lets you do this work',
        icon: 'Briefcase',
    },
    {
        value: 'not_yet',
        title: 'Not yet',
        body: 'Tell us more in your message',
        icon: 'User',
    },
];

const licenceTiles: readonly TileOption<DriverLicenceClass>[] = [
    { value: 'full', title: 'Full licence', icon: 'ShieldCheck' },
    { value: 'restricted', title: 'Restricted licence', icon: 'Briefcase' },
    { value: 'none', title: 'Learner or none', icon: 'User' },
];

export type ApplicationDraft = {
    first_name: string;
    last_name: string;
    preferred_name: string;
    personal_email: string;
    personal_phone: string;
    source_channel: string;
    source_reference: string;
    years_of_experience: YearsOfExperience | '';
    experience_summary: string;
    cover_letter: string;
    cv: File | null;
    screening_answers: { question: string; answer: string }[];
    right_to_work_status: RightToWorkStatus | '';
    driver_licence_class: DriverLicenceClass | '';
    privacy_consent: boolean;
};

type Errors = Record<string, string | undefined>;

function blank(job: PublicJob, sourceChannel: string): ApplicationDraft {
    return {
        first_name: '',
        last_name: '',
        preferred_name: '',
        personal_email: '',
        personal_phone: '',
        source_channel: sourceChannelOptions.some(
            (option) => option.value === sourceChannel,
        )
            ? sourceChannel
            : 'career_page',
        source_reference: '',
        years_of_experience: '',
        experience_summary: '',
        cover_letter: '',
        cv: null,
        screening_answers: job.screening_questions.map((question) => ({
            question,
            answer: '',
        })),
        right_to_work_status: '',
        driver_licence_class: '',
        privacy_consent: false,
    };
}

/** The checks the wizard makes before sending (the server checks again). */
export function validateApplication(data: ApplicationDraft): Errors {
    const errors: Errors = {};
    if (!data.first_name.trim()) errors.first_name = 'Enter your first name.';
    if (!data.last_name.trim()) errors.last_name = 'Enter your last name.';
    if (!data.personal_email.trim())
        errors.personal_email = 'Enter your email address.';
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.personal_email.trim()))
        errors.personal_email =
            'Check your email address. We send your status link there.';
    if (!data.right_to_work_status)
        errors.right_to_work_status =
            'Tell us whether you can work in New Zealand.';
    if (!data.privacy_consent)
        errors.privacy_consent =
            'Tick to agree, so we can consider your application.';
    return errors;
}

/** Fields in the order they appear, so the first problem is found first. */
const FIELD_ORDER = [
    'first_name',
    'last_name',
    'preferred_name',
    'personal_email',
    'personal_phone',
    'source_channel',
    'source_reference',
    'years_of_experience',
    'experience_summary',
    'cover_letter',
    'screening_answers',
    'cv',
    'right_to_work_status',
    'driver_licence_class',
    'privacy_consent',
    'application',
];

/** Which step owns a field (server keys like `screening_answers.0.answer` too). */
export function stepForField(key: string): number {
    const field = key.split('.')[0];
    const index = FIELD_ORDER.indexOf(field);
    if (index < 0) return REVIEW_STEP;
    if (index <= FIELD_ORDER.indexOf('source_reference')) return 0;
    if (index <= FIELD_ORDER.indexOf('cv')) return 1;
    if (index <= FIELD_ORDER.indexOf('driver_licence_class')) return 2;
    return REVIEW_STEP;
}

/** The element to focus for a field. */
function focusIdFor(key: string): string {
    const [field, index] = key.split('.');
    if (field === 'screening_answers') return `apply-question-${index ?? 0}`;
    return (
        (
            {
                first_name: 'apply-first-name',
                last_name: 'apply-last-name',
                preferred_name: 'apply-preferred-name',
                personal_email: 'apply-email',
                personal_phone: 'apply-phone',
                source_channel: 'apply-source',
                source_reference: 'apply-source-reference',
                years_of_experience: 'apply-years',
                experience_summary: 'apply-experience-summary',
                cover_letter: 'apply-cover-letter',
                cv: 'apply-cv',
                right_to_work_status: 'apply-right-to-work',
                driver_licence_class: 'apply-licence',
                privacy_consent: 'apply-privacy',
            } as Record<string, string>
        )[field] ?? 'apply-failure'
    );
}

/** The first field with a problem, in the order the wizard shows them. */
export function firstProblem(errors: Errors): string | null {
    const keys = Object.keys(errors).filter((key) => errors[key]);
    if (!keys.length) return null;
    return keys.sort((a, b) => {
        const step = stepForField(a) - stepForField(b);
        if (step) return step;
        const rank = (key: string) => {
            const at = FIELD_ORDER.indexOf(key.split('.')[0]);
            return at < 0 ? FIELD_ORDER.length : at;
        };
        return rank(a) - rank(b) || a.localeCompare(b);
    })[0];
}

function Field({
    id,
    label,
    error,
    hint,
    children,
    className,
}: {
    id: string;
    label: string;
    error?: string;
    hint?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={className ?? 'space-y-1.5'}>
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && (
                <p
                    id={`${id}-hint`}
                    className="text-caption text-muted-foreground"
                >
                    {hint}
                </p>
            )}
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

/** aria wiring for a text control with an optional hint and error. */
function described(id: string, error?: string, hint?: boolean) {
    const ids = [hint && `${id}-hint`, error && `${id}-error`].filter(Boolean);
    return {
        'aria-invalid': error ? true : undefined,
        'aria-describedby': ids.length ? ids.join(' ') : undefined,
    } as const;
}

const sourceLabel = (value: string) =>
    value === 'career_page'
        ? 'Our website'
        : sourceChannelOptions.find((option) => option.value === value)?.label;

const tileTitle = <T extends string>(
    options: readonly TileOption<T>[],
    value: T | '',
) => options.find((option) => option.value === value)?.title;

export function ApplyWizard({
    job,
    open,
    onClose,
    siteName,
    sourceChannel = 'career_page',
}: {
    job: PublicJob;
    open: boolean;
    onClose: () => void;
    /** The provider's name for the privacy line, or null when none is set. */
    siteName: string | null;
    sourceChannel?: string;
}) {
    const [step, setStep] = useState(0);
    const [data, setData] = useState<ApplicationDraft>(() =>
        blank(job, sourceChannel),
    );
    const [errors, setErrors] = useState<Errors>({});
    const [sending, setSending] = useState(false);
    const [sent, setSent] = useState(false);
    const [failure, setFailure] = useState('');
    const [confirmClose, setConfirmClose] = useState(false);
    const [focusTarget, setFocusTarget] = useState<string | null>(null);
    const body = useRef<HTMLDivElement>(null);
    // A sent or discarded application starts clean next time. The reset waits
    // until the wizard has closed, so the closing pop-up doesn't flash empty.
    const resetAfterClose = useRef(false);
    const reset = () => {
        setData(blank(job, sourceChannel));
        setErrors({});
        setFailure('');
        setSent(false);
        setStep(0);
    };
    const closeAndReset = () => {
        resetAfterClose.current = true;
        onClose();
    };

    // Move focus to the field that needs attention once its step shows.
    useEffect(() => {
        if (!focusTarget) return;
        const frame = requestAnimationFrame(() => {
            document.getElementById(focusTarget)?.focus();
            setFocusTarget(null);
        });
        return () => cancelAnimationFrame(frame);
    }, [focusTarget, step]);

    const site = job.site?.name ?? null;
    const set = <K extends keyof ApplicationDraft>(
        key: K,
        value: ApplicationDraft[K],
    ) => {
        setData((previous) => ({ ...previous, [key]: value }));
        setErrors((previous) => ({ ...previous, [key]: undefined }));
    };
    const go = (next: number) => {
        setStep(next);
        body.current?.scrollTo?.({ top: 0 });
    };
    const dirty =
        JSON.stringify({ ...data, cv: data.cv?.name ?? null }) !==
        JSON.stringify({ ...blank(job, sourceChannel), cv: null });
    const requestClose = () => {
        if (sending) return;
        if (sent) closeAndReset();
        else if (dirty) setConfirmClose(true);
        else onClose();
    };
    const showProblems = (problems: Errors) => {
        setErrors(problems);
        const first = firstProblem(problems);
        if (!first) return;
        go(stepForField(first));
        setFocusTarget(focusIdFor(first));
    };

    const pickCv = (files: File[]) => {
        const file = files[0];
        if (!file) return;
        const extension = file.name.split('.').pop()?.toLowerCase() ?? '';
        if (!CV_TYPES.includes(extension)) {
            setErrors((previous) => ({
                ...previous,
                cv: 'Choose a PDF or Word document (DOC or DOCX).',
            }));
            return;
        }
        if (file.size > CV_MAX_BYTES) {
            setErrors((previous) => ({
                ...previous,
                cv: 'Choose a file under 10 MB.',
            }));
            return;
        }
        set('cv', file);
    };

    const submit = () => {
        if (sending) return;
        const problems = validateApplication(data);
        if (Object.keys(problems).length) {
            showProblems(problems);
            return;
        }
        setSending(true);
        setFailure('');
        let settled = false;
        router.post(applyUrl(job.slug), data, {
            forceFormData: true,
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                settled = true;
                setSent(true);
            },
            onError: (serverErrors) => {
                settled = true;
                const problems = serverErrors as Errors;
                if (problems.application) setFailure(problems.application);
                showProblems(problems);
            },
            onFinish: () => {
                setSending(false);
                if (!settled) {
                    setFailure(
                        'We couldn’t send your application. Your answers are still here, so please try again.',
                    );
                    go(REVIEW_STEP);
                    setFocusTarget('apply-failure');
                }
            },
        });
    };

    const filled = [
        data.first_name,
        data.last_name,
        data.personal_email,
        data.personal_phone,
        data.years_of_experience,
        data.experience_summary || data.cover_letter,
        data.cv,
        data.right_to_work_status,
        data.driver_licence_class,
        data.privacy_consent || null,
    ].filter(Boolean).length;
    const pct = Math.round((filled / 10) * 100);
    const licenceNeeded = /driver.?s? licen[cs]e/i.test(
        `${job.requirements ?? ''} ${job.description ?? ''}`,
    );
    const outstanding = Object.values(errors).some(Boolean);

    return (
        <>
            <WizardShell
                open={open}
                onClose={requestClose}
                onCloseAutoFocus={() => {
                    if (!resetAfterClose.current) return;
                    resetAfterClose.current = false;
                    reset();
                }}
                frontline
                maxWidth={APPLY_WIZARD_WIDTH}
                bodyRef={body}
                title={`Apply for ${job.title}`}
                description={`Apply for the ${job.title} role${site ? ` at ${site}` : ''}.`}
                railIcon={Briefcase}
                railTitle={`Apply: ${job.title}`}
                railSub={[site, employmentType(job.employment_type)]
                    .filter(Boolean)
                    .join(' · ')}
                steps={steps}
                stepIndex={step}
                onStepClick={go}
                pct={pct}
                success={
                    sent ? (
                        <WizardSuccessPane
                            title="Thanks — your application is with us"
                            blurb={
                                <>
                                    We’re emailing{' '}
                                    <strong>{data.personal_email}</strong> a
                                    link to check your application’s progress at
                                    any time. Our team reads every application,
                                    and we’ll contact you if you’re shortlisted
                                    for an interview.
                                </>
                            }
                            actions={
                                <>
                                    <Button asChild variant="outline">
                                        <Link href="/careers">
                                            See other open roles
                                        </Link>
                                    </Button>
                                    <Button onClick={closeAndReset}>
                                        Done
                                    </Button>
                                </>
                            }
                        />
                    ) : undefined
                }
                footerStart={
                    <Button
                        variant="outline"
                        disabled={sending}
                        onClick={() =>
                            step > 0 ? go(step - 1) : requestClose()
                        }
                    >
                        {step > 0 ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        disabled={sending}
                        onClick={() =>
                            step < REVIEW_STEP ? go(step + 1) : submit()
                        }
                    >
                        {sending && <Spinner aria-hidden />}
                        {step < REVIEW_STEP
                            ? 'Continue'
                            : sending
                              ? 'Sending…'
                              : 'Send application'}
                    </Button>
                }
            >
                {step === 0 && (
                    <WizardStepPane>
                        <div className="space-y-5">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    id="apply-first-name"
                                    label="First name"
                                    error={errors.first_name}
                                >
                                    <Input
                                        id="apply-first-name"
                                        autoComplete="given-name"
                                        maxLength={255}
                                        value={data.first_name}
                                        onChange={(event) =>
                                            set(
                                                'first_name',
                                                event.target.value,
                                            )
                                        }
                                        {...described(
                                            'apply-first-name',
                                            errors.first_name,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="apply-last-name"
                                    label="Last name"
                                    error={errors.last_name}
                                >
                                    <Input
                                        id="apply-last-name"
                                        autoComplete="family-name"
                                        maxLength={255}
                                        value={data.last_name}
                                        onChange={(event) =>
                                            set('last_name', event.target.value)
                                        }
                                        {...described(
                                            'apply-last-name',
                                            errors.last_name,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="apply-preferred-name"
                                    label="Preferred name (optional)"
                                    error={errors.preferred_name}
                                    className="space-y-1.5 sm:col-span-2"
                                >
                                    <Input
                                        id="apply-preferred-name"
                                        autoComplete="nickname"
                                        maxLength={255}
                                        value={data.preferred_name}
                                        onChange={(event) =>
                                            set(
                                                'preferred_name',
                                                event.target.value,
                                            )
                                        }
                                        {...described(
                                            'apply-preferred-name',
                                            errors.preferred_name,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="apply-email"
                                    label="Email"
                                    error={errors.personal_email}
                                    hint="We send your status link here."
                                >
                                    <Input
                                        id="apply-email"
                                        type="email"
                                        autoComplete="email"
                                        maxLength={255}
                                        value={data.personal_email}
                                        onChange={(event) =>
                                            set(
                                                'personal_email',
                                                event.target.value,
                                            )
                                        }
                                        {...described(
                                            'apply-email',
                                            errors.personal_email,
                                            true,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="apply-phone"
                                    label="Mobile (optional)"
                                    error={errors.personal_phone}
                                >
                                    <Input
                                        id="apply-phone"
                                        type="tel"
                                        autoComplete="tel"
                                        inputMode="tel"
                                        maxLength={50}
                                        value={data.personal_phone}
                                        onChange={(event) =>
                                            set(
                                                'personal_phone',
                                                event.target.value,
                                            )
                                        }
                                        {...described(
                                            'apply-phone',
                                            errors.personal_phone,
                                        )}
                                    />
                                </Field>
                            </div>
                            <div className="grid gap-4 rounded-2xl bg-muted p-4 sm:grid-cols-2">
                                <Field
                                    id="apply-source"
                                    label="How did you hear about this role?"
                                    error={errors.source_channel}
                                >
                                    <Select
                                        value={data.source_channel}
                                        onValueChange={(value) =>
                                            set('source_channel', value)
                                        }
                                    >
                                        <SelectTrigger
                                            id="apply-source"
                                            className="w-full bg-card"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {sourceChannelOptions.map(
                                                (option) => (
                                                    <SelectItem
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {sourceLabel(
                                                            option.value,
                                                        )}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </Field>
                                <Field
                                    id="apply-source-reference"
                                    label="Who referred you? (optional)"
                                    error={errors.source_reference}
                                >
                                    <Input
                                        id="apply-source-reference"
                                        className="bg-card"
                                        maxLength={255}
                                        placeholder="A friend’s name or a campaign code"
                                        value={data.source_reference}
                                        onChange={(event) =>
                                            set(
                                                'source_reference',
                                                event.target.value,
                                            )
                                        }
                                        {...described(
                                            'apply-source-reference',
                                            errors.source_reference,
                                        )}
                                    />
                                </Field>
                            </div>
                        </div>
                    </WizardStepPane>
                )}

                {step === 1 && (
                    <WizardStepPane>
                        <div className="space-y-6">
                            <RadioTiles
                                id="apply-years"
                                label="How long have you done support or care work?"
                                hint="Paid or unpaid: caring for whānau and volunteering count too."
                                value={data.years_of_experience || null}
                                onChange={(value) =>
                                    set('years_of_experience', value)
                                }
                                options={experienceTiles}
                                error={errors.years_of_experience}
                            />
                            <Field
                                id="apply-experience-summary"
                                label="Your experience, in a sentence or two (optional)"
                                hint="For example: two years at a rest home, or caring for my nan."
                                error={errors.experience_summary}
                            >
                                <Textarea
                                    id="apply-experience-summary"
                                    rows={3}
                                    maxLength={2000}
                                    value={data.experience_summary}
                                    onChange={(event) =>
                                        set(
                                            'experience_summary',
                                            event.target.value,
                                        )
                                    }
                                    {...described(
                                        'apply-experience-summary',
                                        errors.experience_summary,
                                        true,
                                    )}
                                />
                            </Field>
                            <Field
                                id="apply-cover-letter"
                                label="Tell us about yourself (optional)"
                                hint="Why this role, and anything you’d like us to know. A few lines is plenty."
                                error={errors.cover_letter}
                            >
                                <Textarea
                                    id="apply-cover-letter"
                                    rows={5}
                                    maxLength={10000}
                                    value={data.cover_letter}
                                    onChange={(event) =>
                                        set('cover_letter', event.target.value)
                                    }
                                    {...described(
                                        'apply-cover-letter',
                                        errors.cover_letter,
                                        true,
                                    )}
                                />
                            </Field>
                            {data.screening_answers.map((row, index) => {
                                const error =
                                    errors[
                                        `screening_answers.${index}.answer`
                                    ] ??
                                    errors[
                                        `screening_answers.${index}.question`
                                    ];
                                return (
                                    <Field
                                        key={row.question}
                                        id={`apply-question-${index}`}
                                        label={`${row.question} (optional)`}
                                        error={error}
                                    >
                                        <Textarea
                                            id={`apply-question-${index}`}
                                            rows={3}
                                            maxLength={5000}
                                            value={row.answer}
                                            onChange={(event) =>
                                                set(
                                                    'screening_answers',
                                                    data.screening_answers.map(
                                                        (answer, i) =>
                                                            i === index
                                                                ? {
                                                                      ...answer,
                                                                      answer: event
                                                                          .target
                                                                          .value,
                                                                  }
                                                                : answer,
                                                    ),
                                                )
                                            }
                                            {...described(
                                                `apply-question-${index}`,
                                                error,
                                            )}
                                        />
                                    </Field>
                                );
                            })}
                            <div className="space-y-2">
                                <Label id="apply-cv-label">
                                    Your CV (optional)
                                </Label>
                                {data.cv ? (
                                    <StagedFileCard
                                        file={data.cv}
                                        onRemove={() => set('cv', null)}
                                    />
                                ) : (
                                    <FileDropzone
                                        id="apply-cv"
                                        aria-labelledby="apply-cv-label"
                                        aria-describedby={
                                            errors.cv
                                                ? 'apply-cv-error'
                                                : undefined
                                        }
                                        aria-invalid={
                                            errors.cv ? true : undefined
                                        }
                                        onFiles={pickCv}
                                        multiple={false}
                                        accept=".pdf,.doc,.docx"
                                        title="Drag your CV here, or browse"
                                        hint="PDF, DOC or DOCX · up to 10 MB"
                                    />
                                )}
                                <InputError
                                    id="apply-cv-error"
                                    message={errors.cv}
                                />
                            </div>
                        </div>
                    </WizardStepPane>
                )}

                {step === 2 && (
                    <WizardStepPane>
                        <div className="space-y-6">
                            <RadioTiles
                                id="apply-right-to-work"
                                label="Can you work in New Zealand?"
                                value={data.right_to_work_status || null}
                                onChange={(value) =>
                                    set('right_to_work_status', value)
                                }
                                columns="sm:grid-cols-3"
                                options={rightToWorkTiles}
                                error={errors.right_to_work_status}
                            />
                            <RadioTiles
                                id="apply-licence"
                                label="Do you have a New Zealand driver licence?"
                                hint={
                                    licenceNeeded
                                        ? 'This role needs one.'
                                        : 'Useful for getting people out and about.'
                                }
                                value={data.driver_licence_class || null}
                                onChange={(value) =>
                                    set('driver_licence_class', value)
                                }
                                columns="sm:grid-cols-3"
                                options={licenceTiles}
                                error={errors.driver_licence_class}
                            />
                            <div className="flex gap-4 rounded-2xl bg-muted p-5">
                                <Lens icon={ShieldCheck} />
                                <div className="space-y-2 text-sm">
                                    <p className="font-semibold">
                                        A note on police vetting
                                    </p>
                                    <p className="text-muted-foreground">
                                        Because this role supports people who
                                        may be vulnerable, we ask the New
                                        Zealand Police Vetting Service to check
                                        everyone before they start. We only ask
                                        for your consent and ID if we offer you
                                        the role. You don’t need to do anything
                                        now.
                                    </p>
                                    <p className="text-muted-foreground">
                                        Having a conviction doesn’t
                                        automatically rule you out. We look at
                                        each one fairly and in confidence.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </WizardStepPane>
                )}

                {step === REVIEW_STEP && (
                    <WizardStepPane>
                        <div className="space-y-5">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <ReviewCard
                                    icon={User}
                                    title="About you"
                                    onEdit={() => go(0)}
                                >
                                    <ReviewRow
                                        label="Name"
                                        value={[data.first_name, data.last_name]
                                            .map((part) => part.trim())
                                            .filter(Boolean)
                                            .join(' ')}
                                    />
                                    <ReviewRow
                                        label="Email"
                                        value={data.personal_email.trim()}
                                    />
                                    <ReviewRow
                                        label="Mobile"
                                        value={data.personal_phone.trim()}
                                    />
                                    <ReviewRow
                                        label="Heard about it"
                                        value={sourceLabel(data.source_channel)}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Briefcase}
                                    title="Experience"
                                    onEdit={() => go(1)}
                                >
                                    <ReviewRow
                                        label="Support work"
                                        value={tileTitle(
                                            experienceTiles,
                                            data.years_of_experience,
                                        )}
                                    />
                                    <ReviewRow
                                        label="Your experience"
                                        value={
                                            data.experience_summary.trim()
                                                ? 'Written'
                                                : undefined
                                        }
                                    />
                                    <ReviewRow
                                        label="About you"
                                        value={
                                            data.cover_letter.trim()
                                                ? 'Written'
                                                : undefined
                                        }
                                    />
                                    {data.screening_answers.length > 0 && (
                                        <ReviewRow
                                            label="Questions answered"
                                            value={`${data.screening_answers.filter((row) => row.answer.trim()).length} of ${data.screening_answers.length}`}
                                        />
                                    )}
                                    <ReviewRow
                                        label="CV"
                                        value={data.cv?.name}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={ShieldCheck}
                                    title="Right to work"
                                    onEdit={() => go(2)}
                                    span
                                >
                                    <ReviewRow
                                        label="Work in New Zealand"
                                        value={tileTitle(
                                            rightToWorkTiles,
                                            data.right_to_work_status,
                                        )}
                                    />
                                    <ReviewRow
                                        label="Driver licence"
                                        value={tileTitle(
                                            licenceTiles,
                                            data.driver_licence_class,
                                        )}
                                    />
                                </ReviewCard>
                            </div>
                            <div className="space-y-2 rounded-2xl bg-muted p-4">
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="apply-privacy"
                                        checked={data.privacy_consent}
                                        onCheckedChange={(checked) =>
                                            set(
                                                'privacy_consent',
                                                checked === true,
                                            )
                                        }
                                        aria-invalid={
                                            errors.privacy_consent
                                                ? true
                                                : undefined
                                        }
                                        aria-describedby={
                                            errors.privacy_consent
                                                ? 'apply-privacy-error'
                                                : undefined
                                        }
                                        className="mt-0.5"
                                    />
                                    <Label
                                        htmlFor="apply-privacy"
                                        className="text-sm leading-5 font-normal"
                                    >
                                        {siteName
                                            ? `I agree to ${siteName} using my information to consider my application, as set out in the privacy statement and the Privacy Act 2020.`
                                            : 'I agree to my information being used to consider my application, as set out in the privacy statement and the Privacy Act 2020.'}
                                    </Label>
                                </div>
                                <p className="pl-7 text-sm">
                                    <a
                                        href={siteLinks.privacy}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="font-semibold text-primary hover:underline"
                                    >
                                        Read the privacy statement
                                        <span className="sr-only">
                                            {' '}
                                            (opens in a new tab)
                                        </span>
                                    </a>
                                </p>
                                <InputError
                                    id="apply-privacy-error"
                                    message={errors.privacy_consent}
                                />
                            </div>
                            {failure && (
                                <Alert
                                    variant="destructive"
                                    id="apply-failure"
                                    tabIndex={-1}
                                >
                                    <TriangleAlert aria-hidden />
                                    <AlertDescription>
                                        {failure}
                                    </AlertDescription>
                                </Alert>
                            )}
                            {outstanding && !failure && (
                                <p
                                    role="alert"
                                    className="text-sm text-status-critical"
                                >
                                    A few things still need your attention. Use
                                    Edit to go back to them.
                                </p>
                            )}
                        </div>
                    </WizardStepPane>
                )}
            </WizardShell>

            <ConfirmDialog
                open={confirmClose}
                onClose={() => setConfirmClose(false)}
                onConfirm={closeAndReset}
                variant="destructive"
                frontline
                title="Discard this application?"
                description="What you’ve entered so far won’t be sent or saved."
                confirmText="Discard application"
                cancelText="Keep going"
            />
        </>
    );
}
