import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { LoadingState } from '@/components/ui/loading-state';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    ArrowUpRight,
    BatteryCharging,
    Gauge,
    Loader2,
    Navigation,
    Radio,
    Signal,
    Zap,
    type LucideIcon,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import {
    GV500CG_CAPABILITIES,
    observedLabel,
    sampleLabel,
    telemetryTiles,
} from './map-model';
import type { VehicleTelemetry } from './map-types';
import './map.css';
import { MileageFeed } from './mileage-feed';
import './studio.css';
import type { VehicleWorkspace } from './types';
import type { WorkspaceLocation } from './workspace-model';

type Load = 'loading' | 'ready' | 'error' | 'unavailable';

const TILE_ICONS: Record<string, LucideIcon> = {
    ignition: Zap,
    motion: Navigation,
    voltage: BatteryCharging,
    backup: BatteryCharging,
    connection: Signal,
    distance: Gauge,
};

function useVehicleTelemetry(vehicleId: number, enabled: boolean) {
    const [data, setData] = useState<VehicleTelemetry | null>(null);
    const [load, setLoad] = useState<Load>('loading');
    const [refreshing, setRefreshing] = useState(false);
    const [attempt, setAttempt] = useState(0);
    const refresh = useCallback(() => {
        setRefreshing(true);
        setAttempt((value) => value + 1);
    }, []);
    useEffect(() => {
        if (!enabled) return;
        const controller = new AbortController();
        fetch(`/fleet-assets/vehicles/${vehicleId}/telemetry`, {
            signal: controller.signal,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        })
            .then(async (response) => {
                if (response.status === 403 || response.status === 404) {
                    setLoad('unavailable');
                    return;
                }
                if (!response.ok) throw new Error(String(response.status));
                setData((await response.json()) as VehicleTelemetry);
                setLoad('ready');
            })
            .catch((error: unknown) => {
                if ((error as Error)?.name !== 'AbortError') setLoad('error');
            })
            .finally(() => {
                if (!controller.signal.aborted) setRefreshing(false);
            });
        return () => controller.abort();
    }, [vehicleId, enabled, attempt]);
    return { data, load, refresh, refreshing };
}

/**
 * Map › Vehicle telemetry, built to the approved PKG-02B v13 design
 * (TelemetryStudio): the tracker's recorded state with its source and
 * freshness, a recorded-sample selector, model capabilities and the
 * vehicle's identity. Device facts come from the canonical technology
 * projection; unknown values stay unknown.
 */
export function VehicleTelemetryPanel({
    workspace,
    onNavigate,
    onChanged,
}: {
    workspace: VehicleWorkspace;
    onNavigate: (location: WorkspaceLocation) => void;
    /** For the distance feed's saves: refresh the workspace. */
    onChanged: () => void;
}) {
    const allowed = workspace.can.view_vehicle_technology;
    const { data, load, refresh, refreshing } = useVehicleTelemetry(
        workspace.vehicle.id,
        allowed,
    );
    const [sampleId, setSampleId] = useState<number | null>(null);

    if (!allowed || load === 'unavailable')
        return (
            <EmptyState
                icon={Radio}
                title="Telemetry needs device access"
                description="Vehicle telemetry comes from the installed tracker. Ask for Security & Devices access to see it."
            />
        );
    if (load === 'loading' && !data)
        return <LoadingState message="Loading vehicle telemetry…" />;
    if (!data)
        return (
            <ErrorState
                title="Telemetry couldn’t be loaded"
                message="The tracker’s recorded state couldn’t be loaded. Try again."
                onRetry={refresh}
            />
        );

    const telemetry = data.telemetry;
    const tracker = telemetry.tracker;
    const samples = telemetry.samples;
    const latest = samples[0] ?? null;
    const sample =
        samples.find((entry) => entry.id === sampleId) ?? latest ?? null;
    const isUnavailable = tracker === null && latest === null;
    const fresh = latest !== null && latest.id === telemetry.current_sample_id;
    const tiles = telemetryTiles(
        sample,
        telemetry.current_sample_id,
        latest?.id ?? null,
        tracker,
    );
    const gv500 = tracker?.family === 'gv500cg';
    const model = tracker?.model ?? null;
    const vin = telemetry.vehicle.vin;

    return (
        <div className="telemetry-studio">
            <div className="activity-title">
                <div>
                    <span className="studio-eyebrow">CONNECTED VEHICLE</span>
                    <h2 className="text-section-title">
                        {model
                            ? `${model} · vehicle telemetry`
                            : 'Vehicle telemetry'}
                    </h2>
                    <p className="muted">
                        Vehicle state, power and mileage with source confidence.
                    </p>
                </div>
                <StatusBadge
                    variant={
                        isUnavailable
                            ? 'neutral'
                            : fresh
                              ? 'success'
                              : 'warning'
                    }
                >
                    {isUnavailable
                        ? 'No tracker'
                        : fresh
                          ? 'Current sample'
                          : latest
                            ? 'Last known sample'
                            : 'No reports yet'}
                </StatusBadge>
            </div>
            {!isUnavailable && (
                <div className="telemetry-sample-bar">
                    <span>
                        <Radio className="size-[15px]" aria-hidden />
                        {sample
                            ? `Recorded sample · ${observedLabel(sample.occurred_at)} · ${sampleLabel(sample)}`
                            : 'No recorded samples yet'}
                    </span>
                    {samples.length > 0 && (
                        <label>
                            Sample{' '}
                            <select
                                aria-label="Recorded sample"
                                value={sample?.id ?? ''}
                                onChange={(event) =>
                                    setSampleId(Number(event.target.value))
                                }
                            >
                                {samples.map((entry, index) => (
                                    <option key={entry.id} value={entry.id}>
                                        {observedLabel(entry.occurred_at)} ·{' '}
                                        {sampleLabel(entry)}
                                        {index === 0 ? ' · latest' : ''}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={refreshing}
                        onClick={() => {
                            setSampleId(null);
                            refresh();
                        }}
                    >
                        {refreshing && (
                            <Loader2 className="size-4 animate-spin" />
                        )}
                        Check for a newer sample
                    </Button>
                </div>
            )}
            <div className="telemetry-grid">
                {tiles.map((tile) => {
                    const Icon = TILE_ICONS[tile.key] ?? Radio;
                    return (
                        <section
                            className="studio-card telemetry-metric"
                            key={tile.key}
                        >
                            <div>
                                <Icon className="size-[21px]" aria-hidden />
                                <span>{tile.label}</span>
                                <span
                                    className={`signal-dot ${tile.current ? '' : 'stale'}`}
                                    role="img"
                                    aria-label={
                                        tile.current
                                            ? 'Current sample'
                                            : 'Not current'
                                    }
                                />
                            </div>
                            <strong>{tile.value}</strong>
                            <p>{tile.caption}</p>
                            {!tile.current && !isUnavailable && sample && (
                                <small>
                                    {sample.id === latest?.id
                                        ? 'Current state needs a fresh report'
                                        : `Recorded ${observedLabel(sample.occurred_at)} · not current`}
                                </small>
                            )}
                        </section>
                    );
                })}
            </div>
            {/* The design's distance feed (owned by mileage-feed.tsx). */}
            <MileageFeed workspace={workspace} onChanged={onChanged} />
            <div className="telemetry-two">
                <section className="studio-card capability-card">
                    <span className="studio-eyebrow">MODEL CAPABILITIES</span>
                    <h3>What this unit can contribute</h3>
                    {gv500 ? (
                        GV500CG_CAPABILITIES.map(([title, caption]) => (
                            <div className="evidence-row" key={title}>
                                <div className="row-label">
                                    <strong>{title}</strong>
                                    <small>{caption}</small>
                                </div>
                            </div>
                        ))
                    ) : (
                        <div className="evidence-row">
                            <div className="row-label">
                                <strong>
                                    {isUnavailable
                                        ? 'No tracker assigned'
                                        : `Capabilities not reviewed for ${model ?? 'this tracker'}`}
                                </strong>
                                <small>
                                    {isUnavailable
                                        ? 'Assign and validate a device in Security & Devices.'
                                        : 'Only a reviewed model and firmware have their features described here. Check the device record before relying on one.'}
                                </small>
                            </div>
                        </div>
                    )}
                    {telemetry.can.view_alerts && (
                        <Button
                            variant="outline"
                            onClick={() =>
                                onNavigate({ tab: 'map', view: 'alerts' })
                            }
                        >
                            Open vehicle alerts{' '}
                            <ArrowUpRight className="size-[15px]" />
                        </Button>
                    )}
                </section>
                <section className="studio-card capability-card">
                    <span className="studio-eyebrow">VEHICLE IDENTITY</span>
                    <h3>VIN stays with the vehicle record</h3>
                    <p className="vin-value">{vin ?? 'Not recorded'}</p>
                    <StatusBadge variant={vin ? 'neutral' : 'warning'}>
                        {vin ? 'Manually recorded' : 'Evidence needed'}
                    </StatusBadge>
                    <p className="muted">
                        {gv500
                            ? 'GV500CG uses the OBD connector for power only. It does not read ECU VIN, dashboard odometer, diagnostic trouble codes, engine RPM or fuel level directly.'
                            : 'The tracker’s reports don’t supply the VIN or the dashboard odometer; both stay with the vehicle record and its evidence.'}
                    </p>
                    <Button
                        variant="outline"
                        disabled={!workspace.can.manage}
                        onClick={() =>
                            onNavigate({ tab: 'overview', view: 'details' })
                        }
                    >
                        Review vehicle identity
                    </Button>
                    <details>
                        <summary>Sources and feature availability</summary>
                        <p>
                            {gv500
                                ? 'Queclink GV500CG datasheet and User Manual TRACGV500CGUM001, section 2.2. BLE accessories, report fields and command support must be validated for installed firmware. Public integration parameter catalogues include other-family fields and do not prove CG hardware capability.'
                                : 'Features depend on the exact tracker model and its installed firmware. Confirm them against the manufacturer’s documentation before relying on them.'}{' '}
                            {tracker
                                ? `Installed firmware: ${tracker.firmware ?? 'not recorded'}.`
                                : ''}
                        </p>
                    </details>
                    <Button
                        variant="ghost"
                        onClick={() =>
                            onNavigate({ tab: 'service', view: 'mileage' })
                        }
                    >
                        Mileage history <ArrowUpRight className="size-[15px]" />
                    </Button>
                </section>
            </div>
        </div>
    );
}
