import { Route, UserRoundCheck, UsersRound } from 'lucide-react';

interface RoutingEntity {
    id: number;
    name: string;
}

export interface TicketRoutingDetails {
    queue: RoutingEntity | null;
    team: RoutingEntity | null;
    owner: RoutingEntity | null;
}

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
                value={routing.owner?.name ?? 'Owner not assigned'}
            />
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
