import {
    enquiryUrl,
    phoneUrl,
    type PublicProvider,
} from '@/components/public-provider';
import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import MarketingLayout from '@/layouts/marketing-layout';
import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    HeartHandshake,
    MessageCircle,
    Phone,
    Users,
    Wallet,
} from 'lucide-react';
import type { ReactNode } from 'react';

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
const titles: Record<Page, string> = {
    home: 'Support for the life you want to lead',
    services: 'Support shaped around you',
    service: 'Explore support',
    funding: 'Find your way through funding',
    about: 'Your choices come first',
    contact: 'Let’s start a conversation',
    privacy: 'Your information and privacy',
    terms: 'Using this website',
    accessibility: 'Help using this website',
};
const journey = [
    [
        'Tell us what matters',
        'Share your goals and how we can contact you safely. You can ask about the process before sharing someone’s personal details.',
    ],
    [
        'Explore support and funding',
        'We review your enquiry with you, confirm what is available and work through funding questions and next steps.',
    ],
    [
        'Agree a plan together',
        'You review the proposed support, costs and responsibilities. Starting a referral does not commit you to a service.',
    ],
    [
        'Prepare for a safe start',
        'Care planning, your agreement, consent and the people providing support are checked before a start is confirmed.',
    ],
];

function Action({
    children = 'Explore support',
    purpose = 'support',
    service,
    secondary = false,
}: {
    children?: ReactNode;
    purpose?: string;
    service?: string;
    secondary?: boolean;
}) {
    return (
        <Button
            asChild
            variant={secondary ? 'outline' : 'default'}
            className="frontline-tap h-auto min-h-11 py-3 text-center whitespace-normal"
        >
            <Link href={enquiryUrl(purpose, service)}>
                {children}
                <ArrowRight aria-hidden className="size-4 shrink-0" />
            </Link>
        </Button>
    );
}

function Journey() {
    return (
        <section aria-labelledby="journey-heading" className="space-y-5">
            <div>
                <p className="text-caption text-primary">A clear next step</p>
                <h2 id="journey-heading" className="text-section-title">
                    From a first conversation to agreed support
                </h2>
            </div>
            <ol className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                {journey.map(([title, description], index) => (
                    <li key={title}>
                        <Card className="h-full">
                            <CardHeader className="gap-3">
                                <span className="text-caption font-medium text-primary">
                                    Step {index + 1}
                                </span>
                                <h3 className="font-medium">{title}</h3>
                            </CardHeader>
                            <CardContent>
                                <p className="text-subtle">{description}</p>
                            </CardContent>
                        </Card>
                    </li>
                ))}
            </ol>
        </section>
    );
}

function Services({ provider }: { provider: PublicProvider }) {
    return (
        <section className="space-y-5" aria-labelledby="services-heading">
            <h2 id="services-heading" className="text-section-title">
                Explore our support
            </h2>
            {provider.services.length ? (
                <div className="grid gap-4 md:grid-cols-2">
                    {provider.services.map((service) => (
                        <Card key={service.id}>
                            <CardHeader>
                                <HeartHandshake
                                    aria-hidden
                                    className="size-5 text-primary"
                                />
                                <h3 className="text-section-title">
                                    {service.label}
                                </h3>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <p className="text-subtle">
                                    Talk through your goals, location and the
                                    support you are looking for. We will confirm
                                    suitability and availability with you.
                                </p>
                                <Button
                                    variant="outline"
                                    asChild
                                    className="frontline-tap h-auto max-w-full py-2 text-left break-words whitespace-normal"
                                >
                                    <Link
                                        href={`/services/${encodeURIComponent(service.id)}`}
                                    >
                                        Explore {service.label.toLowerCase()}
                                        <ArrowRight
                                            aria-hidden
                                            className="size-4"
                                        />
                                    </Link>
                                </Button>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            ) : (
                <p className="text-subtle">
                    Service information is being updated. You can check the
                    contact page for available ways to reach us.
                </p>
            )}
            <p className="text-caption text-muted-foreground">
                Availability depends on your location, assessed needs and the
                support team’s capacity.
            </p>
        </section>
    );
}

function Funding() {
    const routes = [
        {
            name: 'Disability support and needs assessment',
            text: 'A Needs Assessment and Service Coordination service (NASC) or an Enabling Good Lives team can help you explore disability support. We can help you prepare your questions, gather information and follow up on the next steps.',
            href: 'https://www.disabilitysupport.govt.nz/disabled-people/assessment-and-funding/needs-assessment-services',
            source: 'DSS: needs assessment services',
        },
        {
            name: 'Injury-related support',
            text: 'If your need for support relates to an injury, ACC may be a relevant pathway. We can help you clarify the assessment, decision and support arrangements that apply to your situation.',
            href: 'https://www.acc.co.nz/im-injured/types-of-ongoing-support/home-help',
            source: 'ACC: support at home',
        },
        {
            name: 'Personal income and everyday costs',
            text: 'Help with living costs is separate from funding that pays a support provider. You can ask for help exploring Work and Income assistance alongside your care funding.',
            href: 'https://www.workandincome.govt.nz/eligibility/health-and-disability',
            source: 'Work and Income: health and disability',
        },
        {
            name: 'Other funding or private payment',
            text: 'You may already have a health-service arrangement, individual funding, insurance, a grant or private funding. Share what you know; we will work through what it covers, who can authorise payment and any remaining gap.',
        },
    ];
    return (
        <>
            <div className="grid gap-5 lg:grid-cols-2">
                {routes.map((route) => (
                    <Card key={route.name}>
                        <CardHeader>
                            <h2 className="text-section-title">{route.name}</h2>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <p className="text-subtle">{route.text}</p>
                            {route.href && (
                                <a
                                    href={route.href}
                                    className="frontline-tap text-subtle inline-flex items-center rounded text-primary underline underline-offset-4 focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    {route.source}
                                </a>
                            )}
                        </CardContent>
                    </Card>
                ))}
            </div>
            <section className="grid gap-6 rounded-2xl border border-border bg-muted/40 p-6 md:grid-cols-2">
                <div className="space-y-3">
                    <h2 className="text-section-title">
                        You do not need funding sorted before asking
                    </h2>
                    <p className="text-subtle">
                        We can help identify the relevant pathway, prepare an
                        application or assessment, track requested information
                        and follow up on a decision. The funding agency decides
                        eligibility and the amount of support.
                    </p>
                    <Action purpose="funding">Ask for funding help</Action>
                </div>
                <div className="space-y-3">
                    <h3 className="font-medium">
                        Useful things to have, if you know them
                    </h3>
                    <ul className="text-subtle space-y-3">
                        {[
                            'What support you would like and why',
                            'Any agency or coordinator you already speak to',
                            'Whether an assessment or application is underway',
                            'Questions about costs, conditions or a decision',
                        ].map((item) => (
                            <li key={item} className="flex gap-2">
                                <Check
                                    aria-hidden
                                    className="mt-0.5 size-4 shrink-0 text-primary"
                                />
                                {item}
                            </li>
                        ))}
                    </ul>
                    <p className="text-caption text-muted-foreground">
                        Please do not send identity documents, bank details or
                        medical reports through the first enquiry form. We will
                        discuss what is needed and how to share it.
                    </p>
                </div>
            </section>
        </>
    );
}

function Questions() {
    return (
        <section aria-labelledby="questions-heading">
            <h2 id="questions-heading" className="text-section-title">
                Questions before getting started
            </h2>
            <Accordion type="single" collapsible className="mt-4">
                <AccordionItem value="funding">
                    <AccordionTrigger>
                        Can I enquire without funding?
                    </AccordionTrigger>
                    <AccordionContent>
                        Yes. Choose “Help with funding” in the enquiry form. We
                        can explore the next steps with you; making an enquiry
                        does not guarantee funding or a place.
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="someone">
                    <AccordionTrigger>
                        Can I enquire for someone else?
                    </AccordionTrigger>
                    <AccordionContent>
                        Yes. Explain your relationship and whether you have
                        their permission. If you are unsure, you can ask about
                        the process without giving their personal details.
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="choice">
                    <AccordionTrigger>
                        Can my whānau or a supporter be involved?
                    </AccordionTrigger>
                    <AccordionContent>
                        Tell us who you would like involved, how you prefer to
                        communicate and what helps you make decisions. We will
                        discuss permission before sharing information.
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="next">
                    <AccordionTrigger>
                        What happens after I send an enquiry?
                    </AccordionTrigger>
                    <AccordionContent>
                        You will see a receipt reference once your enquiry is
                        saved. The intake team reviews it and uses your chosen
                        safe contact method to discuss the next steps. The
                        receipt is not a funding approval or a confirmed service
                        start.
                    </AccordionContent>
                </AccordionItem>
            </Accordion>
        </section>
    );
}

export default function PublicProviderPage({
    provider,
    page,
    service,
    previousFormRetired = false,
}: Props) {
    const title = page === 'service' && service ? service.label : titles[page];
    const description =
        page === 'home'
            ? 'Build everyday independence, stay connected and have a say in your support. Start with what matters to you — we can work through the next steps together.'
            : page === 'funding'
              ? 'Funding can involve several people, assessments and decisions. You can ask for help at any stage, including when you are not sure where to begin.'
              : page === 'contact'
                ? 'Choose the enquiry that fits. You can tell us how and when it is safe to contact you.'
                : page === 'services' || page === 'service'
                  ? 'Your goals, preferences and circumstances guide the conversation. We will discuss the right fit, funding and availability before agreeing support.'
                  : page === 'about'
                    ? 'Supported living begins with listening: your routines, relationships, goals and the decisions you want to make for yourself.'
                    : '';
    return (
        <MarketingLayout title={page === 'home' ? undefined : title}>
            <div className="space-y-10 sm:space-y-14">
                <section className="grid gap-8 rounded-2xl border border-border bg-card p-6 sm:p-8 lg:grid-cols-3">
                    <div
                        className={
                            page === 'home'
                                ? 'space-y-5 lg:col-span-2'
                                : 'space-y-4 lg:col-span-3'
                        }
                    >
                        {page === 'service' && (
                            <Link
                                href="/services"
                                className="frontline-tap text-subtle inline-flex items-center rounded text-primary focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                Our support
                            </Link>
                        )}
                        <p className="text-caption text-primary">
                            {provider.name} · Supported living
                        </p>
                        <h1 className="text-page-title max-w-3xl text-balance">
                            {title}
                        </h1>
                        {description && (
                            <p className="text-subtle max-w-2xl leading-relaxed">
                                {description}
                            </p>
                        )}
                        {['home', 'services', 'service', 'about'].includes(
                            page,
                        ) && (
                            <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                                <Action service={service?.id} />
                                <Action
                                    purpose="referral"
                                    service={service?.id}
                                    secondary
                                >
                                    Make a referral
                                </Action>
                            </div>
                        )}
                    </div>
                    {page === 'home' && (
                        <div className="space-y-4 rounded-xl bg-muted/60 p-5">
                            <MessageCircle
                                aria-hidden
                                className="size-5 text-primary"
                            />
                            <h2 className="text-section-title">
                                Not sure where to start?
                            </h2>
                            <p className="text-subtle">
                                You do not need to know all the answers. Tell us
                                what you need help with.
                            </p>
                            <Action purpose="funding" secondary>
                                Help with funding
                            </Action>
                            <Link
                                href="/contact"
                                className="frontline-tap text-subtle flex items-center gap-2 rounded text-primary focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                Other ways to get in touch
                                <ArrowRight aria-hidden className="size-4" />
                            </Link>
                        </div>
                    )}
                </section>

                {!provider.intake_enabled &&
                    [
                        'home',
                        'services',
                        'service',
                        'funding',
                        'contact',
                    ].includes(page) && (
                        <Alert>
                            <AlertTitle>
                                Online enquiries are currently unavailable
                            </AlertTitle>
                            <AlertDescription>
                                {provider.phone
                                    ? 'You can call us using the number on the contact page.'
                                    : 'Please check back for updated contact and enquiry options.'}
                            </AlertDescription>
                        </Alert>
                    )}

                {(page === 'home' || page === 'services') && (
                    <Services provider={provider} />
                )}
                {page === 'service' && (
                    <section className="grid gap-5 md:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <h2 className="text-section-title">
                                    A conversation about your everyday life
                                </h2>
                            </CardHeader>
                            <CardContent className="text-subtle space-y-3">
                                <p>
                                    Tell us about the things you want to do,
                                    what is working now and where some support
                                    would help. You can involve people you
                                    trust.
                                </p>
                                <p>
                                    We will review your needs and preferences,
                                    the service you are asking about and where
                                    support would take place. We confirm the
                                    agreed activities and costs before you
                                    decide.
                                </p>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <h2 className="text-section-title">
                                    Funding and availability
                                </h2>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <p className="text-subtle">
                                    An enquiry is the first step. Funding
                                    approval, service availability and a safe
                                    start each need to be confirmed. Ask for
                                    help if your funding is still being
                                    explored.
                                </p>
                                <Action
                                    purpose="funding"
                                    service={service?.id}
                                    secondary
                                >
                                    Talk about funding
                                </Action>
                            </CardContent>
                        </Card>
                    </section>
                )}
                {page === 'funding' && <Funding />}
                {page === 'about' && (
                    <section className="grid gap-4 md:grid-cols-3">
                        {[
                            {
                                Icon: HeartHandshake,
                                title: 'Your voice',
                                text: 'Tell us what matters and how you want to be involved. Support planning should be understandable and reflect your choices.',
                            },
                            {
                                Icon: Users,
                                title: 'People you trust',
                                text: 'Let us know who you want alongside you. We check permission and keep your information limited to the people who need it.',
                            },
                            {
                                Icon: Wallet,
                                title: 'Clear next steps',
                                text: 'We work through support options, costs and funding questions with you, and explain what needs to happen before a service starts.',
                            },
                        ].map(({ Icon, title: heading, text }) => (
                            <Card key={heading}>
                                <CardHeader>
                                    <Icon
                                        aria-hidden
                                        className="size-5 text-primary"
                                    />
                                    <h2 className="text-section-title">
                                        {heading}
                                    </h2>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-subtle">{text}</p>
                                </CardContent>
                            </Card>
                        ))}
                    </section>
                )}
                {page === 'contact' && (
                    <>
                        {previousFormRetired && (
                            <Alert variant="destructive">
                                <AlertTitle>
                                    Your earlier message was not sent
                                </AlertTitle>
                                <AlertDescription>
                                    The earlier contact form has been replaced.
                                    Please start a new enquiry using an option
                                    below and review the current privacy
                                    information before sending.
                                </AlertDescription>
                            </Alert>
                        )}
                        <section className="grid gap-4 md:grid-cols-2">
                            {[
                                {
                                    title: 'Explore support or make a referral',
                                    text: 'For yourself, someone you support, or a professional referral.',
                                    purpose: 'referral',
                                    action: 'Start an enquiry',
                                },
                                {
                                    title: 'Get help with funding',
                                    text: 'Explore options, prepare for an assessment or follow up on a decision.',
                                    purpose: 'funding',
                                    action: 'Ask for funding help',
                                },
                                {
                                    title: 'Ask us to contact you',
                                    text: 'Choose a safe phone or email contact and tell us your preferred time.',
                                    purpose: 'callback',
                                    action: 'Request contact',
                                },
                                {
                                    title: 'Something else',
                                    text: 'Ask a general question, raise feedback or request help with your information. Keep sensitive details for a follow-up conversation.',
                                    purpose: 'business',
                                    action: 'General enquiry',
                                },
                            ].map((item) => (
                                <Card key={item.purpose}>
                                    <CardHeader>
                                        <h2 className="text-section-title">
                                            {item.title}
                                        </h2>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        <p className="text-subtle">
                                            {item.text}
                                        </p>
                                        <Action
                                            purpose={item.purpose}
                                            secondary
                                        >
                                            {item.action}
                                        </Action>
                                    </CardContent>
                                </Card>
                            ))}
                        </section>
                        <section className="space-y-3 rounded-xl border border-border p-6">
                            <h2 className="text-section-title">
                                Contact hours
                            </h2>
                            <p className="text-subtle">
                                {provider.response_hours}
                            </p>
                            {provider.phone && (
                                <a
                                    href={phoneUrl(provider.phone)}
                                    className="frontline-tap inline-flex items-center gap-2 rounded font-medium text-primary focus-visible:ring-2 focus-visible:ring-ring"
                                >
                                    <Phone aria-hidden className="size-4" />
                                    {provider.phone}
                                </a>
                            )}
                            <p className="text-caption text-muted-foreground">
                                This enquiry service is not monitored
                                continuously. If someone is in immediate danger
                                in New Zealand, call 111.
                            </p>
                        </section>
                    </>
                )}
                {page === 'privacy' && (
                    <section className="max-w-3xl space-y-6">
                        <h2 className="text-section-title">
                            Before you send an enquiry
                        </h2>
                        <p className="text-subtle">
                            The enquiry form asks for contact details, how it is
                            safe to reach you and a short outline of the support
                            or funding help you are looking for. If you are
                            unsure about sharing someone else’s information,
                            choose that option and ask about the process first.
                        </p>
                        <Card>
                            <CardHeader>
                                <h2 className="text-section-title">
                                    Current enquiry privacy notice
                                </h2>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {provider.notice ? (
                                    <>
                                        <p className="text-subtle break-words whitespace-pre-wrap">
                                            {provider.notice}
                                        </p>
                                        <p className="text-caption text-muted-foreground">
                                            Notice version:{' '}
                                            {provider.notice_version}
                                        </p>
                                    </>
                                ) : (
                                    <p className="text-subtle">
                                        The enquiry privacy notice has not yet
                                        been published. Online intake remains
                                        unavailable until it is ready.
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                        <h2 className="text-section-title">
                            Reviewing or correcting information
                        </h2>
                        <p className="text-subtle">
                            Use a general enquiry to ask about information you
                            have shared or to request a correction. Include your
                            enquiry reference if you have it; do not include
                            identity documents or detailed health information.
                            The team will confirm identity and discuss the
                            request with you.
                        </p>
                        <Action purpose="business" secondary>
                            Ask about your information
                        </Action>
                        <h2 className="text-section-title">
                            Your device and external links
                        </h2>
                        <p className="text-subtle">
                            The website uses session and security cookies to
                            support forms and sign-in. An appearance preference
                            may be stored on your device. Links to funding
                            agencies take you to their websites, where their own
                            privacy information applies.
                        </p>
                    </section>
                )}
                {page === 'terms' && (
                    <section className="max-w-3xl space-y-6">
                        <h2 className="text-section-title">
                            Information to help you get started
                        </h2>
                        <p className="text-subtle">
                            This website explains how to enquire about support.
                            It does not decide eligibility for public funding or
                            replace advice from your funding agency.
                            Availability, prices and support arrangements need
                            to be discussed and agreed individually.
                        </p>
                        <h2 className="text-section-title">
                            An enquiry is not an agreement
                        </h2>
                        <p className="text-subtle">
                            Submitting a form records your enquiry. It does not
                            create a service agreement, guarantee a place or
                            confirm a start date. You can ask questions, correct
                            your information or tell us that you do not wish to
                            proceed.
                        </p>
                        <h2 className="text-section-title">
                            Share only what is needed
                        </h2>
                        <p className="text-subtle">
                            Provide accurate contact information and only share
                            another person’s details when you have the
                            appropriate permission. Do not upload documents or
                            payment details through the initial enquiry. We will
                            discuss any further information needed with you.
                        </p>
                        <Action purpose="business" secondary>
                            Ask a question
                        </Action>
                    </section>
                )}
                {page === 'accessibility' && (
                    <section className="max-w-3xl space-y-6">
                        <h2 className="text-section-title">
                            Choose how you communicate
                        </h2>
                        <p className="text-subtle">
                            You can tell us your preferred contact method, a
                            safe time and any communication needs in the enquiry
                            form. A trusted person can help you enquire. If
                            permission is uncertain, they can ask about the
                            process without sharing your personal details.
                        </p>
                        <h2 className="text-section-title">Using the form</h2>
                        <p className="text-subtle">
                            The form is divided into steps. You can go back to
                            review your answers before sending. Errors are shown
                            beside the relevant fields. Keep the receipt
                            reference after submission. If a response is
                            uncertain, use the retry action rather than starting
                            another enquiry.
                        </p>
                        <h2 className="text-section-title">
                            If something is difficult to use
                        </h2>
                        <p className="text-subtle">
                            Use the contact page for available contact options
                            and tell us which page or step caused a problem.
                            Please do not include sensitive personal details in
                            a problem report.
                        </p>
                        <Button
                            variant="outline"
                            asChild
                            className="frontline-tap"
                        >
                            <Link href="/contact">
                                Contact options
                                <ArrowRight aria-hidden className="size-4" />
                            </Link>
                        </Button>
                    </section>
                )}
                {['home', 'services', 'service', 'about'].includes(page) && (
                    <Journey />
                )}
                {['home', 'services', 'service', 'funding'].includes(page) && (
                    <Questions />
                )}
            </div>
        </MarketingLayout>
    );
}
