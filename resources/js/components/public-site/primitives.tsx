// Building blocks of the public website (mockup v2, site.tsx): the page intro
// in the ink band, photos, the ring lens, tile pickers, the Journey pathway
// and the "talk to a person" band. Every colour is a token; tap targets on
// the public site are 44 px (.frontline-tap).
import { enquiryUrl, phoneUrl } from '@/components/public-provider';
import { usePublicSite } from '@/components/public-site/site';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
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
    Mail,
    MessageSquareText,
    Phone,
    PhoneCall,
    Receipt,
    ShieldCheck,
    Stethoscope,
    Trees,
    TriangleAlert,
    User,
    UserRound,
    Users,
    Wallet,
    type LucideIcon,
} from 'lucide-react';
import { useRef, useState, type KeyboardEvent, type ReactNode } from 'react';

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
    UserRound,
    Users,
    Wallet,
};

/* ── Page intro and headings ─────────────────────────────────────────── */

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
                    <Link
                        href={back.href}
                        className="journey-site-navlink -ml-3 gap-1.5 text-sm"
                    >
                        <ArrowRight className="size-4 rotate-180" aria-hidden />
                        {back.label}
                    </Link>
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

export type SitePhotoSource = { src: string; alt: string };

/**
 * A photo in a fixed aspect-ratio box. With no photo (a client site without
 * its own, or a file that fails to load) the box keeps its shape as a plain
 * brand-colour panel: never our demo photos, never the islands art.
 */
export function SitePhoto({
    photo,
    className,
    eager = false,
    icon: Icon,
}: {
    photo: SitePhotoSource | null;
    /** Include an aspect-ratio class (aspect-[4/3], aspect-[4/5], …). */
    className: string;
    eager?: boolean;
    /** Drawn in a lens on the plain panel, so a set of panels can be told apart. */
    icon?: LucideIcon;
}) {
    const [missing, setMissing] = useState(false);
    if (!photo || missing) {
        return (
            <div
                data-testid="site-photo-panel"
                className={cn(
                    'journey-site-panel relative grid place-items-center overflow-hidden rounded-3xl',
                    className,
                )}
            >
                {Icon && (
                    <span
                        aria-hidden
                        className="grid size-16 place-items-center rounded-full bg-journey-paper/10 text-journey-paper ring-1 ring-journey-paper/30"
                    >
                        <Icon className="size-7" />
                    </span>
                )}
            </div>
        );
    }

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
                className="size-full object-cover"
            />
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
    id,
    label,
    value,
    onChange,
    options,
    columns = 'sm:grid-cols-2',
    error,
    hint,
    disabled = false,
}: {
    /** Prefix for the error id the group points at. */
    id: string;
    label: string;
    value: T | null;
    onChange: (value: T) => void;
    options: readonly TileOption<T>[];
    columns?: string;
    error?: string;
    hint?: string;
    disabled?: boolean;
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
        if (!step || disabled) return;
        event.preventDefault();
        const next = (index + step + options.length) % options.length;
        onChange(options[next].value);
        refs.current[next]?.focus();
    };
    const describedBy =
        [hint && `${id}-hint`, error && `${id}-error`]
            .filter(Boolean)
            .join(' ') || undefined;

    return (
        <fieldset className="space-y-3" disabled={disabled}>
            <legend className="text-section-title">{label}</legend>
            {hint && (
                <p id={`${id}-hint`} className="text-sm text-muted-foreground">
                    {hint}
                </p>
            )}
            <div
                role="radiogroup"
                aria-label={label}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy}
                className={cn('grid gap-3', columns)}
            >
                {options.map((option, index) => {
                    const Icon = icons[option.icon] ?? HeartHandshake;
                    const checked = value === option.value;
                    return (
                        // eslint-disable-next-line no-restricted-syntax -- a tile picker (radio card), not a command button
                        <button
                            key={option.value}
                            id={index === 0 ? id : undefined}
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
                            className="journey-site-tile journey-site-tile--choice disabled:cursor-not-allowed disabled:opacity-60"
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
                <p
                    id={`${id}-error`}
                    className="text-sm text-status-critical"
                    role="alert"
                >
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

/** A compact "icon + text" line used in asides. */
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

export function TickList({ items }: { items: readonly string[] }) {
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

/** A plan on the pathway ("what happens next"): filled ends, rings between. */
export function PlanPathway({
    steps,
}: {
    steps: readonly { title: string; body: string }[];
}) {
    return (
        <ol className="journey-site-progress journey-site-progress--plan mt-4">
            {steps.map((step) => (
                <li key={step.title}>
                    <span className="journey-site-progress-dot" aria-hidden />
                    <span>
                        <span className="block font-semibold">
                            {step.title}
                        </span>
                        <span className="block text-sm text-muted-foreground">
                            {step.body}
                        </span>
                    </span>
                </li>
            ))}
        </ol>
    );
}

/* ── Talk to a person ────────────────────────────────────────────────── */

export function SafeContactBand() {
    const { provider } = usePublicSite();
    const phone = provider.phone?.trim();
    const hours = provider.response_hours?.trim();

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
                        {phone ? 'Call our intake team, or ask' : 'Ask'} for a
                        callback at a time that’s safe for you. We can help you
                        make a referral over the phone.
                    </p>
                    <EmergencyNote className="max-w-xl" />
                </div>
                <div className="flex flex-col justify-center gap-3">
                    {phone && (
                        <Button
                            asChild
                            size="lg"
                            className="frontline-tap justify-start"
                        >
                            <a href={phoneUrl(phone)}>
                                <Phone className="size-4" aria-hidden />
                                {phone}
                            </a>
                        </Button>
                    )}
                    <Button
                        asChild
                        size="lg"
                        variant={phone ? 'outline' : 'default'}
                        className="frontline-tap justify-start"
                    >
                        <Link href={enquiryUrl('callback')}>
                            <PhoneCall className="size-4" aria-hidden />
                            Request a callback
                        </Link>
                    </Button>
                    <Button
                        asChild
                        size="lg"
                        variant="outline"
                        className="frontline-tap justify-start"
                    >
                        <Link href="/contact">
                            <Mail className="size-4" aria-hidden />
                            Send us a message
                        </Link>
                    </Button>
                    {hours && (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Clock className="size-4 shrink-0" aria-hidden />
                            {hours}
                        </p>
                    )}
                </div>
            </div>
        </section>
    );
}

/* ── Unavailable intake ──────────────────────────────────────────────── */

/** Online enquiries are off in Settings › Referrals: say so, near the start. */
export function IntakeClosedNotice({ className }: { className?: string }) {
    const { provider } = usePublicSite();
    return (
        <Alert variant="info" className={className}>
            <MessageSquareText aria-hidden />
            <AlertDescription>
                <strong className="font-semibold">
                    Online enquiries are currently unavailable.
                </strong>{' '}
                {provider.phone
                    ? `Call us on ${provider.phone} to talk about support or make a referral by phone.`
                    : 'Please check back soon for ways to get in touch.'}
            </AlertDescription>
        </Alert>
    );
}
