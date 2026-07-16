import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { CheckCircle2, CircleAlert } from 'lucide-react';

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

export function JourneyGateList({ gate }: { gate: JourneyGateData }) {
    return (
        <section
            aria-label="Transition requirements"
            className="space-y-2 rounded-xl border border-border bg-card/70 p-3"
        >
            <p
                className={cn(
                    'text-sm font-semibold',
                    gate.allowed
                        ? 'text-status-success-foreground'
                        : 'text-status-critical-foreground',
                )}
            >
                {gate.allowed
                    ? 'Ready to continue'
                    : 'Complete these requirements'}
            </p>
            <ul className="space-y-2">
                {gate.requirements.map((requirement) => {
                    const Icon = requirement.complete
                        ? CheckCircle2
                        : CircleAlert;
                    const content = (
                        <>
                            <Icon
                                aria-hidden
                                className={cn(
                                    'mt-0.5 h-4 w-4 shrink-0',
                                    requirement.complete
                                        ? 'text-status-success'
                                        : 'text-status-critical',
                                )}
                            />
                            <span className="min-w-0 flex-1">
                                {requirement.label}
                            </span>
                            <span
                                className={cn(
                                    'shrink-0 text-xs font-semibold',
                                    requirement.complete
                                        ? 'text-status-success-foreground'
                                        : 'text-status-critical-foreground',
                                )}
                            >
                                {requirement.complete ? 'Complete' : 'Required'}
                            </span>
                        </>
                    );

                    return (
                        <li key={requirement.key}>
                            {requirement.complete || !requirement.href ? (
                                <div className="flex items-start gap-2 rounded-lg border border-border/70 px-3 py-2 text-sm">
                                    {content}
                                </div>
                            ) : (
                                <Link
                                    href={requirement.href}
                                    aria-label={requirement.label}
                                    className="flex items-start gap-2 rounded-lg border border-status-critical/30 px-3 py-2 text-sm transition-colors hover:bg-status-critical-bg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {content}
                                </Link>
                            )}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
