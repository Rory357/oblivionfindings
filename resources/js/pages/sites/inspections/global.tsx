import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ClipboardCheck, Clock, AlertTriangle, CheckCircle2 } from 'lucide-react';
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
    pass: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10',
    fail: 'border-red-500/30 text-red-400 bg-red-500/10',
    partial: 'border-amber-500/30 text-amber-400 bg-amber-500/10',
    na: 'border-slate-500/30 text-slate-400',
};

export default function GlobalSiteInspections({
    schedules,
    records,
    sites,
    inspectionTypes,
    filters,
}: Props) {
    const [siteFilter, setSiteFilter] = useState<string>(filters.site_id ? String(filters.site_id) : 'all');
    const [inspectionTypeFilter, setInspectionTypeFilter] = useState<string>(filters.inspection_type ?? 'all');
    const [statusFilter, setStatusFilter] = useState<string>(filters.status ?? 'all');
    const [dueStateFilter, setDueStateFilter] = useState<string>(filters.due_state ?? 'all');
    const [resultFilter, setResultFilter] = useState<string>(filters.result ?? 'all');

    const today = new Date();
    const sevenDaysFromNow = new Date();
    sevenDaysFromNow.setDate(today.getDate() + 7);

    const filteredSchedules = useMemo(() => {
        return schedules.filter((s) => {
            if (siteFilter !== 'all' && String(s.site_id) !== siteFilter) return false;
            if (inspectionTypeFilter !== 'all' && s.inspection_type !== inspectionTypeFilter) return false;
            if (statusFilter === 'active' && !s.is_active) return false;
            if (statusFilter === 'inactive' && s.is_active) return false;
            if (dueStateFilter !== 'all' && s.next_due_date) {
                const due = new Date(s.next_due_date);
                if (dueStateFilter === 'overdue' && due >= today) return false;
                if (dueStateFilter === 'due_soon' && (due < today || due > sevenDaysFromNow)) return false;
            }
            return true;
        });
    }, [schedules, siteFilter, inspectionTypeFilter, statusFilter, dueStateFilter, today, sevenDaysFromNow]);

    const filteredRecords = useMemo(() => {
        return records.filter((r) => {
            if (siteFilter !== 'all' && String(r.site_id) !== siteFilter) return false;
            if (resultFilter !== 'all' && r.result !== resultFilter) return false;
            return true;
        });
    }, [records, siteFilter, resultFilter]);

    const overdueCount = filteredSchedules.filter((s) => s.next_due_date && new Date(s.next_due_date) < today).length;
    const dueSoonCount = filteredSchedules.filter((s) => s.next_due_date && new Date(s.next_due_date) >= today && new Date(s.next_due_date) <= sevenDaysFromNow).length;
    const completedPassCount = filteredRecords.filter((r) => r.result === 'pass').length;

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: 'Inspections & Maintenance', href: '/sites/inspections' }]}>
            <Head title="Inspections & Maintenance" />

            <div className="m-4 space-y-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <ClipboardCheck className="w-5 h-5" />
                            Inspections & Maintenance
                        </h1>
                        <p className="text-sm text-slate-400">All sites</p>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-4">
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">{filteredSchedules.length}</div>
                            <div className="text-sm text-slate-400">Schedules</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-red-500/5 border-red-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-red-400">{overdueCount}</div>
                            <div className="text-sm text-slate-400">Overdue</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-amber-500/5 border-amber-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-amber-400">{dueSoonCount}</div>
                            <div className="text-sm text-slate-400">Due In 7 Days</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-emerald-500/5 border-emerald-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-emerald-400">{completedPassCount}</div>
                            <div className="text-sm text-slate-400">Passed Records</div>
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
                                <Select value={siteFilter} onValueChange={setSiteFilter}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Sites</SelectItem>
                                        {sites.map((site) => (
                                            <SelectItem key={site.id} value={String(site.id)}>
                                                {site.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Inspection Type</Label>
                                <Select value={inspectionTypeFilter} onValueChange={setInspectionTypeFilter}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Types</SelectItem>
                                        {inspectionTypes.map((type) => (
                                            <SelectItem key={type} value={type}>
                                                {type}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Schedule Status</Label>
                                <Select value={statusFilter} onValueChange={setStatusFilter}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All</SelectItem>
                                        <SelectItem value="active">Active</SelectItem>
                                        <SelectItem value="inactive">Inactive</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Due State</Label>
                                <Select value={dueStateFilter} onValueChange={setDueStateFilter}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All</SelectItem>
                                        <SelectItem value="overdue">Overdue</SelectItem>
                                        <SelectItem value="due_soon">Due Soon</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Record Result</Label>
                                <Select value={resultFilter} onValueChange={setResultFilter}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All</SelectItem>
                                        <SelectItem value="pass">Pass</SelectItem>
                                        <SelectItem value="fail">Fail</SelectItem>
                                        <SelectItem value="partial">Partial</SelectItem>
                                        <SelectItem value="na">N/A</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Schedules ({filteredSchedules.length})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {filteredSchedules.length === 0 ? (
                            <div className="text-center py-8 text-slate-400">No inspection schedules match your filters.</div>
                        ) : (
                            <div className="space-y-2">
                                {filteredSchedules.map((schedule) => {
                                    const overdue = !!schedule.next_due_date && new Date(schedule.next_due_date) < today;
                                    return (
                                        <div key={schedule.id} className="rounded-lg border p-3 flex items-center justify-between gap-3">
                                            <div>
                                                <div className="font-medium">{schedule.title}</div>
                                                <div className="text-sm text-slate-400">
                                                    {schedule.site_name} • {schedule.inspection_type} • {schedule.frequency}
                                                </div>
                                                <div className="text-xs text-slate-500 mt-1">
                                                    {schedule.assigned_to_name ? `Assigned: ${schedule.assigned_to_name} • ` : ''}
                                                    Due: {schedule.next_due_date ?? '—'}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                {!schedule.is_active && (
                                                    <Badge variant="outline" className="border-slate-500/30 text-slate-400">
                                                        Inactive
                                                    </Badge>
                                                )}
                                                {overdue ? (
                                                    <Badge variant="outline" className="border-red-500/30 text-red-400 bg-red-500/10">
                                                        <AlertTriangle className="w-3 h-3 mr-1" />
                                                        Overdue
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="outline" className="border-slate-500/30 text-slate-300">
                                                        <Clock className="w-3 h-3 mr-1" />
                                                        Scheduled
                                                    </Badge>
                                                )}
                                                <Button asChild size="sm" variant="outline">
                                                    <Link href={`/sites/${schedule.site_id}/inspections`}>Open Site</Link>
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
                        <CardTitle className="text-base">Recent Records ({filteredRecords.length})</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {filteredRecords.length === 0 ? (
                            <div className="text-center py-8 text-slate-400">No records match your filters.</div>
                        ) : (
                            <div className="space-y-2">
                                {filteredRecords.map((record) => (
                                    <div key={record.id} className="rounded-lg border p-3 flex items-center justify-between gap-3">
                                        <div>
                                            <div className="font-medium">{record.schedule_title || 'Inspection record'}</div>
                                            <div className="text-sm text-slate-400">
                                                {record.site_name} • Due {record.due_date ?? '—'}
                                            </div>
                                            <div className="text-xs text-slate-500 mt-1">
                                                Completed: {record.completed_at ?? '—'}
                                                {record.completed_by_name ? ` • By ${record.completed_by_name}` : ''}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {record.result && (
                                                <Badge variant="outline" className={resultColors[record.result] || 'border-slate-500/30 text-slate-400'}>
                                                    {record.result === 'pass' ? <CheckCircle2 className="w-3 h-3 mr-1" /> : null}
                                                    {record.result.toUpperCase()}
                                                </Badge>
                                            )}
                                            <Button asChild size="sm" variant="outline">
                                                <Link href={`/sites/${record.site_id}/inspections`}>Open Site</Link>
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
