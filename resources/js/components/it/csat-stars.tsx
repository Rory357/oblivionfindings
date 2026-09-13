import { Star } from 'lucide-react';

export const CSAT_LIT = {
    color: 'var(--status-warning)',
    fill: 'var(--status-warning)',
};

/** Read-only star row for a score already given (the rail / row chip). */
export function CsatStars({
    score,
    size = 'h-4 w-4',
}: {
    score: number;
    size?: string;
}) {
    return (
        <span
            className="inline-flex items-center gap-0.5"
            role="img"
            aria-label={`Rated ${score} out of 5 stars`}
        >
            {[1, 2, 3, 4, 5].map((n) => (
                <Star
                    key={n}
                    aria-hidden
                    className={
                        n <= score ? size : `${size} text-muted-foreground/40`
                    }
                    style={n <= score ? CSAT_LIT : undefined}
                />
            ))}
        </span>
    );
}
