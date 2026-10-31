import { Ring } from '@/components/signature/blocks';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

export type JourneyGateRequirement = {
    key: string;
    complete: boolean;
    label: string;
    href: string | null;
};

export type JourneyGateData = {
    allowed: boolean;
    requirements: JourneyGateRequirement[];
};

/**
 * The server-owned requirements before a transition, read by their rings
 * (signature Checks): a filled ring for done, a three-quarter warning ring for
 * still required. Unmet requirements link to where they are finished when the
 * viewer can open that module; labels stay exactly as the server sends them.
 */
export function JourneyGateList({ gate }: { gate: JourneyGateData }) {
    return (
        <section
            aria-label="Transition requirements"
            className="rounded-xl border border-border bg-card px-3.5 pt-3 pb-1.5"
        >
            <p
                className={cn(
                    'flex items-center gap-2 text-sm font-semibold',
                    gate.allowed
                        ? 'text-status-success-foreground'
                        : 'text-status-warning-foreground',
                )}
            >
                <Ring tone={gate.allowed ? 'success' : 'warning'} />
                {gate.allowed
                    ? 'Ready to continue'
                    : 'Complete these requirements'}
            </p>
            <ul className="mt-1 divide-y divide-border/60">
                {gate.requirements.map((requirement) => (
                    <li
                        key={requirement.key}
                        className="flex items-center gap-2.5 py-2 text-sm"
                    >
                        <Ring
                            tone={requirement.complete ? 'success' : 'warning'}
                        />
                        <span className="min-w-0 flex-1">
                            {requirement.complete || !requirement.href ? (
                                requirement.label
                            ) : (
                                <Link
                                    href={requirement.href}
                                    aria-label={requirement.label}
                                    className="rounded-sm font-medium text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {requirement.label}
                                </Link>
                            )}
                        </span>
                        <small
                            className={cn(
                                'shrink-0 text-xs font-semibold',
                                requirement.complete
                                    ? 'text-status-success-foreground'
                                    : 'text-status-warning-foreground',
                            )}
                        >
                            {requirement.complete ? 'Complete' : 'Required'}
                        </small>
                    </li>
                ))}
            </ul>
        </section>
    );
}
