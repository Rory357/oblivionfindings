// Careers inside the public site shell. Field set, role fields and the
// "how did you hear" options come from the real careers pages
// (pages/careers/index.tsx, apply.tsx, application-status.tsx) and
// lib/job-posting-constants.ts.
import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
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
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    employmentTypeLabels,
    sourceChannelOptions,
} from '@/lib/job-posting-constants';
import {
    ArrowRight,
    Briefcase,
    CalendarClock,
    ClipboardCheck,
    Clock,
    GraduationCap,
    HeartHandshake,
    MapPin,
    PhoneCall,
    ShieldCheck,
    User,
    Users,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { jobs, photos, provider, type Job } from './data';
import { WIZARD_WIDTH } from './page-referral';
import {
    Lens,
    PageIntro,
    Pathway,
    Photo,
    ProgressPathway,
    RadioTiles,
    SectionHeading,
    SitePage,
    useSiteName,
} from './site';

/** The real labels are Title Case ("Part Time"); public copy is sentence case. */
const employmentType = (type: string) => {
    const label = employmentTypeLabels[type] ?? type;
    return label.charAt(0) + label.slice(1).toLowerCase();
};

function JobFacts({ job }: { job: Job }) {
    return (
        <ul className="flex flex-wrap gap-x-5 gap-y-2 text-sm">
            <li className="flex items-center gap-1.5">
                <MapPin className="size-4 shrink-0" aria-hidden />
                {job.site.name}
            </li>
            <li className="flex items-center gap-1.5">
                <Briefcase className="size-4 shrink-0" aria-hidden />
                {employmentType(job.employment_type)}
            </li>
            <li className="flex items-center gap-1.5">
                <CalendarClock className="size-4 shrink-0" aria-hidden />
                Applications close {job.closing_at}
            </li>
        </ul>
    );
}

const hiringSteps = [
    {
        title: 'Apply online',
        body: 'About ten minutes. A CV helps but isn’t required.',
    },
    {
        title: 'A chat with us',
        body: 'If you’re shortlisted, we’ll call to arrange an interview.',
    },
    {
        title: 'Checks',
        body: 'Two references and a New Zealand Police vet, with your consent.',
    },
    {
        title: 'Welcome aboard',
        body: 'Paid induction and training, then shadow shifts with your team.',
    },
];

/* ── Job list ────────────────────────────────────────────────────────── */

export function CareersPage() {
    const name = useSiteName();
    return (
        <SitePage
            current="careers"
            intro={
                <PageIntro
                    eyebrow="Careers"
                    title="Work that makes a real difference"
                    lede={`Join a local team that supports people to live the lives they choose. ${name} trains you, backs you and gives you a team that’s always a call away.`}
                    media={
                        <Photo
                            photo={photos.careers}
                            className="aspect-[4/3] shadow-2xl"
                            eager
                        />
                    }
                >
                    <Button asChild size="lg">
                        <a href="#open-roles" onClick={scrollToRoles}>
                            See open roles
                            <ArrowRight className="size-4" aria-hidden />
                        </a>
                    </Button>
                </PageIntro>
            }
        >
            <section
                id="open-roles"
                aria-labelledby="roles-heading"
                className="journey-site-container journey-site-anchor space-y-8 py-16"
            >
                <SectionHeading
                    id="roles-heading"
                    kicker="Join the team"
                    title="Open roles"
                >
                    No experience? Many of our best support workers started with
                    us. We’ll train you.
                </SectionHeading>
                {jobs.length === 0 ? (
                    <div className="rounded-3xl border border-border bg-card p-10 text-center">
                        <p className="font-semibold">No open roles right now</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            New roles appear here first. You can also send us a
                            careers question.
                        </p>
                    </div>
                ) : (
                    <ul className="grid gap-4">
                        {jobs.map((job) => (
                            <li key={job.id}>
                                <article
                                    aria-labelledby={`job-${job.slug}`}
                                    className="grid gap-5 rounded-3xl border border-border bg-card p-6 md:grid-cols-[1fr_auto] md:items-center md:p-8"
                                >
                                    <div className="space-y-3">
                                        <h3
                                            id={`job-${job.slug}`}
                                            className="text-section-title"
                                        >
                                            <a
                                                href={`#/careers/${job.slug}`}
                                                className="hover:underline"
                                            >
                                                {job.title}
                                                <span className="font-normal text-muted-foreground">
                                                    {' '}
                                                    · {job.site.name}
                                                </span>
                                            </a>
                                        </h3>
                                        <p className="text-muted-foreground">
                                            {job.summary}
                                        </p>
                                        <div className="text-muted-foreground">
                                            <JobFacts job={job} />
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap gap-2 md:flex-col">
                                        <Button asChild>
                                            <a
                                                href={`#/careers/${job.slug}/apply`}
                                            >
                                                Apply
                                            </a>
                                        </Button>
                                        <Button asChild variant="outline">
                                            <a href={`#/careers/${job.slug}`}>
                                                Read more
                                            </a>
                                        </Button>
                                    </div>
                                </article>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section
                aria-labelledby="why-heading"
                className="journey-site-lilac"
            >
                <div className="journey-site-container space-y-8 py-16">
                    <SectionHeading
                        id="why-heading"
                        kicker="Why work with us"
                        title="We look after the people who look after people"
                    />
                    <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {(
                            [
                                [
                                    GraduationCap,
                                    'Paid training',
                                    'A paid induction, shadow shifts and support to gain your Health and Wellbeing certificates.',
                                ],
                                [
                                    CalendarClock,
                                    'Rosters you can plan around',
                                    'Rosters published ahead, with shifts that work around study and whānau.',
                                ],
                                [
                                    PhoneCall,
                                    'Never on your own',
                                    'An on-call team leader, day and night, whenever you need a hand.',
                                ],
                                [
                                    Users,
                                    'A team that stays',
                                    'Small, steady teams, so the people we support see familiar faces.',
                                ],
                            ] as const
                        ).map(([icon, title, body]) => (
                            <li
                                key={title}
                                className="flex flex-col gap-3 rounded-3xl border border-border bg-card p-6"
                            >
                                <Lens icon={icon} />
                                <p className="font-semibold">{title}</p>
                                <p className="text-sm text-muted-foreground">
                                    {body}
                                </p>
                            </li>
                        ))}
                    </ul>
                </div>
            </section>

            <section
                aria-labelledby="hiring-heading"
                className="journey-site-container space-y-10 py-16"
            >
                <SectionHeading
                    id="hiring-heading"
                    kicker="How applying works"
                    title="From application to your first shift"
                />
                <Pathway steps={hiringSteps} />
                <p className="text-sm text-muted-foreground">
                    Questions about a role?{' '}
                    <a
                        href="#/contact"
                        className="font-semibold text-primary hover:underline"
                    >
                        Send us a careers question
                    </a>{' '}
                    or call {provider.phone}.
                </p>
            </section>
        </SitePage>
    );
}

function scrollToRoles(event: { preventDefault: () => void }) {
    // In-page anchors would change the hash route, so scroll instead.
    event.preventDefault();
    document.getElementById('open-roles')?.scrollIntoView({ block: 'start' });
}

/* ── Job detail + apply ──────────────────────────────────────────────── */

const applySteps = [
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

const experienceOptions = [
    {
        value: 'paid',
        title: 'Yes, paid work',
        body: 'Disability, aged care, health or similar',
        icon: 'Briefcase',
    },
    {
        value: 'unpaid',
        title: 'Yes, unpaid',
        body: 'Caring for whānau, or volunteering',
        icon: 'HeartHandshake',
    },
    {
        value: 'none',
        title: 'Not yet',
        body: 'That’s fine. We’ll train you',
        icon: 'User',
    },
] as const;

const rightToWorkOptions = [
    {
        value: 'citizen',
        title: 'Yes, citizen or resident',
        body: 'New Zealand or Australian',
        icon: 'ShieldCheck',
    },
    {
        value: 'visa',
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
] as const;

const licenceOptions = [
    { value: 'full', title: 'Full licence', icon: 'ShieldCheck' },
    { value: 'restricted', title: 'Restricted licence', icon: 'Briefcase' },
    { value: 'none', title: 'Learner or none', icon: 'User' },
] as const;

type Application = {
    first_name: string;
    last_name: string;
    preferred_name: string;
    personal_email: string;
    personal_phone: string;
    source_channel: string;
    source_reference: string;
    experience: string | null;
    cover_letter: string;
    cv: File | null;
    screening_answers: string[];
    right_to_work: string | null;
    licence: string | null;
    privacy_consent: boolean;
};

const blank = (job: Job): Application => ({
    first_name: '',
    last_name: '',
    preferred_name: '',
    personal_email: '',
    personal_phone: '',
    source_channel: 'career_page',
    source_reference: '',
    experience: null,
    cover_letter: '',
    cv: null,
    screening_answers: job.screening_questions.map(() => ''),
    right_to_work: null,
    licence: null,
    privacy_consent: false,
});

type Errors = Partial<Record<keyof Application, string>>;

/** Which step owns each required field (for jumping to the first error). */
const fieldStep: Partial<Record<keyof Application, number>> = {
    first_name: 0,
    last_name: 0,
    personal_email: 0,
    right_to_work: 2,
    privacy_consent: 3,
};

/** The element to focus for each required field. */
const fieldFocus: Partial<Record<keyof Application, string>> = {
    first_name: 'apply-first-name',
    last_name: 'apply-last-name',
    personal_email: 'apply-email',
    right_to_work: 'apply-right-to-work',
    privacy_consent: 'apply-privacy',
};

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
                <p className="text-caption text-muted-foreground">{hint}</p>
            )}
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}

export function JobPage({ slug, apply }: { slug: string; apply: boolean }) {
    const name = useSiteName();
    const job = jobs.find((candidate) => candidate.slug === slug);
    const [open, setOpen] = useState(apply);
    const [step, setStep] = useState(0);
    const [data, setData] = useState<Application | null>(
        job ? blank(job) : null,
    );
    const [errors, setErrors] = useState<Errors>({});
    const [sending, setSending] = useState(false);
    const [sent, setSent] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);

    if (!job || !data) {
        return (
            <SitePage
                current="careers"
                intro={
                    <PageIntro
                        eyebrow="Careers"
                        title="This role isn’t open any more"
                        lede="It may have closed or been filled. Our open roles are listed on the careers page."
                        back={{ href: '#/careers', label: 'All open roles' }}
                    />
                }
            >
                <div className="journey-site-container py-12">
                    <Button asChild>
                        <a href="#/careers">See open roles</a>
                    </Button>
                </div>
            </SitePage>
        );
    }

    const set = <K extends keyof Application>(
        key: K,
        value: Application[K],
    ) => {
        setData((previous) =>
            previous ? { ...previous, [key]: value } : previous,
        );
        setErrors((previous) => ({ ...previous, [key]: undefined }));
    };
    const dirty = JSON.stringify(data) !== JSON.stringify(blank(job));
    const close = () => {
        setOpen(false);
        if (window.location.hash.endsWith('/apply'))
            window.history.replaceState(null, '', `#/careers/${job.slug}`);
    };
    const requestClose = () =>
        !sent && dirty ? setConfirmClose(true) : close();
    const openWizard = () => {
        setStep(0);
        setSent(false);
        setOpen(true);
    };

    const validate = (): Errors => {
        const next: Errors = {};
        if (!data.first_name.trim()) next.first_name = 'Enter your first name.';
        if (!data.last_name.trim()) next.last_name = 'Enter your last name.';
        if (!data.personal_email.trim())
            next.personal_email = 'Enter your email address.';
        else if (!/^\S+@\S+\.\S+$/.test(data.personal_email.trim()))
            next.personal_email =
                'Check your email address. We send your status link there.';
        if (!data.right_to_work)
            next.right_to_work = 'Tell us whether you can work in New Zealand.';
        if (!data.privacy_consent)
            next.privacy_consent =
                'Tick to agree, so we can consider your application.';
        return next;
    };
    const submit = () => {
        const next = validate();
        setErrors(next);
        const steps = Object.keys(next).map(
            (key) => fieldStep[key as keyof Application] ?? 3,
        );
        if (steps.length) {
            setStep(Math.min(...steps));
            // Focus the first field that needs attention once its step shows.
            const first = (Object.keys(next) as (keyof Application)[])[0];
            window.setTimeout(() => {
                const target = document.getElementById(fieldFocus[first] ?? '');
                (
                    target?.querySelector<HTMLElement>('[role="radio"]') ??
                    target
                )?.focus();
            }, 50);
            return;
        }
        setSending(true);
        window.setTimeout(() => {
            setSending(false);
            setSent(true);
        }, 900);
    };

    const filled = [
        data.first_name,
        data.last_name,
        data.personal_email,
        data.personal_phone,
        data.experience,
        data.cover_letter,
        data.cv,
        data.right_to_work,
        data.licence,
        data.privacy_consent || null,
    ].filter(Boolean).length;
    const pct = Math.round((filled / 10) * 100);
    const source = sourceChannelOptions.find(
        (option) => option.value === data.source_channel,
    );
    const label = <T extends { value: string; title: string }>(
        options: readonly T[],
        value: string | null,
    ) => options.find((option) => option.value === value)?.title;

    return (
        <SitePage
            current="careers"
            intro={
                <PageIntro
                    eyebrow="Careers"
                    title={`${job.title} · ${job.site.name}`}
                    lede={job.summary}
                    back={{ href: '#/careers', label: 'All open roles' }}
                >
                    <div className="text-journey-ink-muted">
                        <JobFacts job={job} />
                    </div>
                    <Button size="lg" onClick={openWizard}>
                        Apply for this role
                        <ArrowRight className="size-4" aria-hidden />
                    </Button>
                </PageIntro>
            }
        >
            <div className="journey-site-container grid items-start gap-6 py-12 lg:grid-cols-[1.6fr_1fr]">
                <article className="space-y-8 rounded-3xl border border-border bg-card p-6 md:p-10">
                    <section className="space-y-3">
                        <h2 className="text-section-title">About the role</h2>
                        <p className="leading-relaxed text-muted-foreground">
                            {job.description}
                        </p>
                    </section>
                    <section className="space-y-3">
                        <h2 className="text-section-title">What you’ll do</h2>
                        <ul className="list-disc space-y-2 pl-5 text-muted-foreground marker:text-primary">
                            {job.responsibilities.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                    </section>
                    <section className="space-y-3">
                        <h2 className="text-section-title">
                            What you’ll bring
                        </h2>
                        <ul className="list-disc space-y-2 pl-5 text-muted-foreground marker:text-primary">
                            {job.requirements.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                    </section>
                    <section className="space-y-3 rounded-2xl bg-muted p-5">
                        <h2 className="font-semibold">
                            Checks before you start
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            If we offer you the role, we’ll check two references
                            and ask for your consent to a New Zealand Police vet
                            before your first shift. Having a conviction doesn’t
                            automatically rule you out: we look at each one
                            fairly and in confidence.
                        </p>
                    </section>
                </article>
                <aside className="space-y-4 rounded-3xl border border-border bg-card p-6">
                    <p className="font-semibold">At a glance</p>
                    <dl className="space-y-3 text-sm">
                        {(
                            [
                                [MapPin, 'Where', job.site.name],
                                [
                                    Briefcase,
                                    'Type',
                                    employmentType(job.employment_type),
                                ],
                                [Clock, 'Hours', job.hours],
                                [
                                    CalendarClock,
                                    'Applications close',
                                    job.closing_at,
                                ],
                            ] as const
                        ).map(([Icon, term, value]) => (
                            <div key={term} className="flex gap-3">
                                <Icon
                                    className="mt-0.5 size-4 shrink-0 text-primary"
                                    aria-hidden
                                />
                                <div>
                                    <dt className="text-muted-foreground">
                                        {term}
                                    </dt>
                                    <dd className="font-medium">{value}</dd>
                                </div>
                            </div>
                        ))}
                    </dl>
                    <Button className="w-full" onClick={openWizard}>
                        Apply for this role
                    </Button>
                    <p className="text-sm text-muted-foreground">
                        Questions?{' '}
                        <a
                            href="#/contact"
                            className="font-semibold text-primary hover:underline"
                        >
                            Send us a careers question
                        </a>
                        .
                    </p>
                </aside>
            </div>

            <WizardShell
                open={open}
                onClose={requestClose}
                maxWidth={WIZARD_WIDTH}
                title={`Apply for ${job.title}`}
                description={`Apply for the ${job.title} role in ${job.site.name}.`}
                railIcon={Briefcase}
                railTitle={`Apply: ${job.title}`}
                railSub={`${job.site.name} · ${employmentType(job.employment_type)}`}
                steps={applySteps}
                stepIndex={step}
                onStepClick={setStep}
                pct={pct}
                success={
                    sent ? (
                        <WizardSuccessPane
                            title="Thanks — your application is with us"
                            blurb={
                                <>
                                    We’ve emailed{' '}
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
                                        <a href="#/careers/status">
                                            Check application status
                                        </a>
                                    </Button>
                                    <Button onClick={close}>Done</Button>
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
                            step > 0 ? setStep(step - 1) : requestClose()
                        }
                    >
                        {step > 0 ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        disabled={sending}
                        onClick={() =>
                            step < applySteps.length - 1
                                ? setStep(step + 1)
                                : submit()
                        }
                    >
                        {sending && <Spinner aria-hidden />}
                        {step < applySteps.length - 1
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
                                        value={data.first_name}
                                        onChange={(event) =>
                                            set(
                                                'first_name',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={
                                            errors.first_name ? true : undefined
                                        }
                                        aria-describedby="apply-first-name-error"
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
                                        value={data.last_name}
                                        onChange={(event) =>
                                            set('last_name', event.target.value)
                                        }
                                        aria-invalid={
                                            errors.last_name ? true : undefined
                                        }
                                        aria-describedby="apply-last-name-error"
                                    />
                                </Field>
                                <Field
                                    id="apply-preferred-name"
                                    label="Preferred name (optional)"
                                    className="space-y-1.5 sm:col-span-2"
                                >
                                    <Input
                                        id="apply-preferred-name"
                                        value={data.preferred_name}
                                        onChange={(event) =>
                                            set(
                                                'preferred_name',
                                                event.target.value,
                                            )
                                        }
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
                                        value={data.personal_email}
                                        onChange={(event) =>
                                            set(
                                                'personal_email',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={
                                            errors.personal_email
                                                ? true
                                                : undefined
                                        }
                                        aria-describedby="apply-email-error"
                                    />
                                </Field>
                                <Field
                                    id="apply-phone"
                                    label="Mobile (optional)"
                                >
                                    <Input
                                        id="apply-phone"
                                        autoComplete="tel"
                                        inputMode="tel"
                                        value={data.personal_phone}
                                        onChange={(event) =>
                                            set(
                                                'personal_phone',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            </div>
                            <div className="grid gap-4 rounded-2xl bg-muted p-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="apply-source">
                                        How did you hear about this role?
                                    </Label>
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
                                                        {option.value ===
                                                        'career_page'
                                                            ? 'Our website'
                                                            : option.label}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <Field
                                    id="apply-source-reference"
                                    label="Who referred you? (optional)"
                                >
                                    <Input
                                        id="apply-source-reference"
                                        className="bg-card"
                                        placeholder="A friend’s name or a campaign code"
                                        value={data.source_reference}
                                        onChange={(event) =>
                                            set(
                                                'source_reference',
                                                event.target.value,
                                            )
                                        }
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
                                label="Have you done support or care work before?"
                                value={data.experience}
                                onChange={(value) => set('experience', value)}
                                columns="sm:grid-cols-3"
                                options={experienceOptions}
                            />
                            <Field
                                id="apply-cover-letter"
                                label="Tell us about yourself (optional)"
                                hint="Why this role, and anything you’d like us to know. A few lines is plenty."
                            >
                                <Textarea
                                    id="apply-cover-letter"
                                    rows={5}
                                    value={data.cover_letter}
                                    onChange={(event) =>
                                        set('cover_letter', event.target.value)
                                    }
                                />
                            </Field>
                            {job.screening_questions.map((question, index) => (
                                <Field
                                    key={question}
                                    id={`apply-question-${index}`}
                                    label={`${question} (optional)`}
                                >
                                    <Textarea
                                        id={`apply-question-${index}`}
                                        rows={3}
                                        value={data.screening_answers[index]}
                                        onChange={(event) =>
                                            set(
                                                'screening_answers',
                                                data.screening_answers.map(
                                                    (answer, i) =>
                                                        i === index
                                                            ? event.target.value
                                                            : answer,
                                                ),
                                            )
                                        }
                                    />
                                </Field>
                            ))}
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
                                        aria-labelledby="apply-cv-label"
                                        onFiles={(list) =>
                                            set('cv', list[0] ?? null)
                                        }
                                        multiple={false}
                                        accept=".pdf,.doc,.docx"
                                        title="Drag your CV here, or browse"
                                        hint="PDF, DOC or DOCX · up to 10 MB"
                                    />
                                )}
                            </div>
                        </div>
                    </WizardStepPane>
                )}

                {step === 2 && (
                    <WizardStepPane>
                        <div className="space-y-6">
                            <div id="apply-right-to-work">
                                <RadioTiles
                                    label="Can you work in New Zealand?"
                                    value={data.right_to_work}
                                    onChange={(value) =>
                                        set('right_to_work', value)
                                    }
                                    columns="sm:grid-cols-3"
                                    options={rightToWorkOptions}
                                    error={errors.right_to_work}
                                />
                            </div>
                            <RadioTiles
                                label="Do you have a New Zealand driver licence?"
                                hint={
                                    job.requirements.some((item) =>
                                        item.includes('driver licence'),
                                    )
                                        ? 'You need one for this role.'
                                        : 'Not needed for this role, but useful.'
                                }
                                value={data.licence}
                                onChange={(value) => set('licence', value)}
                                columns="sm:grid-cols-3"
                                options={licenceOptions}
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

                {step === 3 && (
                    <WizardStepPane>
                        <div className="space-y-5">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <ReviewCard
                                    icon={User}
                                    title="About you"
                                    onEdit={() => setStep(0)}
                                >
                                    <ReviewRow
                                        label="Name"
                                        value={[data.first_name, data.last_name]
                                            .filter(Boolean)
                                            .join(' ')}
                                    />
                                    <ReviewRow
                                        label="Email"
                                        value={data.personal_email}
                                    />
                                    <ReviewRow
                                        label="Mobile"
                                        value={data.personal_phone}
                                    />
                                    <ReviewRow
                                        label="Heard about it"
                                        value={
                                            source?.value === 'career_page'
                                                ? 'Our website'
                                                : source?.label
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Briefcase}
                                    title="Experience"
                                    onEdit={() => setStep(1)}
                                >
                                    <ReviewRow
                                        label="Support work"
                                        value={label(
                                            experienceOptions,
                                            data.experience,
                                        )}
                                    />
                                    <ReviewRow
                                        label="About you"
                                        value={
                                            data.cover_letter
                                                ? 'Written'
                                                : undefined
                                        }
                                    />
                                    <ReviewRow
                                        label="Questions answered"
                                        value={`${data.screening_answers.filter(Boolean).length} of ${job.screening_questions.length}`}
                                    />
                                    <ReviewRow
                                        label="CV"
                                        value={data.cv?.name}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={ShieldCheck}
                                    title="Right to work"
                                    onEdit={() => setStep(2)}
                                    span
                                >
                                    <ReviewRow
                                        label="Work in New Zealand"
                                        value={label(
                                            rightToWorkOptions,
                                            data.right_to_work,
                                        )}
                                    />
                                    <ReviewRow
                                        label="Driver licence"
                                        value={label(
                                            licenceOptions,
                                            data.licence,
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
                                        className="mt-0.5"
                                    />
                                    <Label
                                        htmlFor="apply-privacy"
                                        className="text-sm leading-5 font-normal"
                                    >
                                        I agree to {name} using my information
                                        to consider my application, as set out
                                        in the privacy statement and the Privacy
                                        Act 2020.
                                    </Label>
                                </div>
                                <InputError message={errors.privacy_consent} />
                            </div>
                            {Object.values(errors).some(Boolean) && (
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
                onConfirm={close}
                variant="destructive"
                frontline
                title="Discard this application?"
                description="What you’ve entered so far won’t be sent or saved."
                confirmText="Discard application"
                cancelText="Keep going"
            />
        </SitePage>
    );
}

/* ── Application status (the link the applicant gets) ────────────────── */

const statusSteps = [
    {
        title: 'Application received',
        body: '20 October 2026. Thanks for applying.',
    },
    {
        title: 'Under review',
        body: 'Our team reads every application.',
    },
    {
        title: 'Interview',
        body: 'If you’re shortlisted, we’ll call you to arrange a time.',
    },
    {
        title: 'Checks',
        body: 'References and a police vet, with your consent.',
    },
    {
        title: 'Decision',
        body: 'We’ll let you know either way.',
    },
];

export function ApplicationStatusPage() {
    const job = jobs[0];
    return (
        <SitePage
            current="careers"
            intro={
                <PageIntro
                    eyebrow="Your application"
                    title={`${job.title} · ${job.site.name}`}
                    lede="This page shows where your application is up to. It updates as things move along."
                />
            }
        >
            <div className="journey-site-container grid items-start gap-6 py-12 lg:grid-cols-[1.6fr_1fr]">
                <section
                    aria-labelledby="status-heading"
                    className="space-y-6 rounded-3xl border border-border bg-card p-6 md:p-8"
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="status-heading" className="text-section-title">
                            Where it’s up to
                        </h2>
                        <StatusBadge variant="info">
                            Application received
                        </StatusBadge>
                    </div>
                    <ProgressPathway steps={statusSteps} current={0} />
                </section>
                <aside className="space-y-4 rounded-3xl border border-border bg-card p-6 text-sm">
                    <p className="flex items-start gap-2.5">
                        <HeartHandshake
                            className="mt-0.5 size-4 shrink-0 text-primary"
                            aria-hidden
                        />
                        This link is just for you. Please don’t share it.
                    </p>
                    <p className="text-muted-foreground">
                        Questions about your application? Call {provider.phone}{' '}
                        or{' '}
                        <a
                            href="#/contact"
                            className="font-semibold text-primary hover:underline"
                        >
                            send us a careers question
                        </a>
                        .
                    </p>
                    <Button asChild variant="outline" className="w-full">
                        <a href="#/careers">See other open roles</a>
                    </Button>
                </aside>
            </div>
        </SitePage>
    );
}
