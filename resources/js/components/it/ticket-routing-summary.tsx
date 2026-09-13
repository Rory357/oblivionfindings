import { Route, UserRoundCheck, UsersRound } from 'lucide-react';

interface RoutingEntity {
    id: number;
    name: string;
}

export interface TicketRoutingDetails {
    queue: RoutingEntity | null;
    team: RoutingEntity | null;
    owner: RoutingEntity | null;
    accountable_owner?: RoutingEntity | null;
    cover?: RoutingEntity | null;
    strategy?:
        | 'rule'
        | 'fallback'
        | 'override'
        | 'unconfigured'
        | 'not_evaluated';
    explanation?: string;
    gaps?: string[];
    override?: {
        reason: string;
        actor: RoutingEntity | null;
        fields: string[];
        suspended_fields: string[];
    } | null;
}

const gapLabels: Record<string, string> = {
    no_eligible_queue: 'No eligible queue is configured.',
    no_accountable_team: 'An accountable team is required.',
    no_available_owner: 'No eligible owner is available.',
    no_accountable_owner:
        'The accountable manager or service owner needs current staff access.',
    no_available_cover: 'No eligible absence cover is available.',
    manual_override_suspended:
        'A manual choice is unavailable and has been suspended.',
    routing_not_evaluated:
        'This ticket has not yet been assessed by the current routing rules.',
};

export function TicketRoutingSummary({
    routing,
    compact = false,
}: {
    routing: TicketRoutingDetails;
    compact?: boolean;
}) {
    if (compact) {
        return (
            <span className="block min-w-0" aria-label="Routed ownership">
                <span className="block truncate text-[11px] font-medium text-foreground">
                    {routing.queue?.name ?? 'Queue not configured'}
                </span>
                <span className="block truncate text-[10.5px] text-muted-foreground">
                    {routing.team?.name ?? 'Team not configured'} · Owner:{' '}
                    {routing.owner?.name ?? 'not assigned'}
                </span>
            </span>
        );
    }

    return (
        <div
            className="space-y-2 rounded-xl border border-border/60 bg-muted/35 p-2.5"
            aria-label="Routed ownership"
        >
            <RoutingLine
                icon={Route}
                label="Queue"
                value={routing.queue?.name ?? 'Queue not configured'}
            />
            <RoutingLine
                icon={UsersRound}
                label="Responsible team"
                value={routing.team?.name ?? 'Team not configured'}
            />
            <RoutingLine
                icon={UserRoundCheck}
                label="Accountable owner"
                value={
                    routing.accountable_owner?.name ??
                    routing.owner?.name ??
                    'Owner not assigned'
                }
            />
            <RoutingLine
                icon={UserRoundCheck}
                label="Absence cover"
                value={routing.cover?.name ?? 'Cover not configured'}
            />
            {routing.explanation && (
                <p className="text-sm text-muted-foreground">
                    {routing.explanation}
                </p>
            )}
            {!!routing.gaps?.length && (
                <ul className="list-disc space-y-1 pl-4 text-sm text-muted-foreground">
                    {routing.gaps.map((gap) => (
                        <li key={gap}>
                            {gapLabels[gap] ??
                                'Routing needs review in IT setup.'}
                        </li>
                    ))}
                </ul>
            )}
            {routing.override && (
                <div className="space-y-1 border-t border-border pt-2 text-sm">
                    <p className="font-medium">
                        Manual choice
                        {routing.override.actor
                            ? ` by ${routing.override.actor.name}`
                            : ''}
                    </p>
                    <p className="break-words text-muted-foreground">
                        {routing.override.reason}
                    </p>
                    {!!routing.override.suspended_fields.length && (
                        <p className="text-muted-foreground">
                            Unavailable:{' '}
                            {routing.override.suspended_fields
                                .map(
                                    (field) =>
                                        ({
                                            assigned_to_user_id:
                                                'assigned technician',
                                            owner_user_id: 'owner',
                                            queue_id: 'queue',
                                        })[field] ?? 'ownership choice',
                                )
                                .join(', ')}
                            .
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}

function RoutingLine({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof Route;
    label: string;
    value: string;
}) {
    return (
        <div className="flex items-start gap-2">
            <span className="grid h-7 w-7 flex-none place-items-center rounded-lg bg-primary/10 text-primary">
                <Icon className="h-3.5 w-3.5" aria-hidden="true" />
            </span>
            <span className="min-w-0">
                <span className="block text-[10.5px] font-semibold tracking-wide text-muted-foreground uppercase">
                    {label}
                </span>
                <span className="block truncate text-[12.5px] font-medium text-foreground">
                    {value}
                </span>
            </span>
        </div>
    );
}
