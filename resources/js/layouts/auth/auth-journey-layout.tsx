import { LocaleSwitch } from '@/components/auth/locale-switch';
import {
    BrandLockup,
    JourneyWordmark,
    useCustomBrandName,
} from '@/components/brand/brand-lockup';
import { JourneyMark } from '@/components/brand/journey-mark';
import { cn } from '@/lib/utils';
import { privacy, terms } from '@/routes';
import { Head, Link } from '@inertiajs/react';
import { type PropsWithChildren, type ReactNode } from 'react';

export type AuthAudience = 'staff' | 'family';

/**
 * The approved half-and-half sign-in layout (Journey brand, 13 Oct 2026):
 * a brand half (staff: ink; family: lilac, ink in dark mode) with the NZ
 * islands mark and headline, and a plain form half. Every sign-in, password
 * and invitation screen uses it. `signing` plays the journey animation once.
 */
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
}>) {
    const family = audience === 'family';
    const customName = useCustomBrandName();
    const [first, second] =
        headline ??
        (family
            ? ['Separate islands.', 'One shared journey.']
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
                    family ? 'journey-brand--family' : 'journey-brand--staff',
                )}
            >
                <div className="relative flex items-center gap-3.5">
                    {family ? (
                        <>
                            <BrandLockup
                                surface="light"
                                className="dark:hidden"
                            />
                            <BrandLockup
                                surface="dark"
                                className="hidden dark:flex"
                            />
                            <span className="inline-flex h-[30px] items-center rounded-full border border-journey-lavender bg-journey-surface px-3 text-sm font-semibold text-primary">
                                Family portal
                            </span>
                        </>
                    ) : (
                        <BrandLockup surface="dark" />
                    )}
                </div>

                <div className="relative mt-[52px] flex max-w-[520px] flex-col gap-4">
                    {/* Brand statement, not a heading: the form's <h1> is the page heading. */}
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
                    <div className="journey-brand-links flex items-center gap-5 text-[13px]">
                        <Link
                            href={privacy()}
                            className="font-medium hover:underline"
                        >
                            {family
                                ? 'How we protect your information'
                                : 'Privacy'}
                        </Link>
                        {!family && (
                            <Link
                                href={terms()}
                                className="font-medium hover:underline"
                            >
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
                </div>
            </section>

            <main className="journey-form-half">
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
