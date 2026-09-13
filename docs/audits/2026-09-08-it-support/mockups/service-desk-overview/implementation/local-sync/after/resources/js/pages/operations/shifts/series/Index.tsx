import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Repeat } from 'lucide-react';
import { useMemo, useState } from 'react';

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
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');

    const rows = useMemo(() => series.data ?? [], [series.data]);
    const openTotal = rows.reduce(
        (acc, row) => acc + (row.open_occurrences_count ?? 0),
        0,
    );

    const statusOptions = useMemo(
        () => [
            { value: 'all', label: 'All statuses' },
            ...Array.from(new Set(rows.map((row) => row.status)))
                .sort()
                .map((status) => ({
                    value: status,
                    label:
                        status.charAt(0).toUpperCase() +
                        status.slice(1).replace(/_/g, ' '),
                })),
        ],
        [rows],
    );

    const shown = useMemo(() => {
        const q = search.trim().toLowerCase();
        return rows.filter((row) => {
            if (statusFilter !== 'all' && row.status !== statusFilter)
                return false;
            if (q) {
                const hay = `${row.client?.name ?? ''} ${
                    row.staff?.name ?? ''
                } ${row.location ?? ''} ${
                    row.service_context?.name ?? ''
                }`.toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });
    }, [rows, search, statusFilter]);

    const header = (
        <PageHeader
            variant="profile"
            backHref="/operations/rostering"
            icon={Repeat}
            title="Recurring series"
            titleChip={
                openTotal > 0 ? (
                    <PageHeaderStatusChip variant="warning">
                        {openTotal} open occurrences
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="success">
                        All covered
                    </PageHeaderStatusChip>
                )
            }
            subline="Recurring roster patterns · open occurrences and replacements at a glance"
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search series…"
                    />
                    {canManageAny ? (
                        <PageHeaderPrimaryButton
                            icon={CheckCircle2}
                            onClick={() =>
                                router.visit(
                                    '/operations/shifts?create=1&repeat_weekly=1',
                                )
                            }
                        >
                            New recurring shift
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    icon={Repeat}
                    label="All statuses"
                    value={statusFilter}
                    options={statusOptions}
                    onChange={setStatusFilter}
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                { title: 'Rostering', href: '/operations/rostering' },
                {
                    title: 'Recurring series',
                    href: '/operations/shifts/series',
                },
            ]}
        >
            <Head title="Recurring series" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Recurring series"
                        caption={`${shown.length} of ${rows.length} on this page shown`}
                    />

                    <div className="grid gap-4">
                        {shown.length > 0 ? (
                            shown.map((row) => (
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
                                                {row.starts_time &&
                                                row.ends_time
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
                                            <StatusBadge
                                                variant={
                                                    row.status === 'cancelled'
                                                        ? 'critical'
                                                        : row.status ===
                                                            'active'
                                                          ? 'success'
                                                          : 'neutral'
                                                }
                                                className="capitalize"
                                            >
                                                {row.status}
                                            </StatusBadge>
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
                                                    {row.service_context
                                                        ?.name ?? 'Not set'}
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
                                                          ).toLocaleString(
                                                              'en-NZ',
                                                          )
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
                                                <StatusBadge variant="warning">
                                                    Open:{' '}
                                                    {row.open_occurrences_count}
                                                </StatusBadge>
                                            ) : null}
                                            {row.replacement_occurrences_count >
                                            0 ? (
                                                <StatusBadge variant="info">
                                                    Replacements:{' '}
                                                    {
                                                        row.replacement_occurrences_count
                                                    }
                                                </StatusBadge>
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
                            <EmptyState
                                icon={Repeat}
                                title="No recurring series"
                                description={
                                    search.trim() !== '' ||
                                    statusFilter !== 'all'
                                        ? 'No series match your search or filter.'
                                        : 'No recurring series yet.'
                                }
                            />
                        )}
                    </div>

                    {series.links && series.links.length > 3 ? (
                        <div className="flex flex-wrap gap-2">
                            {series.links.map((link, index) => (
                                <Button
                                    key={`shift-series-link-${index}`}
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
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
                </div>
            </PageLayout>
        </AppLayout>
    );
}
