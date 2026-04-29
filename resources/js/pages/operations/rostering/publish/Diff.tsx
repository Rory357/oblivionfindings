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
};

type Summary = {
    added: number;
    removed: number;
    changed: number;
    total: number;
};

type FieldChange = {
    field: string;
    label: string;
    before: unknown;
    after: unknown;
};

type Change = {
    type: 'added' | 'removed' | 'changed';
    shift_id: number;
    label: string;
    starts_at: string | null;
    changes: FieldChange[];
};

type Props = {
    period: Period;
    summary: Summary;
    changes: Change[];
};

type TFunction = (key: string, fallback?: string) => string;

function formatDate(value?: string | null) {
    if (!value) return 'Unscheduled';

    return new Intl.DateTimeFormat(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

function formatValue(value: unknown, t: TFunction) {
    if (value === null || value === undefined || value === '') {
        return t('rostering.common.none', 'None');
    }
    if (Array.isArray(value)) return value.join(', ');
    if (typeof value === 'boolean') {
        return value
            ? t('rostering.common.yes', 'Yes')
            : t('rostering.common.no', 'No');
    }

    return String(value);
}

export default function Diff({ period, summary, changes }: Props) {
    const { t } = useI18n();

    const postPeriodAction = (action: 'review' | 'republish') => {
        router.post(
            `/operations/rostering/periods/${period.id}/${action}`,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: t('rostering.title', 'Rostering'),
                    href: `/operations/rostering?week=${period.week_start}&site_id=${period.site_id}`,
                },
                {
                    title: t('rostering.publish.diff_title', 'Publish diff'),
                    href: '#',
                },
            ]}
        >
            <Head
                title={t(
                    'rostering.publish.diff_head_title',
                    'Roster publish diff',
                )}
            />

            <div className="space-y-4 p-4">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold">
                            {t('rostering.publish.diff_title', 'Publish diff')}
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
                                period.status === 'changed_after_publish'
                                    ? 'destructive'
                                    : 'outline'
                            }
                        >
                            {t(
                                `rostering.publish.${period.status}`,
                                period.status.replaceAll('_', ' '),
                            )}
                        </Badge>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={period.status === 'archived'}
                            onClick={() => postPeriodAction('review')}
                        >
                            <RotateCcw className="mr-1 h-4 w-4" />
                            {t('rostering.publish.re_review', 'Re-review')}
                        </Button>
                        <Button
                            size="sm"
                            disabled={period.status === 'archived'}
                            onClick={() => postPeriodAction('republish')}
                        >
                            <CheckCircle2 className="mr-1 h-4 w-4" />
                            {t('rostering.publish.republish', 'Re-publish')}
                        </Button>
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
                                    'rostering.publish.diff.total_changes',
                                    'Total changes',
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {summary.total}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                {t('rostering.publish.diff.changed', 'Changed')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {summary.changed}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                {t('rostering.publish.diff.added', 'Added')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {summary.added}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                {t('rostering.publish.diff.removed', 'Removed')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {summary.removed}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">
                            {t(
                                'rostering.publish.diff.shift_changes',
                                'Shift changes since publish',
                            )}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {changes.length === 0 ? (
                            <div className="rounded-md border p-4 text-sm text-muted-foreground">
                                {t(
                                    'rostering.publish.diff.no_changes',
                                    'No roster changes were found against the current publish snapshot.',
                                )}
                            </div>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t(
                                                'rostering.publish.diff.shift',
                                                'Shift',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'rostering.publish.diff.change',
                                                'Change',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'rostering.publish.diff.field',
                                                'Field',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'rostering.publish.diff.before',
                                                'Before',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'rostering.publish.diff.after',
                                                'After',
                                            )}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {changes.map((change) =>
                                        change.changes.length > 0 ? (
                                            change.changes.map(
                                                (fieldChange, index) => (
                                                    <TableRow
                                                        key={`${change.shift_id}-${fieldChange.field}`}
                                                    >
                                                        <TableCell>
                                                            {index === 0 ? (
                                                                <div>
                                                                    <div className="font-medium">
                                                                        {
                                                                            change.label
                                                                        }
                                                                    </div>
                                                                    <div className="text-muted-foreground">
                                                                        {formatDate(
                                                                            change.starts_at,
                                                                        )}
                                                                    </div>
                                                                </div>
                                                            ) : null}
                                                        </TableCell>
                                                        <TableCell>
                                                            {index === 0 ? (
                                                                <Badge variant="outline">
                                                                    {
                                                                        change.type
                                                                    }
                                                                </Badge>
                                                            ) : null}
                                                        </TableCell>
                                                        <TableCell>
                                                            {fieldChange.label}
                                                        </TableCell>
                                                        <TableCell>
                                                            {formatValue(
                                                                fieldChange.before,
                                                                t,
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            {formatValue(
                                                                fieldChange.after,
                                                                t,
                                                            )}
                                                        </TableCell>
                                                    </TableRow>
                                                ),
                                            )
                                        ) : (
                                            <TableRow
                                                key={`${change.shift_id}-${change.type}`}
                                            >
                                                <TableCell>
                                                    <div>
                                                        <div className="font-medium">
                                                            {change.label}
                                                        </div>
                                                        <div className="text-muted-foreground">
                                                            {formatDate(
                                                                change.starts_at,
                                                            )}
                                                        </div>
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant="outline">
                                                        {change.type}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell colSpan={3}>
                                                    {change.type === 'added'
                                                        ? t(
                                                              'rostering.publish.diff.new_shift',
                                                              'New shift',
                                                          )
                                                        : t(
                                                              'rostering.publish.diff.removed_shift',
                                                              'Removed shift',
                                                          )}
                                                </TableCell>
                                            </TableRow>
                                        ),
                                    )}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
