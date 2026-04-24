import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ClipboardCheck,
    Clock,
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
    ]);

    const filteredRecords = useMemo(() => {
        return records.filter((r) => {
            if (siteFilter !== 'all' && String(r.site_id) !== siteFilter)
                return false;
            if (resultFilter !== 'all' && r.result !== resultFilter)
                return false;
            return true;
        });
    }, [records, siteFilter, resultFilter]);

    const overdueCount = filteredSchedules.filter(
        (s) => s.next_due_date && new Date(s.next_due_date) < today,
    ).length;
    const dueSoonCount = filteredSchedules.filter(
        (s) =>
            s.next_due_date &&
            new Date(s.next_due_date) >= today &&
            new Date(s.next_due_date) <= sevenDaysFromNow,
    ).length;
    const completedPassCount = filteredRecords.filter(
        (r) => r.result === 'pass',
    ).length;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                {
                    title: 'Inspections & Maintenance',
                    href: '/sites/inspections',
                },
            ]}
        >
            <Head title="Inspections & Maintenance" />

            <div className="m-4 space-y-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="flex items-center gap-2 text-lg font-semibold">
                            <ClipboardCheck className="h-5 w-5" />
                            Inspections & Maintenance
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            All sites
                        </p>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-4">
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">
                                {filteredSchedules.length}
                            </div>
                            <div className="text-sm text-muted-foreground">
                                Schedules
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-status-critical/20 bg-status-critical">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-status-critical">
                                {overdueCount}
                            </div>
                            <div className="text-sm text-muted-foreground">
                                Overdue
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-status-warning/20 bg-status-warning">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-status-warning">
                                {dueSoonCount}
                            </div>
                            <div className="text-sm text-muted-foreground">
                                Due In 7 Days
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-status-success/20 bg-status-success">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-status-success">
                                {completedPassCount}
                            </div>
                            <div className="text-sm text-muted-foreground">
                                Passed Records
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 md:grid-cols-5">
                            <div>
                                <Label className="text-xs">Site</Label>
                                <Select
                                    value={siteFilter}
                                    onValueChange={setSiteFilter}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            All Sites
                                        </SelectItem>
                                        {sites.map((site) => (
                                            <SelectItem
                                                key={site.id}
                                                value={String(site.id)}
                                            >
                                                {site.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">
                                    Inspection Type
                                </Label>
                                <Select
                                    value={inspectionTypeFilter}
                                    onValueChange={setInspectionTypeFilter}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            All Types
                                        </SelectItem>
                                        {inspectionTypes.map((type) => (
                                            <SelectItem key={type} value={type}>
                                                {type}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">
                                    Schedule Status
                                </Label>
                                <Select
                                    value={statusFilter}
                                    onValueChange={setStatusFilter}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All</SelectItem>
                                        <SelectItem value="active">
                                            Active
                                        </SelectItem>
                                        <SelectItem value="inactive">
                                            Inactive
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Due State</Label>
                                <Select
                                    value={dueStateFilter}
                                    onValueChange={setDueStateFilter}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All</SelectItem>
                                        <SelectItem value="overdue">
                                            Overdue
                                        </SelectItem>
                                        <SelectItem value="due_soon">
                                            Due Soon
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Record Result</Label>
                                <Select
                                    value={resultFilter}
                                    onValueChange={setResultFilter}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All</SelectItem>
                                        <SelectItem value="pass">
                                            Pass
                                        </SelectItem>
                                        <SelectItem value="fail">
                                            Fail
                                        </SelectItem>
                                        <SelectItem value="partial">
                                            Partial
                                        </SelectItem>
                                        <SelectItem value="na">N/A</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </CardContent>
                </Card>

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
                                                    {schedule.inspection_type} •{' '}
                                                    {schedule.frequency}
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
            </div>
        </AppLayout>
    );
}
