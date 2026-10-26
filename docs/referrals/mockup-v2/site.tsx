// Shared chrome for the client demo website: one header, one footer, the ink
// page band, photos, tile pickers and the Journey pathway. Every logo spot is
// the real `BrandLockup` (Settings › Branding); every colour is a token.
import {
    BrandLockup,
    JourneyWordmark,
    useCustomBrandName,
} from '@/components/brand/brand-lockup';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    ArrowRight,
    BedDouble,
    Briefcase,
    Building2,
    Check,
    Clock,
    Compass,
    HandHeart,
    HeartHandshake,
    Home,
    LogIn,
    Mail,
    Menu,
    MessageSquareText,
    Phone,
    PhoneCall,
    Receipt,
    ShieldCheck,
    Stethoscope,
    Trees,
    TriangleAlert,
    User,
    Users,
    Wallet,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { provider } from './data';

export const icons: Record<string, LucideIcon> = {
    BedDouble,
    Briefcase,
    Building2,
    Compass,
    HandHeart,
    HeartHandshake,
    Home,
    Mail,
    MessageSquareText,
    Phone,
    PhoneCall,
    Receipt,
    ShieldCheck,
    Stethoscope,
    Trees,
    User,
    Users,
    Wallet,
};

/** The site's own name, from the brand slot. Falls back to "Oblivion Care"
 *  exactly as the real app does today (a real-app change: see README). */
export function useSiteName(): string {
    return useCustomBrandName() ?? 'Oblivion Care';
}

/* ── Header ──────────────────────────────────────────────────────────── */

export type NavKey =
    | 'home'
    | 'support'
    | 'funding'
    | 'about'
    | 'careers'
    | 'contact'
    | 'referral'
    | 'sign-in'
    | 'legal'
    | 'upload';

const nav: { key: NavKey; label: string; href: string }[] = [
    { key: 'support', label: 'Our support', href: '#/support' },
    { key: 'funding', label: 'Funding help', href: '#/funding' },
    { key: 'about', label: 'About us', href: '#/about' },
    { key: 'careers', label: 'Careers', href: '#/careers' },
    { key: 'contact', label: 'Contact us', href: '#/contact' },
];

/** The brand slot as the home link — never a hard-coded logo. */
export function Lockup({ surface = 'dark' }: { surface?: 'dark' | 'light' }) {
    const name = useSiteName();
    return (
        <a
            href="#/home"
            className="flex min-h-[44px] items-center rounded-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            aria-label={`${name} home`}
        >
            <BrandLockup surface={surface} />
        </a>
    );
}

function SiteHeader({ current }: { current: NavKey }) {
    const [open, setOpen] = useState(false);
    const close = () => setOpen(false);
    return (
        <header className="journey-site-container flex min-h-[76px] items-center justify-between gap-4 py-3">
            <Lockup />
            <nav
                aria-label="Main"
                className="hidden items-center gap-0.5 xl:flex"
            >
                {nav.map((item) => (
                    <a
                        key={item.key}
                        href={item.href}
                        className="journey-site-navlink"
                        aria-current={current === item.key ? 'page' : undefined}
                    >
                        {item.label}
                    </a>
                ))}
            </nav>
            <div className="hidden items-center gap-2 xl:flex">
                <a
                    href="#/sign-in"
                    className="journey-site-navlink gap-2"
                    aria-current={current === 'sign-in' ? 'page' : undefined}
                >
                    <LogIn className="size-4" aria-hidden />
                    Sign in
                </a>
                <Button asChild>
                    <a
                        href="#/referral"
                        aria-current={
                            current === 'referral' ? 'page' : undefined
                        }
                    >
                        Make a referral
                        <ArrowRight className="size-4" aria-hidden />
                    </a>
                </Button>
            </div>
            <Button
                variant="outline"
                size="icon"
                className="size-[44px] xl:hidden"
                aria-expanded={open}
                aria-controls="site-menu"
                aria-label={open ? 'Close menu' : 'Open menu'}
                onClick={() => setOpen((value) => !value)}
            >
                {open ? <X className="size-5" /> : <Menu className="size-5" />}
            </Button>
            {open && (
                <div
                    id="site-menu"
                    className="journey-site-horizon absolute inset-x-0 top-[76px] z-50 bg-journey-ink px-5 pt-1 pb-6 shadow-lg xl:hidden"
                >
                    <nav aria-label="Main" className="flex flex-col py-2">
                        {nav.map((item) => (
                            <a
                                key={item.key}
                                href={item.href}
                                className="journey-site-navlink"
                                aria-current={
                                    current === item.key ? 'page' : undefined
                                }
                                onClick={close}
                            >
                                {item.label}
                            </a>
                        ))}
                        <a
                            href="#/sign-in"
                            className="journey-site-navlink gap-2"
                            onClick={close}
                        >
                            <LogIn className="size-4" aria-hidden />
                            Sign in
                        </a>
                    </nav>
                    <Button asChild className="w-full">
                        <a href="#/referral" onClick={close}>
                            Make a referral
                            <ArrowRight className="size-4" aria-hidden />
                        </a>
                    </Button>
                </div>
            )}
        </header>
    );
}

/* ── Footer ──────────────────────────────────────────────────────────── */

const footerColumns: { title: string; links: [string, string][] }[] = [
    {
        title: 'Our support',
        links: [
            ['Support at home', '#/support/home'],
            ['In the community', '#/support/community'],
            ['Short breaks (respite)', '#/support/respite'],
            ['Funding help', '#/funding'],
        ],
    },
    {
        title: 'Get in touch',
        links: [
            ['Make a referral', '#/referral'],
            ['Request a callback', '#/referral/callback'],
            ['Contact us', '#/contact'],
        ],
    },
    {
        title: 'About',
        links: [
            ['About us', '#/about'],
            ['Careers', '#/careers'],
            ['Staff sign in', '#/sign-in'],
            ['Family portal', '#/sign-in/family'],
        ],
    },
];

function SiteFooter() {
    const customName = useCustomBrandName();
    const name = useSiteName();
    return (
        <footer className="journey-site-footer">
            <div className="journey-site-container grid gap-10 py-12 md:grid-cols-2 lg:grid-cols-[1.4fr_repeat(3,1fr)]">
                <div className="space-y-4">
                    <span className="block text-journey-paper">
                        <Lockup />
                    </span>
                    <p className="max-w-xs text-sm">
                        Supported living across{' '}
                        {provider.regions.slice(0, -1).join(', ')} and{' '}
                        {provider.regions.at(-1)}.
                    </p>
                    <p className="text-sm">
                        <a href={provider.phoneHref} className="font-semibold">
                            {provider.phone}
                        </a>
                        <br />
                        {provider.hours}
                    </p>
                </div>
                {footerColumns.map((column) => (
                    <div key={column.title} className="space-y-3 text-sm">
                        <p className="font-semibold text-journey-paper">
                            {column.title}
                        </p>
                        <ul className="space-y-1">
                            {column.links.map(([label, href]) => (
                                <li key={label}>
                                    <a
                                        href={href}
                                        className="inline-flex min-h-8 items-center"
                                    >
                                        {label}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
            <div className="journey-site-container flex flex-wrap items-center justify-between gap-3 border-t border-journey-paper/10 py-5 text-sm">
                <span className="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <span>© 2026 {name}</span>
                    {customName && (
                        <span className="flex items-center gap-1.5">
                            Powered by
                            <JourneyWordmark className="text-[13px] text-current" />
                            <span className="sr-only">Oblivion Care</span>
                        </span>
                    )}
                </span>
                <span className="flex flex-wrap gap-5">
                    <a href="#/privacy">Privacy</a>
                    <a href="#/terms">Terms</a>
                    <a href="#/accessibility">Accessibility</a>
                </span>
            </div>
        </footer>
    );
}

/* ── Page shell ──────────────────────────────────────────────────────── */

/** Every page: skip link, the ink band (header + page intro), main, footer. */
export function SitePage({
    current,
    intro,
    children,
}: {
    current: NavKey;
    intro: ReactNode;
    children: ReactNode;
}) {
    const main = useRef<HTMLElement>(null);
    return (
        <>
            <button
                type="button"
                className="journey-site-skip"
                onClick={() => main.current?.focus()}
            >
                Skip to main content
            </button>
            <div className="journey-site-ink relative">
                <SiteHeader current={current} />
                {intro}
            </div>
            <main
                ref={main}
                id="main-content"
                tabIndex={-1}
                className="outline-none"
            >
                {children}
            </main>
            <SiteFooter />
        </>
    );
}

/** The page intro inside the ink band, with an optional photo beside it. */
export function PageIntro({
    eyebrow,
    title,
    lede,
    children,
    media,
    back,
}: {
    eyebrow: string;
    title: ReactNode;
    lede?: ReactNode;
    children?: ReactNode;
    media?: ReactNode;
    back?: { href: string; label: string };
}) {
    return (
        <section
            className={cn(
                'journey-site-container pt-6 pb-14',
                media &&
                    'grid items-center gap-10 lg:grid-cols-[1.15fr_1fr] lg:pb-16',
            )}
        >
            <div className="flex flex-col items-start gap-4">
                {back && (
                    <a
                        href={back.href}
                        className="journey-site-navlink -ml-3 gap-1.5 text-sm"
                    >
                        <ArrowRight className="size-4 rotate-180" aria-hidden />
                        {back.label}
                    </a>
                )}
                <p className="journey-site-eyebrow">{eyebrow}</p>
                <h1 className="journey-site-display journey-site-display--page">
                    {title}
                </h1>
                {lede && <p className="journey-lede">{lede}</p>}
                {children}
            </div>
            {media}
        </section>
    );
}

export function SectionHeading({
    id,
    kicker,
    title,
    children,
    className,
}: {
    id: string;
    kicker: string;
    title: ReactNode;
    children?: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('max-w-2xl space-y-3', className)}>
            <p className="journey-site-kicker">{kicker}</p>
            <h2 id={id} className="journey-site-section-title">
                {title}
            </h2>
            {children && (
                <div className="text-muted-foreground">{children}</div>
            )}
        </div>
    );
}

/* ── Photos ──────────────────────────────────────────────────────────── */

/** A photo in a fixed aspect-ratio box with a muted ground, so the layout
 *  holds while a file is missing (the alt text then shows in the box). */
export function Photo({
    photo,
    className,
    eager = false,
}: {
    photo: { src: string; alt: string };
    /** Include an aspect-ratio class (aspect-[4/3], aspect-[4/5], …). */
    className: string;
    eager?: boolean;
}) {
    const [missing, setMissing] = useState(false);
    return (
        <div
            className={cn(
                'relative overflow-hidden rounded-3xl bg-muted',
                className,
            )}
        >
            <img
                src={photo.src}
                alt={photo.alt}
                loading={eager ? 'eager' : 'lazy'}
                decoding="async"
                onError={() => setMissing(true)}
                className={cn('size-full object-cover', missing && 'opacity-0')}
            />
            {missing && (
                <span
                    aria-hidden
                    className="absolute inset-0 grid place-items-center p-6 text-center text-sm text-muted-foreground"
                >
                    {photo.alt}
                </span>
            )}
        </div>
    );
}

/* ── Lens, tiles, notes ──────────────────────────────────────────────── */

/** Signature lens: an icon in a ring with a little brand light. */
export function Lens({
    icon: Icon,
    className,
}: {
    icon: LucideIcon;
    className?: string;
}) {
    return (
        <span className={cn('journey-site-tile-icon shrink-0', className)}>
            <Icon className="size-5" aria-hidden />
        </span>
    );
}

export type TileOption<T extends string> = {
    value: T;
    title: string;
    body?: string;
    icon: string;
};

/** Tile picker for short choices (radio semantics, arrow keys move). */
export function RadioTiles<T extends string>({
    label,
    value,
    onChange,
    options,
    columns = 'sm:grid-cols-2',
    error,
    hint,
}: {
    label: string;
    value: T | null;
    onChange: (value: T) => void;
    options: readonly TileOption<T>[];
    columns?: string;
    error?: string;
    hint?: string;
}) {
    const refs = useRef<(HTMLButtonElement | null)[]>([]);
    const selected = options.findIndex((option) => option.value === value);
    const onKeyDown = (event: KeyboardEvent, index: number) => {
        const step =
            event.key === 'ArrowDown' || event.key === 'ArrowRight'
                ? 1
                : event.key === 'ArrowUp' || event.key === 'ArrowLeft'
                  ? -1
                  : 0;
        if (!step) return;
        event.preventDefault();
        const next = (index + step + options.length) % options.length;
        onChange(options[next].value);
        refs.current[next]?.focus();
    };
    return (
        <fieldset className="space-y-3">
            <legend className="text-section-title">{label}</legend>
            {hint && <p className="text-sm text-muted-foreground">{hint}</p>}
            <div
                role="radiogroup"
                aria-label={label}
                aria-invalid={error ? true : undefined}
                className={cn('grid gap-3', columns)}
            >
                {options.map((option, index) => {
                    const Icon = icons[option.icon];
                    const checked = value === option.value;
                    return (
                        <button
                            key={option.value}
                            ref={(node) => {
                                refs.current[index] = node;
                            }}
                            type="button"
                            role="radio"
                            aria-checked={checked}
                            tabIndex={
                                checked || (selected < 0 && index === 0)
                                    ? 0
                                    : -1
                            }
                            onClick={() => onChange(option.value)}
                            onKeyDown={(event) => onKeyDown(event, index)}
                            className="journey-site-tile journey-site-tile--choice"
                        >
                            <Lens icon={Icon} />
                            <span className="min-w-0 flex-1 space-y-1">
                                <span className="block font-semibold">
                                    {option.title}
                                </span>
                                {option.body && (
                                    <span className="block text-sm text-muted-foreground">
                                        {option.body}
                                    </span>
                                )}
                            </span>
                            <span
                                className="journey-site-tile-check"
                                aria-hidden
                            >
                                {checked && <Check className="size-3.5" />}
                            </span>
                        </button>
                    );
                })}
            </div>
            {error && (
                <p className="text-sm text-status-critical" role="alert">
                    {error}
                </p>
            )}
        </fieldset>
    );
}

/** Not monitored for emergencies — a fixed status pair, never the brand. */
export function EmergencyNote({ className }: { className?: string }) {
    return (
        <Alert variant="warning" className={className}>
            <TriangleAlert aria-hidden />
            <AlertDescription>
                This website isn’t monitored for emergencies. If someone is in
                danger, call 111.
            </AlertDescription>
        </Alert>
    );
}

/* ── The Journey pathway ─────────────────────────────────────────────── */

/** Steps drawn as the Journey pathway: a solid line, filled ends and rings
 *  between (no dashes — those mean a target). */
export function Pathway({
    steps,
}: {
    steps: readonly { title: string; body: string }[];
}) {
    return (
        <ol className="journey-site-steps">
            {steps.map((step, index) => (
                <li key={step.title} className="journey-site-step">
                    <span className="journey-site-step-dot">{index + 1}</span>
                    <span className="space-y-2">
                        <h3 className="text-lg font-semibold tracking-tight">
                            {step.title}
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            {step.body}
                        </p>
                    </span>
                </li>
            ))}
        </ol>
    );
}

/** Progress on the pathway: done = filled, now = the ring, next = hollow. */
export function ProgressPathway({
    steps,
    current,
}: {
    steps: readonly { title: string; body: string }[];
    current: number;
}) {
    return (
        <ol className="journey-site-progress">
            {steps.map((step, index) => {
                const state =
                    index < current
                        ? 'done'
                        : index === current
                          ? 'now'
                          : 'next';
                return (
                    <li
                        key={step.title}
                        data-state={state}
                        aria-current={state === 'now' ? 'step' : undefined}
                    >
                        <span className="journey-site-progress-dot" aria-hidden>
                            {state === 'done' && <Check className="size-3.5" />}
                        </span>
                        <span>
                            <span className="block font-semibold">
                                {step.title}
                                <span className="sr-only">
                                    {state === 'done'
                                        ? ' (done)'
                                        : state === 'now'
                                          ? ' (where it is now)'
                                          : ' (still to come)'}
                                </span>
                            </span>
                            <span className="block text-sm text-muted-foreground">
                                {step.body}
                            </span>
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}

/* ── Talk to a person ────────────────────────────────────────────────── */

export function SafeContactBand() {
    return (
        <section
            aria-labelledby="contact-band-heading"
            className="journey-site-container py-14"
        >
            <div className="grid gap-6 rounded-3xl border border-border bg-card p-6 md:grid-cols-[1.3fr_1fr] md:p-10">
                <div className="space-y-3">
                    <p className="journey-site-kicker">Talk to a person</p>
                    <h2
                        id="contact-band-heading"
                        className="journey-site-section-title"
                    >
                        Prefer to talk it through?
                    </h2>
                    <p className="max-w-xl text-muted-foreground">
                        Call our intake team, or ask for a callback at a time
                        that’s safe for you. We can help you make a referral
                        over the phone.
                    </p>
                    <EmergencyNote className="max-w-xl" />
                </div>
                <div className="flex flex-col justify-center gap-3">
                    <Button asChild size="lg" className="justify-start">
                        <a href={provider.phoneHref}>
                            <Phone className="size-4" aria-hidden />
                            {provider.phone}
                        </a>
                    </Button>
                    <Button
                        asChild
                        size="lg"
                        variant="outline"
                        className="justify-start"
                    >
                        <a href="#/referral/callback">
                            <PhoneCall className="size-4" aria-hidden />
                            Request a callback
                        </a>
                    </Button>
                    <Button
                        asChild
                        size="lg"
                        variant="outline"
                        className="justify-start"
                    >
                        <a href="#/contact">
                            <Mail className="size-4" aria-hidden />
                            Send us a message
                        </a>
                    </Button>
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Clock className="size-4 shrink-0" aria-hidden />
                        {provider.hours}
                    </p>
                </div>
            </div>
        </section>
    );
}

/** A compact "lens + text" line used in asides. */
export function NoteLine({
    icon: Icon,
    children,
}: {
    icon: LucideIcon;
    children: ReactNode;
}) {
    return (
        <p className="flex items-start gap-2.5 text-sm">
            <Icon className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden />
            <span>{children}</span>
        </p>
    );
}
