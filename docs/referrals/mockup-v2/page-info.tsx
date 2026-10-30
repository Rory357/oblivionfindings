import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    ArrowRight,
    Check,
    FilePenLine,
    LogIn,
    Minus,
    Scale,
    Wallet,
} from 'lucide-react';
import { useEffect, type ReactNode } from 'react';
import { pathways, photos, provider, services, team, values } from './data';
import { ServiceCard } from './page-home';
import {
    icons,
    Lens,
    PageIntro,
    Pathway,
    Photo,
    SafeContactBand,
    SectionHeading,
    SitePage,
    useSiteName,
} from './site';

function TickList({ items }: { items: readonly string[] }) {
    return (
        <ul className="space-y-2.5">
            {items.map((item) => (
                <li key={item} className="flex items-start gap-2.5 text-sm">
                    <Check
                        className="mt-0.5 size-4 shrink-0 text-primary"
                        aria-hidden
                    />
                    {item}
                </li>
            ))}
        </ul>
    );
}

/* ── Our support ─────────────────────────────────────────────────────── */

export function SupportPage({ focus }: { focus?: string }) {
    useEffect(() => {
        if (!focus) return;
        document
            .getElementById(`service-${focus}`)
            ?.scrollIntoView({ block: 'start' });
    }, [focus]);

    return (
        <SitePage
            current="support"
            intro={
                <PageIntro
                    eyebrow="Our support"
                    title="Support shaped around your life"
                    lede={`We support adults across ${provider.regions.slice(0, -1).join(', ')} and ${provider.regions.at(-1)}. Most people use a mix of support, and it can change as life does.`}
                >
                    <div className="flex flex-wrap gap-2">
                        {services.map((service) => (
                            <Button key={service.key} asChild variant="outline">
                                <a href={`#/support/${service.key}`}>
                                    {service.title}
                                </a>
                            </Button>
                        ))}
                    </div>
                </PageIntro>
            }
        >
            <section
                aria-label="Ways we support people"
                className="journey-site-container py-16"
            >
                <div className="grid gap-4 md:grid-cols-3">
                    {services.map((service) => (
                        <ServiceCard key={service.key} service={service} />
                    ))}
                </div>
            </section>

            <div className="journey-site-container space-y-6 pb-4">
                {services.map((service, index) => (
                    <section
                        key={service.key}
                        id={`service-${service.key}`}
                        aria-labelledby={`service-${service.key}-heading`}
                        className="journey-site-anchor grid gap-8 rounded-3xl border border-border bg-card p-6 md:p-10 lg:grid-cols-2 lg:gap-12"
                    >
                        <Photo
                            photo={service.photo}
                            className={cn(
                                'aspect-[4/3] self-start',
                                index % 2 === 1 && 'lg:order-last',
                            )}
                        />
                        <div className="flex flex-col items-start gap-5">
                            <Lens icon={icons[service.icon]} />
                            <SectionHeading
                                id={`service-${service.key}-heading`}
                                kicker="Our support"
                                title={service.title}
                            >
                                {service.summary}
                            </SectionHeading>
                            <div className="w-full space-y-3">
                                <p className="font-semibold">
                                    What it can include
                                </p>
                                <TickList items={service.includes} />
                            </div>
                            <div className="grid w-full gap-3 sm:grid-cols-2">
                                <div className="rounded-2xl bg-muted p-4">
                                    <p className="text-sm font-semibold">
                                        Who it’s for
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {service.forWhom}
                                    </p>
                                </div>
                                <div className="rounded-2xl bg-muted p-4">
                                    <p className="text-sm font-semibold">
                                        How it’s usually funded
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {service.funding}
                                    </p>
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-3">
                                <Button asChild>
                                    <a href="#/referral/support">
                                        Ask about{' '}
                                        {service.title
                                            .replace(/ \(.*\)$/, '')
                                            .toLowerCase()}
                                        <ArrowRight
                                            className="size-4"
                                            aria-hidden
                                        />
                                    </a>
                                </Button>
                                <Button asChild variant="outline">
                                    <a href="#/funding">
                                        <Wallet
                                            className="size-4"
                                            aria-hidden
                                        />
                                        Funding help
                                    </a>
                                </Button>
                            </div>
                        </div>
                    </section>
                ))}
            </div>

            <SafeContactBand />
        </SitePage>
    );
}

/* ── Funding help ────────────────────────────────────────────────────── */

const fundingSteps = [
    {
        title: 'Talk to us',
        body: 'Tell us what support you’re looking for. We’ll explain the pathways that might fit.',
    },
    {
        title: 'An assessment or approval',
        body: 'If a funder is involved, they assess your needs and decide what they’ll fund. We can help you prepare.',
    },
    {
        title: 'A clear proposal',
        body: 'We send you a written proposal: the support, the hours and the costs, and who pays for what.',
    },
    {
        title: 'Agree and start',
        body: 'Nothing starts until you, and the funder if there is one, have agreed the plan.',
    },
];

export function FundingPage() {
    return (
        <SitePage
            current="funding"
            intro={
                <PageIntro
                    eyebrow="Funding help"
                    title="Help to work out how support is paid for"
                    lede="Most support in Aotearoa is funded through one of a few pathways. We can talk them through with you, help you prepare and follow up. The funder makes the decision, not us."
                >
                    <Button asChild size="lg">
                        <a href="#/referral/funding">
                            Get help with funding
                            <ArrowRight className="size-4" aria-hidden />
                        </a>
                    </Button>
                </PageIntro>
            }
        >
            <section
                aria-labelledby="pathways-heading"
                className="journey-site-container space-y-8 py-16"
            >
                <SectionHeading
                    id="pathways-heading"
                    kicker="The main pathways"
                    title="Where funding usually comes from"
                >
                    You might use more than one. If you’re not sure which
                    applies, that’s normal: ask us and we’ll work it out with
                    you.
                </SectionHeading>
                <div className="grid gap-4 md:grid-cols-2">
                    {pathways.map((pathway) => (
                        <article
                            key={pathway.key}
                            aria-labelledby={`pathway-${pathway.key}`}
                            className="flex flex-col gap-4 rounded-3xl border border-border bg-card p-6 md:last:odd:col-span-2"
                        >
                            <div className="flex items-center gap-3">
                                <Lens icon={icons[pathway.icon]} />
                                <h3
                                    id={`pathway-${pathway.key}`}
                                    className="text-lg font-semibold tracking-tight"
                                >
                                    {pathway.title}
                                </h3>
                            </div>
                            <p className="text-muted-foreground">
                                {pathway.body}
                            </p>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="rounded-2xl bg-muted p-4">
                                    <p className="text-sm font-semibold">
                                        How to start
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {pathway.start}
                                    </p>
                                </div>
                                <div className="rounded-2xl bg-muted p-4">
                                    <p className="text-sm font-semibold">
                                        How we can help
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {pathway.help}
                                    </p>
                                </div>
                            </div>
                        </article>
                    ))}
                </div>
            </section>

            <section
                aria-labelledby="honest-heading"
                className="journey-site-lilac"
            >
                <div className="journey-site-container grid gap-10 py-16 lg:grid-cols-[1fr_1.4fr]">
                    <SectionHeading
                        id="honest-heading"
                        kicker="Straight answers"
                        title="What we can and can’t do"
                    >
                        We’d rather be clear up front than promise something we
                        can’t control.
                    </SectionHeading>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="rounded-3xl border border-border bg-card p-6">
                            <p className="mb-4 font-semibold">We can</p>
                            <TickList
                                items={[
                                    'Explain each pathway in plain language',
                                    'Help you prepare for an assessment',
                                    'Check whether the support you want can be funded',
                                    'Give you a written proposal with the costs',
                                    'Follow up with the funder, with your agreement',
                                ]}
                            />
                        </div>
                        <div className="rounded-3xl border border-border bg-card p-6">
                            <p className="mb-4 font-semibold">We can’t</p>
                            <ul className="space-y-2.5">
                                {[
                                    'Decide whether you’re eligible for funding',
                                    'Decide how much funding you get',
                                    'Treat a referral as a funding application',
                                    'Promise a place or a start date before a plan is agreed',
                                ].map((item) => (
                                    <li
                                        key={item}
                                        className="flex items-start gap-2.5 text-sm"
                                    >
                                        <Minus
                                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                            aria-hidden
                                        />
                                        {item}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                </div>
            </section>

            <section
                aria-labelledby="funding-steps-heading"
                className="journey-site-container space-y-10 py-16"
            >
                <SectionHeading
                    id="funding-steps-heading"
                    kicker="How it usually goes"
                    title="From a question to agreed support"
                />
                <Pathway steps={fundingSteps} />
            </section>

            <SafeContactBand />
        </SitePage>
    );
}

/* ── About us ────────────────────────────────────────────────────────── */

const initials = (name: string) =>
    name
        .split(' ')
        .map((part) => part[0])
        .join('');

export function AboutPage() {
    const name = useSiteName();
    return (
        <SitePage
            current="about"
            intro={
                <PageIntro
                    eyebrow="About us"
                    title="Supported living, grounded in whānau"
                    lede={`${name} supports adults to live in their own homes and take part in their communities. We’re a local team, from the Kāpiti Coast to Wellington.`}
                    media={
                        <Photo
                            photo={photos.whanau}
                            className="aspect-[4/3] shadow-2xl"
                            eager
                        />
                    }
                />
            }
        >
            <section
                aria-labelledby="values-heading"
                className="journey-site-container space-y-8 py-16"
            >
                <SectionHeading
                    id="values-heading"
                    kicker="What we believe"
                    title="Our values"
                />
                <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {values.map((value) => (
                        <li
                            key={value.title}
                            className="flex flex-col gap-3 rounded-3xl border border-border bg-card p-6"
                        >
                            <Lens icon={icons[value.icon]} />
                            <p className="text-lg font-semibold tracking-tight">
                                {value.title}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {value.body}
                            </p>
                        </li>
                    ))}
                </ul>
            </section>

            <section
                aria-labelledby="whanau-heading"
                className="journey-site-lilac"
            >
                <div className="journey-site-container grid gap-10 py-16 lg:grid-cols-[1fr_1.2fr]">
                    <SectionHeading
                        id="whanau-heading"
                        kicker="Whānau"
                        title="Family and whānau are part of the team"
                    >
                        When the person we support wants them involved, we keep
                        whānau in the loop: at planning meetings, when something
                        changes, and day to day.
                    </SectionHeading>
                    <div className="space-y-4 rounded-3xl border border-border bg-card p-6 md:p-8">
                        <TickList
                            items={[
                                'Whānau can join planning and review meetings',
                                'You choose what is shared, and with whom',
                                'The family portal shows updates, visits, messages and documents',
                                'One named coordinator to call when you have a question',
                            ]}
                        />
                        <Button asChild variant="outline">
                            <a href="#/sign-in/family">
                                <LogIn className="size-4" aria-hidden />
                                Family portal sign in
                            </a>
                        </Button>
                    </div>
                </div>
            </section>

            <section
                aria-labelledby="team-heading"
                className="journey-site-container space-y-8 py-16"
            >
                <SectionHeading
                    id="team-heading"
                    kicker="Our team"
                    title="People you’ll get to know"
                >
                    Around 80 support workers, team leaders and coordinators,
                    led by a small local team.
                </SectionHeading>
                <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {team.map((person) => (
                        <li
                            key={person.name}
                            className="flex flex-col gap-3 rounded-3xl border border-border bg-card p-6"
                        >
                            <Avatar className="size-14">
                                <AvatarFallback className="bg-muted text-lg font-semibold text-primary">
                                    {initials(person.name)}
                                </AvatarFallback>
                            </Avatar>
                            <div>
                                <p className="font-semibold">{person.name}</p>
                                <p className="text-sm text-primary">
                                    {person.role}
                                </p>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {person.body}
                            </p>
                        </li>
                    ))}
                </ul>
            </section>

            <section
                aria-labelledby="commitments-heading"
                className="journey-site-container pb-4"
            >
                <div className="grid gap-8 rounded-3xl border border-border bg-card p-6 md:p-10 lg:grid-cols-[1fr_1.4fr]">
                    <div className="flex flex-col items-start gap-4">
                        <Lens icon={Scale} />
                        <SectionHeading
                            id="commitments-heading"
                            kicker="Our commitments"
                            title="Your rights come first"
                        />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {[
                            [
                                'Your rights',
                                'We follow the Code of Health and Disability Services Consumers’ Rights in everything we do.',
                            ],
                            [
                                'Your information',
                                'We look after your personal information under the Privacy Act 2020. You can ask to see or correct it.',
                            ],
                            [
                                'Raising a concern',
                                'Tell any of us, or contact our general manager directly. We’ll listen and tell you what we’ll do.',
                            ],
                            [
                                'Independent advice',
                                'The Health and Disability Advocacy Service is free and independent, and can help you raise a concern.',
                            ],
                        ].map(([title, body]) => (
                            <div
                                key={title}
                                className="rounded-2xl bg-muted p-4"
                            >
                                <p className="text-sm font-semibold">{title}</p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {body}
                                </p>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <SafeContactBand />
        </SitePage>
    );
}

/* ── Privacy, terms, accessibility ───────────────────────────────────── */

type LegalKind = 'privacy' | 'terms' | 'accessibility';

const legal: Record<
    LegalKind,
    { title: string; what: string; sections: [string, ReactNode][] }
> = {
    privacy: {
        title: 'Privacy statement',
        what: 'privacy statement',
        sections: [
            [
                'What we collect',
                'The details you give us in a referral, a message or a job application: names, contact details, and what you tell us about the support you’re looking for.',
            ],
            [
                'Why we collect it',
                'To reply to you, to plan support with you, and to meet our obligations as a support provider. We don’t sell your information or use it for advertising.',
            ],
            [
                'Who sees it',
                'Only the staff who need it: our intake team for referrals and messages, and our recruitment team for job applications.',
            ],
            [
                'Your rights',
                'Under the Privacy Act 2020 you can ask to see the information we hold about you and ask us to correct it. If you’re unhappy with how we’ve handled it, you can contact the Office of the Privacy Commissioner.',
            ],
            [
                'Contact our privacy officer',
                `Email ${provider.email} or call ${provider.phone}.`,
            ],
        ],
    },
    terms: {
        title: 'Terms of use',
        what: 'terms of use',
        sections: [
            [
                'Using this website',
                'This website tells you about our support and lets you make a referral, send us a message or apply for a job.',
            ],
            [
                'Not for emergencies',
                'This website isn’t monitored for emergencies. If someone is in danger, call 111.',
            ],
            [
                'Information on this site',
                'We keep this website accurate and up to date, but it’s general information. Talk to us about your own situation.',
            ],
            [
                'Changes',
                'We may update these terms. The date at the bottom shows when they last changed.',
            ],
        ],
    },
    accessibility: {
        title: 'Accessibility',
        what: 'accessibility statement',
        sections: [
            [
                'Our aim',
                'We want everyone to be able to use this website. We aim to meet the Web Content Accessibility Guidelines (WCAG) 2.1 at level AA.',
            ],
            [
                'Other ways to reach us',
                `Call ${provider.phone}, or ask us to call you back. We can arrange a New Zealand Sign Language interpreter or another language.`,
            ],
            [
                'Something not working?',
                'Tell us what you were trying to do and what went wrong, and we’ll fix it or help you another way.',
            ],
        ],
    },
};

export function LegalPage({ kind }: { kind: LegalKind }) {
    const name = useSiteName();
    const page = legal[kind];
    return (
        <SitePage
            current="legal"
            intro={<PageIntro eyebrow={name} title={page.title} />}
        >
            <div className="journey-site-container grid items-start gap-6 py-12 lg:grid-cols-[1fr_340px]">
                <article className="space-y-8 rounded-3xl border border-border bg-card p-6 md:p-10">
                    <Alert variant="info">
                        <FilePenLine aria-hidden />
                        <AlertTitle>Placeholder text</AlertTitle>
                        <AlertDescription>
                            {name} replaces this with its own {page.what} before
                            the website goes live.
                        </AlertDescription>
                    </Alert>
                    {page.sections.map(([heading, body]) => (
                        <section key={heading} className="space-y-2">
                            <h2 className="text-section-title">{heading}</h2>
                            <p className="max-w-3xl leading-relaxed text-muted-foreground">
                                {body}
                            </p>
                        </section>
                    ))}
                    <p className="border-t border-border pt-6 text-sm text-muted-foreground">
                        Last updated 20 October 2026.
                    </p>
                </article>
                <aside className="space-y-4 rounded-3xl border border-border bg-card p-6">
                    <p className="font-semibold">Questions about this page?</p>
                    <p className="text-sm text-muted-foreground">
                        Call us on {provider.phone} ({provider.hours}), or send
                        us a message.
                    </p>
                    <Button asChild variant="outline" className="w-full">
                        <a href="#/contact">Contact us</a>
                    </Button>
                </aside>
            </div>
        </SitePage>
    );
}
