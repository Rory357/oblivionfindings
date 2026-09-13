import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
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
import { Head, router } from '@inertiajs/react';
import {
    CheckCircle2,
    FileDiff,
    Layers,
    MinusCircle,
    Pencil,
    PlusCircle,
    RotateCcw,
} from 'lucide-react';
import { useMemo, useState } from 'react';

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

type ViewKey = 'all' | 'changed' | 'added' | 'removed';

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

    const [view, setView] = useState<ViewKey>('all');
    const [search, setSearch] = useState('');
    const [fieldFilter, setFieldFilter] = useState('all');

    const postPeriodAction = (action: 'review' | 'republish') => {
        router.post(
            `/operations/rostering/periods/${period.id}/${action}`,
            {},
            { preserveScroll: true },
        );
    };

    const backHref = `/operations/rostering?week=${period.week_start}&site_id=${period.site_id}`;

    const fieldOptions = useMemo(
        () => [
            { value: 'all', label: 'All fields' },
            ...Array.from(
                new Set(
                    changes.flatMap((change) =>
                        change.changes.map((fieldChange) => fieldChange.label),
                    ),
                ),
            )
                .sort()
                .map((label) => ({ value: label, label })),
        ],
        [changes],
    );

    const shownChanges = useMemo(() => {
        const q = search.trim().toLowerCase();
        return changes.filter((change) => {
            if (view !== 'all' && change.type !== view) return false;
            if (
                fieldFilter !== 'all' &&
                !change.changes.some(
                    (fieldChange) => fieldChange.label === fieldFilter,
                )
            )
                return false;
            if (q) {
                const hay = `${change.label} ${change.type} ${change.changes
                    .map((fieldChange) => fieldChange.label)
                    .join(' ')}`.toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });
    }, [changes, view, search, fieldFilter]);

    const railItems: PageHeaderRailItem<ViewKey>[] = [
        {
            key: 'all',
            label: 'All changes',
            icon: Layers,
            count: summary.total,
        },
        {
            key: 'changed',
            label: t('rostering.publish.diff.changed', 'Changed'),
            icon: Pencil,
            count: summary.changed,
        },
        {
            key: 'added',
            label: t('rostering.publish.diff.added', 'Added'),
            icon: PlusCircle,
            count: summary.added,
        },
        {
            key: 'removed',
            label: t('rostering.publish.diff.removed', 'Removed'),
            icon: MinusCircle,
            count: summary.removed,
        },
    ];

    const currentViewLabel =
        railItems.find((v) => v.key === view)?.label ?? 'All changes';
    const viewTotal = view === 'all' ? summary.total : (summary[view] ?? 0);

    const titleChip =
        period.status === 'changed_after_publish' ? (
            <PageHeaderStatusChip variant="warning">
                {summary.total} changed since publish
            </PageHeaderStatusChip>
        ) : period.status === 'published' ? (
            <PageHeaderStatusChip variant="success">
                {t('rostering.publish.published', 'Published')}
            </PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip variant="neutral">
                {t(
                    `rostering.publish.${period.status}`,
                    period.status.replaceAll('_', ' '),
                )}
            </PageHeaderStatusChip>
        );

    const header = (
        <PageHeader
            variant="profile"
            backHref={backHref}
            icon={FileDiff}
            title={
                period.site_name ??
                t('rostering.publish.selected_site', 'Selected site')
            }
            titleChip={titleChip}
            subline={`${t('rostering.publish.diff_title', 'Publish diff')} · ${t(
                'rostering.publish.week_of',
                'Week of',
            )} ${period.week_start} · ${t('rostering.publish.version', 'Version')} ${period.version}`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search shift changes…"
                    />
                    <PageHeaderGlassButton
                        icon={RotateCcw}
                        disabled={period.status === 'archived'}
                        className="disabled:pointer-events-none disabled:opacity-50"
                        onClick={() => postPeriodAction('review')}
                    >
                        {t('rostering.publish.re_review', 'Re-review')}
                    </PageHeaderGlassButton>
                    <PageHeaderPrimaryButton
                        icon={CheckCircle2}
                        disabled={period.status === 'archived'}
                        className="disabled:pointer-events-none disabled:opacity-50"
                        onClick={() => postPeriodAction('republish')}
                    >
                        {t('rostering.publish.republish', 'Re-publish')}
                    </PageHeaderPrimaryButton>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    icon={Pencil}
                    label="All fields"
                    value={fieldFilter}
                    options={fieldOptions}
                    onChange={setFieldFilter}
                />
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={view}
                    onSelect={setView}
                    ariaLabel="Change views"
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
                    href: backHref,
                },
                {
                    title: t('rostering.publish.diff_title', 'Publish diff'),
                    href: `/operations/rostering/periods/${period.id}/diff`,
                },
            ]}
        >
            <Head
                title={t(
                    'rostering.publish.diff_head_title',
                    'Roster publish diff',
                )}
            />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={currentViewLabel}
                        caption={`${shownChanges.length} of ${viewTotal} shown`}
                    />

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
                            {shownChanges.length === 0 ? (
                                <div className="rounded-md border p-4 text-sm text-muted-foreground">
                                    {search.trim() !== '' ||
                                    view !== 'all' ||
                                    fieldFilter !== 'all'
                                        ? 'No changes match this view or your filters.'
                                        : t(
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
                                        {shownChanges.map((change) =>
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
                                                                {
                                                                    fieldChange.label
                                                                }
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
            </PageLayout>
        </AppLayout>
    );
}
