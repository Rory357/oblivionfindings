import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import {
    ArrowRight,
    Bell,
    Gauge,
    History,
    Pencil,
    Plus,
    Radio,
    Route,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    VehicleCollectionToggle,
    VehicleRecordCollection,
    useVehicleCollectionView,
    type VehicleCollectionRecord,
} from './record-collection';
import { VehicleSearchSelect } from './search-select';
import {
    type OdometerObservation,
    type RecordPage,
    type VehicleReadiness,
} from './types';

export const observationSourceNames: Record<
    OdometerObservation['source_kind'],
    string
> = {
    dashboard_manual: 'Dashboard observation',
    booking_checkout: 'Booking checkout',
    booking_return: 'Booking return',
    inspection: 'Vehicle check',
    legacy_unverified: 'Historical value · unverified',
};

export function VehicleMileagePanel({
    readings,
    readiness,
    nextService,
    canManage,
    onRecord,
    onDetails,
    onPage,
    onSearch,
    onReminder,
    onTrips,
    onSchedule,
    query = '',
    source = 'all',
}: {
    readings: RecordPage<OdometerObservation>;
    readiness: VehicleReadiness;
    nextService: {
        name: string;
        next_due_km: number | null;
        next_due_on: string | null;
    } | null;
    canManage: boolean;
    onRecord: (corrects?: OdometerObservation) => void;
    onDetails: (reading: OdometerObservation) => void;
    onPage: (page: number) => void;
    onSearch: (query: string, source: string) => void;
    onReminder: () => void;
    onTrips: () => void;
    onSchedule: () => void;
    query?: string;
    source?: string;
}) {
    const { view, setView } = useVehicleCollectionView();
    const [search, setSearch] = useState(query);
    const tracker = readiness.tracker_estimate;
    const km = readiness.odometer_km;
    const remaining =
        nextService?.next_due_km != null && km != null
            ? nextService.next_due_km - km
            : null;
    const current = readings.data.find(
        (reading) => reading.id === readiness.odometer_observation_id,
    );
    const chart = readings.data
        .filter(
            (reading) =>
                !reading.is_corrected &&
                reading.source_kind !== 'legacy_unverified',
        )
        .map((reading) => ({
            at: new Date(reading.observed_at).getTime(),
            km: reading.value_km,
            id: reading.id,
        }))
        .sort((a, b) => a.at - b.at || a.id - b.id);
    const rows: VehicleCollectionRecord[] = readings.data.map((reading) => ({
        id: reading.id,
        name: `${reading.value_km.toLocaleString('en-NZ')} km`,
        subline: formatDateTime(reading.observed_at),
        icon: Gauge,
        tone:
            reading.source_kind === 'legacy_unverified' ? 'warning' : 'success',
        onOpen: () => onDetails(reading),
        fields: [
            <div key="source" className="space-y-1 text-sm">
                <p>
                    {reading.source_reference ||
                        observationSourceNames[reading.source_kind]}
                </p>
                <p className="text-xs text-muted-foreground">
                    {reading.recorded_by_name ?? 'Author not recorded'}
                </p>
            </div>,
            <StatusBadge
                key="state"
                variant={
                    reading.is_corrected
                        ? 'neutral'
                        : reading.is_current
                          ? 'info'
                          : reading.source_kind === 'legacy_unverified'
                            ? 'warning'
                            : 'neutral'
                }
            >
                {reading.is_corrected
                    ? 'Corrected'
                    : reading.is_current
                      ? 'Current'
                      : reading.source_kind === 'legacy_unverified'
                        ? 'Unverified'
                        : 'Recorded'}
            </StatusBadge>,
            <Button
                key="details"
                variant="ghost"
                size="sm"
                onClick={(event) => {
                    event.stopPropagation();
                    onDetails(reading);
                }}
            >
                View details
                <ArrowRight className="size-4" />
            </Button>,
        ],
        actions: [
            {
                label: 'View original & history',
                icon: History,
                onClick: () => onDetails(reading),
            },
            ...(canManage && !reading.is_corrected
                ? [
                      {
                          label: 'Record a correction',
                          icon: Pencil,
                          onClick: () => onRecord(reading),
                      },
                  ]
                : []),
        ],
    }));
    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                        Observed readings & planning
                    </p>
                    <h2 className="mt-1 text-xl font-semibold">Mileage</h2>
                </div>
                {canManage && (
                    <Button onClick={() => onRecord()}>
                        <Plus className="size-4" />
                        Record mileage
                    </Button>
                )}
            </div>
            {tracker && (
                <Card className="flex flex-wrap items-center justify-between gap-4 p-4">
                    <div className="flex items-center gap-3">
                        <span className="rounded-xl bg-primary/10 p-3 text-primary">
                            <Radio className="size-5" />
                        </span>
                        <div>
                            <p className="font-semibold">
                                Tracker estimate ·{' '}
                                {tracker.value_km.toLocaleString('en-NZ')} km
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {formatDateTime(tracker.observed_at)}
                                {km != null
                                    ? ` · ${(tracker.value_km - km).toLocaleString('en-NZ', { signDisplay: 'always' })} km from the recorded dashboard`
                                    : ''}
                            </p>
                        </div>
                    </div>
                    {canManage && (
                        <Button variant="outline" onClick={() => onRecord()}>
                            Cross-check & record
                        </Button>
                    )}
                    <p className="basis-full text-xs text-muted-foreground">
                        Verify the dashboard before updating the recorded
                        odometer. Tracker distance remains a separate planning
                        estimate.
                    </p>
                </Card>
            )}
            <div className="vehicle-workspace-grid">
                <Card className="flex items-center gap-5 p-6">
                    <span className="rounded-xl bg-primary/10 p-3 text-primary">
                        <Gauge className="size-6" />
                    </span>
                    <div>
                        <p className="mb-2 text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                            Current recorded odometer
                        </p>
                        <p className="text-3xl font-bold tabular-nums">
                            {km == null ? (
                                'Not recorded'
                            ) : (
                                <>
                                    {km.toLocaleString('en-NZ')}{' '}
                                    <span className="text-sm font-normal">
                                        km
                                    </span>
                                </>
                            )}
                        </p>
                        {current && (
                            <p className="mt-2 text-xs text-muted-foreground">
                                {formatDateTime(current.observed_at)}
                            </p>
                        )}
                        <div className="mt-3">
                            <StatusBadge
                                variant={km == null ? 'warning' : 'info'}
                            >
                                {km == null
                                    ? 'Record a dashboard observation'
                                    : 'Recorded observation'}
                            </StatusBadge>
                        </div>
                    </div>
                </Card>
                <Card className="p-5">
                    <p className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                        Next distance trigger
                    </p>
                    <h3 className="mt-3 font-semibold">
                        {nextService?.name ?? 'No service schedule recorded'}
                    </h3>
                    <p className="mt-3 text-2xl font-bold text-primary">
                        {remaining == null
                            ? 'Distance not recorded'
                            : `${Math.abs(remaining).toLocaleString('en-NZ')} km ${remaining < 0 ? 'past recorded due' : 'remaining'}`}
                    </p>
                    {nextService && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            {nextService.next_due_km == null
                                ? 'No distance due'
                                : `Due at ${nextService.next_due_km.toLocaleString('en-NZ')} km`}{' '}
                            ·{' '}
                            {formatDateOnly(
                                nextService.next_due_on,
                                'No date due',
                            )}
                        </p>
                    )}
                    <Button
                        variant="ghost"
                        size="sm"
                        className="mt-3"
                        onClick={onSchedule}
                    >
                        View service schedules
                        <ArrowRight className="size-4" />
                    </Button>
                </Card>
            </div>
            <div className="vehicle-workspace-grid">
                <Card className="min-w-0 p-5">
                    <div className="mb-5 flex flex-wrap gap-3">
                        <form
                            className="min-w-48 flex-1"
                            onSubmit={(event) => {
                                event.preventDefault();
                                onSearch(search, source);
                            }}
                        >
                            <Input
                                aria-label="Search mileage readings"
                                placeholder="Reading, person or source…"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                            />
                        </form>
                        <div className="w-52">
                            <VehicleSearchSelect
                                label="Reading source"
                                value={source}
                                options={[
                                    { value: 'all', label: 'All readings' },
                                    ...Object.entries(
                                        observationSourceNames,
                                    ).map(([value, label]) => ({
                                        value,
                                        label,
                                    })),
                                ]}
                                onChange={(value) => onSearch(search, value)}
                            />
                        </div>
                        <VehicleCollectionToggle
                            label="Mileage"
                            view={view}
                            onChange={setView}
                        />
                    </div>
                    <VehicleRecordCollection
                        label="Odometer history"
                        view={view}
                        records={rows}
                        total={readings.total}
                        columns={[
                            { label: 'Original source', width: '1.5fr' },
                            { label: 'Record state', width: '0.8fr' },
                            { label: 'Details', width: '0.85fr' },
                        ]}
                        empty="No readings match these filters. Record the vehicle's dashboard reading to start its history."
                    />
                    {readings.last_page > 1 && (
                        <div className="mt-4 flex items-center justify-between gap-3 text-xs">
                            <span>
                                Page {readings.current_page} of{' '}
                                {readings.last_page}
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={readings.current_page <= 1}
                                    onClick={() =>
                                        onPage(readings.current_page - 1)
                                    }
                                >
                                    Previous
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={
                                        readings.current_page >=
                                        readings.last_page
                                    }
                                    onClick={() =>
                                        onPage(readings.current_page + 1)
                                    }
                                >
                                    Next
                                </Button>
                            </div>
                        </div>
                    )}
                </Card>
                <Card className="p-5">
                    <p className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                        Reading progression
                    </p>
                    <h3 className="mt-3 font-semibold">Recorded distance</h3>
                    {chart.length > 1 ? (
                        <div
                            className="mt-4 h-40"
                            role="img"
                            aria-label="Odometer observations in time order, from the current results page"
                        >
                            <ResponsiveContainer width="100%" height="100%">
                                <LineChart
                                    data={chart}
                                    margin={{
                                        top: 8,
                                        right: 10,
                                        bottom: 5,
                                        left: 0,
                                    }}
                                >
                                    <CartesianGrid
                                        vertical={false}
                                        stroke="var(--border)"
                                    />
                                    <XAxis
                                        dataKey="at"
                                        type="number"
                                        domain={['dataMin', 'dataMax']}
                                        tickFormatter={(value: number) =>
                                            formatDateTime(value)
                                        }
                                        tick={false}
                                    />
                                    <YAxis
                                        domain={['dataMin', 'dataMax']}
                                        hide
                                    />
                                    <Tooltip
                                        labelFormatter={(value) =>
                                            formatDateTime(Number(value))
                                        }
                                        formatter={(value) => [
                                            `${Number(value).toLocaleString('en-NZ')} km`,
                                            'Recorded reading',
                                        ]}
                                        contentStyle={{
                                            background: 'var(--card)',
                                            borderColor: 'var(--border)',
                                            color: 'var(--foreground)',
                                        }}
                                    />
                                    <Line
                                        dataKey="km"
                                        type="linear"
                                        stroke="var(--primary)"
                                        strokeWidth={2}
                                        dot={{ r: 3 }}
                                        isAnimationActive={false}
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        </div>
                    ) : (
                        <p className="my-6 text-sm text-muted-foreground">
                            Two observed readings are needed to show
                            progression.
                        </p>
                    )}
                    <p className="mt-3 text-xs leading-relaxed text-muted-foreground">
                        Observations on this page, in time order. Corrected
                        originals and unverified historical values stay in the
                        history.
                    </p>
                    <div className="mt-4 flex gap-2 rounded-lg bg-primary/5 p-3 text-xs">
                        <ShieldCheck className="size-5 shrink-0 text-primary" />
                        <span>
                            Original observations are retained. Every correction
                            needs a reason.
                        </span>
                    </div>
                    {canManage && (
                        <Button
                            variant="outline"
                            className="mt-5 w-full"
                            onClick={onReminder}
                        >
                            <Bell className="size-4" />
                            Remind me to check mileage
                        </Button>
                    )}
                    <Button
                        variant="ghost"
                        className="mt-2 w-full"
                        onClick={onTrips}
                    >
                        <Route className="size-4" />
                        Open trip history
                        <ArrowRight className="size-4" />
                    </Button>
                </Card>
            </div>
        </div>
    );
}
