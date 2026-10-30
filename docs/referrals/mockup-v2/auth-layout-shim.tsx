// Mockup-only copy of `layouts/auth/auth-journey-layout.tsx` (Stephan,
// 21 Oct). vite.config.mjs aliases the real layout to this file, so the REAL
// `pages/auth/login.tsx` and `pages/portal/login.tsx` render their real forms
// inside it. Two changes from the approved layout:
// - with client branding, the brand half shows the client's own hero photo
//   (Settings › Branding) under a scrim, with their logo and the welcome
//   text; the NZ islands art stays only as Oblivion's own default;
// - a "Back to website" link at the top of both sign-in pages.
// Everything else is the approved layout, unchanged.
import { LocaleSwitch } from '@/components/auth/locale-switch';
import {
    BrandLockup,
    JourneyWordmark,
    useCustomBrandName,
} from '@/components/brand/brand-lockup';
import { JourneyMark } from '@/components/brand/journey-mark';
import { cn } from '@/lib/utils';
import { privacy, terms } from '@/routes';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { type PropsWithChildren, type ReactNode } from 'react';

export type AuthAudience = 'staff' | 'family';

/** The client's hero photo from the brand slot (mockup prop `heroUrl`). */
function useHeroPhoto(): string | null {
    const { branding } = usePage().props as {
        branding?: { heroUrl?: string | null };
    };
    return branding?.heroUrl ?? null;
}

function BackToWebsite({ className }: { className?: string }) {
    return (
        <a
            href="#/home"
            className={cn(
                'inline-flex min-h-[44px] items-center gap-2 rounded-lg px-2 text-[14px] font-semibold hover:underline focus-visible:ring-2 focus-visible:ring-current focus-visible:outline-none',
                className,
            )}
        >
            <ArrowLeft aria-hidden="true" className="size-4" />
            Back to website
        </a>
    );
}

/** Privacy / Terms / "Powered by" at the foot of the brand half. */
function BrandLinks({
    family,
    customName,
}: {
    family: boolean;
    customName: string | null;
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
            {customName && (
                <span className="flex items-center gap-1.5">
                    Powered by
                    <JourneyWordmark className="text-[13px] text-current" />
                    <span className="sr-only">Oblivion Care</span>
                </span>
            )}
        </div>
    );
}

export default function AuthJourneyLayout({
    audience = 'staff',
    title,
    headline,
    lede,
    brandFooter,
    signing = false,
    footer,
    children,
}: PropsWithChildren<{
    audience?: AuthAudience;
    title: string;
    headline?: [ReactNode, ReactNode];
    lede?: ReactNode;
    brandFooter?: ReactNode;
    signing?: boolean;
    footer?: ReactNode;
}>) {
    const family = audience === 'family';
    const customName = useCustomBrandName();
    const hero = useHeroPhoto();
    // The islands line belongs to the islands art; over a client's photo the
    // family half says hello instead.
    const [first, second] =
        headline ??
        (family
            ? hero
                ? ['Kia ora, whānau.', 'Good to see you.']
                : ['Separate islands.', 'One shared journey.']
            : ["Today's journey", 'starts here.']);

    return (
        <div
            className={cn('journey-auth', signing && 'is-signing')}
            data-audience={audience}
        >
            <Head title={title} />

            <section
                aria-label={
                    family
                        ? 'About the family portal'
                        : (customName ?? 'Oblivion Care')
                }
                className={cn(
                    'journey-brand',
                    hero
                        ? 'journey-brand--photo'
                        : family
                          ? 'journey-brand--family'
                          : 'journey-brand--staff',
                )}
            >
                {hero && (
                    <>
                        {/* Decorative: the page's purpose is signing in. */}
                        <img
                            src={hero}
                            alt=""
                            className="journey-brand-photo"
                        />
                        <span
                            className="journey-brand-scrim"
                            aria-hidden="true"
                        />
                    </>
                )}

                <div className="relative -ml-2">
                    <BackToWebsite />
                </div>

                <div className="relative mt-5 flex items-center gap-3.5">
                    {family && !hero ? (
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

                {hero ? (
                    // Over a photo, the welcome text and links sit low in one
                    // block that carries its own scrim (AA on any photo).
                    <div className="journey-brand-welcome mt-auto flex flex-col items-start gap-6 pt-10">
                        <div className="flex max-w-[520px] flex-col gap-4">
                            <p className="journey-display">
                                {first}
                                <br />
                                <span className="journey-display__accent">
                                    {second}
                                </span>
                            </p>
                            {lede && <p className="journey-lede">{lede}</p>}
                        </div>
                        {brandFooter}
                        <BrandLinks family={family} customName={customName} />
                    </div>
                ) : (
                    <>
                        <div className="relative mt-[52px] flex max-w-[520px] flex-col gap-4">
                            <p className="journey-display">
                                {first}
                                <br />
                                <span className="journey-display__accent">
                                    {second}
                                </span>
                            </p>
                            {lede && <p className="journey-lede">{lede}</p>}
                        </div>
                        <JourneyMark
                            tone={family ? 'primary' : 'lavender'}
                            traveller
                            className="journey-hero-mark"
                        />
                        <div className="relative mt-auto flex flex-col items-start gap-4">
                            {brandFooter}
                            <BrandLinks
                                family={family}
                                customName={customName}
                            />
                        </div>
                    </>
                )}
            </section>

            <main className="journey-form-half">
                {/* Phones hide the brand half, so the link moves here. */}
                <BackToWebsite className="-ml-2 self-start text-foreground min-[961px]:hidden" />
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
