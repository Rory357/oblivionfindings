import { Button } from '@/components/ui/button';
import {
    ArrowRight,
    Briefcase,
    CheckCircle2,
    Clock,
    MessageSquareText,
    Phone,
    Wallet,
} from 'lucide-react';
import {
    helpOptions,
    jobs,
    journey,
    pathways,
    photos,
    provider,
    services,
} from './data';
import {
    icons,
    Lens,
    Pathway,
    Photo,
    SafeContactBand,
    SectionHeading,
    SitePage,
} from './site';

/** A service as a photo card (Home and Our support). */
export function ServiceCard({
    service,
}: {
    service: (typeof services)[number];
}) {
    return (
        <a
            href={`#/support/${service.key}`}
            className="journey-site-tile overflow-hidden p-0"
        >
            <Photo
                photo={service.photo}
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
        </a>
    );
}

export function HomePage() {
    return (
        <SitePage
            current="home"
            intro={
                <section className="journey-site-container grid items-center gap-12 pt-8 pb-16 lg:grid-cols-[1.1fr_1fr] lg:pt-14 lg:pb-24">
                    <div className="flex flex-col items-start gap-6">
                        <p className="journey-site-eyebrow">
                            Supported living · Kāpiti Coast to Wellington
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
                            <Button asChild size="lg">
                                <a href="#/referral">
                                    Make a referral
                                    <ArrowRight
                                        className="size-4"
                                        aria-hidden
                                    />
                                </a>
                            </Button>
                            <Button asChild size="lg" variant="outline">
                                <a href="#/funding">
                                    <Wallet className="size-4" aria-hidden />
                                    Help with funding
                                </a>
                            </Button>
                        </div>
                        <p className="flex items-center gap-2 text-sm text-journey-ink-muted">
                            <Phone className="size-4" aria-hidden />
                            Or call {provider.phone} · {provider.hours}
                        </p>
                    </div>
                    <div className="relative">
                        <Photo
                            photo={photos.hero}
                            className="aspect-[4/3] shadow-2xl lg:aspect-[6/5]"
                            eager
                        />
                        {/* The journey, told on the photo: a first
                            conversation leads to support that fits. */}
                        <span
                            aria-hidden
                            className="journey-site-pin journey-site-pin--photo-start"
                        >
                            <MessageSquareText className="size-3.5 text-primary" />
                            A first conversation
                        </span>
                        <span
                            aria-hidden
                            className="journey-site-pin journey-site-pin--photo-end"
                        >
                            <CheckCircle2 className="size-3.5 text-primary" />
                            Support that fits
                        </span>
                    </div>
                </section>
            }
        >
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
                        <a
                            key={option.value}
                            href={`#/referral/${option.value}`}
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
                        </a>
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
                    <Button asChild variant="outline">
                        <a href="#/support">
                            All our support
                            <ArrowRight className="size-4" aria-hidden />
                        </a>
                    </Button>
                </div>
                <div className="grid gap-4 md:grid-cols-3">
                    {services.map((service) => (
                        <ServiceCard key={service.key} service={service} />
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
                        <Button asChild>
                            <a href="#/funding">
                                How funding works
                                <ArrowRight className="size-4" aria-hidden />
                            </a>
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
                    <Photo photo={photos.careers} className="aspect-[3/2]" />
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
                        <Button asChild>
                            <a href="#/careers">
                                <Briefcase className="size-4" aria-hidden />
                                See {jobs.length} open roles
                            </a>
                        </Button>
                    </div>
                </div>
            </section>

            <SafeContactBand />
        </SitePage>
    );
}
