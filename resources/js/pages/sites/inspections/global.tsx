import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
    type PageHeaderRailItem,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CalendarClock,
    CheckCircle2,
    ClipboardCheck,
    ClipboardList,
    Clock,
    Wrench,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type SiteOption = {
    id: number;
    name: string;
    type: 'head_office' | 'house' | 'facility' | 'residential';
};

type InspectionSchedule = {
    id: number;
    site_id: number;
    site_name?: string | null;
    site_type?: string | null;
    inspection_type: string;
    title: string;
    frequency: string;
    next_due_date?: string | null;
    is_active: boolean;
    assigned_to_name?: string | null;
};

type InspectionRecord = {
    id: number;
    site_id: number;
    site_name?: string | null;
    site_type?: string | null;
    schedule_title?: string | null;
    due_date?: string | null;
    completed_at?: string | null;
    completed_by_name?: string | null;
    result?: 'pass' | 'fail' | 'partial' | 'na' | null;
    findings?: string | null;
};

type Props = {
    schedules: InspectionSchedule[];
    records: InspectionRecord[];
    sites: SiteOption[];
    inspectionTypes: string[];
    filters: {
        site_id?: string | number;
        inspection_type?: string;
        status?: 'active' | 'inactive';
        due_state?: 'overdue' | 'due_soon';
        result?: 'pass' | 'fail' | 'partial' | 'na';
    };
};

type View = 'schedules' | 'records';

const resultColors: Record<string, string> = {
    pass: 'border-status-success/30 text-status-success bg-status-success',
    fail: 'border-status-critical/30 text-status-critical bg-status-critical',
    partial: 'border-status-warning/30 text-status-warning bg-status-warning',
    na: 'border-border/30 text-muted-foreground',
};

export default function GlobalSiteInspections({
    schedules,
    records,
    sites,
    inspectionTypes,
    filters,
}: Props) {
    const [view, setView] = useState<View>('schedules');
    const [query, setQuery] = useState('');
    const [siteFilter, setSiteFilter] = useState<string>(
        filters.site_id ? String(filters.site_id) : 'all',
    );
    const [inspectionTypeFilter, setInspectionTypeFilter] = useState<string>(
        filters.inspection_type ?? 'all',
    );
    const [statusFilter, setStatusFilter] = useState<string>(
        filters.status ?? 'all',
    );
    const [dueStateFilter, setDueStateFilter] = useState<string>(
        filters.due_state ?? 'all',
    );
    const [resultFilter, setResultFilter] = useState<string>(
        filters.result ?? 'all',
    );

    const today = useMemo(() => new Date(), []);
    const sevenDaysFromNow = useMemo(() => {
        const date = new Date(today);
        date.setDate(today.getDate() + 7);
        return date;
    }, [today]);

    const q = query.trim().toLowerCase();

    const filteredSchedules = useMemo(() => {
        return schedules.filter((s) => {
            if (siteFilter !== 'all' && String(s.site_id) !== siteFilter)
                return false;
            if (
                inspectionTypeFilter !== 'all' &&
                s.inspection_type !== inspectionTypeFilter
            )
                return false;
            if (statusFilter === 'active' && !s.is_active) return false;
            if (statusFilter === 'inactive' && s.is_active) return false;
            if (dueStateFilter !== 'all' && s.next_due_date) {
                const due = new Date(s.next_due_date);
                if (dueStateFilter === 'overdue' && due >= today) return false;
                if (
                    dueStateFilter === 'due_soon' &&
                    (due < today || due > sevenDaysFromNow)
                )
                    return false;
            }
            if (
                q &&
                ![s.title, s.site_name, s.inspection_type]
                    .filter(Boolean)
                    .some((v) => v!.toLowerCase().includes(q))
            )
                return false;
            return true;
        });
    }, [
        schedules,
        siteFilter,
        inspectionTypeFilter,
        statusFilter,
        dueStateFilter,
        today,
        sevenDaysFromNow,
        q,
    ]);

    const filteredRecords = useMemo(() => {
        return records.filter((r) => {
            if (siteFilter !== 'all' && String(r.site_id) !== siteFilter)
                return false;
            if (resultFilter !== 'all' && r.result !== resultFilter)
                return false;
            if (
                q &&
                ![r.schedule_title, r.site_name]
                    .filter(Boolean)
                    .some((v) => v!.toLowerCase().includes(q))
            )
                return false;
            return true;
        });
    }, [records, siteFilter, resultFilter, q]);

    const overdueCount = filteredSchedules.filter(
        (s) => s.next_due_date && new Date(s.next_due_date) < today,
    ).length;
    const dueSoonCount = filteredSchedules.filter(
        (s) =>
            s.next_due_date &&
            new Date(s.next_due_date) >= today &&
            new Date(s.next_due_date) <= sevenDaysFromNow,
    ).length;
    const activeCount = filteredSchedules.filter((s) => s.is_active).length;
    const completedPassCount = filteredRecords.filter(
        (r) => r.result === 'pass',
    ).length;

    const railItems: PageHeaderRailItem<View>[] = [
        {
            key: 'schedules',
            label: 'Schedules',
            icon: CalendarClock,
            count: filteredSchedules.length,
        },
        {
            key: 'records',
            label: 'Records',
            icon: ClipboardList,
            count: filteredRecords.length,
        },
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Sites', href: '/sites' },
                {
                    title: 'Inspections & Maintenance',
                    href: '/sites/inspections',
                },
            ]}
        >
            <Head title="Inspections & Maintenance" />

            <PageLayout
                hero={
                    <PageHeader
                        icon={ClipboardCheck}
                        title="Inspections & Maintenance"
                        subline={`Schedules and records across every site · ${sites.length} ${sites.length === 1 ? 'site' : 'sites'} · ${schedules.length} schedules · ${records.length} recent records`}
                        actions={
                            <PageHeaderSearch
                                value={query}
                                onChange={setQuery}
                                placeholder="Search inspections, sites…"
                            />
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Schedules"
                                    ariaLabel="View inspection schedules"
                                    onClick={() => {
                                        setView('schedules');
                                        setDueStateFilter('all');
                                    }}
                                >
                                    <PageHeaderMeterBig>
                                        {filteredSchedules.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {activeCount} active ·{' '}
                                        {filteredSchedules.length - activeCount}{' '}
                                        inactive
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Overdue"
                                    tone={
                                        overdueCount > 0
                                            ? 'critical'
                                            : 'success'
                                    }
                                    ariaLabel="View overdue schedules"
                                    onClick={() => {
                                        setView('schedules');
                                        setDueStateFilter('overdue');
                                    }}
                                >
                                    <PageHeaderMeterBig>
                                        {overdueCount}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        past due date
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Due in 7 days"
                                    tone={
                                        dueSoonCount > 0 ? 'warning' : 'brand'
                                    }
                                    ariaLabel="View schedules due soon"
                                    onClick={() => {
                                        setView('schedules');
                                        setDueStateFilter('due_soon');
                                    }}
                                >
                                    <PageHeaderMeterBig>
                                        {dueSoonCount}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        within the next week
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Passed records"
                                    tone="success"
                                    ariaLabel="View passed inspection records"
                                    onClick={() => {
                                        setView('records');
                                        setResultFilter('pass');
                                    }}
                                >
                                    <PageHeaderMeterBig>
                                        {completedPassCount}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        of {filteredRecords.length} shown
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={
                            <>
                                <PageHeaderFilterSelect
                                    icon={Building2}
                                    label="All sites"
                                    value={siteFilter}
                                    options={[
                                        { value: 'all', label: 'All sites' },
                                        ...sites.map((site) => ({
                                            value: String(site.id),
                                            label: site.name,
                                        })),
                                    ]}
                                    onChange={setSiteFilter}
                                />
                                <PageHeaderFilterSelect
                                    icon={Wrench}
                                    label="All types"
                                    value={inspectionTypeFilter}
                                    options={[
                                        { value: 'all', label: 'All types' },
                                        ...inspectionTypes.map((type) => ({
                                            value: type,
                                            label: type,
                                        })),
                                    ]}
                                    onChange={setInspectionTypeFilter}
                                />
                                {view === 'schedules' ? (
                                    <>
                                        <PageHeaderFilterSelect
                                            label="Any status"
                                            value={statusFilter}
                                            options={[
                                                {
                                                    value: 'all',
                                                    label: 'Any status',
                                                },
                                                {
                                                    value: 'active',
                                                    label: 'Active',
                                                },
                                                {
                                                    value: 'inactive',
                                                    label: 'Inactive',
                                                },
                                            ]}
                                            onChange={setStatusFilter}
                                        />
                                        <PageHeaderFilterSelect
                                            icon={CalendarClock}
                                            label="Any due date"
                                            value={dueStateFilter}
                                            options={[
                                                {
                                                    value: 'all',
                                                    label: 'Any due date',
                                                },
                                                {
                                                    value: 'overdue',
                                                    label: 'Overdue',
                                                },
                                                {
                                                    value: 'due_soon',
                                                    label: 'Due soon',
                                                },
                                            ]}
                                            onChange={setDueStateFilter}
                                        />
                                    </>
                                ) : (
                                    <PageHeaderFilterSelect
                                        icon={CheckCircle2}
                                        label="Any result"
                                        value={resultFilter}
                                        options={[
                                            {
                                                value: 'all',
                                                label: 'Any result',
                                            },
                                            { value: 'pass', label: 'Pass' },
                                            { value: 'fail', label: 'Fail' },
                                            {
                                                value: 'partial',
                                                label: 'Partial',
                                            },
                                            { value: 'na', label: 'N/A' },
                                        ]}
                                        onChange={setResultFilter}
                                    />
                                )}
                            </>
                        }
                        rail={
                            <PageHeaderRail
                                items={railItems}
                                value={view}
                                onSelect={setView}
                                ariaLabel="Inspection views"
                            />
                        }
                    />
                }
            >
                {view === 'schedules' ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Schedules ({filteredSchedules.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {filteredSchedules.length === 0 ? (
                                <div className="py-8 text-center text-muted-foreground">
                                    No inspection schedules match your filters.
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    {filteredSchedules.map((schedule) => {
                                        const overdue =
                                            !!schedule.next_due_date &&
                                            new Date(schedule.next_due_date) <
                                                today;
                                        return (
                                            <div
                                                key={schedule.id}
                                                className="flex items-center justify-between gap-3 rounded-lg border p-3"
                                            >
                                                <div>
                                                    <div className="font-medium">
                                                        {schedule.title}
                                                    </div>
                                                    <div className="text-sm text-muted-foreground">
                                                        {schedule.site_name} •{' '}
                                                        {
                                                            schedule.inspection_type
                                                        }{' '}
                                                        • {schedule.frequency}
                                                    </div>
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        {schedule.assigned_to_name
                                                            ? `Assigned: ${schedule.assigned_to_name} • `
                                                            : ''}
                                                        Due:{' '}
                                                        {schedule.next_due_date ??
                                                            '—'}
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    {!schedule.is_active && (
                                                        <Badge
                                                            variant="outline"
                                                            className="border-border/30 text-muted-foreground"
                                                        >
                                                            Inactive
                                                        </Badge>
                                                    )}
                                                    {overdue ? (
                                                        <Badge
                                                            variant="outline"
                                                            className="border-status-critical/30 bg-status-critical text-status-critical"
                                                        >
                                                            <AlertTriangle className="mr-1 h-3 w-3" />
                                                            Overdue
                                                        </Badge>
                                                    ) : (
                                                        <Badge
                                                            variant="outline"
                                                            className="border-border/30 text-muted-foreground"
                                                        >
                                                            <Clock className="mr-1 h-3 w-3" />
                                                            Scheduled
                                                        </Badge>
                                                    )}
                                                    <Button
                                                        asChild
                                                        size="sm"
                                                        variant="outline"
                                                    >
                                                        <Link
                                                            href={`/sites/${schedule.site_id}/inspections`}
                                                        >
                                                            Open Site
                                                        </Link>
                                                    </Button>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Recent Records ({filteredRecords.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {filteredRecords.length === 0 ? (
                                <div className="py-8 text-center text-muted-foreground">
                                    No records match your filters.
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    {filteredRecords.map((record) => (
                                        <div
                                            key={record.id}
                                            className="flex items-center justify-between gap-3 rounded-lg border p-3"
                                        >
                                            <div>
                                                <div className="font-medium">
                                                    {record.schedule_title ||
                                                        'Inspection record'}
                                                </div>
                                                <div className="text-sm text-muted-foreground">
                                                    {record.site_name} • Due{' '}
                                                    {record.due_date ?? '—'}
                                                </div>
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    Completed:{' '}
                                                    {record.completed_at ?? '—'}
                                                    {record.completed_by_name
                                                        ? ` • By ${record.completed_by_name}`
                                                        : ''}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                {record.result && (
                                                    <Badge
                                                        variant="outline"
                                                        className={
                                                            resultColors[
                                                                record.result
                                                            ] ||
                                                            'border-border/30 text-muted-foreground'
                                                        }
                                                    >
                                                        {record.result ===
                                                        'pass' ? (
                                                            <CheckCircle2 className="mr-1 h-3 w-3" />
                                                        ) : null}
                                                        {record.result.toUpperCase()}
                                                    </Badge>
                                                )}
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={`/sites/${record.site_id}/inspections`}
                                                    >
                                                        Open Site
                                                    </Link>
                                                </Button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}
            </PageLayout>
        </AppLayout>
    );
}
