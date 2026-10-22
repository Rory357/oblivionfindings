import { LocaleSwitch } from '@/components/auth/locale-switch';
import {
    BrandLockup,
    JourneyWordmark,
    useCustomBrandName,
} from '@/components/brand/brand-lockup';
import { JourneyMark } from '@/components/brand/journey-mark';
import { cn } from '@/lib/utils';
import { home, privacy, terms } from '@/routes';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { type PropsWithChildren, type ReactNode } from 'react';

export type AuthAudience = 'staff' | 'family';

type BrandingProps = {
    branding?: {
        logoUrl?: string | null;
        logoDarkUrl?: string | null;
        heroUrl?: string | null;
    };
};

/**
 * What the brand half shows (Stephan, 21 Oct 2026):
 * - `photo`: the client's website and sign-in photo (Settings › Branding);
 * - `panel`: client branding without a photo — a plain brand-colour panel;
 * - `islands`: no client branding at all — Oblivion's own islands default.
 */
export function useBrandHalf(): {
    kind: 'photo' | 'panel' | 'islands';
    heroUrl: string | null;
} {
    const { branding } = usePage<BrandingProps>().props;
    const customName = useCustomBrandName();
    const heroUrl = branding?.heroUrl ?? null;
    if (heroUrl) return { kind: 'photo', heroUrl };
    if (customName || branding?.logoUrl || branding?.logoDarkUrl)
        return { kind: 'panel', heroUrl: null };
    return { kind: 'islands', heroUrl: null };
}

/** Back to the provider's public website (its home page). */
function BackToWebsite({ className }: { className?: string }) {
    return (
        <Link
            href={home()}
            className={cn(
                'inline-flex min-h-[44px] items-center gap-2 rounded-lg px-2 text-[14px] font-semibold hover:underline focus-visible:ring-2 focus-visible:ring-current focus-visible:outline-none',
                className,
            )}
        >
            <ArrowLeft aria-hidden="true" className="size-4" />
            Back to website
        </Link>
    );
}

/** Privacy / Terms / "Powered by" at the foot of the brand half. */
function BrandLinks({
    family,
    poweredBy,
}: {
    family: boolean;
    poweredBy: boolean;
}) {
    return (
        <div className="journey-brand-links flex items-center gap-5 text-[13px]">
            <Link href={privacy()} className="font-medium hover:underline">
                {family ? 'How we protect your information' : 'Privacy'}
            </Link>
            {!family && (
                <Link href={terms()} className="font-medium hover:underline">
                    Terms
                </Link>
            )}
            {poweredBy && (
                <span className="flex items-center gap-1.5">
                    Powered by
                    <JourneyWordmark className="text-[13px] text-current" />
                    <span className="sr-only">Oblivion Care</span>
                </span>
            )}
        </div>
    );
}

/**
 * The approved half-and-half sign-in layout (Journey brand, 13 Oct 2026):
 * a brand half and a plain form half. Every sign-in, password and
 * invitation screen uses it. With client branding (21 Oct 2026) the brand
 * half shows the client's photo under two scrims (AA on any photo), or a
 * plain brand-colour panel; the NZ islands art and its journey animation
 * are only Oblivion's own default. `signing` plays that animation once.
 */
export default function AuthJourneyLayout({
    audience = 'staff',
    title,
    headline,
    lede,
    brandFooter,
    signing = false,
    footer,
    backToWebsite = false,
    children,
}: PropsWithChildren<{
    audience?: AuthAudience;
    /** Browser tab title. */
    title: string;
    /** Two-line brand headline; defaults per audience. */
    headline?: [ReactNode, ReactNode];
    lede?: ReactNode;
    /** Family: the portal chips. Rendered at the bottom of the brand half. */
    brandFooter?: ReactNode;
    signing?: boolean;
    /** Links under the form half (e.g. "Family or whānau? …"). */
    footer?: ReactNode;
    /** The two sign-in pages link back to the public website, at the top of
     *  the brand half (at the top of the form on phones). */
    backToWebsite?: boolean;
}>) {
    const family = audience === 'family';
    const customName = useCustomBrandName();
    const { kind, heroUrl } = useBrandHalf();
    const islands = kind === 'islands';
    // "Separate islands" belongs to the islands art; over a client's photo or
    // brand colour the family half says hello instead.
    const [first, second] =
        headline ??
        (family
            ? islands
                ? ['Separate islands.', 'One shared journey.']
                : ['Kia ora, whānau.', 'Good to see you.']
            : ["Today's journey", 'starts here.']);
    const welcome = (
        <div className="flex max-w-[520px] flex-col gap-4">
            {/* Brand statement, not a heading: the form's <h1> is the page heading. */}
            <p className="journey-display">
                {first}
                <br />
                <span className="journey-display__accent">{second}</span>
            </p>
            {lede && <p className="journey-lede">{lede}</p>}
        </div>
    );
    const links = <BrandLinks family={family} poweredBy={!islands} />;

    return (
        <div
            className={cn('journey-auth', signing && islands && 'is-signing')}
            data-audience={audience}
        >
            <Head title={title} />

            <section
                aria-label={
                    family
                        ? 'About the family portal'
                        : (customName ?? 'About staff sign in')
                }
                data-brand-half={kind}
                className={cn(
                    'journey-brand',
                    kind === 'photo'
                        ? 'journey-brand--photo'
                        : kind === 'panel'
                          ? 'journey-brand--panel'
                          : family
                            ? 'journey-brand--family'
                            : 'journey-brand--staff',
                )}
            >
                {heroUrl && (
                    <>
                        {/* Decorative: the page's purpose is signing in. */}
                        <img
                            src={heroUrl}
                            alt=""
                            className="journey-brand-photo"
                        />
                        <span
                            className="journey-brand-scrim"
                            aria-hidden="true"
                        />
                    </>
                )}

                {backToWebsite && (
                    <div className="relative mb-5 -ml-2">
                        <BackToWebsite />
                    </div>
                )}

                <div className="relative flex items-center gap-3.5">
                    {family && islands ? (
                        <>
                            <BrandLockup
                                surface="light"
                                className="dark:hidden"
                            />
                            <BrandLockup
                                surface="dark"
                                className="hidden dark:flex"
                            />
                        </>
                    ) : (
                        <BrandLockup surface="dark" />
                    )}
                    {family && (
                        <span className="inline-flex h-[30px] items-center rounded-full border border-journey-lavender bg-journey-surface px-3 text-sm font-semibold text-primary">
                            Family portal
                        </span>
                    )}
                </div>

                {islands ? (
                    <>
                        <div className="relative mt-[52px]">{welcome}</div>
                        <JourneyMark
                            tone={family ? 'primary' : 'lavender'}
                            traveller
                            className="journey-hero-mark"
                        />
                        <div className="relative mt-auto flex flex-col items-start gap-4">
                            {brandFooter}
                            {links}
                        </div>
                    </>
                ) : (
                    // Over a photo, the welcome text and links sit low in one
                    // block that carries its own scrim (AA on any photo).
                    <div className="journey-brand-welcome mt-auto flex flex-col items-start gap-6 pt-10">
                        {welcome}
                        {brandFooter}
                        {links}
                    </div>
                )}
            </section>

            <main className="journey-form-half">
                {backToWebsite && (
                    // Phones hide the brand half, so the link moves here.
                    <BackToWebsite className="-ml-2 self-start text-foreground min-[961px]:hidden" />
                )}
                <div className="flex items-center justify-between gap-4">
                    <span className="min-[961px]:hidden">
                        <BrandLockup surface="light" />
                    </span>
                    <LocaleSwitch className="ml-auto" />
                </div>

                <div className="flex flex-1 items-center justify-center py-5">
                    <div className="journey-form">{children}</div>
                </div>

                {footer && (
                    <div className="flex flex-col items-center gap-2 text-center text-[13.5px] text-muted-foreground">
                        {footer}
                    </div>
                )}
            </main>
        </div>
    );
}
