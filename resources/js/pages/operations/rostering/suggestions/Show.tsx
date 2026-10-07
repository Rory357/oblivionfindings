import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import {
    formatDateOnly,
    formatDateTimeInZone,
    WORKER_TIMEZONE,
} from '@/lib/datetime';
import { useI18n } from '@/lib/i18n';
import { Head, router } from '@inertiajs/react';
import { Check, RefreshCw, Send, Wand2, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type SuggestionRun = {
    id: number;
    status: string;
    strategy: string;
    week_start: string;
    week_end: string;
    site: { id: number; name: string } | null;
    requested_by: string | null;
    totals: {
        open_shifts?: number;
        suggested_shifts?: number;
        suggestion_count?: number;
    };
    parameters: {
        estimated_evaluations?: number;
        queue_threshold?: number;
    };
    expires_at: string | null;
    failure_message: string | null;
    is_expired: boolean;
    can?: { apply_accepted: boolean };
    urls?: { apply_accepted: string | null };
};

type Suggestion = {
    id: number;
    shift_id: number;
    rank: number;
    score: number;
    status: string;
    current_source?: {
        status: 'available' | 'unavailable';
        reason: string | null;
    };
    can?: { accept: boolean; dismiss: boolean; apply: boolean };
    urls?: {
        accept: string | null;
        dismiss: string | null;
        apply: string | null;
    };
    reasons: Record<string, number | string | null>;
    eligibility_snapshot: {
        warning_reasons?: string[];
    };
    candidate: { id: number; name: string; email?: string | null } | null;
    shift: {
        id: number;
        starts_at: string | null;
        ends_at: string | null;
        status: string;
        client: string | null;
        site: string | null;
        service_context: string | null;
        current_staff: string | null;
    } | null;
};

type Props = {
    run: SuggestionRun;
    suggestions: Suggestion[];
    worker_timezone?: string;
    suggestion_visibility?: {
        basis: 'current_canonical_run_site';
        recorded_count: number;
        visible_count: number;
        withheld_count: number;
    };
};

export default function Show(props: Props) {
    return <SuggestionRunPage key={props.run.id} {...props} />;
}

function SuggestionRunPage({
    run,
    suggestions,
    worker_timezone = WORKER_TIMEZONE,
    suggestion_visibility,
}: Props) {
    const { t } = useI18n();
    const isGenerating = run.status === 'pending' || run.status === 'running';
    const canApply = !run.is_expired && run.status === 'completed';
    const hasAccepted = suggestions.some((item) => item.status === 'accepted');
    const [processing, setProcessing] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [refreshError, setRefreshError] = useState(false);
    const busy = processing || refreshing;
    const blocked = busy || refreshError;
    const missingCapabilities =
        !run.can ||
        !run.urls ||
        suggestions.some((item) => !item.can || !item.urls);
    const canApplyAccepted = Boolean(
        canApply &&
        hasAccepted &&
        run.can?.apply_accepted &&
        run.urls?.apply_accepted,
    );
    const refresh = () => {
        if (busy) return;
        setRefreshing(true);
        setRefreshError(false);
        router.reload({
            only: [
                'run',
                'suggestions',
                'worker_timezone',
                'suggestion_visibility',
            ],
            onError: () => setRefreshError(true),
            onCancel: () => setRefreshError(true),
            onFinish: () => setRefreshing(false),
        });
    };

    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');

    useEffect(() => {
        if (!isGenerating || blocked) return;

        const interval = window.setInterval(() => {
            router.reload({
                only: [
                    'run',
                    'suggestions',
                    'worker_timezone',
                    'suggestion_visibility',
                ],
            });
        }, 5000);

        return () => window.clearInterval(interval);
    }, [isGenerating, blocked]);

    const rosterHref = `/operations/rostering?week=${run.week_start}${
        run.site ? `&site_id=${run.site.id}` : ''
    }`;

    const shownSuggestions = useMemo(() => {
        const q = search.trim().toLowerCase();
        return suggestions.filter((suggestion) => {
            if (statusFilter !== 'all' && suggestion.status !== statusFilter)
                return false;
            if (q) {
                const hay =
                    `${suggestion.candidate?.name ?? ''} ${suggestion.candidate?.email ?? ''} ${
                        suggestion.shift?.client ?? ''
                    } ${suggestion.shift?.site ?? ''}`.toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });
    }, [suggestions, search, statusFilter]);

    const grouped = shownSuggestions.reduce<Record<number, Suggestion[]>>(
        (acc, suggestion) => {
            acc[suggestion.shift_id] ??= [];
            acc[suggestion.shift_id].push(suggestion);
            return acc;
        },
        {},
    );

    const statusOptions = useMemo(
        () => [
            { value: 'all', label: 'All statuses' },
            ...Array.from(
                new Set(
                    [
                        statusFilter,
                        ...suggestions.map((suggestion) => suggestion.status),
                    ].filter((status) => status !== 'all'),
                ),
            )
                .sort()
                .map((status) => ({
                    value: status,
                    label: t(
                        `rostering.suggestions.status.${status}`,
                        status.replace(/_/g, ' '),
                    ),
                })),
        ],
        [suggestions, statusFilter, t],
    );

    const applyAccepted = () => {
        if (!canApplyAccepted || blocked || !run.urls?.apply_accepted) return;
        setProcessing(true);
        router.post(
            run.urls.apply_accepted,
            {},
            { preserveScroll: true, onFinish: () => setProcessing(false) },
        );
    };

    const postSuggestion = (
        suggestion: Suggestion,
        action: 'accept' | 'dismiss' | 'apply',
    ) => {
        const url = suggestion.urls?.[action];
        if (blocked || !suggestion.can?.[action] || !url) return;
        setProcessing(true);
        router.post(
            url,
            {},
            { preserveScroll: true, onFinish: () => setProcessing(false) },
        );
    };

    const titleChip = isGenerating ? (
        <PageHeaderStatusChip variant="info">
            {t('rostering.suggestions.status.running', 'Generating…')}
        </PageHeaderStatusChip>
    ) : run.status === 'failed' ? (
        <PageHeaderStatusChip variant="critical">
            {t('rostering.suggestions.status.failed', 'Failed')}
        </PageHeaderStatusChip>
    ) : run.is_expired ? (
        <PageHeaderStatusChip variant="warning">
            {t('rostering.suggestions.status.expired', 'Expired')}
        </PageHeaderStatusChip>
    ) : (
        <PageHeaderStatusChip variant="success">
            {t(`rostering.suggestions.status.${run.status}`, run.status)}
        </PageHeaderStatusChip>
    );

    const openShifts = run.totals.open_shifts ?? 0;
    const suggestedShifts = run.totals.suggested_shifts ?? 0;
    const suggestionCount =
        run.totals.suggestion_count ??
        suggestion_visibility?.recorded_count ??
        suggestions.length;

    const header = (
        <PageHeader
            variant="profile"
            backHref={rosterHref}
            icon={Wand2}
            title={
                run.site?.name ??
                t('rostering.publish.selected_site', 'Selected site')
            }
            titleChip={titleChip}
            subline={`${t(
                'rostering.suggestions.head_title',
                'Roster suggestions',
            )} · ${formatDateOnly(run.week_start)} → ${formatDateOnly(run.week_end)} · ${worker_timezone}${
                run.requested_by ? ` · ${run.requested_by}` : ''
            }`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search candidates and shifts…"
                    />
                    <PageHeaderPrimaryButton
                        icon={Send}
                        disabled={!canApplyAccepted || blocked}
                        className="disabled:pointer-events-none disabled:opacity-50"
                        onClick={applyAccepted}
                        data-test="suggestions-apply-accepted"
                    >
                        {t(
                            'rostering.suggestions.apply_accepted',
                            'Apply accepted',
                        )}
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    {openShifts > 0 ? (
                        <PageHeaderMeterBlock
                            label="Recorded candidate coverage"
                            ariaLabel="Back to the roster week"
                            href={rosterHref}
                        >
                            <PageHeaderMeterDonut
                                percent={(suggestedShifts / openShifts) * 100}
                                caption={
                                    <>
                                        {suggestedShifts} of {openShifts}
                                        <br />
                                        open shifts had candidates when
                                        generated
                                    </>
                                }
                            />
                        </PageHeaderMeterBlock>
                    ) : null}
                    <PageHeaderMeterBlock
                        label={t('rostering.suggestions.title', 'Suggestions')}
                        ariaLabel="Back to the roster week"
                        href={rosterHref}
                    >
                        <PageHeaderMeterBig>
                            {suggestionCount}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            recorded candidates across {suggestedShifts}{' '}
                            {suggestedShifts === 1 ? 'shift' : 'shifts'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    icon={Check}
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
                {
                    title: t('rostering.title', 'Rostering'),
                    href: '/operations/rostering',
                },
                {
                    title: t('rostering.suggestions.title', 'Suggestions'),
                    href: `/operations/rostering/suggestions/${run.id}`,
                },
            ]}
        >
            <Head
                title={t(
                    'rostering.suggestions.head_title',
                    'Roster suggestions',
                )}
            />

            <PageLayout hero={header}>
                <div className="space-y-4" data-test="roster-suggestions-page">
                    <div className="flex flex-wrap items-start justify-between gap-3 rounded-lg border p-4">
                        <div className="min-w-0 space-y-1 text-sm">
                            <p>
                                Accept selects a choice. Apply assigns the
                                worker after current staffing checks.
                            </p>
                            <p className="text-muted-foreground">
                                Apply accepted checks all accepted choices in
                                this run, including those outside the current
                                filter. Rankings and totals reflect when the run
                                was generated.
                            </p>
                            <p
                                className="text-muted-foreground"
                                data-test="suggestion-visible-count"
                            >
                                {shownSuggestions.length} shown by this filter ·{' '}
                                {suggestion_visibility
                                    ? `${suggestion_visibility.visible_count} currently visible of ${suggestion_visibility.recorded_count} recorded suggestions.`
                                    : `${suggestions.length} loaded suggestions.`}
                                {suggestion_visibility &&
                                suggestion_visibility.withheld_count > 0
                                    ? ` ${suggestion_visibility.withheld_count} no longer have a visible duty in this run.`
                                    : ''}
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            className="min-h-[44px] shrink-0"
                            disabled={busy}
                            onClick={refresh}
                        >
                            <RefreshCw className="mr-2 size-4" />
                            {refreshing ? 'Refreshing…' : 'Reload suggestions'}
                        </Button>
                    </div>
                    {missingCapabilities ? (
                        <p className="text-sm text-muted-foreground">
                            Reload suggestions to check which actions are
                            currently available.
                        </p>
                    ) : null}
                    {refreshError ? (
                        <p role="alert" className="text-sm text-destructive">
                            Suggestions could not be refreshed. The list may be
                            out of date. Try reloading again.
                        </p>
                    ) : null}
                    {isGenerating ? (
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-base">
                                    {t(
                                        'rostering.suggestions.generating_title',
                                        'Suggestions are being prepared',
                                    )}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="text-sm text-muted-foreground">
                                {t(
                                    'rostering.suggestions.generating_prefix',
                                    'This run is checking roughly',
                                )}{' '}
                                {run.parameters.estimated_evaluations ?? 0}{' '}
                                {t(
                                    'rostering.suggestions.generating_suffix',
                                    'staff-shift combinations. The page will refresh automatically.',
                                )}
                            </CardContent>
                        </Card>
                    ) : null}

                    {run.status === 'failed' ? (
                        <Card className="border-destructive">
                            <CardHeader className="pb-2">
                                <CardTitle className="text-base">
                                    {t(
                                        'rostering.suggestions.failed_title',
                                        'Suggestion run failed',
                                    )}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="text-sm text-muted-foreground">
                                {t(
                                    'rostering.suggestions.failed_fallback',
                                    'Generate a fresh run before applying assignments.',
                                )}
                            </CardContent>
                        </Card>
                    ) : null}

                    {!isGenerating &&
                    run.status !== 'failed' &&
                    Object.keys(grouped).length === 0 ? (
                        <Card>
                            <CardContent className="p-4 text-sm text-muted-foreground">
                                {search.trim() !== '' || statusFilter !== 'all'
                                    ? 'No suggestions match your search or filter.'
                                    : suggestion_visibility?.withheld_count
                                      ? 'No recorded suggestions are currently visible for this run. Return to the roster to generate new suggestions.'
                                      : t(
                                            'rostering.suggestions.none',
                                            'No suggestions were generated for this run.',
                                        )}
                            </CardContent>
                        </Card>
                    ) : null}

                    <div className="space-y-3">
                        {Object.entries(grouped).map(
                            ([shiftId, shiftSuggestions]) => {
                                const shift = shiftSuggestions[0]?.shift;

                                return (
                                    <Card key={shiftId}>
                                        <CardHeader className="pb-2">
                                            <CardTitle className="text-base">
                                                {shift?.client ??
                                                    t(
                                                        'rostering.suggestions.open_shift',
                                                        'Open shift',
                                                    )}
                                            </CardTitle>
                                            <CardDescription>
                                                {formatDateTimeInZone(
                                                    shift?.starts_at,
                                                    worker_timezone,
                                                    t(
                                                        'rostering.suggestions.start_unavailable',
                                                        'Start time unavailable',
                                                    ),
                                                )}{' '}
                                                →{' '}
                                                {formatDateTimeInZone(
                                                    shift?.ends_at,
                                                    worker_timezone,
                                                    t(
                                                        'rostering.suggestions.end_unavailable',
                                                        'End time unavailable',
                                                    ),
                                                )}
                                            </CardDescription>
                                            <p className="text-sm text-muted-foreground">
                                                {shift?.site ??
                                                    'Site unavailable'}
                                                {shift?.current_staff
                                                    ? ` · Assigned to ${shift.current_staff}`
                                                    : ''}
                                            </p>
                                            {shift?.service_context ? (
                                                <p className="text-sm text-muted-foreground">
                                                    {shift.service_context}
                                                </p>
                                            ) : null}
                                        </CardHeader>
                                        <CardContent className="space-y-2">
                                            {shiftSuggestions.map(
                                                (suggestion) => (
                                                    <div
                                                        key={suggestion.id}
                                                        data-test="suggestion-row"
                                                        data-status={
                                                            suggestion.status
                                                        }
                                                        className="flex min-w-0 flex-col gap-3 rounded-md border p-3 lg:flex-row lg:items-start lg:justify-between"
                                                    >
                                                        <div className="min-w-0 space-y-1 break-words">
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <span className="font-medium">
                                                                    {suggestion
                                                                        .candidate
                                                                        ?.name ??
                                                                        t(
                                                                            'rostering.suggestions.candidate',
                                                                            'Worker unavailable',
                                                                        )}
                                                                </span>
                                                                <Badge variant="outline">
                                                                    {t(
                                                                        'rostering.suggestions.rank',
                                                                        'Rank',
                                                                    )}{' '}
                                                                    {
                                                                        suggestion.rank
                                                                    }
                                                                </Badge>
                                                                <Badge variant="outline">
                                                                    {t(
                                                                        'rostering.suggestions.score',
                                                                        'Score',
                                                                    )}{' '}
                                                                    {
                                                                        suggestion.score
                                                                    }
                                                                </Badge>
                                                                <StatusBadge
                                                                    status={
                                                                        suggestion.status
                                                                    }
                                                                    label={t(
                                                                        `rostering.suggestions.status.${suggestion.status}`,
                                                                        suggestion.status.replace(
                                                                            /_/g,
                                                                            ' ',
                                                                        ),
                                                                    )}
                                                                />
                                                            </div>
                                                            <div className="text-sm text-muted-foreground">
                                                                {t(
                                                                    'rostering.suggestions.recorded_weekly_hours',
                                                                    'Weekly hours when generated',
                                                                )}
                                                                :{' '}
                                                                {suggestion
                                                                    .reasons
                                                                    .weekly_hours ??
                                                                    t(
                                                                        'rostering.common.not_available',
                                                                        'n/a',
                                                                    )}{' '}
                                                                ·{' '}
                                                                {t(
                                                                    'rostering.suggestions.site_familiarity',
                                                                    'Site familiarity',
                                                                )}
                                                                :{' '}
                                                                {suggestion
                                                                    .reasons
                                                                    .site_familiarity ??
                                                                    0}{' '}
                                                                ·{' '}
                                                                {t(
                                                                    'rostering.suggestions.client_consistency',
                                                                    'Client consistency',
                                                                )}
                                                                :{' '}
                                                                {suggestion
                                                                    .reasons
                                                                    .client_consistency ??
                                                                    0}
                                                            </div>
                                                            {suggestion
                                                                .current_source
                                                                ?.reason ? (
                                                                <p className="text-sm text-muted-foreground">
                                                                    {
                                                                        suggestion
                                                                            .current_source
                                                                            .reason
                                                                    }
                                                                </p>
                                                            ) : null}
                                                            {suggestion
                                                                .eligibility_snapshot
                                                                .warning_reasons
                                                                ?.length ? (
                                                                <div className="text-sm text-muted-foreground">
                                                                    {suggestion.eligibility_snapshot.warning_reasons.join(
                                                                        ' ',
                                                                    )}
                                                                </div>
                                                            ) : null}
                                                        </div>
                                                        <div className="flex flex-wrap gap-2">
                                                            <Button
                                                                size="sm"
                                                                className="min-h-[44px]"
                                                                variant="outline"
                                                                disabled={
                                                                    blocked ||
                                                                    !suggestion
                                                                        .can
                                                                        ?.accept ||
                                                                    !suggestion
                                                                        .urls
                                                                        ?.accept
                                                                }
                                                                onClick={() =>
                                                                    postSuggestion(
                                                                        suggestion,
                                                                        'accept',
                                                                    )
                                                                }
                                                                data-test="suggestion-accept"
                                                            >
                                                                <Check className="mr-1 h-4 w-4" />
                                                                {t(
                                                                    'rostering.suggestions.accept',
                                                                    'Accept',
                                                                )}
                                                            </Button>
                                                            <Button
                                                                size="sm"
                                                                className="min-h-[44px]"
                                                                variant="outline"
                                                                disabled={
                                                                    blocked ||
                                                                    !suggestion
                                                                        .can
                                                                        ?.dismiss ||
                                                                    !suggestion
                                                                        .urls
                                                                        ?.dismiss
                                                                }
                                                                onClick={() =>
                                                                    postSuggestion(
                                                                        suggestion,
                                                                        'dismiss',
                                                                    )
                                                                }
                                                            >
                                                                <X className="mr-1 h-4 w-4" />
                                                                {t(
                                                                    'rostering.suggestions.dismiss',
                                                                    'Dismiss',
                                                                )}
                                                            </Button>
                                                            <Button
                                                                size="sm"
                                                                className="min-h-[44px]"
                                                                disabled={
                                                                    blocked ||
                                                                    !suggestion
                                                                        .can
                                                                        ?.apply ||
                                                                    !suggestion
                                                                        .urls
                                                                        ?.apply
                                                                }
                                                                onClick={() =>
                                                                    postSuggestion(
                                                                        suggestion,
                                                                        'apply',
                                                                    )
                                                                }
                                                            >
                                                                {t(
                                                                    'rostering.suggestions.apply',
                                                                    'Apply',
                                                                )}
                                                            </Button>
                                                        </div>
                                                    </div>
                                                ),
                                            )}
                                        </CardContent>
                                    </Card>
                                );
                            },
                        )}
                    </div>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
