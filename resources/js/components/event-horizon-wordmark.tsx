import { cn } from '@/lib/utils';

/**
 * The Event Horizon shell wordmark (APP_SHELL_STYLE_GUIDE.md §2): the ring
 * stands in for the leading "O", followed by the rest of the first word in
 * bright text and any remaining words muted — "⦿blivion Care".
 *
 * Branding-aware: an org with a custom name keeps it (a leading O/o is still
 * devoured by the ring); the stock install renders the approved
 * "Oblivion Care" wordmark. An uploaded logo replaces the ring entirely.
 */

const STOCK_NAMES = new Set(['oblivion findings', 'oblivionfindings', 'laravel']);

export function resolveWordmarkName(brandName?: string | null): string {
    const trimmed = brandName?.trim() ?? '';
    if (trimmed === '' || STOCK_NAMES.has(trimmed.toLowerCase())) {
        return 'Oblivion Care';
    }
    return trimmed;
}

export function EventHorizonWordmark({
    name,
    logoUrl,
    className,
}: {
    name?: string | null;
    logoUrl?: string | null;
    className?: string;
}) {
    const brand = resolveWordmarkName(name);
    const [first = '', ...rest] = brand.split(/\s+/);
    const devoured = /^o/i.test(first) ? first.slice(1) : first;

    return (
        <span className={cn('flex min-w-0 items-center', className)}>
            <span className="sr-only">{brand}</span>
            <span aria-hidden="true" className="flex min-w-0 items-center">
                {logoUrl ? (
                    <img
                        src={logoUrl}
                        alt=""
                        className="size-6 shrink-0 rounded-md object-cover"
                    />
                ) : (
                    <span className="eh-ring" />
                )}
                <span className="ml-1 truncate text-[19px] leading-none font-semibold tracking-tight text-sidebar-accent-foreground">
                    {logoUrl || !devoured ? brand.split(/\s+/)[0] : devoured}
                    {rest.length > 0 && (
                        <span className="ml-1.5 font-normal text-sidebar-foreground">
                            {rest.join(' ')}
                        </span>
                    )}
                </span>
            </span>
        </span>
    );
}
