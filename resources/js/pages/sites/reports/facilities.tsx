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
    CalendarRange,
    CheckCircle2,
    ChevronDown,
    Download,
    LayoutGrid,
    Package,
    Warehouse,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

type Facility = {
    id: number;
    name: string;
    facility_zones_count: number;
    assets_count: number;
    hazards: Array<{
        id: number;
        severity: string;
        status: string;
        hazard_type: string;
    }>;
    checklist_runs: Array<{
        id: number;
        status: string;
    }>;
};

type Props = {
    facilities: Facility[];
    stats: {
        total_facilities: number;
        total_zones: number;
        total_assets: number;
        open_hazards: number;
        equipment_failures: number;
        safety_walkthrough_completion: number;
    };
    dateRange: { from: string; to: string };
};

type Attention = 'all' | 'open' | 'equipment' | 'below_target';

function facilityMetrics(facility: Facility) {
    const openHazards = facility.hazards.filter((h) => h.status === 'open');
    const equipmentIssues = facility.hazards.filter(
        (h) => h.hazard_type === 'equipment',
    );
    const totalRuns = facility.checklist_runs.length;
    const completedRuns = facility.checklist_runs.filter(
        (r) => r.status === 'completed',
    ).length;
    const completionRate =
        totalRuns > 0 ? Math.round((completedRuns / totalRuns) * 100) : 0;
    return { openHazards, equipmentIssues, completionRate };
}

export default function FacilityReports({
    facilities,
    stats,
    dateRange,
}: Props) {
    const [dateFrom, setDateFrom] = useState(dateRange.from);
    const [dateTo, setDateTo] = useState(dateRange.to);
    const [query, setQuery] = useState('');
    const [attention, setAttention] = useState<Attention>('all');

    const exportUrl = `/sites/reports/export?type=facilities&format=csv&date_from=${dateFrom}&date_to=${dateTo}`;
    const q = query.trim().toLowerCase();

    const filteredFacilities = useMemo(() => {
        return facilities.filter((facility) => {
            if (q && !facility.name.toLowerCase().includes(q)) return false;
            const { openHazards, equipmentIssues, completionRate } =
                facilityMetrics(facility);
            if (attention === 'open' && openHazards.length === 0) return false;
            if (attention === 'equipment' && equipmentIssues.length === 0)
                return false;
            if (attention === 'below_target' && completionRate >= 80)
                return false;
            return true;
        });
    }, [facilities, q, attention]);

    const filtersActive = attention !== 'all' || q.length > 0;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Sites', href: '/sites' },
                { title: 'Reports', href: '/sites/reports' },
                { title: 'Facilities', href: '/sites/reports/facilities' },
            ]}
        >
            <Head title="Facility Reports" />

            <PageLayout
                hero={
                    <PageHeader
                        icon={Warehouse}
                        title="Facility Reports"
                        subline={`Equipment safety, zone utilization and compliance · ${stats.total_facilities} facilities · ${stats.total_zones} zones · ${stats.total_assets} assets`}
                        actions={
                            <>
                                <PageHeaderSearch
                                    value={query}
                                    onChange={setQuery}
                                    placeholder="Search facilities…"
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
                                    label="Facilities"
                                    ariaLabel="Show all facilities"
                                    onClick={() => setAttention('all')}
                                >
                                    <PageHeaderMeterBig>
                                        {stats.total_facilities}
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
                                    ariaLabel="Show facilities with open hazards"
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
                                    label="Equipment issues"
                                    tone={
                                        stats.equipment_failures > 0
                                            ? 'critical'
                                            : 'success'
                                    }
                                    ariaLabel="Show facilities with equipment issues"
                                    onClick={() => setAttention('equipment')}
                                >
                                    <PageHeaderMeterBig>
                                        {stats.equipment_failures}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {stats.equipment_failures > 0
                                            ? 'equipment hazards'
                                            : 'none reported'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Walkthroughs"
                                    ariaLabel="Show facilities below the 80% target"
                                    onClick={() => setAttention('below_target')}
                                >
                                    <PageHeaderMeterDonut
                                        percent={
                                            stats.safety_walkthrough_completion
                                        }
                                        caption={
                                            <>
                                                safety checks
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
                                        '/sites/reports/facilities',
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
                {/* Facilities List */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="text-base">
                            Facility Details ({filteredFacilities.length}
                            {filteredFacilities.length !== facilities.length
                                ? ` of ${facilities.length}`
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
                        {filteredFacilities.length === 0 ? (
                            <p className="py-8 text-center text-muted-foreground">
                                No facilities match your filters.
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {filteredFacilities.map((facility) => {
                                    const {
                                        openHazards,
                                        equipmentIssues,
                                        completionRate,
                                    } = facilityMetrics(facility);

                                    return (
                                        <div
                                            key={facility.id}
                                            className="flex items-center justify-between rounded-lg border p-3 hover:bg-muted/50"
                                        >
                                            <div>
                                                <div className="font-medium">
                                                    {facility.name}
                                                </div>
                                                <div className="flex items-center gap-3 text-sm text-muted-foreground">
                                                    <span className="flex items-center gap-1">
                                                        <LayoutGrid className="h-3.5 w-3.5" />
                                                        {
                                                            facility.facility_zones_count
                                                        }{' '}
                                                        zones
                                                    </span>
                                                    <span className="flex items-center gap-1">
                                                        <Package className="h-3.5 w-3.5" />
                                                        {facility.assets_count}{' '}
                                                        assets
                                                    </span>
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-3">
                                                {equipmentIssues.length > 0 && (
                                                    <Badge className="bg-status-warning-bg text-status-warning">
                                                        <AlertTriangle className="mr-1 h-3 w-3" />
                                                        {equipmentIssues.length}{' '}
                                                        Equipment
                                                    </Badge>
                                                )}
                                                {openHazards.length > 0 && (
                                                    <Badge
                                                        variant="outline"
                                                        className="text-status-warning"
                                                    >
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
                                                        href={`/sites/${facility.id}`}
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
