import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, RotateCcw } from 'lucide-react';

type Period = {
    id: number;
    site_id: number;
    site_name: string | null;
    week_start: string;
    week_end: string | null;
    version: number;
    status: string;
    published_at: string | null;
    published_by: string | null;
    last_validated_at: string | null;
};

type ValidationEntry = {
    shift_id: number | null;
    issue_type: string;
    message: string;
    starts_at?: string | null;
    ends_at?: string | null;
    client?: string | null;
    staff?: string | null;
    site?: string | null;
    fix_url?: string | null;
};

type Summary = {
    can_publish: boolean;
    blocks: ValidationEntry[];
    warnings: ValidationEntry[];
    shift_count: number;
};

type ShiftRow = {
    id: number;
    starts_at: string | null;
    ends_at: string | null;
    status: string;
    client: string | null;
    site: string | null;
    staff: string | null;
    service_context: string | null;
    published_at: string | null;
    publish_dirty_at: string | null;
};

type Props = {
    period: Period;
    summary: Summary;
    shifts: ShiftRow[];
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

function postPeriodAction(
    period: Period,
    action: 'review' | 'publish' | 'republish',
) {
    router.post(
        `/operations/rostering/periods/${period.id}/${action}`,
        {},
        { preserveScroll: true },
    );
}

function IssueList({
    title,
    entries,
    variant,
    emptyLabel,
    fixLabel,
    t,
}: {
    title: string;
    entries: ValidationEntry[];
    variant: 'destructive' | 'outline';
    emptyLabel: string;
    fixLabel: string;
    t: TFunction;
}) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="flex items-center justify-between gap-2 text-base">
                    <span>{title}</span>
                    <Badge variant={variant}>{entries.length}</Badge>
                </CardTitle>
            </CardHeader>
            <CardContent>
                {entries.length === 0 ? (
                    <div className="rounded-md border p-3 text-sm text-muted-foreground">
                        {emptyLabel}
                    </div>
                ) : (
                    <div className="space-y-2">
                        {entries.map((entry, index) => (
                            <div
                                key={`${entry.issue_type}-${entry.shift_id ?? 'coverage'}-${index}`}
                                className="rounded-md border p-3"
                            >
                                <div className="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                    <div className="space-y-1">
                                        <div className="font-medium">
                                            {entry.message}
                                        </div>
                                        <div className="text-sm text-muted-foreground">
                                            {entry.client ??
                                                t(
                                                    'rostering.publish.coverage',
                                                    'Coverage',
                                                )}{' '}
                                            ·{' '}
                                            {entry.staff ??
                                                t(
                                                    'rostering.common.unassigned',
                                                    'Unassigned',
                                                )}{' '}
                                            ·{' '}
                                            {formatDateTime(entry.starts_at, t)}
                                        </div>
                                    </div>
                                    {entry.fix_url ? (
                                        <Link href={entry.fix_url}>
                                            <Button size="sm" variant="outline">
                                                {fixLabel}
                                            </Button>
                                        </Link>
                                    ) : null}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export default function Review({ period, summary, shifts }: Props) {
    const { t } = useI18n();
    const hasBlocks = summary.blocks.length > 0;
    const isRepublish = Boolean(
        period.published_at && period.status !== 'published',
    );
    const confirmAction = isRepublish ? 'republish' : 'publish';

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: t('rostering.title', 'Rostering'),
                    href: `/operations/rostering?week=${period.week_start}&site_id=${period.site_id}`,
                },
                {
                    title: t(
                        'rostering.publish.review_title',
                        'Publish review',
                    ),
                    href: '#',
                },
            ]}
        >
            <Head
                title={t(
                    'rostering.publish.review_head_title',
                    'Roster publish review',
                )}
            />

            <div className="space-y-4 p-4" data-test="publish-review-page">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            {t(
                                'rostering.publish.review_title',
                                'Publish review',
                            )}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {period.site_name ??
                                t(
                                    'rostering.publish.selected_site',
                                    'Selected site',
                                )}{' '}
                            · {t('rostering.publish.week_of', 'Week of')}{' '}
                            {period.week_start} ·{' '}
                            {t('rostering.publish.version', 'Version')}{' '}
                            {period.version}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Badge
                            variant={
                                summary.can_publish ? 'default' : 'destructive'
                            }
                        >
                            {summary.can_publish
                                ? t(
                                      'rostering.publish.ready_to_publish',
                                      'Ready to publish',
                                  )
                                : t('rostering.publish.blocked', 'Blocked')}
                        </Badge>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={period.status === 'archived'}
                            onClick={() => postPeriodAction(period, 'review')}
                        >
                            <RotateCcw className="mr-1 h-4 w-4" />
                            {t(
                                'rostering.publish.rerun_review',
                                'Re-run review',
                            )}
                        </Button>
                        <Button
                            size="sm"
                            disabled={hasBlocks || period.status === 'archived'}
                            onClick={() =>
                                postPeriodAction(period, confirmAction)
                            }
                            data-test="publish-review-confirm"
                        >
                            <CheckCircle2 className="mr-1 h-4 w-4" />
                            {isRepublish
                                ? t('rostering.publish.republish', 'Re-publish')
                                : t(
                                      'rostering.publish.confirm_publish',
                                      'Confirm publish',
                                  )}
                        </Button>
                        {period.published_at ? (
                            <Link
                                href={`/operations/rostering/periods/${period.id}/diff`}
                            >
                                <Button size="sm" variant="outline">
                                    {t(
                                        'rostering.publish.view_diff',
                                        'View diff',
                                    )}
                                </Button>
                            </Link>
                        ) : null}
                        <Link
                            href={`/operations/rostering?week=${period.week_start}&site_id=${period.site_id}`}
                        >
                            <Button size="sm" variant="outline">
                                {t(
                                    'rostering.publish.back_to_roster',
                                    'Back to roster',
                                )}
                            </Button>
                        </Link>
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                {t(
                                    'rostering.publish.shifts_reviewed',
                                    'Shifts reviewed',
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {summary.shift_count ?? shifts.length}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                {t('rostering.publish.blockers', 'Blockers')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {summary.blocks.length}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                {t('rostering.publish.warnings', 'Warnings')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {summary.warnings.length}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                {t(
                                    'rostering.publish.last_reviewed',
                                    'Last reviewed',
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm font-medium">
                            {formatDateTime(period.last_validated_at, t)}
                        </CardContent>
                    </Card>
                </div>

                <div className="grid grid-cols-1 gap-3 xl:grid-cols-2">
                    <IssueList
                        title={t(
                            'rostering.publish.publish_blockers',
                            'Publish blockers',
                        )}
                        entries={summary.blocks}
                        variant="destructive"
                        emptyLabel={t(
                            'rostering.publish.nothing_to_resolve',
                            'Nothing to resolve here.',
                        )}
                        fixLabel={t('rostering.publish.fix', 'Fix')}
                        t={t}
                    />
                    <IssueList
                        title={t('rostering.publish.warnings', 'Warnings')}
                        entries={summary.warnings}
                        variant="outline"
                        emptyLabel={t(
                            'rostering.publish.nothing_to_resolve',
                            'Nothing to resolve here.',
                        )}
                        fixLabel={t('rostering.publish.fix', 'Fix')}
                        t={t}
                    />
                </div>

                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">
                            {t(
                                'rostering.publish.period_shifts',
                                'Period shifts',
                            )}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>
                                        {t('rostering.publish.shift', 'Shift')}
                                    </TableHead>
                                    <TableHead>
                                        {t(
                                            'rostering.publish.client',
                                            'Client',
                                        )}
                                    </TableHead>
                                    <TableHead>
                                        {t('rostering.publish.staff', 'Staff')}
                                    </TableHead>
                                    <TableHead>
                                        {t(
                                            'rostering.publish.status',
                                            'Status',
                                        )}
                                    </TableHead>
                                    <TableHead>
                                        {t(
                                            'rostering.publish.publish_state',
                                            'Publish state',
                                        )}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {shifts.map((shift) => (
                                    <TableRow key={shift.id}>
                                        <TableCell>
                                            <div className="font-medium">
                                                {formatDateTime(
                                                    shift.starts_at,
                                                    t,
                                                )}
                                            </div>
                                            <div className="text-muted-foreground">
                                                {shift.service_context ??
                                                    shift.site ??
                                                    t(
                                                        'rostering.publish.service',
                                                        'Service',
                                                    )}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            {shift.client ??
                                                t(
                                                    'rostering.publish.client',
                                                    'Client',
                                                )}
                                        </TableCell>
                                        <TableCell>
                                            {shift.staff ??
                                                t(
                                                    'rostering.common.unassigned',
                                                    'Unassigned',
                                                )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="outline">
                                                {shift.status}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            {shift.publish_dirty_at ? (
                                                <Badge variant="destructive">
                                                    {t(
                                                        'rostering.publish.state_changed',
                                                        'changed',
                                                    )}
                                                </Badge>
                                            ) : shift.published_at ? (
                                                <Badge variant="default">
                                                    {t(
                                                        'rostering.publish.published',
                                                        'published',
                                                    )}
                                                </Badge>
                                            ) : (
                                                <Badge variant="outline">
                                                    {t(
                                                        'rostering.publish.draft',
                                                        'draft',
                                                    )}
                                                </Badge>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
