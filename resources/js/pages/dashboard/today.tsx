import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import { show as showShift } from '@/routes/operations/shifts';
import { Head, Link } from '@inertiajs/react';
import { Calendar } from 'lucide-react';

type Shift = {
    id: number;
    status: string;
    starts_at: string;
    ends_at: string;
    actual_starts_at?: string | null;
    actual_ends_at?: string | null;
    client?: { id: number; first_name: string; last_name: string } | null;
    staff?: { id: number; name: string; email: string } | null;
};

type DueMed = {
    client_id: number;
    client_name: string;
    medication_id: number;
    medication_name: string;
    scheduled_for: string;
    state: 'due' | 'due_soon' | 'overdue' | string;
    shift_id?: number | null;
};

type OpenIncident = {
    id: number;
    client: string | null;
    type: string | null;
    severity: string | null;
    status: string;
    occurred_at: string | null;
};

type Props = {
    date: string;
    shifts: Shift[];
    dueMeds: DueMed[];
    openIncidents: OpenIncident[];
};

function fmt(dt?: string | null) {
    if (!dt) return '';
    try {
        return new Date(dt).toLocaleString();
    } catch {
        return dt;
    }
}

export default function TodayDashboard({
    date,
    shifts,
    dueMeds,
    openIncidents,
}: Props) {
    return (
        <AppLayout>
            <Head title="Today" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Calendar}
                        title="Today"
                        description={date}
                        stats={[
                            { label: 'My shifts', value: shifts.length },
                            { label: 'Due meds', value: dueMeds.length },
                            {
                                label: 'Open incidents',
                                value: openIncidents.length,
                            },
                        ]}
                    />
                }
            >
                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle>My shifts</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-semibold">
                                {shifts.length}
                            </div>
                            <div className="text-sm text-muted-foreground">
                                Scheduled for today
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Due meds</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-semibold">
                                {dueMeds.length}
                            </div>
                            <div className="text-sm text-muted-foreground">
                                Due / due soon / overdue
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Open incidents</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-semibold">
                                {openIncidents.length}
                            </div>
                            <div className="text-sm text-muted-foreground">
                                You reported (last 14 days)
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Shifts</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {shifts.length === 0 ? (
                                <div className="text-sm text-muted-foreground">
                                    No shifts today.
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    {shifts.map((s) => (
                                        <div
                                            key={s.id}
                                            className="rounded-lg border p-3"
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <div className="font-medium">
                                                    <Link
                                                        className="hover:underline"
                                                        href={showShift.url(
                                                            s.id,
                                                        )}
                                                    >
                                                        {s.client
                                                            ? `${s.client.first_name} ${s.client.last_name}`
                                                            : `Shift #${s.id}`}
                                                    </Link>
                                                </div>
                                                <Badge variant="secondary">
                                                    {s.status}
                                                </Badge>
                                            </div>
                                            <div className="mt-1 text-sm text-muted-foreground">
                                                {fmt(s.starts_at)} →{' '}
                                                {fmt(s.ends_at)}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Due medications (next 4h / recent overdue)
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {dueMeds.length === 0 ? (
                                <div className="text-sm text-muted-foreground">
                                    Nothing due right now.
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    {dueMeds.map((d, idx) => (
                                        <div
                                            key={`${d.client_id}-${d.medication_id}-${idx}`}
                                            className="rounded-lg border p-3"
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <div className="font-medium">
                                                    {d.client_name}
                                                </div>
                                                <Badge
                                                    variant={
                                                        d.state === 'overdue'
                                                            ? 'destructive'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {d.state}
                                                </Badge>
                                            </div>
                                            <div className="mt-1 text-sm">
                                                {d.medication_name}
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    · {fmt(d.scheduled_for)}
                                                </span>
                                            </div>
                                            <div className="mt-2 flex gap-2">
                                                <Link
                                                    className="text-sm underline"
                                                    href={`/clients/${d.client_id}/mar?date=${date}`}
                                                >
                                                    Open MAR
                                                </Link>
                                                {d.shift_id ? (
                                                    <>
                                                        <Separator
                                                            orientation="vertical"
                                                            className="h-4"
                                                        />
                                                        <Link
                                                            className="text-sm underline"
                                                            href={showShift.url(
                                                                d.shift_id,
                                                            )}
                                                        >
                                                            Open shift
                                                        </Link>
                                                    </>
                                                ) : null}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Open incidents you reported</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {openIncidents.length === 0 ? (
                            <div className="text-sm text-muted-foreground">
                                None.
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {openIncidents.map((i) => (
                                    <div
                                        key={i.id}
                                        className="rounded-lg border p-3"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <div className="font-medium">
                                                <Link
                                                    className="hover:underline"
                                                    href={`/incidents/${i.id}`}
                                                >
                                                    Incident #{i.id}
                                                </Link>
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    ·{' '}
                                                    {i.client ??
                                                        'Unknown client'}
                                                </span>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                {i.severity ? (
                                                    <Badge variant="outline">
                                                        {i.severity}
                                                    </Badge>
                                                ) : null}
                                                <Badge variant="secondary">
                                                    {i.status}
                                                </Badge>
                                            </div>
                                        </div>
                                        <div className="mt-1 text-sm text-muted-foreground">
                                            {i.type ?? 'Incident'} ·{' '}
                                            {fmt(i.occurred_at)}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
