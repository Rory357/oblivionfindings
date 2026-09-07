import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
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
    BedDouble,
    CalendarRange,
    CheckCircle2,
    ChevronDown,
    Download,
    Home,
    MapPin,
    Users,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

type House = {
    id: number;
    name: string;
    region?: string;
    house_rooms_count: number;
    clients_count: number;
    hazards: Array<{
        id: number;
        severity: string;
        status: string;
        created_at: string;
    }>;
    checklist_runs: Array<{
        id: number;
        status: string;
        completed_at?: string;
    }>;
};

type Props = {
    houses: House[];
    stats: {
        total_houses: number;
        total_bedrooms: number;
        total_clients: number;
        open_hazards: number;
        critical_hazards: number;
        checklist_completion_rate: number;
    };
    dateRange: { from: string; to: string };
    regions: string[];
};

type Attention = 'all' | 'open' | 'critical' | 'below_target';

function houseMetrics(house: House) {
    const openHazards = house.hazards.filter((h) => h.status === 'open');
    const criticalHazards = openHazards.filter(
        (h) => h.severity === 'critical',
    );
    const totalRuns = house.checklist_runs.length;
    const completedRuns = house.checklist_runs.filter(
        (r) => r.status === 'completed',
    ).length;
    const completionRate =
        totalRuns > 0 ? Math.round((completedRuns / totalRuns) * 100) : 0;
    return { openHazards, criticalHazards, completionRate };
}

export default function HouseReports({
    houses,
    stats,
    dateRange,
    regions,
}: Props) {
    const [dateFrom, setDateFrom] = useState(dateRange.from);
    const [dateTo, setDateTo] = useState(dateRange.to);
    const [query, setQuery] = useState('');
    const [region, setRegion] = useState('all');
    const [attention, setAttention] = useState<Attention>('all');

    const exportUrl = `/sites/reports/export?type=houses&format=csv&date_from=${dateFrom}&date_to=${dateTo}`;
    const q = query.trim().toLowerCase();

    const filteredHouses = useMemo(() => {
        return houses.filter((house) => {
            if (region !== 'all' && house.region !== region) return false;
            if (
                q &&
                !`${house.name} ${house.region ?? ''}`.toLowerCase().includes(q)
            )
                return false;
            const { openHazards, criticalHazards, completionRate } =
                houseMetrics(house);
            if (attention === 'open' && openHazards.length === 0) return false;
            if (attention === 'critical' && criticalHazards.length === 0)
                return false;
            if (attention === 'below_target' && completionRate >= 80)
                return false;
            return true;
        });
    }, [houses, region, q, attention]);

    const occupancyPercent =
        stats.total_bedrooms > 0
            ? Math.round((stats.total_clients / stats.total_bedrooms) * 100)
            : 0;
    const filtersActive =
        region !== 'all' || attention !== 'all' || q.length > 0;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Sites', href: '/sites' },
                { title: 'Reports', href: '/sites/reports' },
                { title: 'Houses', href: '/sites/reports/houses' },
            ]}
        >
            <Head title="House Reports" />

            <PageLayout
                hero={
                    <PageHeader
                        icon={Home}
                        title="House Reports"
                        subline={`Quality home checks, occupancy and compliance · ${stats.total_houses} houses · ${stats.total_bedrooms} bedrooms · ${stats.total_clients} clients`}
                        actions={
                            <>
                                <PageHeaderSearch
                                    value={query}
                                    onChange={setQuery}
                                    placeholder="Search houses, regions…"
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
                                    label="Houses"
                                    ariaLabel="Show all houses"
                                    onClick={() => setAttention('all')}
                                >
                                    <PageHeaderMeterBig>
                                        {stats.total_houses}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        in this report
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Open hazards"
                                    tone={
                                        stats.open_hazards > 0
                                            ? 'warning'
                                            : 'success'
                                    }
                                    ariaLabel="Show houses with open hazards"
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
                                    label="Critical hazards"
                                    tone={
                                        stats.critical_hazards > 0
                                            ? 'critical'
                                            : 'success'
                                    }
                                    ariaLabel="Show houses with critical hazards"
                                    onClick={() => setAttention('critical')}
                                >
                                    <PageHeaderMeterBig>
                                        {stats.critical_hazards}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {stats.critical_hazards > 0
                                            ? 'need urgent action'
                                            : 'none open'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Checklist completion"
                                    ariaLabel="Show houses below the 80% target"
                                    onClick={() => setAttention('below_target')}
                                >
                                    <PageHeaderMeterDonut
                                        percent={
                                            stats.checklist_completion_rate
                                        }
                                        caption={
                                            <>
                                                portfolio
                                                <br />
                                                average
                                            </>
                                        }
                                    />
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Occupancy"
                                    value={`${stats.total_clients}/${stats.total_bedrooms}`}
                                    ariaLabel="View clients"
                                    href="/operations/clients"
                                >
                                    <PageHeaderMeterBar
                                        percent={occupancyPercent}
                                    />
                                    <PageHeaderMeterCaption>
                                        {occupancyPercent}% of bedrooms filled
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={
                            <>
                                <DateRangePill
                                    from={dateFrom}
                                    to={dateTo}
                                    onFrom={setDateFrom}
                                    onTo={setDateTo}
                                    onApply={() =>
                                        router.get(
                                            '/sites/reports/houses',
                                            {
                                                date_from: dateFrom,
                                                date_to: dateTo,
                                            },
                                            { preserveState: true },
                                        )
                                    }
                                />
                                {regions.length > 0 ? (
                                    <PageHeaderFilterSelect
                                        icon={MapPin}
                                        label="All regions"
                                        value={region}
                                        options={[
                                            {
                                                value: 'all',
                                                label: 'All regions',
                                            },
                                            ...regions.map((r) => ({
                                                value: r,
                                                label: r,
                                            })),
                                        ]}
                                        onChange={setRegion}
                                    />
                                ) : null}
                            </>
                        }
                    />
                }
            >
                {/* Houses List */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="text-base">
                            House Details ({filteredHouses.length}
                            {filteredHouses.length !== houses.length
                                ? ` of ${houses.length}`
                                : ''}
                            )
                        </CardTitle>
                        {filtersActive ? (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                    setAttention('all');
                                    setRegion('all');
                                    setQuery('');
                                }}
                            >
                                Clear filters
                            </Button>
                        ) : null}
                    </CardHeader>
                    <CardContent>
                        {filteredHouses.length === 0 ? (
                            <p className="py-8 text-center text-muted-foreground">
                                No houses match your filters.
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {filteredHouses.map((house) => {
                                    const {
                                        openHazards,
                                        criticalHazards,
                                        completionRate,
                                    } = houseMetrics(house);

                                    return (
                                        <div
                                            key={house.id}
                                            className="flex items-center justify-between rounded-lg border p-3 hover:bg-muted/50"
                                        >
                                            <div>
                                                <div className="font-medium">
                                                    {house.name}
                                                </div>
                                                <div className="flex items-center gap-3 text-sm text-muted-foreground">
                                                    <span className="flex items-center gap-1">
                                                        <BedDouble className="h-3.5 w-3.5" />
                                                        {
                                                            house.house_rooms_count
                                                        }{' '}
                                                        bedrooms
                                                    </span>
                                                    <span className="flex items-center gap-1">
                                                        <Users className="h-3.5 w-3.5" />
                                                        {house.clients_count}{' '}
                                                        clients
                                                    </span>
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-3">
                                                {criticalHazards.length > 0 && (
                                                    <Badge className="bg-status-critical-bg text-status-critical">
                                                        <AlertTriangle className="mr-1 h-3 w-3" />
                                                        {criticalHazards.length}{' '}
                                                        Critical
                                                    </Badge>
                                                )}
                                                {openHazards.length > 0 && (
                                                    <Badge
                                                        variant="outline"
                                                        className="text-status-warning"
                                                    >
                                                        {openHazards.length}{' '}
                                                        Open Hazards
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
                                                        href={`/sites/${house.id}`}
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
