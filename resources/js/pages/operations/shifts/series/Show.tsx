import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

type SeriesOccurrence = {
    id: number;
    starts_at?: string | null;
    ends_at?: string | null;
    status: string;
    user_id: number | null;
    staff?: { id: number; name: string; email?: string | null } | null;
    service_context?: { id: number; name: string; type?: string | null } | null;
    location?: string | null;
    tasks_total: number;
    tasks_completed: number;
    incidents_count: number;
    replacement?: {
        id: number;
        status: string;
        reason: string;
        requested_by?: string | null;
        current_staff?: string | null;
        replacement_staff?: string | null;
        open_position_status?: string | null;
        open_position_claimed_by?: string | null;
        expires_at?: string | null;
    } | null;
};

type Props = {
    series: {
        id: number;
        status: string;
        shift_type?: string | null;
        client?: { id: number; name: string } | null;
        staff?: { id: number; name: string; email?: string | null } | null;
        service_context?: {
            id: number;
            name: string;
            type?: string | null;
        } | null;
        location?: string | null;
        notes?: string | null;
        weekdays: string[];
        starts_time?: string | null;
        ends_time?: string | null;
        start_date?: string | null;
        end_date?: string | null;
        is_sleepover?: boolean;
        is_on_call?: boolean;
        expected_break_minutes?: number | null;
    };
    stats: {
        occurrences_total: number;
        remaining_occurrences: number;
        open_occurrences: number;
        completed_occurrences: number;
        cancelled_occurrences: number;
        active_replacements: number;
    };
    nextOccurrence?: SeriesOccurrence | null;
    upcomingOccurrences: SeriesOccurrence[];
    recentOccurrences: SeriesOccurrence[];
    coverageAlignment: {
        linked_rule_issues: Array<{
            site_id: number;
            site_name: string;
            rule_name: string;
            window_label: string;
            starts_at?: string | null;
            ends_at?: string | null;
            required_staff: number;
            assigned_staff: number;
            open_shifts: number;
            missing_staff: number;
            unfilled_after_open_shifts?: number;
            issue_type: string;
        }>;
        orphan_series?: {
            series_id: number;
            site_id?: number | null;
            site_name: string;
            issue_type: string;
        } | null;
    };
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

function statusBadgeVariant(status: string): BadgeVariant {
    if (status === 'completed') return 'secondary';
    if (status === 'cancelled') return 'destructive';
    if (status === 'in_progress') return 'default';
    return 'outline';
}

function replacementBadgeVariant(status?: string | null): BadgeVariant {
    if (status === 'claimed') return 'default';
    if (status === 'approved') return 'secondary';
    if (status === 'cancelled') return 'destructive';
    return 'outline';
}

function formatDateTime(value?: string | null) {
    if (!value) return 'Not scheduled';

    return new Date(value).toLocaleString('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatTimeRange(
    startsAt?: string | null,
    endsAt?: string | null,
): string {
    if (!startsAt || !endsAt) return 'Time not set';

    const start = new Date(startsAt);
    const end = new Date(endsAt);

    return `${start.toLocaleDateString('en-NZ', {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
    })} · ${start.toLocaleTimeString('en-NZ', {
        hour: '2-digit',
        minute: '2-digit',
    })}-${end.toLocaleTimeString('en-NZ', {
        hour: '2-digit',
        minute: '2-digit',
    })}`;
}

function seriesTimeLabel(startsTime?: string | null, endsTime?: string | null) {
    if (!startsTime || !endsTime) return '';
    const overnight = endsTime <= startsTime;
    return `${startsTime}-${endsTime}${overnight ? ' overnight' : ''}`;
}

export default function ShiftSeriesShow({
    series,
    stats,
    nextOccurrence,
    upcomingOccurrences,
    recentOccurrences,
    coverageAlignment,
    canManageAny,
}: Props) {
    const hasActionNeeded =
        stats.open_occurrences > 0 || stats.active_replacements > 0;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Rostering', href: '/operations/rostering' },
                {
                    title: 'Recurring series',
                    href: '/operations/shifts/series',
                },
                {
                    title: series.client?.name ?? `Series #${series.id}`,
                    href: `/operations/shifts/series/${series.id}`,
                },
            ]}
        >
            <Head
                title={`Recurring series - ${series.client?.name ?? series.id}`}
            />
            <PageShell>
                <PageHero
                    variant="compact"
                    title={series.client?.name ?? 'Recurring support series'}
                    description="Operational view of this recurring shift pattern, including open occurrences and active replacement workflows."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/operations/shifts/series">
                                    All series
                                </Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href="/operations/rostering">
                                    Rostering
                                </Link>
                            </Button>
                            {nextOccurrence ? (
                                <>
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={`/operations/shifts/${nextOccurrence.id}`}
                                        >
                                            Open next shift
                                        </Link>
                                    </Button>
                                    {canManageAny ? (
                                        <Button asChild>
                                            <Link
                                                href={`/operations/shifts/${nextOccurrence.id}`}
                                            >
                                                Open future
                                            </Link>
                                        </Button>
                                    ) : null}
                                </>
                            ) : null}
                            {canManageAny && stats.remaining_occurrences > 0 ? (
                                <Button
                                    variant="destructive"
                                    onClick={() => {
                                        if (
                                            !confirm(
                                                'Cancel all future active occurrences in this recurring series?',
                                            )
                                        ) {
                                            return;
                                        }

                                        router.patch(
                                            `/operations/shifts/series/${series.id}/cancel-future`,
                                            {},
                                            { preserveScroll: true },
                                        );
                                    }}
                                >
                                    Cancel future occurrences
                                </Button>
                            ) : null}
                        </div>
                    }
                />
                <Card className="border-border/60 bg-card/70">
                    <CardContent className="grid gap-4 p-5 lg:grid-cols-[1.1fr_0.9fr]">
                        <div className="space-y-3">
                            <div className="flex flex-wrap gap-2">
                                <Badge
                                    variant={
                                        series.status === 'cancelled'
                                            ? 'destructive'
                                            : 'secondary'
                                    }
                                    className="capitalize"
                                >
                                    {series.status}
                                </Badge>
                                <Badge variant="outline">
                                    {shiftTypeLabel(series.shift_type)}
                                </Badge>
                                {series.is_sleepover ? (
                                    <Badge variant="outline">Sleepover</Badge>
                                ) : null}
                                {series.is_on_call ? (
                                    <Badge variant="outline">On-call</Badge>
                                ) : null}
                                {series.expected_break_minutes ? (
                                    <Badge variant="outline">
                                        Break {series.expected_break_minutes}{' '}
                                        min
                                    </Badge>
                                ) : null}
                            </div>

                            <div className="space-y-1">
                                <div className="text-sm font-medium text-foreground">
                                    {series.weekdays
                                        .map(weekdayLabel)
                                        .join(', ')}
                                    {series.starts_time && series.ends_time
                                        ? ` · ${seriesTimeLabel(series.starts_time, series.ends_time)}`
                                        : ''}
                                </div>
                                <div className="text-sm text-muted-foreground">
                                    {series.service_context?.name ??
                                        'No service context'}
                                    {series.location
                                        ? ` · ${series.location}`
                                        : ''}
                                    {series.staff
                                        ? ` · Primary staff ${series.staff.name}`
                                        : ' · Open recurring pattern'}
                                </div>
                                <div className="text-sm text-muted-foreground">
                                    Range {series.start_date ?? '—'} to{' '}
                                    {series.end_date ?? '—'}
                                </div>
                            </div>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            {/* eslint-disable-next-line no-restricted-syntax -- Summary tiles are nested inside the series Card content. */}
                            <div className="rounded-xl border bg-background/80 p-4">
                                <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                    Next occurrence
                                </div>
                                <div className="mt-2 text-sm font-medium">
                                    {nextOccurrence
                                        ? formatTimeRange(
                                              nextOccurrence.starts_at,
                                              nextOccurrence.ends_at,
                                          )
                                        : 'No future occurrence'}
                                </div>
                            </div>
                            {/* eslint-disable-next-line no-restricted-syntax -- Summary tiles are nested inside the series Card content. */}
                            <div className="rounded-xl border bg-background/80 p-4">
                                <div className="text-xs tracking-wide text-muted-foreground uppercase">
                                    Action needed
                                </div>
                                <div className="mt-2 text-sm font-medium">
                                    {hasActionNeeded
                                        ? `${stats.open_occurrences} open, ${stats.active_replacements} replacement`
                                        : 'Nothing urgent'}
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div className="mt-4 grid gap-3 md:grid-cols-4">
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-muted-foreground uppercase">
                                Total
                            </div>
                            <div className="mt-1 text-2xl font-semibold">
                                {stats.occurrences_total}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-muted-foreground uppercase">
                                Remaining
                            </div>
                            <div className="mt-1 text-2xl font-semibold">
                                {stats.remaining_occurrences}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-muted-foreground uppercase">
                                Open
                            </div>
                            <div className="mt-1 text-2xl font-semibold">
                                {stats.open_occurrences}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-muted-foreground uppercase">
                                Replacements
                            </div>
                            <div className="mt-1 text-2xl font-semibold">
                                {stats.active_replacements}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="mt-4 grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
                    <div className="space-y-4">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Pattern summary
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="grid gap-3 md:grid-cols-2">
                                    <div className="rounded-xl border p-3">
                                        <div className="text-xs text-muted-foreground uppercase">
                                            Client
                                        </div>
                                        <div className="mt-1 font-medium">
                                            {series.client?.name ??
                                                'Not linked'}
                                        </div>
                                    </div>
                                    <div className="rounded-xl border p-3">
                                        <div className="text-xs text-muted-foreground uppercase">
                                            Service
                                        </div>
                                        <div className="mt-1 font-medium">
                                            {series.service_context?.name ??
                                                'Not set'}
                                        </div>
                                    </div>
                                    <div className="rounded-xl border p-3">
                                        <div className="text-xs text-muted-foreground uppercase">
                                            Assigned staff
                                        </div>
                                        <div className="mt-1 font-medium">
                                            {series.staff?.name ??
                                                'Recurring open shift'}
                                        </div>
                                    </div>
                                    <div className="rounded-xl border p-3">
                                        <div className="text-xs text-muted-foreground uppercase">
                                            Location
                                        </div>
                                        <div className="mt-1 font-medium">
                                            {series.location ?? 'Not set'}
                                        </div>
                                    </div>
                                </div>

                                {series.notes ? (
                                    <div className="rounded-xl border p-3">
                                        <div className="text-xs text-muted-foreground uppercase">
                                            Pattern notes
                                        </div>
                                        <div className="mt-2 text-sm whitespace-pre-wrap text-foreground">
                                            {series.notes}
                                        </div>
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Upcoming occurrences
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {upcomingOccurrences.length === 0 ? (
                                    <div className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                                        No future occurrences scheduled.
                                    </div>
                                ) : (
                                    upcomingOccurrences.map((occurrence) => (
                                        <div
                                            key={occurrence.id}
                                            className="rounded-xl border p-4"
                                        >
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <div className="space-y-1">
                                                    <div className="text-sm font-medium">
                                                        {formatTimeRange(
                                                            occurrence.starts_at,
                                                            occurrence.ends_at,
                                                        )}
                                                    </div>
                                                    <div className="text-sm text-muted-foreground">
                                                        {occurrence.staff
                                                            ?.name ??
                                                            'Unassigned'}
                                                        {occurrence
                                                            .service_context
                                                            ?.name
                                                            ? ` · ${occurrence.service_context.name}`
                                                            : ''}
                                                        {occurrence.location
                                                            ? ` · ${occurrence.location}`
                                                            : ''}
                                                    </div>
                                                </div>

                                                <div className="flex flex-wrap gap-2">
                                                    <Badge
                                                        variant={statusBadgeVariant(
                                                            occurrence.status,
                                                        )}
                                                        className="capitalize"
                                                    >
                                                        {occurrence.status.replace(
                                                            /_/g,
                                                            ' ',
                                                        )}
                                                    </Badge>
                                                    {occurrence.tasks_total >
                                                    0 ? (
                                                        <Badge variant="outline">
                                                            Tasks{' '}
                                                            {
                                                                occurrence.tasks_completed
                                                            }
                                                            /
                                                            {
                                                                occurrence.tasks_total
                                                            }
                                                        </Badge>
                                                    ) : null}
                                                    {occurrence.incidents_count >
                                                    0 ? (
                                                        <Badge variant="destructive">
                                                            Incidents{' '}
                                                            {
                                                                occurrence.incidents_count
                                                            }
                                                        </Badge>
                                                    ) : null}
                                                </div>
                                            </div>

                                            {occurrence.replacement ? (
                                                <div className="mt-3 rounded-xl border bg-muted/30 p-3 text-sm">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <div className="font-medium">
                                                            Replacement in
                                                            progress
                                                        </div>
                                                        <Badge
                                                            variant={replacementBadgeVariant(
                                                                occurrence
                                                                    .replacement
                                                                    .status,
                                                            )}
                                                            className="capitalize"
                                                        >
                                                            {
                                                                occurrence
                                                                    .replacement
                                                                    .status
                                                            }
                                                        </Badge>
                                                        {occurrence.replacement
                                                            .open_position_status ? (
                                                            <Badge variant="outline">
                                                                Job board{' '}
                                                                {
                                                                    occurrence
                                                                        .replacement
                                                                        .open_position_status
                                                                }
                                                            </Badge>
                                                        ) : null}
                                                    </div>
                                                    <div className="mt-2 text-muted-foreground">
                                                        {
                                                            occurrence
                                                                .replacement
                                                                .reason
                                                        }
                                                    </div>
                                                </div>
                                            ) : null}

                                            <div className="mt-3 flex flex-wrap gap-2">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/operations/shifts/${occurrence.id}`}
                                                    >
                                                        Open shift
                                                    </Link>
                                                </Button>
                                                {canManageAny ? (
                                                    <Button size="sm" asChild>
                                                        <Link
                                                            href={`/operations/shifts/${occurrence.id}`}
                                                        >
                                                            Open occurrence
                                                        </Link>
                                                    </Button>
                                                ) : null}
                                                {canManageAny &&
                                                occurrence.status !==
                                                    'cancelled' &&
                                                occurrence.status !==
                                                    'completed' ? (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            router.patch(
                                                                `/operations/shifts/${occurrence.id}/cancel`,
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Cancel occurrence
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </div>
                                    ))
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <div className="space-y-4">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Action needed
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="flex items-center justify-between rounded-xl border p-3">
                                    <span className="text-muted-foreground">
                                        Open occurrences
                                    </span>
                                    <Badge
                                        variant={
                                            stats.open_occurrences > 0
                                                ? 'default'
                                                : 'outline'
                                        }
                                    >
                                        {stats.open_occurrences}
                                    </Badge>
                                </div>
                                <div className="flex items-center justify-between rounded-xl border p-3">
                                    <span className="text-muted-foreground">
                                        Active replacements
                                    </span>
                                    <Badge
                                        variant={
                                            stats.active_replacements > 0
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                    >
                                        {stats.active_replacements}
                                    </Badge>
                                </div>
                                <div className="flex items-center justify-between rounded-xl border p-3">
                                    <span className="text-muted-foreground">
                                        Completed
                                    </span>
                                    <Badge variant="outline">
                                        {stats.completed_occurrences}
                                    </Badge>
                                </div>
                                <div className="flex items-center justify-between rounded-xl border p-3">
                                    <span className="text-muted-foreground">
                                        Cancelled
                                    </span>
                                    <Badge
                                        variant={
                                            stats.cancelled_occurrences > 0
                                                ? 'destructive'
                                                : 'outline'
                                        }
                                    >
                                        {stats.cancelled_occurrences}
                                    </Badge>
                                </div>
                                {coverageAlignment.linked_rule_issues.length >
                                0 ? (
                                    <div className="rounded-xl border border-status-critical/30 bg-status-critical-bg p-3">
                                        <div className="text-xs font-semibold tracking-wide text-status-critical uppercase">
                                            Coverage drift
                                        </div>
                                        <div className="mt-1 text-sm text-status-critical">
                                            This recurring series is linked to
                                            demand windows that are still short.
                                        </div>
                                        <div className="mt-2 space-y-2">
                                            {coverageAlignment.linked_rule_issues
                                                .slice(0, 3)
                                                .map((issue, index) => (
                                                    // eslint-disable-next-line no-restricted-syntax -- Coverage issue rows live inside an alert panel, not as standalone Cards.
                                                    <div
                                                        key={`${issue.rule_name}-${index}`}
                                                        className="rounded-lg border border-status-critical/30 bg-white/80 p-3"
                                                    >
                                                        <div className="text-sm font-medium text-foreground">
                                                            {issue.rule_name}
                                                        </div>
                                                        <div className="mt-1 text-xs text-muted-foreground">
                                                            {issue.window_label}
                                                        </div>
                                                        <div className="mt-1 text-xs text-status-critical">
                                                            Missing{' '}
                                                            {
                                                                issue.missing_staff
                                                            }{' '}
                                                            assigned staff
                                                            {issue.unfilled_after_open_shifts &&
                                                            issue.unfilled_after_open_shifts >
                                                                0
                                                                ? ` · ${issue.unfilled_after_open_shifts} still need new cover shifts`
                                                                : ''}
                                                        </div>
                                                    </div>
                                                ))}
                                        </div>
                                    </div>
                                ) : null}
                                {coverageAlignment.orphan_series ? (
                                    <div className="rounded-xl border border-status-warning/30 bg-status-warning-bg p-3">
                                        <div className="text-xs font-semibold tracking-wide text-status-warning uppercase">
                                            Demand mismatch
                                        </div>
                                        <div className="mt-1 text-sm text-status-warning">
                                            This recurring series no longer has
                                            a matching active coverage rule for{' '}
                                            {
                                                coverageAlignment.orphan_series
                                                    .site_name
                                            }
                                            .
                                        </div>
                                        {coverageAlignment.orphan_series
                                            .site_id ? (
                                            <div className="mt-3">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/sites/${coverageAlignment.orphan_series.site_id}`}
                                                    >
                                                        Open site rules
                                                    </Link>
                                                </Button>
                                            </div>
                                        ) : null}
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Recent history
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {recentOccurrences.length === 0 ? (
                                    <div className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                                        No completed or historical occurrences
                                        yet.
                                    </div>
                                ) : (
                                    recentOccurrences.map((occurrence) => (
                                        <div
                                            key={occurrence.id}
                                            className="rounded-xl border p-3"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <div className="text-sm font-medium">
                                                        {formatDateTime(
                                                            occurrence.starts_at,
                                                        )}
                                                    </div>
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        {occurrence.staff
                                                            ?.name ??
                                                            'Unassigned'}
                                                        {occurrence.location
                                                            ? ` · ${occurrence.location}`
                                                            : ''}
                                                    </div>
                                                </div>
                                                <Badge
                                                    variant={statusBadgeVariant(
                                                        occurrence.status,
                                                    )}
                                                    className="capitalize"
                                                >
                                                    {occurrence.status.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </Badge>
                                            </div>

                                            <div className="mt-2 flex flex-wrap gap-2">
                                                {occurrence.tasks_total > 0 ? (
                                                    <Badge variant="outline">
                                                        Tasks{' '}
                                                        {
                                                            occurrence.tasks_completed
                                                        }
                                                        /
                                                        {occurrence.tasks_total}
                                                    </Badge>
                                                ) : null}
                                                {occurrence.incidents_count >
                                                0 ? (
                                                    <Badge variant="destructive">
                                                        Incidents{' '}
                                                        {
                                                            occurrence.incidents_count
                                                        }
                                                    </Badge>
                                                ) : null}
                                            </div>

                                            <div className="mt-3">
                                                <div className="flex flex-wrap gap-2">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={`/operations/shifts/${occurrence.id}`}
                                                        >
                                                            Open shift
                                                        </Link>
                                                    </Button>
                                                    {canManageAny &&
                                                    occurrence.status ===
                                                        'cancelled' ? (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                router.patch(
                                                                    `/operations/shifts/${occurrence.id}/reopen`,
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Reopen occurrence
                                                        </Button>
                                                    ) : null}
                                                </div>
                                            </div>
                                        </div>
                                    ))
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
