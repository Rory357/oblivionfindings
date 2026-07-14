import { formatDateTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import {
    CheckCircle2,
    Circle,
    Clock3,
    LoaderCircle,
    OctagonAlert,
    type LucideIcon,
} from 'lucide-react';

export type IncidentJourneyStageState =
    | 'not_started'
    | 'in_progress'
    | 'waiting'
    | 'complete'
    | 'blocked';
export type IncidentJourneyStage = {
    key: 'control_room' | 'incident' | 'health_safety';
    label: string;
    referenceNumber: string | null;
    state: IncidentJourneyStageState;
    href: string | null;
};

const STATE: Record<
    IncidentJourneyStageState,
    { label: string; icon: LucideIcon; className: string }
> = {
    not_started: {
        label: 'Not started',
        icon: Circle,
        className: 'text-muted-foreground',
    },
    in_progress: {
        label: 'In progress',
        icon: LoaderCircle,
        className: 'text-primary',
    },
    waiting: {
        label: 'Waiting for acceptance',
        icon: Clock3,
        className: 'text-status-warning-foreground',
    },
    complete: {
        label: 'Complete',
        icon: CheckCircle2,
        className: 'text-status-success-foreground',
    },
    blocked: {
        label: 'Blocked',
        icon: OctagonAlert,
        className: 'text-status-critical-foreground',
    },
};

export function IncidentJourneyStatus({
    stages,
    occurredAt,
}: {
    stages: readonly IncidentJourneyStage[];
    occurredAt?: string | null;
}) {
    return (
        <div className="space-y-2">
            {occurredAt ? (
                <p className="text-xs text-muted-foreground">
                    Occurred {formatDateTime(occurredAt)}
                </p>
            ) : null}
            <ol
                aria-label="Incident journey"
                className="grid grid-cols-3 gap-2"
            >
                {stages.map((stage) => {
                    const state = STATE[stage.state];
                    const Icon = state.icon;
                    const content = (
                        <>
                            <span className="text-xs font-semibold text-foreground">
                                {stage.label}
                            </span>
                            <span className="font-mono text-[11px] text-muted-foreground">
                                {stage.referenceNumber ?? 'Reference pending'}
                            </span>
                            <span
                                className={cn(
                                    'inline-flex items-center gap-1 text-xs font-medium',
                                    state.className,
                                )}
                            >
                                <Icon
                                    className={cn(
                                        'h-3.5 w-3.5',
                                        stage.state === 'in_progress' &&
                                            'animate-spin motion-reduce:animate-none',
                                    )}
                                    aria-hidden
                                />
                                {state.label}
                            </span>
                        </>
                    );

                    return (
                        <li key={stage.key} className="min-w-0">
                            {stage.href ? (
                                <Link
                                    href={stage.href}
                                    className="flex min-h-24 flex-col gap-1 rounded-xl border bg-card p-3 transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {content}
                                </Link>
                            ) : (
                                // eslint-disable-next-line no-restricted-syntax -- compact lifecycle stage tile, not a standalone content card.
                                <div className="flex min-h-24 flex-col gap-1 rounded-xl border bg-card p-3">
                                    {content}
                                </div>
                            )}
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}
