import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Building,
    Calendar,
    CalendarRange,
    CheckCircle2,
    ChevronDown,
    DoorOpen,
    Download,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

type Office = {
    id: number;
    name: string;
    ho_resources_count: number;
    hazards: Array<{
        id: number;
        severity: string;
        status: string;
    }>;
    checklist_runs: Array<{
        id: number;
        status: string;
    }>;
    calendar_events: Array<{
        id: number;
    }>;
};

type Props = {
    offices: Office[];
    stats: {
        total_offices: number;
        total_rooms: number;
        room_bookings: number;
        open_hazards: number;
        safety_compliance_rate: number;
    };
    dateRange: { from: string; to: string };
};

type Attention = 'all' | 'open' | 'below_target';

function officeMetrics(office: Office) {
    const openHazards = office.hazards.filter((h) => h.status === 'open');
    const totalRuns = office.checklist_runs.length;
    const completedRuns = office.checklist_runs.filter(
        (r) => r.status === 'completed',
    ).length;
    const completionRate =
        totalRuns > 0 ? Math.round((completedRuns / totalRuns) * 100) : 0;
    return { openHazards, completionRate };
}

export default function HeadOfficeReports({
    offices,
    stats,
    dateRange,
}: Props) {
    const [dateFrom, setDateFrom] = useState(dateRange.from);
    const [dateTo, setDateTo] = useState(dateRange.to);
    const [query, setQuery] = useState('');
    const [attention, setAttention] = useState<Attention>('all');

    const exportUrl = `/sites/reports/export?type=head_office&format=csv&date_from=${dateFrom}&date_to=${dateTo}`;
    const q = query.trim().toLowerCase();

    const filteredOffices = useMemo(() => {
        return offices.filter((office) => {
            if (q && !office.name.toLowerCase().includes(q)) return false;
            const { openHazards, completionRate } = officeMetrics(office);
            if (attention === 'open' && openHazards.length === 0) return false;
            if (attention === 'below_target' && completionRate >= 80)
                return false;
            return true;
        });
    }, [offices, q, attention]);

    const filtersActive = attention !== 'all' || q.length > 0;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Sites', href: '/sites' },
                { title: 'Reports', href: '/sites/reports' },
                { title: 'Head Office', href: '/sites/reports/head-office' },
            ]}
        >
            <Head title="Head Office Reports" />

            <PageLayout
                hero={
                    <PageHeader
                        icon={Building}
                        title="Head Office Reports"
                        subline={`Room utilization, safety compliance and facilities · ${stats.total_offices} offices · ${stats.total_rooms} rooms · ${stats.room_bookings} bookings`}
                        actions={
                            <>
                                <PageHeaderSearch
                                    value={query}
                                    onChange={setQuery}
                                    placeholder="Search offices…"
                                />
                                <PageHeaderGlassButton
                                    icon={Download}
                                    onClick={() => {
                                        window.location.href = exportUrl;
                                    }}
                                >
                                    Export CSV
                                </PageHeaderGlassButton>
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Offices"
                                    ariaLabel="Show all head offices"
                                    onClick={() => setAttention('all')}
                                >
                                    <PageHeaderMeterBig>
                                        {stats.total_offices}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {stats.total_rooms} rooms
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Open hazards"
                                    tone={
                                        stats.open_hazards > 0
                                            ? 'critical'
                                            : 'success'
                                    }
                                    ariaLabel="Show offices with open hazards"
                                    onClick={() => setAttention('open')}
                                >
                                    <PageHeaderMeterBig>
                                        {stats.open_hazards}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        across the portfolio
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Room bookings"
                                    ariaLabel="View the calendar"
                                    href="/calendar"
                                >
                                    <PageHeaderMeterBig>
                                        {stats.room_bookings}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        in this period
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Safety compliance"
                                    ariaLabel="Show offices below the 80% target"
                                    onClick={() => setAttention('below_target')}
                                >
                                    <PageHeaderMeterDonut
                                        percent={stats.safety_compliance_rate}
                                        caption={
                                            <>
                                                checks
                                                <br />
                                                completed
                                            </>
                                        }
                                    />
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={
                            <DateRangePill
                                from={dateFrom}
                                to={dateTo}
                                onFrom={setDateFrom}
                                onTo={setDateTo}
                                onApply={() =>
                                    router.get(
                                        '/sites/reports/head-office',
                                        {
                                            date_from: dateFrom,
                                            date_to: dateTo,
                                        },
                                        { preserveState: true },
                                    )
                                }
                            />
                        }
                    />
                }
            >
                {/* Offices List */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="text-base">
                            Head Office Details ({filteredOffices.length}
                            {filteredOffices.length !== offices.length
                                ? ` of ${offices.length}`
                                : ''}
                            )
                        </CardTitle>
                        {filtersActive ? (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                    setAttention('all');
                                    setQuery('');
                                }}
                            >
                                Clear filters
                            </Button>
                        ) : null}
                    </CardHeader>
                    <CardContent>
                        {filteredOffices.length === 0 ? (
                            <p className="py-8 text-center text-muted-foreground">
                                No head offices match your filters.
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {filteredOffices.map((office) => {
                                    const { openHazards, completionRate } =
                                        officeMetrics(office);

                                    return (
                                        <div
                                            key={office.id}
                                            className="flex items-center justify-between rounded-lg border p-3 hover:bg-muted/50"
                                        >
                                            <div>
                                                <div className="font-medium">
                                                    {office.name}
                                                </div>
                                                <div className="flex items-center gap-3 text-sm text-muted-foreground">
                                                    <span className="flex items-center gap-1">
                                                        <DoorOpen className="h-3.5 w-3.5" />
                                                        {
                                                            office.ho_resources_count
                                                        }{' '}
                                                        rooms
                                                    </span>
                                                    <span className="flex items-center gap-1">
                                                        <Calendar className="h-3.5 w-3.5" />
                                                        {
                                                            office
                                                                .calendar_events
                                                                .length
                                                        }{' '}
                                                        bookings
                                                    </span>
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-3">
                                                {openHazards.length > 0 && (
                                                    <Badge className="bg-status-critical-bg text-status-critical">
                                                        <AlertTriangle className="mr-1 h-3 w-3" />
                                                        {openHazards.length}{' '}
                                                        Open
                                                    </Badge>
                                                )}
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        completionRate >= 80
                                                            ? 'text-status-success'
                                                            : completionRate >=
                                                                50
                                                              ? 'text-status-warning'
                                                              : 'text-status-critical'
                                                    }
                                                >
                                                    <CheckCircle2 className="mr-1 h-3 w-3" />
                                                    {completionRate}%
                                                </Badge>
                                                <Button
                                                    asChild
                                                    variant="ghost"
                                                    size="sm"
                                                >
                                                    <Link
                                                        href={`/sites/${office.id}`}
                                                    >
                                                        View
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
            </PageLayout>
        </AppLayout>
    );
}

/** Compact date-range control for the header filter row: a 23px pill that
 *  opens a From/To popover and reloads the report on Apply. */
function DateRangePill({
    from,
    to,
    onFrom,
    onTo,
    onApply,
}: {
    from: string;
    to: string;
    onFrom: (v: string) => void;
    onTo: (v: string) => void;
    onApply: () => void;
}) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLButtonElement>(null);
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <PageHeaderFilterButton
                    ref={ref}
                    icon={CalendarRange}
                    aria-haspopup="dialog"
                    aria-expanded={open}
                >
                    {from} → {to}
                    <ChevronDown className="size-3 opacity-70" />
                </PageHeaderFilterButton>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-auto p-3">
                <div className="flex items-end gap-2">
                    <div className="space-y-1">
                        <Label className="text-xs">From</Label>
                        <Input
                            type="date"
                            value={from}
                            onChange={(e) => onFrom(e.target.value)}
                        />
                    </div>
                    <div className="space-y-1">
                        <Label className="text-xs">To</Label>
                        <Input
                            type="date"
                            value={to}
                            onChange={(e) => onTo(e.target.value)}
                        />
                    </div>
                    <Button
                        size="sm"
                        onClick={() => {
                            onApply();
                            setOpen(false);
                        }}
                    >
                        Apply
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    );
}
