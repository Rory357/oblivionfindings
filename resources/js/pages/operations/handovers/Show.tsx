import type { HandoverWorkerNotes } from '@/components/handover-person-notes';
import HandoverPersonNotes from '@/components/handover-person-notes';
import { PageLayout } from '@/components/page';
import {
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { StatusVariant } from '@/components/ui/status-badge';
import { WorkforcePageHeader } from '@/components/workforce/workforce-page-header';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeftRight,
    CheckCircle2,
    ClipboardList,
    Clock,
    FileText,
    GitBranch,
    Pill,
    ShieldAlert,
    Users,
} from 'lucide-react';
import type { ReactNode } from 'react';

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
    worker_notes?: HandoverWorkerNotes | null;
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
        return 'Not yet';
    }

    return new Date(value).toLocaleString('en-NZ', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

const STATUS: Record<
    string,
    { label: string; variant: StatusVariant; icon?: typeof Clock }
> = {
    acknowledged: {
        label: 'Acknowledged',
        variant: 'success',
        icon: CheckCircle2,
    },
    submitted: {
        label: 'Waiting for acknowledgement',
        variant: 'warning',
        icon: Clock,
    },
    draft: { label: 'Draft', variant: 'neutral' },
};

function statusFor(status: string) {
    const known = STATUS[status];
    if (known) return known;
    const words = status.replace(/_/g, ' ');
    return {
        label: words.charAt(0).toUpperCase() + words.slice(1),
        variant: 'neutral' as StatusVariant,
    };
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

function listValues(items: unknown[] | null): string[] {
    return (items ?? [])
        .map(displayListItem)
        .map((value) => value.trim())
        .filter(Boolean);
}

function DetailList({
    id,
    title,
    icon: Icon,
    values,
    emptyLabel,
}: {
    id: string;
    title: string;
    icon: typeof ClipboardList;
    values: string[];
    emptyLabel: string;
}) {
    return (
        <Card id={id} className="scroll-mt-5">
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <Icon className="size-4" />
                    {title}
                </CardTitle>
            </CardHeader>
            <CardContent>
                {values.length === 0 ? (
                    <p className="text-subtle">{emptyLabel}</p>
                ) : (
                    <ul className="space-y-2 text-sm">
                        {values.map((value, index) => (
                            <li
                                key={`${id}-${index}`}
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

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <div className="text-caption">{label}</div>
            <div className="mt-1 text-sm font-medium">{children}</div>
        </div>
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

function scrollToSection(id: string) {
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function ShiftLink({
    label,
    shift,
    fallbackStaff,
    emptyLabel,
}: {
    label: string;
    shift: ShiftSummary;
    fallbackStaff: Person;
    emptyLabel: string;
}) {
    return (
        <div className="rounded-md border bg-muted/20 p-3">
            <div className="text-caption">{label}</div>
            {shift ? (
                <div className="mt-1 space-y-1">
                    <Link
                        href={`/operations/shifts/${shift.id}`}
                        className="font-medium hover:underline"
                    >
                        {formatDateTime(shift.starts_at)} to{' '}
                        {formatDateTime(shift.ends_at)}
                    </Link>
                    <div className="text-muted-foreground">
                        Staff: {personName(shift.staff ?? fallbackStaff)}
                        <span className="text-caption">
                            {' '}
                            · Shift #{shift.id}
                        </span>
                    </div>
                </div>
            ) : (
                <div className="mt-1 text-muted-foreground">{emptyLabel}</div>
            )}
        </div>
    );
}

export default function HandoversShow({ handover }: Props) {
    const status = statusFor(handover.status);
    const client = clientName(handover.client);
    const title = handover.client ? `Handover for ${client}` : 'Shift handover';
    const tasks = listValues(handover.tasks_pending);
    const followUps = listValues(handover.follow_up_items);
    const medications = listValues(handover.medications_due);
    const incidents = listValues(handover.incidents_to_note);
    const observations = listValues(handover.observations_summary);

    const meters: Array<{
        id: string;
        label: string;
        values: string[];
        caption: string;
        tone?: 'warning';
    }> = [
        {
            id: 'pending-tasks',
            label: 'Pending tasks',
            values: tasks,
            caption: 'Carried to the next shift',
        },
        {
            id: 'follow-up-items',
            label: 'Follow-ups',
            values: followUps,
            caption: 'Items to check on',
        },
        {
            id: 'medications-due',
            label: 'Medications due',
            values: medications,
            caption: 'Prompts attached',
        },
        {
            id: 'incidents-to-note',
            label: 'Incidents to note',
            values: incidents,
            caption: 'Flagged in this handover',
            ...(incidents.length > 0 ? { tone: 'warning' as const } : {}),
        },
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Workforce', href: '/operations/handovers' },
                { title: 'Handovers', href: '/operations/handovers' },
                { title, href: `/operations/handovers/${handover.id}` },
            ]}
        >
            <Head title={title} />
            <PageLayout
                hero={
                    <WorkforcePageHeader
                        variant="profile"
                        mobileSummary="Handover"
                        backHref="/operations/handovers"
                        icon={ArrowLeftRight}
                        title={title}
                        wrapTitle
                        titleChip={
                            <PageHeaderStatusChip
                                variant={status.variant}
                                icon={status.icon}
                            >
                                {status.label}
                            </PageHeaderStatusChip>
                        }
                        subline={`${personName(handover.outgoing_staff)} to ${personName(handover.incoming_staff)} · Submitted ${formatDateTime(handover.submitted_at)}`}
                        meters={
                            <>
                                {meters.map((meter) => (
                                    <PageHeaderMeterBlock
                                        key={meter.id}
                                        label={meter.label}
                                        tone={meter.tone}
                                        ariaLabel={`Go to ${meter.label.toLowerCase()}`}
                                        onClick={() =>
                                            scrollToSection(meter.id)
                                        }
                                    >
                                        <PageHeaderMeterBig>
                                            {meter.values.length}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {meter.caption}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ))}
                            </>
                        }
                    />
                }
            >
                <div className="grid gap-5 lg:grid-cols-[2fr_1fr]">
                    <div className="flex min-w-0 flex-col gap-5">
                        <Card>
                            <CardHeader>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <CardTitle className="text-base">
                                        Handover notes
                                    </CardTitle>
                                    {handover.client_mood ? (
                                        <Badge variant="outline">
                                            Mood: {handover.client_mood}
                                        </Badge>
                                    ) : null}
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <HandoverPersonNotes
                                    notes={handover.worker_notes}
                                />
                                {!handover.worker_notes && (
                                    <p className="text-sm leading-6 whitespace-pre-wrap">
                                        {handover.handover_notes ||
                                            'No handover notes recorded.'}
                                    </p>
                                )}
                                <div className="grid gap-3 sm:grid-cols-3">
                                    {(
                                        [
                                            ['Created', handover.created_at],
                                            [
                                                'Submitted',
                                                handover.submitted_at,
                                            ],
                                            [
                                                'Acknowledged',
                                                handover.acknowledged_at,
                                            ],
                                        ] as const
                                    ).map(([label, value]) => (
                                        <div
                                            key={label}
                                            className="rounded-md border bg-muted/20 p-3"
                                        >
                                            <Fact label={label}>
                                                {formatDateTime(value)}
                                            </Fact>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>

                        <div className="grid gap-5 xl:grid-cols-2">
                            <DetailList
                                id="pending-tasks"
                                title="Pending tasks"
                                icon={ClipboardList}
                                values={tasks}
                                emptyLabel="No pending tasks were captured for this handover."
                            />
                            <DetailList
                                id="follow-up-items"
                                title="Follow-up items"
                                icon={FileText}
                                values={followUps}
                                emptyLabel="No follow-up items were captured for this handover."
                            />
                            <DetailList
                                id="medications-due"
                                title="Medications due"
                                icon={Pill}
                                values={medications}
                                emptyLabel="No medication prompts were attached to this handover."
                            />
                            <DetailList
                                id="incidents-to-note"
                                title="Incidents to note"
                                icon={ShieldAlert}
                                values={incidents}
                                emptyLabel="No incidents were flagged in this handover."
                            />
                        </div>

                        <DetailList
                            id="observations-summary"
                            title="Observations summary"
                            icon={ClipboardList}
                            values={observations}
                            emptyLabel="No structured observations were recorded."
                        />
                    </div>

                    <div className="flex min-w-0 flex-col gap-5">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Users className="size-4" />
                                    People
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <Fact label="Outgoing staff">
                                    {personName(handover.outgoing_staff)}
                                </Fact>
                                <Fact label="Incoming staff">
                                    {personName(handover.incoming_staff)}
                                </Fact>
                                <Fact label="Client">
                                    {handover.client ? (
                                        <Link
                                            href={`/operations/clients/${handover.client.id}`}
                                            className="hover:underline"
                                        >
                                            {client}
                                        </Link>
                                    ) : (
                                        client
                                    )}
                                </Fact>
                                <Fact label="Submitted by">
                                    {personName(handover.submitter)}
                                </Fact>
                                <Fact label="Acknowledged by">
                                    {personName(handover.acknowledger)}
                                </Fact>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <GitBranch className="size-4" />
                                    Shifts
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <ShiftLink
                                    label="Outgoing shift"
                                    shift={handover.outgoing_shift}
                                    fallbackStaff={handover.outgoing_staff}
                                    emptyLabel="No outgoing shift linked."
                                />
                                <ShiftLink
                                    label="Incoming shift"
                                    shift={handover.incoming_shift}
                                    fallbackStaff={handover.incoming_staff}
                                    emptyLabel="No incoming shift linked."
                                />
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
