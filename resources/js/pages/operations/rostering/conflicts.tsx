import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';

type ShiftRow = {
    id: number;
    client_name: string;
    staff_name?: string | null;
    service_context?: string | null;
    status: string;
    shift_type?: string | null;
    location?: string | null;
    starts_at?: string | null;
    ends_at?: string | null;
    shift_series_id?: number | null;
};

type Props = {
    weekStart: string;
    weekEnd: string;
    staffOverlaps: Array<{
        staff_id: number;
        staff_name: string;
        first: ShiftRow;
        second: ShiftRow;
    }>;
    clientOverlaps: Array<{
        client_id: number;
        client_name: string;
        first: ShiftRow;
        second: ShiftRow;
    }>;
    timeOffConflicts: Array<{
        shift: ShiftRow;
        time_off: {
            id: number;
            user_name: string;
            type: string;
            label?: string | null;
            starts_at?: string | null;
            ends_at?: string | null;
        };
    }>;
    tightTurnarounds: Array<{
        staff_id: number;
        staff_name: string;
        gap_minutes: number;
        first: ShiftRow;
        second: ShiftRow;
    }>;
    openShifts: ShiftRow[];
    activeReplacements: Array<{
        id: number;
        shift: ShiftRow;
        status: string;
        reason: string;
        requested_by?: string | null;
        current_staff?: string | null;
        replacement_staff?: string | null;
        claimed_by?: string | null;
        open_position_id?: number | null;
    }>;
    coverageGaps: Array<{
        site_id: number;
        site_name: string;
        rule_id?: number;
        coverage_window_key?: string | null;
        rule_name: string;
        window_label: string;
        starts_at?: string;
        ends_at?: string;
        required_staff: number;
        assigned_staff: number;
        planned_staff?: number;
        missing_staff: number;
        preferred_client_id?: number | null;
        role_shortages?: Array<{
            key: string;
            label?: string | null;
            missing?: number;
        }>;
        planned_role_shortages?: Array<{
            key: string;
            label?: string | null;
            missing?: number;
        }>;
        unfilled_after_open_shifts?: number;
        coverage_state: string;
        planned_coverage_state?: string;
        gap_kind?: string | null;
        recommended_fill_action?: string | null;
        contradictions?: string[];
        partial_window_uncovered_slices?: Array<{
            starts_at: string;
            ends_at: string;
            missing_staff?: number;
        }>;
        acknowledgement?: {
            state: 'acked' | 'dismissed';
            actor?: { id: number; name?: string | null } | null;
            reason?: string | null;
            since?: string | null;
        } | null;
        open_shift_ids?: number[];
        contributing_shifts?: ShiftRow[];
        matching_series?: Array<{
            id: number;
            client_name?: string | null;
            staff_name?: string | null;
            service_context_name?: string | null;
            shift_type?: string | null;
            weekdays: string[];
            starts_time?: string | null;
            ends_time?: string | null;
            location?: string | null;
            next_starts_at?: string | null;
            active_occurrences_count?: number;
            open_occurrences_count?: number;
        }>;
    }>;
    recurringCoverageAlignment: {
        rule_drift: Array<{
            site_id: number;
            site_name: string;
            rule_id?: number;
            rule_name: string;
            window_label: string;
            starts_at?: string;
            ends_at?: string;
            required_staff: number;
            assigned_staff: number;
            open_shifts: number;
            missing_staff: number;
            unfilled_after_open_shifts?: number;
            issue_type: string;
            matching_series?: Array<{
                id: number;
                client_name?: string | null;
                weekdays: string[];
                starts_time?: string | null;
                ends_time?: string | null;
            }>;
        }>;
        orphan_series: Array<{
            series_id: number;
            site_id?: number | null;
            site_name: string;
            client_name?: string | null;
            staff_name?: string | null;
            service_context_name?: string | null;
            shift_type?: string | null;
            weekdays: string[];
            starts_time?: string | null;
            ends_time?: string | null;
            next_starts_at?: string | null;
            active_occurrences_count?: number;
            issue_type: string;
        }>;
    };
};

function formatWindow(startsAt?: string | null, endsAt?: string | null) {
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

function shiftTypeLabel(value?: string | null) {
    return String(value ?? 'standard').replace(/_/g, ' ');
}

function coverageRolesForAction(gap: {
    planned_role_shortages?: Array<{
        key: string;
        label?: string | null;
        missing?: number;
    }>;
    role_shortages?: Array<{
        key: string;
        label?: string | null;
        missing?: number;
    }>;
}) {
    return (
        (gap.planned_role_shortages?.length
            ? gap.planned_role_shortages
            : gap.role_shortages) ?? []
    );
}

function gapKindLabel(kind?: string | null) {
    switch (kind) {
        case 'headcount_open':
            return 'Open shift gap';
        case 'headcount_unplanned':
            return 'Unplanned headcount gap';
        case 'role_open':
            return 'Open role gap';
        case 'role_unplanned':
            return 'Unplanned role gap';
        case 'mixed_open':
            return 'Open shift + role gap';
        case 'mixed_unplanned':
            return 'Headcount + role gap';
        case 'overfill_not_allowed':
            return 'Overfill not allowed';
        case 'overfilled_wrong_role_mix':
            return 'Overfilled role imbalance';
        case 'overfill_and_role_imbalance':
            return 'Overfill + role imbalance';
        default:
            return 'Coverage gap';
    }
}

function fillActionLabel(action?: string | null) {
    switch (action) {
        case 'fill_existing_open_shift':
            return 'Fill existing open shift';
        case 'retag_or_replace_open_shift':
            return 'Retag or replace open shift';
        case 'create_role_specific_shift':
            return 'Create role-specific cover';
        case 'create_recurring_cover':
            return 'Create recurring cover';
        case 'review_existing_supply':
            return 'Review existing supply';
        case 'rebalance_existing_supply':
            return 'Rebalance existing supply';
        default:
            return 'Create cover shift';
    }
}

function shouldOfferCreation(action?: string | null) {
    return !['review_existing_supply', 'rebalance_existing_supply'].includes(
        action ?? '',
    );
}

function formatCoverageSlice(startsAt?: string | null, endsAt?: string | null) {
    if (!startsAt || !endsAt) return 'Partial window';
    const start = new Date(startsAt);
    const end = new Date(endsAt);

    return `${start.toLocaleTimeString('en-NZ', {
        hour: '2-digit',
        minute: '2-digit',
    })}-${end.toLocaleTimeString('en-NZ', {
        hour: '2-digit',
        minute: '2-digit',
    })}`;
}

function ShiftSummary({ shift }: { shift: ShiftRow }) {
    return (
        <div className="rounded-xl border p-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <div className="text-sm font-medium">
                        {shift.client_name}
                    </div>
                    <div className="mt-1 text-xs text-muted-foreground">
                        {formatWindow(shift.starts_at, shift.ends_at)}
                    </div>
                </div>
                <Badge variant="outline" className="capitalize">
                    {shiftTypeLabel(shift.shift_type)}
                </Badge>
            </div>
            <div className="mt-2 text-xs text-muted-foreground">
                {shift.staff_name ?? 'Unassigned'}
                {shift.service_context ? ` · ${shift.service_context}` : ''}
                {shift.location ? ` · ${shift.location}` : ''}
            </div>
            <div className="mt-3 flex flex-wrap gap-2">
                <Button size="sm" variant="outline" asChild>
                    <Link href={`/operations/shifts/${shift.id}`}>
                        Open shift
                    </Link>
                </Button>
                {shift.shift_series_id ? (
                    <Button size="sm" variant="outline" asChild>
                        <Link
                            href={`/operations/shifts/series/${shift.shift_series_id}`}
                        >
                            Series
                        </Link>
                    </Button>
                ) : null}
            </div>
        </div>
    );
}

export default function RosteringConflicts({
    weekStart,
    weekEnd,
    staffOverlaps,
    clientOverlaps,
    timeOffConflicts,
    tightTurnarounds,
    openShifts,
    activeReplacements,
    coverageGaps,
    recurringCoverageAlignment,
}: Props) {
    const returnTo = `/operations/rostering/conflicts?week=${encodeURIComponent(weekStart)}`;
    const buildCoverageCreateHref = (
        gap: {
            site_id: number;
            rule_id?: number;
            coverage_window_key?: string | null;
            rule_name: string;
            starts_at?: string;
            ends_at?: string;
            required_staff: number;
            missing_staff: number;
            preferred_client_id?: number | null;
            role_shortages?: Array<{
                key: string;
                label?: string | null;
                missing?: number;
            }>;
            planned_role_shortages?: Array<{
                key: string;
                label?: string | null;
                missing?: number;
            }>;
        },
        options?: { openShift?: boolean; repeatWeekly?: boolean },
        reservationToken?: string | null,
    ) => {
        const params = new URLSearchParams();
        params.set('site_id', String(gap.site_id));
        if (gap.rule_id) params.set('coverage_rule_id', String(gap.rule_id));
        if (gap.starts_at) params.set('starts_at', gap.starts_at);
        if (gap.ends_at) params.set('ends_at', gap.ends_at);
        if (gap.preferred_client_id) {
            params.set('client_id', String(gap.preferred_client_id));
        }
        params.set('coverage_rule_name', gap.rule_name);
        params.set('coverage_required_staff', String(gap.required_staff));
        params.set('coverage_missing_staff', String(gap.missing_staff));
        const actionRoles = coverageRolesForAction(gap);
        if (actionRoles.length > 0) {
            params.set('coverage_role_shortages', JSON.stringify(actionRoles));
        }
        params.set('return_to', returnTo);
        if (reservationToken) {
            params.set('coverage_reservation_token', reservationToken);
        }
        if (options?.openShift) params.set('open_shift', '1');
        if (options?.repeatWeekly) {
            params.set('repeat_weekly', '1');
            if (gap.starts_at) {
                const repeatEnd = new Date(gap.starts_at);
                repeatEnd.setDate(repeatEnd.getDate() + 28);
                params.set(
                    'repeat_end_date',
                    repeatEnd.toISOString().slice(0, 10),
                );
            }
        }

        return `/operations/shifts/create?${params.toString()}`;
    };
    const coverageRoleKey = (gap: (typeof coverageGaps)[number]) =>
        coverageRolesForAction(gap)[0]?.key ?? null;
    const coverageLifecyclePayload = (gap: (typeof coverageGaps)[number]) => ({
        site_id: gap.site_id,
        coverage_requirement_id: gap.rule_id ?? null,
        window_starts_at: gap.starts_at,
        window_ends_at: gap.ends_at,
        return_to: returnTo,
    });
    const openCoverageCreate = async (
        gap: (typeof coverageGaps)[number],
        options?: { openShift?: boolean; repeatWeekly?: boolean },
    ) => {
        if (!gap.starts_at || !gap.ends_at) {
            router.visit(buildCoverageCreateHref(gap, options));
            return;
        }

        try {
            const response = await fetch('/operations/coverage/reservations', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content ?? '',
                },
                body: JSON.stringify({
                    site_id: gap.site_id,
                    coverage_rule_id: gap.rule_id ?? null,
                    starts_at: gap.starts_at,
                    ends_at: gap.ends_at,
                    role_key: coverageRoleKey(gap),
                    return_to: returnTo,
                }),
            });

            if (!response.ok) {
                router.reload({
                    only: ['coverageGaps', 'recurringCoverageAlignment'],
                    preserveScroll: true,
                });
                return;
            }

            const payload = (await response.json()) as {
                token?: string | null;
            };
            router.visit(buildCoverageCreateHref(gap, options, payload.token));
        } catch {
            router.reload({
                only: ['coverageGaps', 'recurringCoverageAlignment'],
                preserveScroll: true,
            });
        }
    };
    const updateCoverageAcknowledgement = (
        gap: (typeof coverageGaps)[number],
        state: 'acked' | 'dismissed',
    ) => {
        if (!gap.coverage_window_key || !gap.starts_at || !gap.ends_at) return;
        const reason =
            state === 'dismissed'
                ? window.prompt('Dismiss reason')?.trim()
                : undefined;
        if (state === 'dismissed' && !reason) return;

        router.post(
            `/operations/rostering/coverage/${encodeURIComponent(gap.coverage_window_key)}/${state === 'acked' ? 'ack' : 'dismiss'}`,
            {
                ...coverageLifecyclePayload(gap),
                reason,
            },
            { preserveScroll: true },
        );
    };
    const clearCoverageAcknowledgement = (
        gap: (typeof coverageGaps)[number],
    ) => {
        if (!gap.coverage_window_key || !gap.starts_at || !gap.ends_at) return;

        router.delete(
            `/operations/rostering/coverage/${encodeURIComponent(gap.coverage_window_key)}/clear`,
            {
                data: coverageLifecyclePayload(gap),
                preserveScroll: true,
            },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Rostering', href: '/operations/rostering' },
                {
                    title: 'Conflict queue',
                    href: '/operations/rostering/conflicts',
                },
            ]}
        >
            <Head title="Rostering conflict queue" />
            <PageShell>
                <PageHeader
                    title="Conflict queue"
                    description={`Work through overlaps, leave clashes, open coverage, and replacements for ${weekStart} to ${weekEnd}.`}
                    actions={
                        <div className="flex gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/operations/rostering">
                                    Back to rostering
                                </Link>
                            </Button>
                        </div>
                    }
                />

                <div className="grid gap-3 md:grid-cols-6">
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-muted-foreground uppercase">
                                Staff overlaps
                            </div>
                            <div className="mt-1 text-2xl font-semibold">
                                {staffOverlaps.length}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-muted-foreground uppercase">
                                Client overlaps
                            </div>
                            <div className="mt-1 text-2xl font-semibold">
                                {clientOverlaps.length}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-muted-foreground uppercase">
                                Leave clashes
                            </div>
                            <div className="mt-1 text-2xl font-semibold">
                                {timeOffConflicts.length}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-muted-foreground uppercase">
                                Tight turnarounds
                            </div>
                            <div className="mt-1 text-2xl font-semibold">
                                {tightTurnarounds.length}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-muted-foreground uppercase">
                                Open shifts
                            </div>
                            <div className="mt-1 text-2xl font-semibold">
                                {openShifts.length}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-muted-foreground uppercase">
                                Coverage gaps
                            </div>
                            <div className="mt-1 text-2xl font-semibold">
                                {coverageGaps.length}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-muted-foreground uppercase">
                                Replacements
                            </div>
                            <div className="mt-1 text-2xl font-semibold">
                                {activeReplacements.length}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="mt-4 grid gap-4">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Staff overlaps
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {staffOverlaps.length === 0 ? (
                                <div className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                                    No staff double-bookings detected.
                                </div>
                            ) : (
                                staffOverlaps.map((conflict, index) => (
                                    <div
                                        key={`${conflict.staff_id}-${index}`}
                                        className="rounded-xl border p-4"
                                    >
                                        <div className="mb-3 flex items-center justify-between gap-2">
                                            <div className="text-sm font-medium">
                                                {conflict.staff_name}
                                            </div>
                                            <Badge variant="destructive">
                                                Resolve now
                                            </Badge>
                                        </div>
                                        <div className="grid gap-3 lg:grid-cols-2">
                                            <ShiftSummary
                                                shift={conflict.first}
                                            />
                                            <ShiftSummary
                                                shift={conflict.second}
                                            />
                                        </div>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Client overlap warnings
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {clientOverlaps.length === 0 ? (
                                <div className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                                    No client overlap warnings detected.
                                </div>
                            ) : (
                                clientOverlaps.map((conflict, index) => (
                                    <div
                                        key={`${conflict.client_id}-${index}`}
                                        className="rounded-xl border p-4"
                                    >
                                        <div className="mb-3 flex items-center justify-between gap-2">
                                            <div className="text-sm font-medium">
                                                {conflict.client_name}
                                            </div>
                                            <Badge variant="outline">
                                                Review staffing ratio
                                            </Badge>
                                        </div>
                                        <div className="grid gap-3 lg:grid-cols-2">
                                            <ShiftSummary
                                                shift={conflict.first}
                                            />
                                            <ShiftSummary
                                                shift={conflict.second}
                                            />
                                        </div>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <div className="grid gap-4 lg:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Coverage gaps
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {coverageGaps.length === 0 ? (
                                    <div className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                                        No site demand gaps detected.
                                    </div>
                                ) : (
                                    coverageGaps.map((gap, index) => (
                                        <div
                                            key={`${gap.site_id}-${gap.rule_name}-${gap.window_label}-${index}`}
                                            className={`rounded-xl border p-4 ${
                                                gap.acknowledgement?.state ===
                                                'dismissed'
                                                    ? 'bg-muted/40 opacity-80'
                                                    : ''
                                            }`}
                                        >
                                            <div className="mb-3 flex items-center justify-between gap-2">
                                                <div>
                                                    <div className="text-sm font-medium">
                                                        {gap.site_name}
                                                    </div>
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        {gap.rule_name} ·{' '}
                                                        {gap.window_label}
                                                    </div>
                                                </div>
                                                <div className="flex flex-wrap justify-end gap-2">
                                                    {gap.acknowledgement ? (
                                                        <Badge variant="outline">
                                                            {gap
                                                                .acknowledgement
                                                                .state ===
                                                            'dismissed'
                                                                ? 'Dismissed'
                                                                : 'Acked'}
                                                        </Badge>
                                                    ) : null}
                                                    <Badge variant="destructive">
                                                        {gapKindLabel(
                                                            gap.gap_kind,
                                                        )}
                                                    </Badge>
                                                </div>
                                            </div>
                                            <div className="text-sm text-muted-foreground">
                                                Need {gap.required_staff} staff
                                                and only {gap.assigned_staff}{' '}
                                                are assigned in this window.
                                                {(gap.planned_staff ??
                                                    gap.assigned_staff) >
                                                gap.assigned_staff
                                                    ? ` ${gap.planned_staff ?? gap.assigned_staff} are planned once open shifts are filled.`
                                                    : ''}
                                            </div>
                                            {coverageRolesForAction(gap)
                                                .length > 0 ? (
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    {coverageRolesForAction(
                                                        gap,
                                                    ).map((role) => (
                                                        <Badge
                                                            key={`${gap.site_id}-${gap.rule_name}-${role.key}`}
                                                            variant="outline"
                                                        >
                                                            {role.label ??
                                                                role.key.replace(
                                                                    '_',
                                                                    ' ',
                                                                )}{' '}
                                                            still needed x
                                                            {role.missing ?? 1}
                                                        </Badge>
                                                    ))}
                                                </div>
                                            ) : null}
                                            {gap
                                                .partial_window_uncovered_slices
                                                ?.length ? (
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    {gap.partial_window_uncovered_slices.map(
                                                        (slice, sliceIndex) => (
                                                            <Badge
                                                                key={`${gap.site_id}-${gap.rule_name}-slice-${sliceIndex}`}
                                                                variant="outline"
                                                            >
                                                                {formatCoverageSlice(
                                                                    slice.starts_at,
                                                                    slice.ends_at,
                                                                )}{' '}
                                                                still uncovered
                                                            </Badge>
                                                        ),
                                                    )}
                                                </div>
                                            ) : null}
                                            {gap.contradictions &&
                                            gap.contradictions.length > 0 ? (
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    {gap.contradictions.map(
                                                        (issue) => (
                                                            <Badge
                                                                key={`${gap.site_id}-${issue}`}
                                                                variant="outline"
                                                            >
                                                                {issue ===
                                                                'headcount_exact_but_role_gap'
                                                                    ? 'Headcount looks full but role demand is still short'
                                                                    : issue ===
                                                                        'partial_window_undercoverage'
                                                                      ? 'Coverage drops away inside the window and needs partial backfill'
                                                                      : issue ===
                                                                          'planned_supply_exact_but_role_gap'
                                                                        ? 'Open planned supply still misses the required role mix'
                                                                        : issue ===
                                                                            'preferred_client_drift'
                                                                          ? 'Preferred client context has drifted'
                                                                          : issue ===
                                                                              'overfill_not_allowed'
                                                                            ? 'This window is overstaffed beyond the allowed limit'
                                                                            : issue ===
                                                                                'overfilled_but_wrong_role_mix'
                                                                              ? 'This window is overfilled but still has the wrong role mix'
                                                                              : issue}
                                                            </Badge>
                                                        ),
                                                    )}
                                                </div>
                                            ) : null}
                                            <div className="mt-2 rounded-lg border bg-muted/60 p-3 text-xs text-foreground">
                                                {gap.recommended_fill_action ===
                                                'fill_existing_open_shift'
                                                    ? 'Demand is already represented by open shifts. Fill one of those shifts rather than creating another.'
                                                    : gap.recommended_fill_action ===
                                                        'retag_or_replace_open_shift'
                                                      ? 'An open shift already exists, but it is not carrying the right role demand. Retag that shift or create a role-specific cover shift.'
                                                      : gap.unfilled_after_open_shifts &&
                                                          gap.unfilled_after_open_shifts >
                                                              0
                                                        ? `${gap.unfilled_after_open_shifts} more shift slot(s) still need to be created or reopened after existing open shifts are filled.`
                                                        : gap.planned_role_shortages &&
                                                            gap
                                                                .planned_role_shortages
                                                                .length > 0
                                                          ? 'Planned supply exists, but the required role mix is still not covered.'
                                                          : 'Current planned supply already represents the demand window.'}
                                            </div>

                                            {gap.contributing_shifts &&
                                            gap.contributing_shifts.length >
                                                0 ? (
                                                <div className="mt-3 space-y-2">
                                                    <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                        Existing supply
                                                    </div>
                                                    {gap.contributing_shifts.map(
                                                        (shift) => (
                                                            <ShiftSummary
                                                                key={shift.id}
                                                                shift={shift}
                                                            />
                                                        ),
                                                    )}
                                                </div>
                                            ) : null}

                                            {gap.matching_series &&
                                            gap.matching_series.length > 0 ? (
                                                <div className="mt-3 space-y-2">
                                                    <div className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                        Recurring demand links
                                                    </div>
                                                    {gap.matching_series.map(
                                                        (series) => (
                                                            <div
                                                                key={series.id}
                                                                className="rounded-xl border p-3"
                                                            >
                                                                <div className="flex items-start justify-between gap-2">
                                                                    <div>
                                                                        <div className="text-sm font-medium">
                                                                            {series.client_name ??
                                                                                'Recurring series'}
                                                                        </div>
                                                                        <div className="mt-1 text-xs text-muted-foreground">
                                                                            {series.weekdays.join(
                                                                                ', ',
                                                                            )}{' '}
                                                                            ·{' '}
                                                                            {
                                                                                series.starts_time
                                                                            }
                                                                            -
                                                                            {
                                                                                series.ends_time
                                                                            }
                                                                        </div>
                                                                    </div>
                                                                    <Button
                                                                        size="sm"
                                                                        variant="outline"
                                                                        asChild
                                                                    >
                                                                        <Link
                                                                            href={`/operations/shifts/series/${series.id}`}
                                                                        >
                                                                            Open
                                                                            series
                                                                        </Link>
                                                                    </Button>
                                                                </div>
                                                            </div>
                                                        ),
                                                    )}
                                                </div>
                                            ) : null}

                                            <div className="mt-3 flex flex-wrap gap-2">
                                                {gap.open_shift_ids &&
                                                gap.open_shift_ids.length >
                                                    0 ? (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={`/operations/shifts/${gap.open_shift_ids[0]}`}
                                                        >
                                                            Open cover shift
                                                        </Link>
                                                    </Button>
                                                ) : null}
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    asChild
                                                >
                                                    <Link href="/operations/rostering">
                                                        Open rostering
                                                    </Link>
                                                </Button>
                                                {gap.acknowledgement ? (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            clearCoverageAcknowledgement(
                                                                gap,
                                                            )
                                                        }
                                                    >
                                                        Clear
                                                    </Button>
                                                ) : (
                                                    <>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                updateCoverageAcknowledgement(
                                                                    gap,
                                                                    'acked',
                                                                )
                                                            }
                                                        >
                                                            Ack
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                updateCoverageAcknowledgement(
                                                                    gap,
                                                                    'dismissed',
                                                                )
                                                            }
                                                        >
                                                            Dismiss
                                                        </Button>
                                                    </>
                                                )}
                                                {shouldOfferCreation(
                                                    gap.recommended_fill_action,
                                                ) ? (
                                                    <>
                                                        <Button
                                                            size="sm"
                                                            onClick={() =>
                                                                void openCoverageCreate(
                                                                    gap,
                                                                )
                                                            }
                                                        >
                                                            {fillActionLabel(
                                                                gap.recommended_fill_action,
                                                            )}
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                void openCoverageCreate(
                                                                    gap,
                                                                    {
                                                                        openShift: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Create open shift
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                void openCoverageCreate(
                                                                    gap,
                                                                    {
                                                                        openShift: true,
                                                                        repeatWeekly: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Create recurring
                                                            cover
                                                        </Button>
                                                    </>
                                                ) : null}
                                            </div>
                                        </div>
                                    ))
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Leave clashes
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {timeOffConflicts.length === 0 ? (
                                    <div className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                                        No approved leave clashes detected.
                                    </div>
                                ) : (
                                    timeOffConflicts.map((conflict) => (
                                        <div
                                            key={`${conflict.shift.id}-${conflict.time_off.id}`}
                                            className="rounded-xl border p-4"
                                        >
                                            <div className="text-sm font-medium">
                                                {conflict.time_off.user_name}
                                            </div>
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                {conflict.time_off.type}
                                                {conflict.time_off.label
                                                    ? ` · ${conflict.time_off.label}`
                                                    : ''}
                                            </div>
                                            <div className="mt-3">
                                                <ShiftSummary
                                                    shift={conflict.shift}
                                                />
                                            </div>
                                        </div>
                                    ))
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Tight turnarounds
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {tightTurnarounds.length === 0 ? (
                                    <div className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                                        No risky back-to-back shifts detected.
                                    </div>
                                ) : (
                                    tightTurnarounds.map(
                                        (turnaround, index) => (
                                            <div
                                                key={`${turnaround.staff_id}-${index}`}
                                                className="rounded-xl border p-4"
                                            >
                                                <div className="mb-3 flex items-center justify-between gap-2">
                                                    <div className="text-sm font-medium">
                                                        {turnaround.staff_name}
                                                    </div>
                                                    <Badge variant="outline">
                                                        {turnaround.gap_minutes}{' '}
                                                        min gap
                                                    </Badge>
                                                </div>
                                                <div className="mb-3 text-xs text-muted-foreground">
                                                    Very short travel or
                                                    handover time between these
                                                    shifts.
                                                </div>
                                                <div className="grid gap-3 lg:grid-cols-2">
                                                    <ShiftSummary
                                                        shift={turnaround.first}
                                                    />
                                                    <ShiftSummary
                                                        shift={
                                                            turnaround.second
                                                        }
                                                    />
                                                </div>
                                            </div>
                                        ),
                                    )
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Open coverage
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {openShifts.length === 0 ? (
                                    <div className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                                        No open shifts this week.
                                    </div>
                                ) : (
                                    openShifts.map((shift) => (
                                        <ShiftSummary
                                            key={shift.id}
                                            shift={shift}
                                        />
                                    ))
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Recurring demand drift
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {recurringCoverageAlignment.rule_drift.length ===
                                0 &&
                            recurringCoverageAlignment.orphan_series.length ===
                                0 ? (
                                <div className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                                    No recurring demand drift detected.
                                </div>
                            ) : (
                                <>
                                    {recurringCoverageAlignment.rule_drift.map(
                                        (issue, index) => (
                                            <div
                                                key={`${issue.site_id}-${issue.rule_name}-${index}`}
                                                className="rounded-xl border p-4"
                                            >
                                                <div className="flex flex-wrap items-start justify-between gap-2">
                                                    <div>
                                                        <div className="text-sm font-medium">
                                                            {issue.site_name}
                                                        </div>
                                                        <div className="mt-1 text-xs text-muted-foreground">
                                                            {issue.rule_name} ·{' '}
                                                            {issue.window_label}
                                                        </div>
                                                    </div>
                                                    <Badge variant="destructive">
                                                        {issue.issue_type ===
                                                        'demand_without_recurring_supply'
                                                            ? 'No recurring supply'
                                                            : 'Recurring drift'}
                                                    </Badge>
                                                </div>
                                                <div className="mt-2 text-sm text-muted-foreground">
                                                    Need {issue.required_staff}{' '}
                                                    staff , have{' '}
                                                    {issue.assigned_staff}{' '}
                                                    assigned and{' '}
                                                    {issue.open_shifts} open in
                                                    the recurring demand window.
                                                </div>
                                                <div className="mt-3 flex flex-wrap gap-2">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            void openCoverageCreate(
                                                                issue as unknown as (typeof coverageGaps)[number],
                                                                {
                                                                    openShift: true,
                                                                    repeatWeekly: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Create recurring cover
                                                    </Button>
                                                    {(issue.matching_series ??
                                                        [])[0] ? (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/operations/shifts/series/${issue.matching_series?.[0]?.id}`}
                                                            >
                                                                Open series
                                                            </Link>
                                                        </Button>
                                                    ) : null}
                                                </div>
                                            </div>
                                        ),
                                    )}

                                    {recurringCoverageAlignment.orphan_series.map(
                                        (issue) => (
                                            <div
                                                key={issue.series_id}
                                                className="rounded-xl border p-4"
                                            >
                                                <div className="flex flex-wrap items-start justify-between gap-2">
                                                    <div>
                                                        <div className="text-sm font-medium">
                                                            {issue.client_name ??
                                                                `Series #${issue.series_id}`}
                                                        </div>
                                                        <div className="mt-1 text-xs text-muted-foreground">
                                                            {issue.site_name} ·{' '}
                                                            {issue.weekdays.join(
                                                                ', ',
                                                            )}{' '}
                                                            ·{' '}
                                                            {issue.starts_time}-
                                                            {issue.ends_time}
                                                        </div>
                                                    </div>
                                                    <Badge variant="outline">
                                                        No matching demand rule
                                                    </Badge>
                                                </div>
                                                <div className="mt-3 flex flex-wrap gap-2">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={`/operations/shifts/series/${issue.series_id}`}
                                                        >
                                                            Open series
                                                        </Link>
                                                    </Button>
                                                    {issue.site_id ? (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/sites/${issue.site_id}`}
                                                            >
                                                                Open site
                                                            </Link>
                                                        </Button>
                                                    ) : null}
                                                </div>
                                            </div>
                                        ),
                                    )}
                                </>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Replacement requests
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {activeReplacements.length === 0 ? (
                                <div className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                                    No active replacement workflows.
                                </div>
                            ) : (
                                activeReplacements.map((replacement) => (
                                    <div
                                        key={replacement.id}
                                        className="rounded-xl border p-4"
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div>
                                                <div className="text-sm font-medium">
                                                    {
                                                        replacement.shift
                                                            .client_name
                                                    }
                                                </div>
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    {replacement.reason}
                                                </div>
                                            </div>
                                            <Badge
                                                variant="secondary"
                                                className="capitalize"
                                            >
                                                {replacement.status}
                                            </Badge>
                                        </div>
                                        <div className="mt-2 flex flex-wrap gap-3 text-xs text-muted-foreground">
                                            {replacement.requested_by ? (
                                                <span>
                                                    Requested by{' '}
                                                    {replacement.requested_by}
                                                </span>
                                            ) : null}
                                            {replacement.current_staff ? (
                                                <span>
                                                    Current staff{' '}
                                                    {replacement.current_staff}
                                                </span>
                                            ) : null}
                                            {replacement.replacement_staff ? (
                                                <span>
                                                    Replacement{' '}
                                                    {
                                                        replacement.replacement_staff
                                                    }
                                                </span>
                                            ) : null}
                                            {replacement.claimed_by ? (
                                                <span>
                                                    Claimed by{' '}
                                                    {replacement.claimed_by}
                                                </span>
                                            ) : null}
                                        </div>
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                asChild
                                            >
                                                <Link
                                                    href={`/operations/shifts/${replacement.shift.id}`}
                                                >
                                                    Open shift
                                                </Link>
                                            </Button>
                                            {replacement.open_position_id ? (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    asChild
                                                >
                                                    <Link href="/operations/job-board">
                                                        Job board
                                                    </Link>
                                                </Button>
                                            ) : null}
                                            {replacement.status === 'claimed' &&
                                            replacement.open_position_id ? (
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            `/operations/job-board/${replacement.open_position_id}/approve`,
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Approve claim
                                                </Button>
                                            ) : null}
                                        </div>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                </div>
            </PageShell>
        </AppLayout>
    );
}
