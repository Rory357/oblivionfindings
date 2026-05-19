import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';

type SeriesRow = {
    id: number;
    status: string;
    shift_type?: string | null;
    client: { id: number; name: string } | null;
    staff: { id: number; name: string } | null;
    service_context: { id: number; name: string; type?: string | null } | null;
    location?: string | null;
    weekdays: string[];
    starts_time?: string | null;
    ends_time?: string | null;
    is_sleepover?: boolean;
    is_on_call?: boolean;
    start_date?: string | null;
    end_date?: string | null;
    occurrences_total: number;
    active_occurrences_count: number;
    open_occurrences_count: number;
    replacement_occurrences_count: number;
    next_starts_at?: string | null;
};

type Pagination<T> = {
    data: T[];
    links?: Array<{ url: string | null; label: string; active: boolean }>;
};

type Props = {
    series: Pagination<SeriesRow>;
    canManageAny: boolean;
};

function weekdayLabel(code: string) {
    const labels: Record<string, string> = {
        mon: 'Mon',
        tue: 'Tue',
        wed: 'Wed',
        thu: 'Thu',
        fri: 'Fri',
        sat: 'Sat',
        sun: 'Sun',
    };

    return labels[code] ?? code;
}

function shiftTypeLabel(value?: string | null) {
    return (value ?? 'standard').replace(/_/g, ' ');
}

function seriesTimeLabel(startsTime?: string | null, endsTime?: string | null) {
    if (!startsTime || !endsTime) {
        return '';
    }

    const overnight = endsTime <= startsTime;

    return `${startsTime}–${endsTime}${overnight ? ' overnight' : ''}`;
}

export default function ShiftSeriesIndex({ series, canManageAny }: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Rostering', href: '/operations/rostering' },
                {
                    title: 'Recurring series',
                    href: '/operations/shifts/series',
                },
            ]}
        >
            <Head title="Recurring series" />
            <PageShell>
                <PageHero variant="compact"
                    title="Recurring series"
                    description="Review recurring roster patterns, open occurrences, and recurring shifts that need attention."
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/operations/rostering">
                                    Back to rostering
                                </Link>
                            </Button>
                            {canManageAny ? (
                                <Button asChild>
                                    <Link href="/operations/shifts/create">
                                        New recurring shift
                                    </Link>
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                <div className="grid gap-4">
                    {series.data.length > 0 ? (
                        series.data.map((row) => (
                            <Card key={row.id}>
                                <CardHeader className="flex flex-row items-start justify-between gap-3">
                                    <div>
                                        <CardTitle className="text-base">
                                            <Link
                                                href={`/operations/shifts/series/${row.id}`}
                                                className="hover:underline"
                                            >
                                                {row.client?.name ??
                                                    'Recurring support series'}
                                            </Link>
                                        </CardTitle>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {row.weekdays
                                                .map(weekdayLabel)
                                                .join(', ')}
                                            {row.starts_time && row.ends_time
                                                ? ` · ${seriesTimeLabel(row.starts_time, row.ends_time)}`
                                                : ''}
                                            {row.location
                                                ? ` · ${row.location}`
                                                : ''}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Badge variant="outline">
                                            {shiftTypeLabel(row.shift_type)}
                                        </Badge>
                                        <Badge
                                            variant={
                                                row.status === 'cancelled'
                                                    ? 'destructive'
                                                    : 'secondary'
                                            }
                                            className="capitalize"
                                        >
                                            {row.status}
                                        </Badge>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-3 text-sm">
                                    <div className="flex flex-wrap gap-4 text-muted-foreground">
                                        <span>
                                            Staff:{' '}
                                            <span className="font-medium text-foreground">
                                                {row.staff?.name ??
                                                    'Unassigned pattern'}
                                            </span>
                                        </span>
                                        <span>
                                            Service:{' '}
                                            <span className="font-medium text-foreground">
                                                {row.service_context?.name ??
                                                    'Not set'}
                                            </span>
                                        </span>
                                        <span>
                                            Range:{' '}
                                            <span className="font-medium text-foreground">
                                                {row.start_date ?? '—'} to{' '}
                                                {row.end_date ?? '—'}
                                            </span>
                                        </span>
                                        <span>
                                            Next:{' '}
                                            <span className="font-medium text-foreground">
                                                {row.next_starts_at
                                                    ? new Date(
                                                          row.next_starts_at,
                                                      ).toLocaleString('en-NZ')
                                                    : 'No future occurrence'}
                                            </span>
                                        </span>
                                    </div>

                                    <div className="flex flex-wrap gap-2">
                                        <Badge variant="outline">
                                            Total: {row.occurrences_total}
                                        </Badge>
                                        <Badge variant="outline">
                                            Active:{' '}
                                            {row.active_occurrences_count}
                                        </Badge>
                                        {row.open_occurrences_count > 0 ? (
                                            <Badge variant="default">
                                                Open:{' '}
                                                {row.open_occurrences_count}
                                            </Badge>
                                        ) : null}
                                        {row.replacement_occurrences_count >
                                        0 ? (
                                            <Badge variant="secondary">
                                                Replacements:{' '}
                                                {
                                                    row.replacement_occurrences_count
                                                }
                                            </Badge>
                                        ) : null}
                                        {row.is_sleepover ? (
                                            <Badge variant="outline">
                                                Sleepover
                                            </Badge>
                                        ) : null}
                                        {row.is_on_call ? (
                                            <Badge variant="outline">
                                                On-call
                                            </Badge>
                                        ) : null}
                                    </div>

                                    <div className="flex gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={`/operations/shifts/series/${row.id}`}
                                            >
                                                View series
                                            </Link>
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ))
                    ) : (
                        <Card>
                            <CardContent className="py-10 text-center text-sm text-muted-foreground">
                                No recurring series yet.
                            </CardContent>
                        </Card>
                    )}
                </div>

                {series.links && series.links.length > 3 ? (
                    <div className="mt-4 flex flex-wrap gap-2">
                        {series.links.map((link, index) => (
                            <Button
                                key={`shift-series-link-${index}`}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                asChild={!!link.url}
                            >
                                {link.url ? (
                                    <Link
                                        href={link.url}
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                ) : (
                                    <span
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                )}
                            </Button>
                        ))}
                    </div>
                ) : null}
            </PageShell>
        </AppLayout>
    );
}
