import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import {
    CheckCircle2,
    ClipboardList,
    Clock,
    FileText,
    GitBranch,
    Pill,
    ShieldAlert,
    User2,
    Users,
} from 'lucide-react';

type Person = { id: number; name: string } | null;

type Client = { id: number; first_name: string; last_name: string } | null;

type ShiftSummary = {
    id: number;
    starts_at: string | null;
    ends_at: string | null;
    client?: Client;
    staff?: Person;
} | null;

type Handover = {
    id: number;
    status: string;
    handover_notes: string;
    client_mood: string | null;
    tasks_pending: unknown[] | null;
    medications_due: unknown[] | null;
    incidents_to_note: unknown[] | null;
    follow_up_items: unknown[] | null;
    observations_summary: unknown[] | null;
    submitted_at: string | null;
    acknowledged_at: string | null;
    created_at: string | null;
    client: Client;
    outgoing_staff: Person;
    incoming_staff: Person;
    submitter: Person;
    acknowledger: Person;
    outgoing_shift: ShiftSummary;
    incoming_shift: ShiftSummary;
};

type Props = {
    handover: Handover;
};

function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '---';
    }

    return new Date(value).toLocaleString('en-NZ', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function statusBadge(status: string) {
    switch (status) {
        case 'acknowledged':
            return (
                <Badge className="gap-1">
                    <CheckCircle2 className="h-3 w-3" />
                    Acknowledged
                </Badge>
            );
        case 'submitted':
            return (
                <Badge
                    variant="outline"
                    className="gap-1 border-status-warning/30 text-status-warning"
                >
                    <Clock className="h-3 w-3" />
                    Submitted
                </Badge>
            );
        case 'draft':
            return <Badge variant="secondary">Draft</Badge>;
        default:
            return <Badge variant="outline">{status.replace(/_/g, ' ')}</Badge>;
    }
}

function displayListItem(item: unknown): string {
    if (typeof item === 'string') {
        return item;
    }

    if (item && typeof item === 'object') {
        const record = item as Record<string, unknown>;

        for (const key of [
            'label',
            'description',
            'name',
            'title',
            'note',
            'value',
        ]) {
            const value = record[key];
            if (typeof value === 'string' && value.trim() !== '') {
                return value;
            }
        }

        return Object.values(record)
            .filter(
                (value): value is string =>
                    typeof value === 'string' && value.trim() !== '',
            )
            .join(' ');
    }

    return String(item ?? '');
}

function DetailList({
    title,
    icon: Icon,
    items,
    emptyLabel,
}: {
    title: string;
    icon: typeof ClipboardList;
    items: unknown[] | null;
    emptyLabel: string;
}) {
    const values = (items ?? [])
        .map(displayListItem)
        .map((value) => value.trim())
        .filter(Boolean);

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <Icon className="h-4 w-4" />
                    {title}
                </CardTitle>
            </CardHeader>
            <CardContent>
                {values.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {emptyLabel}
                    </p>
                ) : (
                    <ul className="space-y-2 text-sm">
                        {values.map((value, index) => (
                            <li
                                key={`${title}-${index}`}
                                className="rounded-md border bg-muted/20 px-3 py-2"
                            >
                                {value}
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function personName(person: Person): string {
    return person?.name ?? 'Unassigned';
}

function clientName(client: Client): string {
    return client
        ? `${client.first_name} ${client.last_name}`
        : 'No client linked';
}

export default function HandoversShow({ handover }: Props) {
    return (
        <AppLayout>
            <Head title={`Shift Handover #${handover.id}`} />
            <PageHero
                variant="compact"
                title={`Shift Handover #${handover.id}`}
                description="Review outgoing notes, pending work, and the shift-to-shift handover trail."
                backHref="/operations/handovers"
            />
            <PageShell>
                <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <CardTitle className="text-lg">
                                        Handover Notes
                                    </CardTitle>
                                    <div className="flex items-center gap-2">
                                        {statusBadge(handover.status)}
                                        {handover.client_mood ? (
                                            <Badge variant="outline">
                                                Mood: {handover.client_mood}
                                            </Badge>
                                        ) : null}
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <p className="text-sm leading-6 whitespace-pre-wrap">
                                    {handover.handover_notes ||
                                        'No handover notes recorded.'}
                                </p>
                                <div className="grid gap-3 sm:grid-cols-3">
                                    <div className="rounded-md border bg-muted/20 p-3">
                                        <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Created
                                        </div>
                                        <div className="mt-1 text-sm font-medium">
                                            {formatDateTime(
                                                handover.created_at,
                                            )}
                                        </div>
                                    </div>
                                    <div className="rounded-md border bg-muted/20 p-3">
                                        <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Submitted
                                        </div>
                                        <div className="mt-1 text-sm font-medium">
                                            {formatDateTime(
                                                handover.submitted_at,
                                            )}
                                        </div>
                                    </div>
                                    <div className="rounded-md border bg-muted/20 p-3">
                                        <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                            Acknowledged
                                        </div>
                                        <div className="mt-1 text-sm font-medium">
                                            {formatDateTime(
                                                handover.acknowledged_at,
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <div className="grid gap-4 xl:grid-cols-2">
                            <DetailList
                                title="Pending Tasks"
                                icon={ClipboardList}
                                items={handover.tasks_pending}
                                emptyLabel="No pending tasks were captured for this handover."
                            />
                            <DetailList
                                title="Follow-up Items"
                                icon={FileText}
                                items={handover.follow_up_items}
                                emptyLabel="No follow-up items were captured for this handover."
                            />
                            <DetailList
                                title="Medications Due"
                                icon={Pill}
                                items={handover.medications_due}
                                emptyLabel="No medication prompts were attached to this handover."
                            />
                            <DetailList
                                title="Incidents To Note"
                                icon={ShieldAlert}
                                items={handover.incidents_to_note}
                                emptyLabel="No incidents were flagged in this handover."
                            />
                        </div>

                        <DetailList
                            title="Observations Summary"
                            icon={ClipboardList}
                            items={handover.observations_summary}
                            emptyLabel="No structured observations were recorded."
                        />
                    </div>

                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Users className="h-4 w-4" />
                                    People
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div>
                                    <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Outgoing staff
                                    </div>
                                    <div className="mt-1 font-medium">
                                        {personName(handover.outgoing_staff)}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Incoming staff
                                    </div>
                                    <div className="mt-1 font-medium">
                                        {personName(handover.incoming_staff)}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Client
                                    </div>
                                    <div className="mt-1 font-medium">
                                        {handover.client ? (
                                            <Link
                                                href={`/operations/clients/${handover.client.id}`}
                                                className="hover:underline"
                                            >
                                                {clientName(handover.client)}
                                            </Link>
                                        ) : (
                                            clientName(handover.client)
                                        )}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Submitted by
                                    </div>
                                    <div className="mt-1 font-medium">
                                        {personName(handover.submitter)}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Acknowledged by
                                    </div>
                                    <div className="mt-1 font-medium">
                                        {personName(handover.acknowledger)}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <GitBranch className="h-4 w-4" />
                                    Shift Links
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="rounded-md border bg-muted/20 p-3">
                                    <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Outgoing shift
                                    </div>
                                    {handover.outgoing_shift ? (
                                        <div className="mt-1 space-y-1">
                                            <Link
                                                href={`/operations/shifts/${handover.outgoing_shift.id}`}
                                                className="font-medium hover:underline"
                                            >
                                                Shift #
                                                {handover.outgoing_shift.id}
                                            </Link>
                                            <div>
                                                {formatDateTime(
                                                    handover.outgoing_shift
                                                        .starts_at,
                                                )}{' '}
                                                to{' '}
                                                {formatDateTime(
                                                    handover.outgoing_shift
                                                        .ends_at,
                                                )}
                                            </div>
                                            <div className="text-muted-foreground">
                                                Staff:{' '}
                                                {personName(
                                                    handover.outgoing_shift
                                                        .staff ??
                                                        handover.outgoing_staff,
                                                )}
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="mt-1 text-muted-foreground">
                                            No outgoing shift linked.
                                        </div>
                                    )}
                                </div>
                                <div className="rounded-md border bg-muted/20 p-3">
                                    <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Incoming shift
                                    </div>
                                    {handover.incoming_shift ? (
                                        <div className="mt-1 space-y-1">
                                            <Link
                                                href={`/operations/shifts/${handover.incoming_shift.id}`}
                                                className="font-medium hover:underline"
                                            >
                                                Shift #
                                                {handover.incoming_shift.id}
                                            </Link>
                                            <div>
                                                {formatDateTime(
                                                    handover.incoming_shift
                                                        .starts_at,
                                                )}{' '}
                                                to{' '}
                                                {formatDateTime(
                                                    handover.incoming_shift
                                                        .ends_at,
                                                )}
                                            </div>
                                            <div className="text-muted-foreground">
                                                Staff:{' '}
                                                {personName(
                                                    handover.incoming_shift
                                                        .staff ??
                                                        handover.incoming_staff,
                                                )}
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="mt-1 text-muted-foreground">
                                            No incoming shift linked.
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <User2 className="h-4 w-4" />
                                    Quick Summary
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm text-muted-foreground">
                                <p>
                                    {personName(handover.outgoing_staff)} handed
                                    over to{' '}
                                    {personName(handover.incoming_staff)}.
                                </p>
                                <p>
                                    Current status:{' '}
                                    <span className="font-medium text-foreground">
                                        {handover.status.replace(/_/g, ' ')}
                                    </span>
                                </p>
                                <p>
                                    Client context:{' '}
                                    <span className="font-medium text-foreground">
                                        {clientName(handover.client)}
                                    </span>
                                </p>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
