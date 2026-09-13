import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
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
import {
    AlertTriangle,
    CalendarDays,
    CheckCircle2,
    FileDiff,
    RotateCcw,
    ShieldCheck,
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

type ViewKey = 'blockers' | 'warnings' | 'shifts';

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

function issueTypeLabel(type: string): string {
    return type.replace(/_/g, ' ').replace(/^\w/, (m) => m.toUpperCase());
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

    const [view, setView] = useState<ViewKey>(
        hasBlocks ? 'blockers' : 'shifts',
    );
    const [search, setSearch] = useState('');
    const [issueType, setIssueType] = useState('all');
    const [publishState, setPublishState] = useState('all');

    const backHref = `/operations/rostering?week=${period.week_start}&site_id=${period.site_id}`;

    const matches = (haystack: string) => {
        const q = search.trim().toLowerCase();
        return !q || haystack.toLowerCase().includes(q);
    };

    const filterIssues = (entries: ValidationEntry[]) =>
        entries.filter(
            (entry) =>
                (issueType === 'all' || entry.issue_type === issueType) &&
                matches(
                    `${entry.message} ${entry.client ?? ''} ${entry.staff ?? ''} ${entry.issue_type}`,
                ),
        );

    const shownBlocks = useMemo(
        () => filterIssues(summary.blocks),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [summary.blocks, search, issueType],
    );
    const shownWarnings = useMemo(
        () => filterIssues(summary.warnings),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [summary.warnings, search, issueType],
    );
    const shownShifts = useMemo(
        () =>
            shifts.filter((shift) => {
                const state = shift.publish_dirty_at
                    ? 'changed'
                    : shift.published_at
                      ? 'published'
                      : 'draft';
                if (publishState !== 'all' && state !== publishState)
                    return false;
                return matches(
                    `${shift.client ?? ''} ${shift.staff ?? ''} ${
                        shift.site ?? ''
                    } ${shift.service_context ?? ''} ${shift.status}`,
                );
            }),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [shifts, search, publishState],
    );

    const issueTypeOptions = useMemo(
        () => [
            { value: 'all', label: 'All issue types' },
            ...Array.from(
                new Set(
                    [...summary.blocks, ...summary.warnings].map(
                        (entry) => entry.issue_type,
                    ),
                ),
            )
                .sort()
                .map((type) => ({ value: type, label: issueTypeLabel(type) })),
        ],
        [summary.blocks, summary.warnings],
    );

    const publishStateOptions = [
        { value: 'all', label: 'All publish states' },
        { value: 'draft', label: t('rostering.publish.draft', 'Draft') },
        {
            value: 'published',
            label: t('rostering.publish.published', 'Published'),
        },
        {
            value: 'changed',
            label: t('rostering.publish.state_changed', 'Changed'),
        },
    ];

    const railItems: PageHeaderRailItem<ViewKey>[] = [
        {
            key: 'blockers',
            label: t('rostering.publish.blockers', 'Blockers'),
            icon: AlertTriangle,
            count: summary.blocks.length,
            alert: true,
        },
        {
            key: 'warnings',
            label: t('rostering.publish.warnings', 'Warnings'),
            icon: ShieldCheck,
            count: summary.warnings.length,
        },
        {
            key: 'shifts',
            label: t('rostering.publish.period_shifts', 'Period shifts'),
            icon: CalendarDays,
            count: summary.shift_count ?? shifts.length,
        },
    ];

    const header = (
        <PageHeader
            variant="profile"
            backHref={backHref}
            icon={ShieldCheck}
            title={
                period.site_name ??
                t('rostering.publish.selected_site', 'Selected site')
            }
            titleChip={
                summary.can_publish ? (
                    <PageHeaderStatusChip variant="success">
                        {t(
                            'rostering.publish.ready_to_publish',
                            'Ready to publish',
                        )}
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="critical">
                        {t('rostering.publish.blocked', 'Blocked')}
                    </PageHeaderStatusChip>
                )
            }
            subline={`${t('rostering.publish.review_title', 'Publish review')} · ${t(
                'rostering.publish.week_of',
                'Week of',
            )} ${period.week_start} · ${t('rostering.publish.version', 'Version')} ${period.version} · ${t(
                'rostering.publish.last_reviewed',
                'Last reviewed',
            )} ${formatDateTime(period.last_validated_at, t)}`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search issues and shifts…"
                    />
                    <PageHeaderGlassButton
                        icon={RotateCcw}
                        disabled={period.status === 'archived'}
                        className="disabled:pointer-events-none disabled:opacity-50"
                        onClick={() => postPeriodAction(period, 'review')}
                    >
                        {t('rostering.publish.rerun_review', 'Re-run review')}
                    </PageHeaderGlassButton>
                    {period.published_at ? (
                        <PageHeaderGlassButton
                            icon={FileDiff}
                            onClick={() =>
                                router.visit(
                                    `/operations/rostering/periods/${period.id}/diff`,
                                )
                            }
                        >
                            {t('rostering.publish.view_diff', 'View diff')}
                        </PageHeaderGlassButton>
                    ) : null}
                    <PageHeaderPrimaryButton
                        icon={CheckCircle2}
                        disabled={hasBlocks || period.status === 'archived'}
                        className="disabled:pointer-events-none disabled:opacity-50"
                        onClick={() => postPeriodAction(period, confirmAction)}
                        data-test="publish-review-confirm"
                    >
                        {isRepublish
                            ? t('rostering.publish.republish', 'Re-publish')
                            : t(
                                  'rostering.publish.confirm_publish',
                                  'Confirm publish',
                              )}
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label={t(
                            'rostering.publish.shifts_reviewed',
                            'Shifts reviewed',
                        )}
                        ariaLabel="View period shifts"
                        onClick={() => setView('shifts')}
                    >
                        <PageHeaderMeterBig>
                            {summary.shift_count ?? shifts.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            in this roster period
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label={t('rostering.publish.blockers', 'Blockers')}
                        tone={hasBlocks ? 'critical' : 'success'}
                        ariaLabel="View publish blockers"
                        onClick={() => setView('blockers')}
                    >
                        <PageHeaderMeterBig>
                            {summary.blocks.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            must be fixed before publishing
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label={t('rostering.publish.warnings', 'Warnings')}
                        tone={
                            summary.warnings.length > 0 ? 'warning' : 'success'
                        }
                        ariaLabel="View publish warnings"
                        onClick={() => setView('warnings')}
                    >
                        <PageHeaderMeterBig>
                            {summary.warnings.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            worth checking, not blocking
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                view === 'shifts' ? (
                    <PageHeaderFilterSelect
                        icon={CalendarDays}
                        label="All publish states"
                        value={publishState}
                        options={publishStateOptions}
                        onChange={setPublishState}
                    />
                ) : (
                    <PageHeaderFilterSelect
                        icon={AlertTriangle}
                        label="All issue types"
                        value={issueType}
                        options={issueTypeOptions}
                        onChange={setIssueType}
                    />
                )
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={view}
                    onSelect={setView}
                    ariaLabel="Review views"
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
                    title: t(
                        'rostering.publish.review_title',
                        'Publish review',
                    ),
                    href: `/operations/rostering/periods/${period.id}/review`,
                },
            ]}
        >
            <Head
                title={t(
                    'rostering.publish.review_head_title',
                    'Roster publish review',
                )}
            />

            <PageLayout hero={header}>
                <div data-test="publish-review-page" className="space-y-4">
                    {view === 'blockers' ? (
                        <>
                            <ListCaption
                                title={t(
                                    'rostering.publish.publish_blockers',
                                    'Publish blockers',
                                )}
                                caption={`${shownBlocks.length} of ${summary.blocks.length} shown`}
                            />
                            <IssueList
                                title={t(
                                    'rostering.publish.publish_blockers',
                                    'Publish blockers',
                                )}
                                entries={shownBlocks}
                                variant="destructive"
                                emptyLabel={t(
                                    'rostering.publish.nothing_to_resolve',
                                    'Nothing to resolve here.',
                                )}
                                fixLabel={t('rostering.publish.fix', 'Fix')}
                                t={t}
                            />
                        </>
                    ) : null}

                    {view === 'warnings' ? (
                        <>
                            <ListCaption
                                title={t(
                                    'rostering.publish.warnings',
                                    'Warnings',
                                )}
                                caption={`${shownWarnings.length} of ${summary.warnings.length} shown`}
                            />
                            <IssueList
                                title={t(
                                    'rostering.publish.warnings',
                                    'Warnings',
                                )}
                                entries={shownWarnings}
                                variant="outline"
                                emptyLabel={t(
                                    'rostering.publish.nothing_to_resolve',
                                    'Nothing to resolve here.',
                                )}
                                fixLabel={t('rostering.publish.fix', 'Fix')}
                                t={t}
                            />
                        </>
                    ) : null}

                    {view === 'shifts' ? (
                        <>
                            <ListCaption
                                title={t(
                                    'rostering.publish.period_shifts',
                                    'Period shifts',
                                )}
                                caption={`${shownShifts.length} of ${shifts.length} shown`}
                            />
                            <Card>
                                <CardContent>
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>
                                                    {t(
                                                        'rostering.publish.shift',
                                                        'Shift',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {t(
                                                        'rostering.publish.client',
                                                        'Client',
                                                    )}
                                                </TableHead>
                                                <TableHead>
                                                    {t(
                                                        'rostering.publish.staff',
                                                        'Staff',
                                                    )}
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
                                            {shownShifts.map((shift) => (
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
                        </>
                    ) : null}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
