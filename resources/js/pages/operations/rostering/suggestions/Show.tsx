import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarCheck, Check, Send, X } from 'lucide-react';
import { useEffect } from 'react';

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
};

type Suggestion = {
    id: number;
    shift_id: number;
    rank: number;
    score: number;
    status: string;
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
};

type TFunction = (key: string, fallback?: string) => string;

function formatDateTime(value: string | null | undefined, t: TFunction) {
    if (!value) return t('rostering.common.unscheduled', 'Unscheduled');

    return new Intl.DateTimeFormat(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

export default function Show({ run, suggestions }: Props) {
    const { t } = useI18n();
    const isGenerating = run.status === 'pending' || run.status === 'running';
    const canApply = !run.is_expired && run.status === 'completed';

    useEffect(() => {
        if (!isGenerating) return;

        const interval = window.setInterval(() => {
            router.reload({ only: ['run', 'suggestions'] });
        }, 5000);

        return () => window.clearInterval(interval);
    }, [isGenerating]);

    const grouped = suggestions.reduce<Record<number, Suggestion[]>>(
        (acc, suggestion) => {
            acc[suggestion.shift_id] ??= [];
            acc[suggestion.shift_id].push(suggestion);
            return acc;
        },
        {},
    );

    const applyAccepted = () => {
        router.post(
            `/operations/rostering/suggestions/${run.id}/apply-accepted`,
            {},
            { preserveScroll: true },
        );
    };

    const postSuggestion = (
        suggestion: Suggestion,
        action: 'accept' | 'dismiss' | 'apply',
    ) => {
        router.post(
            `/operations/rostering/suggestions/${suggestion.id}/${action}`,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: t('rostering.title', 'Rostering'),
                    href: '/operations/rostering',
                },
                {
                    title: t('rostering.suggestions.title', 'Suggestions'),
                    href: '#',
                },
            ]}
        >
            <Head
                title={t(
                    'rostering.suggestions.head_title',
                    'Roster suggestions',
                )}
            />

            <PageLayout
                hero={
                    <PageHero
                        category="ops"
                        icon={CalendarCheck}
                        title={t(
                            'rostering.suggestions.head_title',
                            'Roster suggestions',
                        )}
                        description={`${
                            run.site?.name ??
                            t(
                                'rostering.publish.selected_site',
                                'Selected site',
                            )
                        } · ${run.week_start} to ${run.week_end}`}
                        actions={
                            <>
                                <Badge
                                    variant={run.is_expired ? 'destructive' : 'outline'}
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground"
                                >
                                    {t(
                                        `rostering.suggestions.status.${run.status}`,
                                        run.status,
                                    )}
                                </Badge>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={!canApply}
                                    onClick={applyAccepted}
                                    data-test="suggestions-apply-accepted"
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <Send className="mr-1 h-4 w-4" />
                                    {t(
                                        'rostering.suggestions.apply_accepted',
                                        'Apply accepted',
                                    )}
                                </Button>
                                <Link
                                    href={`/operations/rostering?week=${run.week_start}${run.site ? `&site_id=${run.site.id}` : ''}`}
                                >
                                    <Button size="sm" variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground">
                                        {t(
                                            'rostering.publish.back_to_roster',
                                            'Back to roster',
                                        )}
                                    </Button>
                                </Link>
                            </>
                        }
                    />
                }
            >
                <div className="space-y-4" data-test="roster-suggestions-page">

                <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                {t(
                                    'rostering.suggestions.open_shifts',
                                    'Open shifts',
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {run.totals.open_shifts ?? 0}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                {t(
                                    'rostering.suggestions.shifts_with_candidates',
                                    'Shifts with candidates',
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {run.totals.suggested_shifts ?? 0}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                {t(
                                    'rostering.suggestions.title',
                                    'Suggestions',
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {run.totals.suggestion_count ?? suggestions.length}
                        </CardContent>
                    </Card>
                </div>

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
                            {run.failure_message ??
                                t(
                                    'rostering.suggestions.failed_fallback',
                                    'Generate a fresh run before applying assignments.',
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
                                            {formatDateTime(
                                                shift?.starts_at,
                                                t,
                                            )}{' '}
                                            ·{' '}
                                            {shift?.client ??
                                                t(
                                                    'rostering.suggestions.open_shift',
                                                    'Open shift',
                                                )}
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-2">
                                        {shiftSuggestions.map((suggestion) => (
                                            <div
                                                key={suggestion.id}
                                                className="flex flex-col gap-3 rounded-md border p-3 md:flex-row md:items-center md:justify-between"
                                            >
                                                <div className="space-y-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="font-medium">
                                                            {suggestion
                                                                .candidate
                                                                ?.name ??
                                                                t(
                                                                    'rostering.suggestions.candidate',
                                                                    'Candidate',
                                                                )}
                                                        </span>
                                                        <Badge variant="outline">
                                                            {t(
                                                                'rostering.suggestions.rank',
                                                                'Rank',
                                                            )}{' '}
                                                            {suggestion.rank}
                                                        </Badge>
                                                        <Badge variant="outline">
                                                            {t(
                                                                'rostering.suggestions.score',
                                                                'Score',
                                                            )}{' '}
                                                            {suggestion.score}
                                                        </Badge>
                                                        <Badge
                                                            variant={
                                                                suggestion.status ===
                                                                'accepted'
                                                                    ? 'default'
                                                                    : suggestion.status ===
                                                                        'dismissed'
                                                                      ? 'destructive'
                                                                      : 'outline'
                                                            }
                                                        >
                                                            {t(
                                                                `rostering.suggestions.status.${suggestion.status}`,
                                                                suggestion.status,
                                                            )}
                                                        </Badge>
                                                    </div>
                                                    <div className="text-sm text-muted-foreground">
                                                        {t(
                                                            'rostering.suggestions.weekly_hours',
                                                            'Weekly hours',
                                                        )}
                                                        :{' '}
                                                        {suggestion.reasons
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
                                                        {suggestion.reasons
                                                            .site_familiarity ??
                                                            0}{' '}
                                                        ·{' '}
                                                        {t(
                                                            'rostering.suggestions.client_consistency',
                                                            'Client consistency',
                                                        )}
                                                        :{' '}
                                                        {suggestion.reasons
                                                            .client_consistency ??
                                                            0}
                                                    </div>
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
                                                        variant="outline"
                                                        disabled={!canApply}
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
                                                        variant="outline"
                                                        disabled={!canApply}
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
                                                        disabled={!canApply}
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
                                        ))}
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
