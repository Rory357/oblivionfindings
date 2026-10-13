import { cn } from '@/lib/utils';

/** Obli Orbit and NZ mark from the approved website/native design; app semantic colours. */
export function ObliMark({
    active = false,
    className,
}: {
    active?: boolean;
    className?: string;
}) {
    return (
        <svg
            viewBox="0 0 100 100"
            aria-hidden="true"
            className={cn('h-10 w-10 shrink-0', className)}
        >
            <g
                className={cn(
                    'origin-center text-primary',
                    active && 'motion-safe:animate-[spin_8s_linear_infinite]',
                )}
            >
                <ellipse
                    cx="50"
                    cy="50"
                    rx="47"
                    ry="37"
                    transform="rotate(-25 50 50)"
                    fill="none"
                    stroke="currentColor"
                    opacity=".45"
                />
                <ellipse
                    cx="50"
                    cy="50"
                    rx="46"
                    ry="42"
                    transform="rotate(42 50 50)"
                    fill="none"
                    stroke="currentColor"
                    opacity=".3"
                />
                <circle cx="14" cy="30" r="3" fill="currentColor" />
                <circle cx="86" cy="68" r="2" fill="currentColor" />
            </g>
            <circle cx="50" cy="50" r="36" className="fill-primary" />
            <circle
                cx="44"
                cy="43"
                r="25"
                className="fill-primary-foreground"
                opacity=".12"
            />
            <path
                className="fill-primary-foreground"
                transform="translate(34 24) scale(.57 .69)"
                d="M29 3 34 13 32 17 37 23 44 24 51 21 50 29 45 35 38 37 34 44 29 43 30 38 24 33 23 27 29 23 27 18 29 15 24 8Z M31 43 29 50 23 54 19 61 11 66 5 65 4 61 9 56 12 51 19 47 23 42 27 43Z M8 68 11 69 9 72 5 71Z"
            />
        </svg>
    );
}
