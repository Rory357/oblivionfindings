import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
    type PageHeaderRailItem,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    Calendar,
    CalendarClock,
    CheckCircle2,
    ClipboardCheck,
    ClipboardList,
    Clock,
    Plus,
    Repeat,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type Site = {
    id: number;
    name: string;
    type: string;
};

type Schedule = {
    id: number;
    inspection_type: string;
    title: string;
    description?: string;
    frequency: string;
    next_due_date: string;
    assigned_to?: { id: number; name: string } | null;
    is_active: boolean;
};

type InspectionRecord = {
    id: number;
    due_date: string;
    completed_at?: string;
    completed_by?: { id: number; name: string } | null;
    result?: 'pass' | 'fail' | 'partial' | 'na';
    findings?: string;
};

type Props = {
    site: Site;
    schedules: Schedule[];
    records: {
        data: InspectionRecord[];
    };
};

type View = 'schedules' | 'records';

const frequencyLabels: Record<string, string> = {
    weekly: 'Weekly',
    monthly: 'Monthly',
    quarterly: 'Quarterly',
    bi_annual: 'Bi-annual',
    annual: 'Annual',
    custom: 'Custom',
};

const resultColors: Record<string, string> = {
    pass: 'border-status-success/30 text-status-success bg-status-success',
    fail: 'border-status-critical/30 text-status-critical bg-status-critical',
    partial: 'border-status-warning/30 text-status-warning bg-status-warning',
    na: 'border-border/30 text-muted-foreground',
};

export default function SiteInspections({ site, schedules, records }: Props) {
    const [showForm, setShowForm] = useState(false);
    const [view, setView] = useState<View>('schedules');
    const [query, setQuery] = useState('');
    const [frequencyFilter, setFrequencyFilter] = useState('all');
    const [dueStateFilter, setDueStateFilter] = useState('all');
    const [resultFilter, setResultFilter] = useState('all');

    // Deep link from the Site Profile header's "Book Inspection" quick
    // action: ?action=add opens the schedule form once, then drops the param.
    useEffect(() => {
        const url = new URL(window.location.href);
        if (url.searchParams.get('action') !== 'add') return;
        url.searchParams.delete('action');
        window.history.replaceState(window.history.state, '', url);
        setView('schedules');
        setShowForm(true);
    }, []);

    const form = useForm({
        inspection_type: '',
        title: '',
        description: '',
        frequency: 'monthly' as const,
        first_due_date: '',
        assigned_to_user_id: '',
        auto_create_calendar_event: true,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/sites/${site.id}/inspections`, {
            onSuccess: () => {
                setShowForm(false);
                form.reset();
            },
        });
    };

    const today = useMemo(() => new Date(), []);
    const sevenDaysFromNow = useMemo(() => {
        const date = new Date(today);
        date.setDate(today.getDate() + 7);
        return date;
    }, [today]);
    const isOverdue = (date: string) => new Date(date) < today;
    const isDueSoon = (date: string) => {
        const due = new Date(date);
        return due >= today && due <= sevenDaysFromNow;
    };

    const q = query.trim().toLowerCase();

    // Header instruments read the site's totals; the lists below reflect the
    // active search + filter pills.
    const overdueCount = schedules.filter((s) =>
        isOverdue(s.next_due_date),
    ).length;
    const dueSoonCount = schedules.filter((s) =>
        isDueSoon(s.next_due_date),
    ).length;
    const activeCount = schedules.filter((s) => s.is_active).length;
    const passedCount = records.data.filter((r) => r.result === 'pass').length;

    const filteredSchedules = useMemo(() => {
        return schedules.filter((s) => {
            if (frequencyFilter !== 'all' && s.frequency !== frequencyFilter)
                return false;
            if (dueStateFilter === 'overdue' && !isOverdue(s.next_due_date))
                return false;
            if (dueStateFilter === 'due_soon' && !isDueSoon(s.next_due_date))
                return false;
            if (
                q &&
                ![
                    s.title,
                    s.inspection_type,
                    frequencyLabels[s.frequency],
                    s.assigned_to?.name,
                ]
                    .filter(Boolean)
                    .some((v) => v!.toLowerCase().includes(q))
            )
                return false;
            return true;
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        schedules,
        frequencyFilter,
        dueStateFilter,
        q,
        today,
        sevenDaysFromNow,
    ]);

    const filteredRecords = useMemo(() => {
        return records.data.filter((r) => {
            if (resultFilter !== 'all' && r.result !== resultFilter)
                return false;
            if (
                q &&
                ![r.due_date, r.findings, r.result]
                    .filter(Boolean)
                    .some((v) => v!.toLowerCase().includes(q))
            )
                return false;
            return true;
        });
    }, [records.data, resultFilter, q]);

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
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Inspections', href: `/sites/${site.id}/inspections` },
            ]}
        >
            <Head title={`${site.name} - Inspections`} />

            <PageLayout
                hero={
                    <PageHeader
                        variant="profile"
                        backHref={`/sites/${site.id}`}
                        icon={ClipboardCheck}
                        title={site.name}
                        titleChip={
                            overdueCount > 0 ? (
                                <PageHeaderStatusChip variant="critical">
                                    {overdueCount} overdue
                                </PageHeaderStatusChip>
                            ) : schedules.length > 0 ? (
                                <PageHeaderStatusChip variant="success">
                                    On schedule
                                </PageHeaderStatusChip>
                            ) : (
                                <PageHeaderStatusChip variant="neutral">
                                    No schedules
                                </PageHeaderStatusChip>
                            )
                        }
                        subline={`Inspections & maintenance · ${schedules.length} ${schedules.length === 1 ? 'schedule' : 'schedules'} · ${records.data.length} recent records`}
                        actions={
                            <>
                                <PageHeaderSearch
                                    value={query}
                                    onChange={setQuery}
                                    placeholder="Search inspections…"
                                />
                                <PageHeaderPrimaryButton
                                    icon={Plus}
                                    onClick={() => {
                                        setView('schedules');
                                        setShowForm(true);
                                    }}
                                >
                                    Schedule inspection
                                </PageHeaderPrimaryButton>
                            </>
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
                                        {schedules.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {activeCount} active
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
                                        {passedCount}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        of {records.data.length} records
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={
                            view === 'schedules' ? (
                                <>
                                    <PageHeaderFilterSelect
                                        icon={Repeat}
                                        label="Any frequency"
                                        value={frequencyFilter}
                                        options={[
                                            {
                                                value: 'all',
                                                label: 'Any frequency',
                                            },
                                            ...Object.entries(
                                                frequencyLabels,
                                            ).map(([value, label]) => ({
                                                value,
                                                label,
                                            })),
                                        ]}
                                        onChange={setFrequencyFilter}
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
                                        { value: 'all', label: 'Any result' },
                                        { value: 'pass', label: 'Pass' },
                                        { value: 'fail', label: 'Fail' },
                                        { value: 'partial', label: 'Partial' },
                                        { value: 'na', label: 'N/A' },
                                    ]}
                                    onChange={setResultFilter}
                                />
                            )
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
                {/* Add Form */}
                {showForm && (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Schedule New Inspection</CardTitle>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setShowForm(false)}
                            >
                                <X className="h-4 w-4" />
                            </Button>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label>Inspection Type *</Label>
                                        <Input
                                            value={form.data.inspection_type}
                                            onChange={(e) =>
                                                form.setData(
                                                    'inspection_type',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g., Fire Safety, Electrical"
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Title *</Label>
                                        <Input
                                            value={form.data.title}
                                            onChange={(e) =>
                                                form.setData(
                                                    'title',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label>Frequency *</Label>
                                        <Select
                                            value={form.data.frequency}
                                            onValueChange={(v) =>
                                                form.setData(
                                                    'frequency',
                                                    v as any,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="weekly">
                                                    Weekly
                                                </SelectItem>
                                                <SelectItem value="monthly">
                                                    Monthly
                                                </SelectItem>
                                                <SelectItem value="quarterly">
                                                    Quarterly
                                                </SelectItem>
                                                <SelectItem value="bi_annual">
                                                    Bi-annual
                                                </SelectItem>
                                                <SelectItem value="annual">
                                                    Annual
                                                </SelectItem>
                                                <SelectItem value="custom">
                                                    Custom
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label>First Due Date *</Label>
                                        <Input
                                            type="date"
                                            value={form.data.first_due_date}
                                            onChange={(e) =>
                                                form.setData(
                                                    'first_due_date',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        />
                                    </div>
                                </div>
                                <div>
                                    <Label>Description</Label>
                                    <Textarea
                                        value={form.data.description}
                                        onChange={(e) =>
                                            form.setData(
                                                'description',
                                                e.target.value,
                                            )
                                        }
                                        rows={3}
                                    />
                                </div>
                                <div className="flex items-center gap-2">
                                    <input
                                        type="checkbox"
                                        checked={
                                            form.data.auto_create_calendar_event
                                        }
                                        onChange={(e) =>
                                            form.setData(
                                                'auto_create_calendar_event',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    <Label className="font-normal">
                                        Create calendar event
                                    </Label>
                                </div>
                                <div className="flex gap-2">
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                    >
                                        Schedule
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setShowForm(false)}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {view === 'schedules' ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Calendar className="h-4 w-4" />
                                Schedules ({filteredSchedules.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {filteredSchedules.length === 0 ? (
                                <p className="py-4 text-center text-muted-foreground">
                                    No inspection schedules match your filters.
                                </p>
                            ) : (
                                <div className="space-y-2">
                                    {filteredSchedules.map((schedule) => (
                                        <div
                                            key={schedule.id}
                                            className="flex items-center justify-between rounded-lg border p-3 hover:bg-muted/50"
                                        >
                                            <div>
                                                <div className="font-medium">
                                                    {schedule.title}
                                                </div>
                                                <div className="text-sm text-muted-foreground">
                                                    {schedule.inspection_type} -{' '}
                                                    {
                                                        frequencyLabels[
                                                            schedule.frequency
                                                        ]
                                                    }
                                                </div>
                                                {schedule.assigned_to && (
                                                    <div className="text-xs text-muted-foreground">
                                                        Assigned:{' '}
                                                        {
                                                            schedule.assigned_to
                                                                .name
                                                        }
                                                    </div>
                                                )}
                                            </div>
                                            <div className="text-right">
                                                <div
                                                    className={`flex items-center gap-1 text-sm ${isOverdue(schedule.next_due_date) ? 'text-status-critical' : 'text-muted-foreground'}`}
                                                >
                                                    {isOverdue(
                                                        schedule.next_due_date,
                                                    ) ? (
                                                        <AlertCircle className="h-4 w-4" />
                                                    ) : (
                                                        <Clock className="h-4 w-4" />
                                                    )}
                                                    Due:{' '}
                                                    {new Date(
                                                        schedule.next_due_date,
                                                    ).toLocaleDateString()}
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <CheckCircle2 className="h-4 w-4" />
                                Records ({filteredRecords.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {filteredRecords.length === 0 ? (
                                <p className="py-4 text-center text-muted-foreground">
                                    No inspection records match your filters.
                                </p>
                            ) : (
                                <div className="space-y-2">
                                    {filteredRecords
                                        .slice(0, 10)
                                        .map((record) => (
                                            <div
                                                key={record.id}
                                                className="flex items-center justify-between rounded-lg border p-3 hover:bg-muted/50"
                                            >
                                                <div>
                                                    <div className="font-medium">
                                                        {record.due_date}
                                                    </div>
                                                    {record.findings && (
                                                        <div className="text-sm text-muted-foreground">
                                                            {record.findings}
                                                        </div>
                                                    )}
                                                </div>
                                                {record.result && (
                                                    <Badge
                                                        className={
                                                            resultColors[
                                                                record.result
                                                            ]
                                                        }
                                                    >
                                                        {record.result.toUpperCase()}
                                                    </Badge>
                                                )}
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
