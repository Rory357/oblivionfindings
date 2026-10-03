import type { ReactNode } from 'react';

/** Full-width facts keep medicine and recording details readable on phones. */
export function MobileMedsFacts({
    facts,
}: {
    facts: { label: string; value: ReactNode }[];
}) {
    return (
        <dl className="grid w-full min-w-0 gap-2.5">
            {facts.map(({ label, value }) => (
                <div key={label} className="min-w-0">
                    <dt className="text-xs text-muted-foreground">{label}</dt>
                    <dd className="break-words text-sm">{value}</dd>
                </div>
            ))}
        </dl>
    );
}
