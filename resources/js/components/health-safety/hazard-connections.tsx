import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { hazardConnectionHref } from '@/lib/health-safety/hazard-navigation';
import { Link } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import type { ReactNode } from 'react';

type Person = { id: number; name: string } | null;
type Work = {
    id: number | string;
    reference_number: string | null;
    title: string;
    status: string;
    display_state: string | null;
    assigned_to: Person;
    due_date: string | null;
    href: string;
};
export type HazardConnections = {
    safety_reviews: {
        id: number;
        reference_number: string;
        status: string;
        owner: Person;
        href: string;
    }[];
    corrective_actions: Work[];
    control_room: {
        id: number;
        reference_number: string;
        status: string;
        priority: number | null;
        assigned_to: Person;
        href: string;
    }[];
    sources: {
        type: 'inspection' | 'checklist';
        id: number;
        title: string;
        status: string;
        occurred_at: string | null;
        href: string;
    }[];
    tasks: (Work & { source: string })[];
    tasks_href: string | null;
};

function ConnectionsGroup({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section className="space-y-3" aria-label={title}>
            <div>
                <h2 className="text-section-title">{title}</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    {description}
                </p>
            </div>
            {children}
        </section>
    );
}
function RecordLink({
    href,
    title,
    status,
    returnTo,
    children,
}: {
    href: string;
    title: string;
    status: string;
    returnTo?: string;
    children?: ReactNode;
}) {
    return (
        <li className="space-y-2 rounded-lg border border-border p-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <Link
                    href={hazardConnectionHref(href, returnTo)}
                    className="frontline-tap frontline-focus inline-flex min-w-0 items-center gap-2 text-sm font-semibold break-words text-primary underline underline-offset-4"
                >
                    {title}
                    <ExternalLink className="h-4 w-4 shrink-0" />
                </Link>
                <StatusBadge variant="neutral">
                    {status.charAt(0).toUpperCase() +
                        status.slice(1).replaceAll('_', ' ')}
                </StatusBadge>
            </div>
            {children ? (
                <div className="text-sm text-muted-foreground">{children}</div>
            ) : null}
        </li>
    );
}
function EmptyConnections() {
    return (
        <p className="text-sm text-muted-foreground">
            No linked records available here.
        </p>
    );
}
function WorkLinks({
    records,
    returnTo,
}: {
    records: Work[];
    returnTo?: string;
}) {
    return records.length ? (
        <ul className="space-y-3">
            {records.map((record) => (
                <RecordLink
                    key={record.id}
                    href={record.href}
                    title={`${record.reference_number ? `${record.reference_number} · ` : ''}${record.title}`}
                    status={record.display_state ?? record.status}
                    returnTo={returnTo}
                >
                    Owner: {record.assigned_to?.name ?? 'Not assigned'}
                    {record.due_date
                        ? ` · Due ${formatDateOnly(record.due_date)}`
                        : ''}
                </RecordLink>
            ))}
        </ul>
    ) : (
        <EmptyConnections />
    );
}

export function HazardConnectionsSection({
    connections,
    returnTo,
}: {
    connections?: HazardConnections;
    returnTo?: string;
}) {
    const data = connections ?? {
        safety_reviews: [],
        corrective_actions: [],
        control_room: [],
        sources: [],
        tasks: [],
        tasks_href: null,
    };
    return (
        <div className="space-y-6">
            <p className="text-sm">
                Open the connected record to continue its work. Each record
                keeps its own owner, permissions and closure decision.
            </p>
            <ConnectionsGroup
                title="Safety reviews"
                description="Investigation, learning and notification decisions remain with the safety review."
            >
                {data.safety_reviews.length ? (
                    <ul className="space-y-3">
                        {data.safety_reviews.map((record) => (
                            <RecordLink
                                key={record.id}
                                href={record.href}
                                title={record.reference_number}
                                status={record.status}
                                returnTo={returnTo}
                            >
                                Review owner:{' '}
                                {record.owner?.name ?? 'Not assigned'}
                            </RecordLink>
                        ))}
                    </ul>
                ) : (
                    <EmptyConnections />
                )}
            </ConnectionsGroup>
            <ConnectionsGroup
                title="Review corrective actions"
                description="These actions belong to linked safety reviews. Hazard control actions remain in this hazard’s Actions section."
            >
                <WorkLinks
                    records={data.corrective_actions}
                    returnTo={returnTo}
                />
            </ConnectionsGroup>
            <ConnectionsGroup
                title="Control Room responses"
                description="Live response and handover remain with Control Room."
            >
                {data.control_room.length ? (
                    <ul className="space-y-3">
                        {data.control_room.map((record) => (
                            <RecordLink
                                key={record.id}
                                href={record.href}
                                title={record.reference_number}
                                status={record.status}
                                returnTo={returnTo}
                            >
                                Response owner:{' '}
                                {record.assigned_to?.name ?? 'Not assigned'}
                                {record.priority != null
                                    ? ` · Priority ${record.priority}`
                                    : ''}
                            </RecordLink>
                        ))}
                    </ul>
                ) : (
                    <EmptyConnections />
                )}
            </ConnectionsGroup>
            <ConnectionsGroup
                title="Originating checks"
                description="Read the inspection or checklist that identified the hazard."
            >
                {data.sources.length ? (
                    <ul className="space-y-3">
                        {data.sources.map((record) => (
                            <RecordLink
                                key={`${record.type}:${record.id}`}
                                href={record.href}
                                title={record.title}
                                status={record.status}
                            >
                                {record.occurred_at
                                    ? formatDateTime(record.occurred_at)
                                    : 'Completion time not recorded'}
                                {record.type === 'inspection'
                                    ? ' · Opens the site inspection register'
                                    : ''}
                            </RecordLink>
                        ))}
                    </ul>
                ) : (
                    <EmptyConnections />
                )}
            </ConnectionsGroup>
            <ConnectionsGroup
                title="Linked tasks"
                description="Task entries reflect the current source record; completing one does not close every connected record."
            >
                <WorkLinks records={data.tasks} returnTo={returnTo} />
                {data.tasks_href ? (
                    <Button asChild variant="outline" className="frontline-tap">
                        <Link href={data.tasks_href}>Open All Tasks</Link>
                    </Button>
                ) : null}
            </ConnectionsGroup>
        </div>
    );
}
