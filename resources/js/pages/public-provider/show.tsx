import { JourneyMark } from '@/components/brand/journey-mark';
import {
    enquiryUrl,
    phoneUrl,
    type PublicProvider,
} from '@/components/public-provider';
import { ContactForm } from '@/components/public-site/contact-form';
import {
    demo,
    demoPhotos,
    fundingSteps,
    helpOptions,
    journey,
    pathways,
    regionList,
    services,
    values,
} from '@/components/public-site/content';
import {
    icons,
    IntakeClosedNotice,
    Lens,
    PageIntro,
    Pathway,
    SafeContactBand,
    SectionHeading,
    SitePhoto,
    TickList,
    type SitePhotoSource,
} from '@/components/public-site/primitives';
import { siteLinks, usePublicSite } from '@/components/public-site/site';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import PublicSiteLayout from '@/layouts/marketing-layout';
import { cn } from '@/lib/utils';
import { Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Briefcase,
    CheckCircle2,
    Clock,
    FilePenLine,
    LogIn,
    Mail,
    MapPin,
    MessageSquareText,
    Minus,
    Phone,
    Scale,
    Users,
    Wallet,
} from 'lucide-react';
import { useEffect, type ReactNode } from 'react';

type Page =
    | 'home'
    | 'services'
    | 'service'
    | 'funding'
    | 'about'
    | 'contact'
    | 'privacy'
    | 'terms'
    | 'accessibility';
type Props = {
    provider: PublicProvider;
    page: Page;
    service?: PublicProvider['services'][number] | null;
    previousFormRetired?: boolean;
};

/** Pages with enquiry links say so when online enquiries are switched off. */
function ClosedIntake({ show }: { show: boolean }) {
    if (!show) return null;
    return (
        <div className="journey-site-container pt-12">
            <IntakeClosedNotice />
        </div>
    );
}

/* ── Home ────────────────────────────────────────────────────────────── */

/** The hero's media: the client's photo, a plain brand panel when a client
 *  has no photo, and the islands only as Oblivion's own default. */
function HomeHeroMedia() {
    const { heroUrl, heroAlt, hasClientBrand } = usePublicSite();
    const pins = (
        <>
            {/* The journey, told on the photo: a first conversation leads
                to support that fits. */}
            <span
                aria-hidden
                className="journey-site-pin journey-site-pin--photo-start"
            >
                <MessageSquareText className="size-3.5 text-primary" />A first
                conversation
            </span>
            <span
                aria-hidden
                className="journey-site-pin journey-site-pin--photo-end"
            >
                <CheckCircle2 className="size-3.5 text-primary" />
                Support that fits
            </span>
        </>
    );

    if (!heroUrl && !hasClientBrand) {
        return (
            <div className="journey-site-map" data-testid="home-islands">
                <JourneyMark tone="lavender" />
                <span
                    aria-hidden
                    className="journey-site-pin journey-site-pin--start"
                >
                    <MessageSquareText className="size-3.5 text-primary" />A
                    first conversation
                </span>
                <span
                    aria-hidden
                    className="journey-site-pin journey-site-pin--end"
                >
                    <CheckCircle2 className="size-3.5 text-primary" />
                    Support that fits
                </span>
            </div>
        );
    }

    return (
        <div className="relative">
            <SitePhoto
                photo={heroUrl ? { src: heroUrl, alt: heroAlt } : null}
                className="aspect-[4/3] shadow-2xl lg:aspect-[6/5]"
                eager
            />
            {pins}
        </div>
    );
}

function ServiceCard({
    service,
    photo,
}: {
    service: (typeof services)[number];
    photo: SitePhotoSource | null;
}) {
    return (
        <Link
            href={`${siteLinks.support}#${service.key}`}
            className="journey-site-tile overflow-hidden p-0"
        >
            <SitePhoto
                photo={photo}
                icon={icons[service.icon]}
                className="aspect-[4/3] rounded-none"
            />
            <span className="flex flex-1 flex-col gap-2 p-6 pt-4">
                <span className="text-lg font-semibold tracking-tight">
                    {service.title}
                </span>
                <span className="text-sm text-muted-foreground">
                    {service.summary}
                </span>
                <span className="mt-auto flex items-center gap-1.5 pt-2 text-sm font-semibold text-primary">
                    Read more
                    <ArrowRight className="size-4" aria-hidden />
                </span>
            </span>
        </Link>
    );
}

function useServicePhoto() {
    const { demo: isDemo } = usePublicSite();
    return (key: (typeof services)[number]['key']) =>
        isDemo ? demoPhotos[key] : null;
}

function HomePage({ provider }: { provider: PublicProvider }) {
    const { demo: isDemo } = usePublicSite();
    const servicePhoto = useServicePhoto();
    const phone = provider.phone?.trim();
    const hours = provider.response_hours?.trim();

    return (
        <PublicSiteLayout
            intro={
                <section className="journey-site-container grid items-center gap-12 pt-8 pb-16 lg:grid-cols-[1.1fr_1fr] lg:pt-14 lg:pb-24">
                    <div className="flex flex-col items-start gap-6">
                        <p className="journey-site-eyebrow">
                            {isDemo
                                ? `Supported living · ${demo.regions[0]} to ${demo.regions[demo.regions.length - 1]}`
                                : 'Supported living'}
                        </p>
                        <h1 className="journey-site-display">
                            A life that feels{' '}
                            <span className="journey-display__accent">
                                more like yours.
                            </span>
                        </h1>
                        <p className="journey-lede">
                            Practical, personal support to live with more choice
                            and independence. Start with a conversation, for
                            yourself or someone you support. You don’t need a
                            funding decision or an assessment first.
                        </p>
                        <div className="flex flex-wrap gap-3">
                            <Button asChild size="lg" className="frontline-tap">
                                <Link href={siteLinks.referral}>
                                    Make a referral
                                    <ArrowRight
                                        className="size-4"
                                        aria-hidden
                                    />
                                </Link>
                            </Button>
                            <Button
                                asChild
                                size="lg"
                                variant="outline"
                                className="frontline-tap"
                            >
                                <Link href={siteLinks.funding}>
                                    <Wallet className="size-4" aria-hidden />
                                    Help with funding
                                </Link>
                            </Button>
                        </div>
                        {phone && (
                            <p className="flex flex-wrap items-center gap-2 text-sm text-journey-ink-muted">
                                <Phone className="size-4" aria-hidden />
                                Or call{' '}
                                <a
                                    href={phoneUrl(phone)}
                                    className="font-semibold text-journey-paper hover:underline"
                                >
                                    {phone}
                                </a>
                                {hours && <> · {hours}</>}
                            </p>
                        )}
                    </div>
                    <HomeHeroMedia />
                </section>
            }
        >
            <ClosedIntake show={!provider.intake_enabled} />

            <section
                aria-labelledby="help-heading"
                className="journey-site-container space-y-8 py-16"
            >
                <SectionHeading
                    id="help-heading"
                    kicker="Start where you are"
                    title="How can we help?"
                >
                    Choose what fits. Every option reaches the same intake team,
                    and you can change your mind later.
                </SectionHeading>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {helpOptions.map((option) => (
                        <Link
                            key={option.value}
                            href={enquiryUrl(option.value)}
                            className="journey-site-tile"
                        >
                            <Lens icon={icons[option.icon]} />
                            <span className="text-lg font-semibold tracking-tight">
                                {option.title}
                            </span>
                            <span className="text-sm text-muted-foreground">
                                {option.body}
                            </span>
                            <span className="mt-auto flex items-center justify-between pt-2 text-sm">
                                <span className="flex items-center gap-1.5 text-muted-foreground">
                                    <Clock className="size-3.5" aria-hidden />
                                    {option.time}
                                </span>
                                <ArrowRight
                                    className="size-4 text-primary"
                                    aria-hidden
                                />
                            </span>
                        </Link>
                    ))}
                </div>
            </section>

            <section
                aria-labelledby="journey-heading"
                className="journey-site-container space-y-10 pb-16"
            >
                <SectionHeading
                    id="journey-heading"
                    kicker="One shared journey"
                    title="From a first conversation to agreed support"
                />
                <Pathway steps={journey} />
            </section>

            <section
                aria-labelledby="services-heading"
                className="journey-site-container space-y-8 pb-16"
            >
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <SectionHeading
                        id="services-heading"
                        kicker="Our support"
                        title="Support shaped around you"
                    />
                    <Button asChild variant="outline" className="frontline-tap">
                        <Link href={siteLinks.support}>
                            All our support
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                    </Button>
                </div>
                <div className="grid gap-4 md:grid-cols-3">
                    {services.map((service) => (
                        <ServiceCard
                            key={service.key}
                            service={service}
                            photo={servicePhoto(service.key)}
                        />
                    ))}
                </div>
            </section>

            <section
                aria-labelledby="funding-heading"
                className="journey-site-lilac"
            >
                <div className="journey-site-container grid gap-10 py-16 lg:grid-cols-[1fr_1.4fr]">
                    <div className="flex flex-col items-start gap-4">
                        <SectionHeading
                            id="funding-heading"
                            kicker="Funding help"
                            title="You don’t have to work funding out alone"
                        >
                            Most support in Aotearoa is funded through one of a
                            few pathways. Your coordinator helps you find the
                            right one, prepare what’s needed and follow it up.
                        </SectionHeading>
                        <p className="text-sm text-muted-foreground">
                            A referral isn’t a funding application, and it
                            doesn’t guarantee a place. Funding decisions are
                            made by the funder.
                        </p>
                        <Button asChild className="frontline-tap">
                            <Link href={siteLinks.funding}>
                                How funding works
                                <ArrowRight className="size-4" aria-hidden />
                            </Link>
                        </Button>
                    </div>
                    <ul className="grid gap-3 sm:grid-cols-2">
                        {pathways.map((pathway) => (
                            <li
                                key={pathway.key}
                                className="rounded-2xl border border-border bg-card p-5 sm:last:odd:col-span-2"
                            >
                                <p className="font-semibold tracking-tight">
                                    {pathway.title}
                                </p>
                                <p className="mt-1.5 text-sm text-muted-foreground">
                                    {pathway.body}
                                </p>
                            </li>
                        ))}
                    </ul>
                </div>
            </section>

            <section
                aria-labelledby="careers-heading"
                className="journey-site-container pt-16"
            >
                <div className="grid items-center gap-8 rounded-3xl border border-border bg-card p-6 md:p-8 lg:grid-cols-[1fr_1.1fr] lg:gap-12">
                    <SitePhoto
                        photo={isDemo ? demoPhotos.careers : null}
                        icon={Users}
                        className="aspect-[3/2]"
                    />
                    <div className="flex flex-col items-start gap-4">
                        <SectionHeading
                            id="careers-heading"
                            kicker="Careers"
                            title="Work with us"
                        >
                            Good support starts with good people. We train you,
                            pay you for it and back you with a team that’s
                            always a call away.
                        </SectionHeading>
                        <Button asChild className="frontline-tap">
                            <Link href={siteLinks.careers}>
                                <Briefcase className="size-4" aria-hidden />
                                See our open roles
                            </Link>
                        </Button>
                    </div>
                </div>
            </section>

            <SafeContactBand />
        </PublicSiteLayout>
    );
}

/* ── Our support ─────────────────────────────────────────────────────── */

function SupportPage({
    provider,
    service,
}: {
    provider: PublicProvider;
    service?: PublicProvider['services'][number] | null;
}) {
    const { demo: isDemo } = usePublicSite();
    const servicePhoto = useServicePhoto();
    const { url } = usePage();

    // /services#respite (footer and card links) lands on that section.
    useEffect(() => {
        const target = window.location.hash.slice(1);
        if (target)
            document.getElementById(target)?.scrollIntoView({ block: 'start' });
    }, [url]);

    const lede = isDemo
        ? `We support adults across ${regionList(demo.regions)}. Most people use a mix of support, and it can change as life does.`
        : 'We support adults to live the lives they choose. Most people use a mix of support, and it can change as life does.';

    return (
        <PublicSiteLayout
            title={service?.label ?? 'Our support'}
            current="support"
            intro={
                service ? (
                    <PageIntro
                        eyebrow="Our support"
                        title={service.label}
                        lede={lede}
                        back={{ href: siteLinks.support, label: 'Our support' }}
                    >
                        <div className="flex flex-wrap gap-3">
                            <Button asChild size="lg" className="frontline-tap">
                                <Link href={enquiryUrl('support', service.id)}>
                                    Ask about {service.label.toLowerCase()}
                                    <ArrowRight
                                        className="size-4"
                                        aria-hidden
                                    />
                                </Link>
                            </Button>
                            <Button
                                asChild
                                size="lg"
                                variant="outline"
                                className="frontline-tap"
                            >
                                <Link href={enquiryUrl('funding', service.id)}>
                                    <Wallet className="size-4" aria-hidden />
                                    Talk about funding
                                </Link>
                            </Button>
                        </div>
                    </PageIntro>
                ) : (
                    <PageIntro
                        eyebrow="Our support"
                        title="Support shaped around your life"
                        lede={lede}
                    >
                        <div className="flex flex-wrap gap-2">
                            {services.map((item) => (
                                <Button
                                    key={item.key}
                                    asChild
                                    variant="outline"
                                    className="frontline-tap"
                                >
                                    <a href={`#${item.key}`}>{item.title}</a>
                                </Button>
                            ))}
                        </div>
                    </PageIntro>
                )
            }
        >
            <ClosedIntake show={!provider.intake_enabled} />

            <section
                aria-label="Ways we support people"
                className="journey-site-container py-16"
            >
                <div className="grid gap-4 md:grid-cols-3">
                    {services.map((item) => (
                        <ServiceCard
                            key={item.key}
                            service={item}
                            photo={servicePhoto(item.key)}
                        />
                    ))}
                </div>
            </section>

            <div className="journey-site-container space-y-6 pb-4">
                {services.map((item, index) => (
                    <section
                        key={item.key}
                        id={item.key}
                        aria-labelledby={`service-${item.key}-heading`}
                        className="journey-site-anchor grid gap-8 rounded-3xl border border-border bg-card p-6 md:p-10 lg:grid-cols-2 lg:gap-12"
                    >
                        <SitePhoto
                            photo={servicePhoto(item.key)}
                            icon={icons[item.icon]}
                            className={cn(
                                'aspect-[4/3] self-start',
                                index % 2 === 1 && 'lg:order-last',
                            )}
                        />
                        <div className="flex flex-col items-start gap-5">
                            <Lens icon={icons[item.icon]} />
                            <SectionHeading
                                id={`service-${item.key}-heading`}
                                kicker="Our support"
                                title={item.title}
                            >
                                {item.summary}
                            </SectionHeading>
                            <div className="w-full space-y-3">
                                <p className="font-semibold">
                                    What it can include
                                </p>
                                <TickList items={item.includes} />
                            </div>
                            <div className="grid w-full gap-3 sm:grid-cols-2">
                                <div className="rounded-2xl bg-muted p-4">
                                    <p className="text-sm font-semibold">
                                        Who it’s for
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {item.forWhom}
                                    </p>
                                </div>
                                <div className="rounded-2xl bg-muted p-4">
                                    <p className="text-sm font-semibold">
                                        How it’s usually funded
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        {item.funding}
                                    </p>
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-3">
                                <Button asChild className="frontline-tap">
                                    <Link
                                        href={enquiryUrl(
                                            'support',
                                            service?.id,
                                        )}
                                    >
                                        Ask about{' '}
                                        {item.title
                                            .replace(/ \(.*\)$/, '')
                                            .toLowerCase()}
                                        <ArrowRight
                                            className="size-4"
                                            aria-hidden
                                        />
                                    </Link>
                                </Button>
                                <Button
                                    asChild
                                    variant="outline"
                                    className="frontline-tap"
                                >
                                    <Link href={siteLinks.funding}>
                                        <Wallet
                                            className="size-4"
                                            aria-hidden
                                        />
                                        Funding help
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    </section>
                ))}
            </div>

            <SafeContactBand />
        </PublicSiteLayout>
    );
}

/* ── Funding help ────────────────────────────────────────────────────── */

function FundingPage({ provider }: { provider: PublicProvider }) {
    return (
        <PublicSiteLayout
            title="Funding help"
            intro={
                <PageIntro
                    eyebrow="Funding help"
                    title="Help to work out how support is paid for"
                    lede="Most support in Aotearoa is funded through one of a few pathways. We can talk them through with you, help you prepare and follow up. The funder makes the decision, not us."
                >
                    <Button asChild size="lg" className="frontline-tap">
                        <Link href={enquiryUrl('funding')}>
                            Get help with funding
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                    </Button>
                </PageIntro>
            }
        >
            <ClosedIntake show={!provider.intake_enabled} />

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
        </PublicSiteLayout>
    );
}

/* ── About us ────────────────────────────────────────────────────────── */

const initials = (name: string) =>
    name
        .split(' ')
        .map((part) => part[0])
        .join('');

function AboutPage() {
    const { name, demo: isDemo } = usePublicSite();
    const lede = `${name ? `${name} supports` : 'We support'} adults to live in their own homes and take part in their communities.${
        isDemo
            ? ` We’re a local team, from the ${demo.regions[0]} to ${demo.regions[demo.regions.length - 1]}.`
            : ''
    }`;

    return (
        <PublicSiteLayout
            title="About us"
            intro={
                <PageIntro
                    eyebrow="About us"
                    title="Supported living, grounded in whānau"
                    lede={lede}
                    media={
                        <SitePhoto
                            photo={isDemo ? demoPhotos.whanau : null}
                            icon={Users}
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
                        <Button
                            asChild
                            variant="outline"
                            className="frontline-tap"
                        >
                            <Link href={siteLinks.familySignIn}>
                                <LogIn className="size-4" aria-hidden />
                                Family portal sign in
                            </Link>
                        </Button>
                    </div>
                </div>
            </section>

            {isDemo && (
                <section
                    aria-labelledby="team-heading"
                    className="journey-site-container space-y-8 py-16"
                >
                    <SectionHeading
                        id="team-heading"
                        kicker="Our team"
                        title="People you’ll get to know"
                    >
                        Around 80 support workers, team leaders and
                        coordinators, led by a small local team.
                    </SectionHeading>
                    <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {demo.team.map((person) => (
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
                                    <p className="font-semibold">
                                        {person.name}
                                    </p>
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
            )}

            <section
                aria-labelledby="commitments-heading"
                className={cn(
                    'journey-site-container pb-4',
                    !isDemo && 'pt-16',
                )}
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
        </PublicSiteLayout>
    );
}

/* ── Contact us ──────────────────────────────────────────────────────── */

function ContactPage({
    provider,
    previousFormRetired,
}: {
    provider: PublicProvider;
    previousFormRetired: boolean;
}) {
    const { name, demo: isDemo, provider: site } = usePublicSite();
    const phone = provider.phone?.trim();
    const email = site.email?.trim();
    const address = site.address?.trim();
    const hours = (site.opening_hours || provider.response_hours)?.trim();

    return (
        <PublicSiteLayout
            title="Contact us"
            intro={
                <PageIntro
                    eyebrow="Contact us"
                    title="Get in touch"
                    lede="Send us a message and our intake team will reply. If you’d rather talk, call us during office hours."
                />
            }
        >
            {previousFormRetired && (
                <div className="journey-site-container pt-12">
                    <Alert variant="destructive">
                        <AlertTitle>
                            Your earlier message was not sent
                        </AlertTitle>
                        <AlertDescription>
                            The earlier contact form has been replaced. Please
                            send your message again below.
                        </AlertDescription>
                    </Alert>
                </div>
            )}
            <ClosedIntake show={!provider.intake_enabled} />
            <div className="journey-site-container grid items-start gap-6 py-12 lg:grid-cols-[1.6fr_1fr]">
                {/* Lane CU's form: a message becomes a general enquiry (ENQ-) in referral intake. */}
                <ContactForm providerName={name} />

                <aside
                    aria-labelledby="office-heading"
                    className="overflow-hidden rounded-3xl border border-border bg-card"
                >
                    <SitePhoto
                        photo={isDemo ? demoPhotos.office : null}
                        icon={MapPin}
                        className="aspect-[4/3] rounded-none"
                    />
                    <div className="space-y-5 p-6">
                        <h2 id="office-heading" className="text-section-title">
                            Our office
                        </h2>
                        <ul className="space-y-4 text-sm">
                            {address && (
                                <ContactRow icon={MapPin} title="Visit us">
                                    <span className="whitespace-pre-line">
                                        {address}
                                    </span>
                                    {isDemo && (
                                        <span className="block text-muted-foreground">
                                            Step-free entrance and parking at
                                            the back.
                                        </span>
                                    )}
                                </ContactRow>
                            )}
                            {phone && (
                                <ContactRow icon={Phone} title="Call us">
                                    <a
                                        href={phoneUrl(phone)}
                                        className="font-semibold text-primary hover:underline"
                                    >
                                        {phone}
                                    </a>
                                    {isDemo && (
                                        <span className="block text-muted-foreground">
                                            Free from any phone
                                        </span>
                                    )}
                                </ContactRow>
                            )}
                            {email && (
                                <ContactRow icon={Mail} title="Email us">
                                    <a
                                        href={`mailto:${email}`}
                                        className="break-all text-primary hover:underline"
                                    >
                                        {email}
                                    </a>
                                </ContactRow>
                            )}
                            {hours && (
                                <ContactRow icon={Clock} title="Office hours">
                                    {hours}
                                    {isDemo && (
                                        <span className="block text-muted-foreground">
                                            Closed on public holidays
                                        </span>
                                    )}
                                </ContactRow>
                            )}
                        </ul>
                    </div>
                </aside>
            </div>
        </PublicSiteLayout>
    );
}

function ContactRow({
    icon,
    title,
    children,
}: {
    icon: typeof Phone;
    title: string;
    children: ReactNode;
}) {
    return (
        <li className="flex items-start gap-3">
            <Lens icon={icon} />
            <span>
                <span className="block font-semibold">{title}</span>
                {children}
            </span>
        </li>
    );
}

/* ── Privacy, terms, accessibility ───────────────────────────────────── */

type LegalKind = 'privacy' | 'terms' | 'accessibility';

function legalPage(
    kind: LegalKind,
    contact: { phone?: string; email?: string },
): { title: string; what: string; sections: [string, ReactNode][] } {
    const reach =
        contact.email && contact.phone
            ? `Email ${contact.email} or call ${contact.phone}.`
            : contact.email
              ? `Email ${contact.email}.`
              : contact.phone
                ? `Call ${contact.phone}.`
                : 'Send us a message through the Contact us page.';
    switch (kind) {
        case 'privacy':
            return {
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
                    ['Contact our privacy officer', reach],
                ],
            };
        case 'terms':
            return {
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
            };
        case 'accessibility':
            return {
                title: 'Accessibility',
                what: 'accessibility statement',
                sections: [
                    [
                        'Our aim',
                        'We want everyone to be able to use this website. We aim to meet the Web Content Accessibility Guidelines (WCAG) 2.1 at level AA.',
                    ],
                    [
                        'Other ways to reach us',
                        `${contact.phone ? `Call ${contact.phone}, or ask` : 'Ask'} us to call you back. We can arrange a New Zealand Sign Language interpreter or another language.`,
                    ],
                    [
                        'Something not working?',
                        'Tell us what you were trying to do and what went wrong, and we’ll fix it or help you another way.',
                    ],
                ],
            };
    }
}

function LegalPage({
    kind,
    provider,
}: {
    kind: LegalKind;
    provider: PublicProvider;
}) {
    const { name, provider: site } = usePublicSite();
    const phone = provider.phone?.trim() || undefined;
    const hours = provider.response_hours?.trim();
    const page = legalPage(kind, {
        phone,
        email: site.email?.trim() || undefined,
    });

    return (
        <PublicSiteLayout
            title={page.title}
            current="legal"
            intro={
                <PageIntro
                    eyebrow={name ?? 'Website information'}
                    title={page.title}
                />
            }
        >
            <div className="journey-site-container grid items-start gap-6 py-12 lg:grid-cols-[1fr_340px]">
                <article className="space-y-8 rounded-3xl border border-border bg-card p-6 md:p-10">
                    <Alert variant="info">
                        <FilePenLine aria-hidden />
                        <AlertTitle>Placeholder text</AlertTitle>
                        <AlertDescription>
                            {name ?? 'The provider'} replaces this with its own{' '}
                            {page.what} before the website goes live.
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
                    {kind === 'privacy' && (
                        // The published enquiry notice is real, not placeholder:
                        // it is what people agree to in Make a referral.
                        <section
                            className="space-y-3 rounded-2xl bg-muted p-5"
                            aria-labelledby="enquiry-notice-heading"
                        >
                            <h2
                                id="enquiry-notice-heading"
                                className="text-section-title"
                            >
                                Our enquiry privacy notice
                            </h2>
                            {provider.notice ? (
                                <>
                                    <p className="max-w-3xl leading-relaxed break-words whitespace-pre-wrap text-muted-foreground">
                                        {provider.notice}
                                    </p>
                                    <p className="text-caption text-muted-foreground">
                                        Notice version:{' '}
                                        {provider.notice_version}
                                    </p>
                                </>
                            ) : (
                                <p className="text-muted-foreground">
                                    The enquiry privacy notice hasn’t been
                                    published yet. Online enquiries stay closed
                                    until it is.
                                </p>
                            )}
                        </section>
                    )}
                    <p className="border-t border-border pt-6 text-sm text-muted-foreground">
                        Last updated 20 October 2026.
                    </p>
                </article>
                <aside className="space-y-4 rounded-3xl border border-border bg-card p-6">
                    <p className="font-semibold">Questions about this page?</p>
                    <p className="text-sm text-muted-foreground">
                        {phone
                            ? `Call us on ${phone}${hours ? ` (${hours})` : ''}, or send us a message.`
                            : 'Send us a message and our team will reply.'}
                    </p>
                    <Button
                        asChild
                        variant="outline"
                        className="frontline-tap w-full"
                    >
                        <Link href={siteLinks.contact}>Contact us</Link>
                    </Button>
                </aside>
            </div>
        </PublicSiteLayout>
    );
}

/* ── The page switch ─────────────────────────────────────────────────── */

export default function PublicProviderPage({
    provider,
    page,
    service,
    previousFormRetired = false,
}: Props) {
    switch (page) {
        case 'home':
            return <HomePage provider={provider} />;
        case 'services':
            return <SupportPage provider={provider} />;
        case 'service':
            return <SupportPage provider={provider} service={service} />;
        case 'funding':
            return <FundingPage provider={provider} />;
        case 'about':
            return <AboutPage />;
        case 'contact':
            return (
                <ContactPage
                    provider={provider}
                    previousFormRetired={previousFormRetired}
                />
            );
        case 'privacy':
        case 'terms':
        case 'accessibility':
            return <LegalPage kind={page} provider={provider} />;
    }
}
