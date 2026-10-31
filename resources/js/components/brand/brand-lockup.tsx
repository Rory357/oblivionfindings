import { JourneyMark } from '@/components/brand/journey-mark';
import { resolveWordmarkName } from '@/components/event-horizon-wordmark';
import { cn } from '@/lib/utils';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';

type BrandingProps = {
    branding?: {
        name?: string | null;
        logoUrl?: string | null;
        logoDarkUrl?: string | null;
    };
};

/** The organisation's own name, or null when the stock "Oblivion Care" applies. */
export function useCustomBrandName(): string | null {
    const { branding } = usePage<BrandingProps>().props;
    const resolved = resolveWordmarkName(branding?.name);

    return resolved === 'Oblivion Care' ? null : resolved;
}

/** Lowercase "oblivion care" wordmark (Instrument Sans 700 / 420). */
export function JourneyWordmark({ className }: { className?: string }) {
    return (
        <span
            className={cn('leading-none tracking-[-0.012em]', className)}
            aria-hidden="true"
        >
            <span className="font-bold">oblivion</span>
            <span className="ml-[0.2em] font-[420]">care</span>
        </span>
    );
}

/**
 * The identity in the corner of the sign-in pages, following Settings ›
 * Branding (approved 13 Oct 2026):
 * - no custom branding: the Journey mark + "oblivion care";
 * - an uploaded logo: that logo (the "dark backgrounds" version on ink);
 * - a custom name only: the name, without our mark.
 */
export function BrandLockup({
    surface,
    className,
    name,
}: {
    surface: 'dark' | 'light';
    className?: string;
    /** The public website passes the provider's resolved name (branding or
     *  referral settings), so a client site never reads "Oblivion Care". */
    name?: string | null;
}) {
    const { branding } = usePage<BrandingProps>().props;
    const brandName = useCustomBrandName();
    const customName = name === undefined ? brandName : name;
    const logo =
        surface === 'dark'
            ? (branding?.logoDarkUrl ?? null)
            : (branding?.logoUrl ?? branding?.logoDarkUrl ?? null);
    // Icon-like logos get the name beside them; wide wordmark logos stand alone.
    const [iconLike, setIconLike] = useState(false);

    if (logo) {
        return (
            <span className={cn('flex min-h-10 items-center gap-3', className)}>
                <img
                    src={logo}
                    alt={customName ?? 'Oblivion Care'}
                    className="h-10 w-auto max-w-[220px] object-contain"
                    onLoad={(event) => {
                        const image = event.currentTarget;
                        setIconLike(
                            image.naturalHeight > 0 &&
                                image.naturalWidth / image.naturalHeight < 1.6,
                        );
                    }}
                />
                {iconLike && (
                    <span className="text-lg font-semibold tracking-tight">
                        {customName ?? 'Oblivion Care'}
                    </span>
                )}
            </span>
        );
    }

    if (customName) {
        return (
            <span
                className={cn(
                    'flex min-h-10 items-center text-2xl font-bold tracking-tight',
                    className,
                )}
            >
                {customName}
            </span>
        );
    }

    return (
        <span className={cn('flex items-center gap-1', className)}>
            <span className="sr-only">Oblivion Care</span>
            <JourneyMark
                tone={surface === 'dark' ? 'lavender' : 'primary'}
                className="journey-lockup-mark mt-[3px] h-[37px] w-[28px]"
            />
            <JourneyWordmark className="text-[27px]" />
        </span>
    );
}
