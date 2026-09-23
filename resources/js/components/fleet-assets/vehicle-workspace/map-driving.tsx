import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { LoadingState } from '@/components/ui/loading-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly, formatDateTime, formatTime } from '@/lib/datetime';
import {
    Activity,
    ArrowUpRight,
    BarChart3,
    ClipboardCheck,
    Clock3,
    Gauge,
    MapPin,
    Navigation,
    ShieldAlert,
    UserRound,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState, type CSSProperties } from 'react';
import {
    LimitDecisionWizard,
    LimitEvidenceDialog,
    ManualLimitWizard,
    ReviewEventWizard,
    ScorePolicyWizard,
    ScoreRulesDialog,
} from './map-driving-dialogs';
import {
    coachingRecords,
    DRIVING_WORKSPACES,
    evaluateEpisode,
    EVENT_TYPE_FILTERS,
    eventMatches,
    km,
    kpiTiles,
    limitBadge,
    limitSegments,
    overspeedExcess,
    PERIOD_OPTIONS,
    plural,
    policyBadge,
    reviewBadge,
    routeSummary,
    scoreCaption,
    scoreHeadline,
    scoreRingDegrees,
    tripFlags,
    type EventTypeFilter,
} from './map-driving-model';
import type {
    DrivingInsights,
    DrivingPeriod,
    DrivingReviews,
    DrivingWorkspaceKey,
    OverspeedEpisode,
    SpeedLimit,
    SpeedLimits,
    TripReviewEvent,
} from './map-driving-types';
import { useSessionValue, useWorkspaceJson } from './map-insights-data';
import { StudioRow, TripDetailGate, whenLabel } from './map-insights-kit';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import { ReminderActionDialog, type ReminderAction } from './service-reminders';
import './studio.css';
import { ConfirmDriverWizard } from './trip-driver-dialog';
import { CoachingWizard } from './trip-follow-up';
import { driverName, tripHistoryUrl } from './trip-model';
import type { TripDetail } from './trip-types';
import type { VehicleReminder, VehicleWorkspace } from './types';
import { StudioNotice } from './wizard-kit';
import type { WorkspaceLocation } from './workspace-model';

type Props = {
    workspace: VehicleWorkspace;
    onNavigate: (location: WorkspaceLocation) => void;
    onChanged: () => void;
};

const KPI_ICONS: Record<string, LucideIcon> = {
    distance: Navigation,
    overspeed: Gauge,
    idle: Clock3,
    rate: Activity,
};

const WORKSPACE_KEYS = DRIVING_WORKSPACES.map((item) => item.key);

function vehicleLabel(workspace: VehicleWorkspace) {
    return {
        id: workspace.vehicle.id,
        name: workspace.vehicle.name,
        registration:
            workspace.vehicle.registration_number ??
            workspace.vehicle.asset_tag ??
            null,
    };
}

function Loading({
    load,
    onRetry,
    what,
}: {
    load: 'loading' | 'error' | 'unavailable' | 'ready';
    onRetry: () => void;
    what: string;
}) {
    if (load === 'unavailable')
        return (
            <EmptyState
                icon={Gauge}
                title="Driving data isn’t available to you"
                description="Driving insights follow the trip history rules: the vehicle's trips must be at one of your sites."
            />
        );
    if (load === 'error')
        return (
            <ErrorState
                title={`${what} couldn’t be loaded`}
                message="Try again. Nothing was changed."
                onRetry={onRetry}
            />
        );
    return <LoadingState message={`Loading ${what.toLowerCase()}…`} />;
}

/**
 * Map › Driving insights, built to the approved PKG-02B v13 design
 * (DrivingInsights): Analytics, Reviews & coaching and Speed limits. Scores,
 * events and overspeed episodes are read from the vehicle's recorded trips
 * exactly as Trip history reads them; personal and consent-blocked trips are
 * never scored or listed.
 */
export function VehicleDrivingPanel({
    workspace,
    onNavigate,
    onChanged,
}: Props) {
    const [active, setActive] = useSessionValue<DrivingWorkspaceKey>(
        `vehicle-driving.${workspace.vehicle.id}.workspace`,
        'analytics',
        WORKSPACE_KEYS,
    );
    const [reviewTrip, setReviewTrip] = useState<number | null>(null);

    return (
        <div className="telemetry-studio">
            <nav className="workflow-switch" aria-label="Driving workspace">
                {DRIVING_WORKSPACES.map((item) => (
                    <Button
                        key={item.key}
                        variant={active === item.key ? 'default' : 'outline'}
                        aria-pressed={active === item.key}
                        onClick={() => setActive(item.key)}
                    >
                        {item.label}
                    </Button>
                ))}
            </nav>
            {active === 'analytics' ? (
                <DrivingAnalytics
                    workspace={workspace}
                    onNavigate={onNavigate}
                    onChanged={onChanged}
                />
            ) : active === 'reviews' ? (
                <DrivingReviewsWorkspace
                    workspace={workspace}
                    onNavigate={onNavigate}
                    onChanged={onChanged}
                    tripId={reviewTrip}
                    onTrip={setReviewTrip}
                />
            ) : (
                <SpeedLimitsWorkspace
                    workspace={workspace}
                    onNavigate={onNavigate}
                />
            )}
        </div>
    );
}

function tripDay(trip: { local_date: string | null }) {
    return trip.local_date
        ? formatDateOnly(trip.local_date)
        : 'Date not recorded';
}

function DrivingAnalytics({ workspace, onNavigate, onChanged }: Props) {
    const vehicle = workspace.vehicle;
    const [period, setPeriod] = useSessionValue<DrivingPeriod>(
        `vehicle-driving.${vehicle.id}.period`,
        'week',
        ['week', 'today'],
    );
    const [driver, setDriver] = useState('all');
    const [eventType, setEventType] = useState<EventTypeFilter>('all');
    const [rules, setRules] = useState(false);
    const [coachTrip, setCoachTrip] = useState<number | null>(null);
    const [sending, setSending] = useState<OverspeedEpisode | null>(null);
    const url = `/fleet-assets/vehicles/${vehicle.id}/driving?${new URLSearchParams({ period, driver })}`;
    const { data, load, reload } = useWorkspaceJson<DrivingInsights>(url);
    const command = useVehicleRecordCommand(isJsonObject);

    if (!data)
        return <Loading load={load} onRetry={reload} what="Driving insights" />;

    const openTrip = (trip: { local_date: string | null }) =>
        onNavigate(
            trip.local_date
                ? { tab: 'trips', date: trip.local_date }
                : { tab: 'trips' },
        );
    const tiles = kpiTiles(data);
    const scored = data.trips.filter((trip) => trip.score !== null).length;
    const events = data.events.filter((event) =>
        eventMatches(event, eventType),
    );
    const send = async () => {
        if (!sending) return;
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}/alerts/route`,
            {
                kind: 'overspeed',
                trip_id: sending.trip_id,
                event_key: sending.key,
            },
        );
        if (result) {
            setSending(null);
            onNavigate({ tab: 'map', view: 'alerts' });
        }
    };

    return (
        <div className="telemetry-studio analytics-studio">
            <div className="activity-title">
                <div>
                    <span className="studio-eyebrow">DRIVING INSIGHTS</span>
                    <h2 className="text-section-title">Driving analytics</h2>
                    <p className="muted">
                        Trip trends, driving events and response workflows.
                    </p>
                </div>
                <div className="telemetry-filters">
                    <select
                        aria-label="Driving period"
                        value={period}
                        onChange={(change) =>
                            setPeriod(change.target.value as DrivingPeriod)
                        }
                    >
                        {PERIOD_OPTIONS.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                    <select
                        aria-label="Driver assignment"
                        value={driver}
                        onChange={(change) => setDriver(change.target.value)}
                    >
                        <option value="all">All assignments</option>
                        {data.drivers.map((option) => (
                            <option key={option.id} value={String(option.id)}>
                                {option.name}
                            </option>
                        ))}
                        <option value="unassigned">Unassigned</option>
                    </select>
                    <Button variant="outline" onClick={() => setRules(true)}>
                        <BarChart3 className="size-4" />
                        Score rules
                    </Button>
                </div>
            </div>
            {!data.has_data ? (
                <StudioNotice title="No driving data">
                    A tracker and sufficient journey coverage are needed.
                    Missing data does not produce a perfect score.
                </StudioNotice>
            ) : (
                <>
                    <div className="analytics-summary">
                        <section className="studio-card driving-score">
                            <div
                                className="score-ring"
                                style={
                                    {
                                        '--score': scoreRingDegrees(
                                            data.score.value,
                                        ),
                                    } as CSSProperties
                                }
                                role="img"
                                aria-label={
                                    data.score.value === null
                                        ? 'Score withheld'
                                        : `Score ${data.score.value} out of 100`
                                }
                            >
                                <strong>
                                    {data.score.value ?? '—'}
                                    <small>/100</small>
                                </strong>
                            </div>
                            <div>
                                <span className="studio-eyebrow">
                                    {data.score.kind === 'vehicle'
                                        ? 'VEHICLE PERIOD SCORE'
                                        : 'DRIVER SCORE'}
                                </span>
                                <h3>{scoreHeadline(data)}</h3>
                                <p>{scoreCaption(data)}</p>
                                <StatusBadge variant="info">
                                    {policyBadge(data.policy)}
                                </StatusBadge>
                                {/* eslint-disable-next-line no-restricted-syntax -- The design's inline "See calculation" link. */}
                                <button
                                    type="button"
                                    className="score-link"
                                    onClick={() => setRules(true)}
                                >
                                    See calculation{' '}
                                    <ArrowUpRight
                                        className="size-[13px]"
                                        aria-hidden
                                    />
                                </button>
                            </div>
                        </section>
                        <section className="studio-card analytics-kpis">
                            {tiles.map((tile) => {
                                const Icon = KPI_ICONS[tile.key] ?? Gauge;
                                return (
                                    <div key={tile.key}>
                                        <Icon
                                            className="size-[19px]"
                                            aria-hidden
                                        />
                                        <small>{tile.label}</small>
                                        <strong>{tile.value}</strong>
                                        <span>{tile.hint}</span>
                                    </div>
                                );
                            })}
                        </section>
                    </div>
                    <div className="analytics-secondary">
                        <section className="studio-card analytics-trend">
                            <div className="activity-title">
                                <div>
                                    <span className="studio-eyebrow">
                                        {data.score.kind === 'vehicle'
                                            ? 'DAILY VEHICLE SCORE'
                                            : 'DAILY DRIVER SCORE'}
                                    </span>
                                    <h3>See change over time</h3>
                                </div>
                                <StatusBadge variant="neutral">
                                    0–100 points
                                </StatusBadge>
                            </div>
                            <div className="trend-axis" aria-hidden>
                                <span>0</span>
                                <span>50</span>
                                <span>100</span>
                            </div>
                            {data.days.map((day) => (
                                <div className="trend-row" key={day.day}>
                                    <span>{formatDateOnly(day.day)}</span>
                                    <div>
                                        {day.score !== null ? (
                                            <i
                                                style={{
                                                    width: `${day.score}%`,
                                                }}
                                            />
                                        ) : (
                                            <em>Insufficient coverage</em>
                                        )}
                                    </div>
                                    <strong>{day.score ?? '—'}</strong>
                                </div>
                            ))}
                            {!data.days.length && (
                                <p className="muted">
                                    No trips were recorded in this period.
                                </p>
                            )}
                            <p className="muted">
                                Distance-weighted eligible trips per day. Gaps
                                are not zero scores. No previous-period
                                comparison is available.
                            </p>
                        </section>
                        <section className="studio-card speed-focus">
                            <div className="activity-title">
                                <div>
                                    <span className="studio-eyebrow">
                                        OVERSPEED → CONTROL ROOM
                                    </span>
                                    <h3>
                                        {data.overspeed.length
                                            ? 'Review the threshold event'
                                            : 'No recorded overspeed events'}
                                    </h3>
                                </div>
                                <Gauge className="size-6" aria-hidden />
                            </div>
                            {data.overspeed.map((episode) => {
                                const excess = overspeedExcess(
                                    episode.peak_kph,
                                    data.policy.speed_threshold_kph,
                                );
                                const sent = routeSummary(episode.route);
                                return (
                                    <div key={episode.source_key}>
                                        <div className="speed-values">
                                            <strong>
                                                {episode.peak_kph === null
                                                    ? '—'
                                                    : Math.round(
                                                          episode.peak_kph,
                                                      )}
                                                <small>km/h peak</small>
                                            </strong>
                                            <span>
                                                {excess === null
                                                    ? 'Peak speed not recorded'
                                                    : `+${excess} km/h over fleet threshold`}
                                                <br />
                                                {episode.seconds === null
                                                    ? 'Duration not recorded'
                                                    : `${episode.seconds} seconds above trigger`}
                                            </span>
                                        </div>
                                        <p>
                                            {tripDay(episode)} ·{' '}
                                            {formatTime(episode.at)} ·{' '}
                                            {driverName(episode.driver)}
                                        </p>
                                        <StatusBadge variant="warning">
                                            Road speed limit unverified
                                        </StatusBadge>
                                        <div className="analytics-actions">
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    openTrip(episode)
                                                }
                                            >
                                                Review trip
                                            </Button>
                                            {episode.route &&
                                            data.can.view_alerts ? (
                                                <Button
                                                    onClick={() =>
                                                        onNavigate({
                                                            tab: 'map',
                                                            view: 'alerts',
                                                        })
                                                    }
                                                >
                                                    <ShieldAlert className="size-[15px]" />
                                                    Open Control Room response
                                                </Button>
                                            ) : (
                                                <Button
                                                    disabled={!data.can.route}
                                                    onClick={() => {
                                                        command.reset();
                                                        setSending(episode);
                                                    }}
                                                >
                                                    <ShieldAlert className="size-[15px]" />
                                                    {episode.route
                                                        ? 'Send again → Control Room'
                                                        : 'Send overspeed → Control Room'}
                                                </Button>
                                            )}
                                        </div>
                                        {sent && (
                                            <p className="muted">{sent}</p>
                                        )}
                                    </div>
                                );
                            })}
                            {!data.overspeed.length && (
                                <p>
                                    Missing samples can hide events. This does
                                    not establish that every road limit was
                                    followed.
                                </p>
                            )}
                            <p className="muted">
                                One sustained episode creates one response
                                record. Repeated reports are correlated; the
                                alert is routed even when the driver is
                                unconfirmed.
                            </p>
                        </section>
                    </div>
                    <section className="studio-card insights-journey-list">
                        <div className="activity-title">
                            <h3>Insights by trip</h3>
                            <StatusBadge variant="neutral">
                                {scored} scored · {data.trips.length - scored}{' '}
                                partial
                            </StatusBadge>
                        </div>
                        {data.trips.map((trip) => {
                            const [first, second] = tripFlags(trip);
                            return (
                                // eslint-disable-next-line no-restricted-syntax -- The design's full-width trip row.
                                <button
                                    type="button"
                                    key={trip.id}
                                    onClick={() => openTrip(trip)}
                                >
                                    <span className="insight-mini-score">
                                        {trip.score ?? '—'}
                                        <small>/100</small>
                                    </span>
                                    <div>
                                        <strong>
                                            {trip.from ?? 'Start position'} →{' '}
                                            {trip.to ??
                                                (trip.in_progress
                                                    ? 'Trip in progress'
                                                    : 'End position')}
                                        </strong>
                                        <small>
                                            {tripDay(trip)} ·{' '}
                                            {driverName(trip.driver)} ·{' '}
                                            {km(trip.distance_km)} ·{' '}
                                            {trip.coverage_pct ?? 0}% coverage
                                        </small>
                                    </div>
                                    <span className="insight-flags">
                                        {first}
                                        <br />
                                        {second}
                                    </span>
                                    <ArrowUpRight
                                        className="size-[18px]"
                                        aria-hidden
                                    />
                                </button>
                            );
                        })}
                        {!data.trips.length && (
                            <p className="muted">
                                No business trips were recorded in this period.
                                {data.summary.withheld.personal +
                                    data.summary.withheld.consent >
                                0
                                    ? ` ${plural(data.summary.withheld.personal + data.summary.withheld.consent, 'personal or restricted trip')} not shown or scored.`
                                    : ''}
                            </p>
                        )}
                        {data.trips.length > 0 &&
                            data.summary.withheld.personal +
                                data.summary.withheld.consent >
                                0 && (
                                <p className="muted">
                                    {plural(
                                        data.summary.withheld.personal +
                                            data.summary.withheld.consent,
                                        'personal or restricted trip',
                                    )}{' '}
                                    not shown or scored.
                                </p>
                            )}
                    </section>
                    <div className="telemetry-two">
                        <section className="studio-card">
                            <div className="activity-title">
                                <h3>Events to review</h3>
                                <select
                                    aria-label="Driving event type"
                                    value={eventType}
                                    onChange={(change) =>
                                        setEventType(
                                            change.target
                                                .value as EventTypeFilter,
                                        )
                                    }
                                >
                                    {EVENT_TYPE_FILTERS.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            {events.map((event) => (
                                // eslint-disable-next-line no-restricted-syntax -- The design's event row opens its trip.
                                <button
                                    type="button"
                                    className="driving-event"
                                    key={`${event.trip_id}-${event.key}`}
                                    onClick={() => openTrip(event)}
                                >
                                    <span className="event-symbol">
                                        {event.type === 'overspeed' ? (
                                            <Gauge
                                                className="size-[19px]"
                                                aria-hidden
                                            />
                                        ) : (
                                            <Activity
                                                className="size-[19px]"
                                                aria-hidden
                                            />
                                        )}
                                    </span>
                                    <div>
                                        <strong>{event.title}</strong>
                                        <small>
                                            {tripDay(event)} ·{' '}
                                            {formatTime(event.at)} ·{' '}
                                            {driverName(event.driver)}
                                        </small>
                                    </div>
                                    <ArrowUpRight
                                        className="size-4"
                                        aria-hidden
                                    />
                                </button>
                            ))}
                            {!events.length && (
                                <p className="muted">
                                    No matching recorded events.
                                </p>
                            )}
                        </section>
                        <section className="studio-card analytics-quality">
                            <span className="studio-eyebrow">
                                MAKE THE SCORE TRUSTWORTHY
                            </span>
                            <h3>Evidence before attribution</h3>
                            <StudioRow
                                title="Driver identity"
                                value={plural(
                                    data.summary.confirmed_trips,
                                    'confirmed trip',
                                )}
                            />
                            <StudioRow
                                title="Coverage"
                                value={`${plural(data.summary.partial_trips, 'partial trip')} excluded`}
                            />
                            <StudioRow
                                title="Harsh cornering"
                                value={
                                    data.summary.cornering_events
                                        ? `${plural(data.summary.cornering_events, 'reported event')} · acceleration weight`
                                        : 'Counted when the tracker reports it · acceleration weight'
                                }
                            />
                            <StudioRow
                                title="Road speed limits"
                                value={
                                    data.manual_limits
                                        ? `${plural(data.manual_limits, 'approved manual limit')}; no verified provider connected`
                                        : 'No verified limit source connected'
                                }
                            />
                            <p className="muted">
                                {data.policy.min_trips !== null &&
                                data.policy.min_distance_km !== null
                                    ? `The driver score needs confirmed checkout or driver handover, at least ${data.policy.min_trips} eligible trips and ${km(data.policy.min_distance_km)} in the period. These minimums and weights need operational approval.`
                                    : 'A driver score needs confirmed checkout or driver handover and a published scoring policy with minimum trips and distance. These minimums and weights need operational approval.'}
                            </p>
                            <Button
                                variant="outline"
                                disabled={
                                    !data.can.coach ||
                                    !data.trips.length ||
                                    !workspace.people.length
                                }
                                onClick={() =>
                                    setCoachTrip(data.trips[0]?.id ?? null)
                                }
                            >
                                Create coaching follow-up
                            </Button>
                        </section>
                    </div>
                </>
            )}
            {rules && (
                <ScoreRulesDialog
                    insights={data}
                    onClose={() => setRules(false)}
                />
            )}
            {coachTrip !== null && (
                <TripDetailGate
                    vehicleId={vehicle.id}
                    tripId={coachTrip}
                    title="Create coaching follow-up"
                    onClose={() => setCoachTrip(null)}
                >
                    {(detail) => (
                        <CoachingWizard
                            workspace={workspace}
                            detail={detail}
                            onClose={() => setCoachTrip(null)}
                            onSaved={onChanged}
                        />
                    )}
                </TripDetailGate>
            )}
            <ConfirmDialog
                open={sending !== null}
                onClose={() => setSending(null)}
                onConfirm={send}
                processing={command.processing}
                variant="default"
                title="Send this overspeed episode to Control Room?"
                description={
                    <>
                        {sending
                            ? `${sending.trip_reference} · ${whenLabel(sending.at)} · ${sending.peak_kph === null ? 'peak not recorded' : `${Math.round(sending.peak_kph)} km/h peak`}. `
                            : ''}
                        Control Room opens one response for this recorded
                        episode; sending it again adds a duplicate report to
                        that response. The road speed limit is not checked and
                        no emergency call or device command is made.
                        {command.message ? ` ${command.message}` : ''}
                    </>
                }
                confirmText={
                    command.uncertain ? 'Retry sending' : 'Send to Control Room'
                }
            />
        </div>
    );
}

function DrivingReviewsWorkspace({
    workspace,
    onNavigate,
    onChanged,
    tripId,
    onTrip,
}: Props & { tripId: number | null; onTrip: (tripId: number) => void }) {
    const vehicle = workspace.vehicle;
    const url = `/fleet-assets/vehicles/${vehicle.id}/driving/reviews${tripId ? `?trip=${tripId}` : ''}`;
    const { data, load, reload } = useWorkspaceJson<DrivingReviews>(url);
    // The trip's own record (driver attribution and its history), as Trip history shows it.
    const selectedId = data?.trip?.id ?? null;
    const record = useWorkspaceJson<TripDetail>(
        selectedId ? tripHistoryUrl(vehicle.id, `/${selectedId}`) : null,
    );
    const [reviewing, setReviewing] = useState<TripReviewEvent | null>(null);
    const [policyOpen, setPolicyOpen] = useState(false);
    const [driverOpen, setDriverOpen] = useState(false);
    const [coaching, setCoaching] = useState(false);
    const [coachAction, setCoachAction] = useState<{
        reminder: VehicleReminder;
        action: ReminderAction;
    } | null>(null);
    const records = useMemo(
        () => coachingRecords(workspace.reminders),
        [workspace.reminders],
    );

    if (!data)
        return <Loading load={load} onRetry={reload} what="Driving reviews" />;

    const trip = data.trip;
    const label = vehicleLabel(workspace);
    const detail =
        record.data && record.data.trip.id === trip?.id ? record.data : null;
    const refreshAll = () => {
        reload();
        record.reload();
    };

    return (
        <div className="telemetry-studio">
            <div className="activity-title">
                <div>
                    <span className="studio-eyebrow">
                        DRIVER & EVENT REVIEW
                    </span>
                    <h2 className="text-section-title">
                        Close the evidence gaps
                    </h2>
                </div>
                {data.trips.length > 0 && (
                    <select
                        aria-label="Review trip"
                        value={trip?.id ?? ''}
                        onChange={(change) =>
                            onTrip(Number(change.target.value))
                        }
                    >
                        {trip &&
                            !data.trips.some(
                                (option) => option.id === trip.id,
                            ) && (
                                <option value={trip.id}>
                                    {trip.reference} · {driverName(trip.driver)}
                                </option>
                            )}
                        {data.trips.map((option) => (
                            <option key={option.id} value={option.id}>
                                {option.reference} ·{' '}
                                {option.local_date
                                    ? formatDateOnly(option.local_date)
                                    : ''}{' '}
                                ·{' '}
                                {option.driver
                                    ? driverName(option.driver)
                                    : 'Unassigned'}
                            </option>
                        ))}
                    </select>
                )}
            </div>
            {!trip ? (
                <StudioNotice title="No business trips to review">
                    Business trips from the last 30 days appear here. Personal
                    and restricted trips are never reviewed or scored.
                </StudioNotice>
            ) : (
                <>
                    <div className="telemetry-two">
                        <section className="studio-card">
                            <div className="activity-title">
                                <h3>
                                    <UserRound
                                        className="inline size-[18px]"
                                        aria-hidden
                                    />{' '}
                                    Driver and handovers
                                </h3>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        onNavigate(
                                            trip.local_date
                                                ? {
                                                      tab: 'trips',
                                                      date: trip.local_date,
                                                  }
                                                : { tab: 'trips' },
                                        )
                                    }
                                >
                                    View trip{' '}
                                    <ArrowUpRight className="size-[14px]" />
                                </Button>
                            </div>
                            <StudioRow
                                title="Planned assignment"
                                value={
                                    trip.driver.booked_driver ??
                                    (trip.driver.state === 'recorded'
                                        ? driverName(trip.driver)
                                        : 'No booking or sign-in')
                                }
                            />
                            <StudioRow
                                title="Confirmed whole-trip driver"
                                value={
                                    trip.driver.state === 'confirmed'
                                        ? driverName(trip.driver)
                                        : 'Not confirmed'
                                }
                            />
                            <Button
                                disabled={!data.can.confirm_driver || !detail}
                                onClick={() => setDriverOpen(true)}
                            >
                                Confirm driver / handover
                            </Button>
                            <details>
                                <summary>Assignment history</summary>
                                {detail?.driver_history.length ? (
                                    detail.driver_history.map((entry) => (
                                        <p key={entry.id}>
                                            {formatDateTime(entry.confirmed_at)}{' '}
                                            ·{' '}
                                            {entry.confirmed_by ?? 'Not shown'}{' '}
                                            ·{' '}
                                            {entry.driver ?? 'Driver not shown'}{' '}
                                            (
                                            {entry.source === 'handover'
                                                ? 'handover from the booking'
                                                : entry.source === 'booking'
                                                  ? 'booked driver confirmed'
                                                  : 'confirmed without a booking'}
                                            ): {entry.reason}
                                        </p>
                                    ))
                                ) : (
                                    <p>
                                        {data.can.confirm_driver
                                            ? 'No confirmation recorded yet. A booking or sign-in is evidence, not proof of who drove.'
                                            : 'The confirmation history is shown to fleet and trip managers.'}
                                    </p>
                                )}
                            </details>
                        </section>
                        <section className="studio-card">
                            <span className="studio-eyebrow">
                                RECALCULATED TRIP SCORE
                            </span>
                            <strong className="review-score">
                                {trip.score ?? '—'}
                                <small>/100</small>
                            </strong>
                            <p>
                                Dismissed events are excluded. A disputed event
                                withholds the score until resolved. The original
                                telemetry is retained.
                            </p>
                            <StatusBadge variant="neutral">
                                {policyBadge(data.policy)}
                            </StatusBadge>{' '}
                            <Button
                                variant="outline"
                                disabled={!data.can.manage_policy}
                                onClick={() => setPolicyOpen(true)}
                            >
                                Review scoring policy
                            </Button>
                            <details>
                                <summary>Policy history</summary>
                                {data.policy.history.length ? (
                                    data.policy.history.map((version) => (
                                        <p key={version.version}>
                                            v{version.version} · braking{' '}
                                            {version.braking}, acceleration{' '}
                                            {version.acceleration}, overspeed{' '}
                                            {version.overspeed}, idle{' '}
                                            {version.idle}/min ·{' '}
                                            {version.min_coverage_pct}% coverage
                                            · {version.min_trips} trips /{' '}
                                            {version.min_distance_km} km ·{' '}
                                            {version.published_by ??
                                                'Former user'}
                                            ,{' '}
                                            {formatDateTime(
                                                version.published_at,
                                            )}
                                            : {version.reason}
                                        </p>
                                    ))
                                ) : (
                                    <p>
                                        The fleet settings apply; no version has
                                        been published yet.
                                    </p>
                                )}
                            </details>
                        </section>
                    </div>
                    <section className="studio-card review-event-list">
                        <h3>Review individual events</h3>
                        {trip.events.map((event) => {
                            const badge = reviewBadge(event.review);
                            return (
                                <div key={event.key}>
                                    <span className="event-symbol">
                                        <ClipboardCheck
                                            className="size-5"
                                            aria-hidden
                                        />
                                    </span>
                                    <div>
                                        <strong>
                                            {event.title} ·{' '}
                                            {formatTime(event.at)}
                                        </strong>
                                        <p>{event.detail}</p>
                                        <small>
                                            Driver at event:{' '}
                                            {event.driver_at_event ??
                                                'Unconfirmed'}{' '}
                                            · Original source retained
                                        </small>
                                        {event.review && (
                                            <p>
                                                {event.review.review_owner ??
                                                    'Review owner'}
                                                : {event.review.reason}
                                            </p>
                                        )}
                                        {event.history.length > 0 && (
                                            <details>
                                                <summary>
                                                    Review history
                                                </summary>
                                                {event.history.map((entry) => (
                                                    <p key={entry.id}>
                                                        {formatDateTime(
                                                            entry.at,
                                                        )}{' '}
                                                        ·{' '}
                                                        {entry.recorded_by ??
                                                            'Former user'}{' '}
                                                        ·{' '}
                                                        {
                                                            reviewBadge(entry)
                                                                .label
                                                        }{' '}
                                                        (owner{' '}
                                                        {entry.review_owner ??
                                                            'not shown'}
                                                        , policy v
                                                        {entry.policy_version}):{' '}
                                                        {entry.reason}
                                                    </p>
                                                ))}
                                            </details>
                                        )}
                                    </div>
                                    <StatusBadge variant={badge.variant}>
                                        {badge.label}
                                    </StatusBadge>
                                    <Button
                                        variant="outline"
                                        disabled={!data.can.review}
                                        onClick={() => setReviewing(event)}
                                    >
                                        Review event
                                    </Button>
                                </div>
                            );
                        })}
                        {!trip.events.length && (
                            <p>No driving events to review for this journey.</p>
                        )}
                    </section>
                    <section className="studio-card coaching-workspace">
                        <div className="activity-title">
                            <div>
                                <span className="studio-eyebrow">
                                    COACHING & FOLLOW-THROUGH
                                </span>
                                <h3>From review to improvement</h3>
                            </div>
                            <Button
                                disabled={
                                    !data.can.coach || !workspace.people.length
                                }
                                onClick={() => setCoaching(true)}
                            >
                                Create coaching follow-up
                            </Button>
                        </div>
                        {records.length ? (
                            records.map((record) => (
                                <div
                                    className="coaching-record"
                                    key={record.reminder.id}
                                >
                                    <div>
                                        <strong>{record.reminder.title}</strong>
                                        <p>
                                            {record.reminder.owner?.name ??
                                                'Owner unavailable'}{' '}
                                            · review{' '}
                                            {formatDateTime(
                                                record.reminder.due_at,
                                            )}
                                        </p>
                                        <p>{record.reminder.action_text}</p>
                                        <details>
                                            <summary>Response history</summary>
                                            {record.reminder.events.map(
                                                (event) => (
                                                    <p key={event.id}>
                                                        {formatDateTime(
                                                            event.occurred_at,
                                                        )}{' '}
                                                        ·{' '}
                                                        {event.actor ??
                                                            'Someone'}{' '}
                                                        · {event.action}
                                                        {event.note
                                                            ? `: ${event.note}`
                                                            : ''}
                                                    </p>
                                                ),
                                            )}
                                        </details>
                                    </div>
                                    <StatusBadge
                                        variant={record.status.variant}
                                    >
                                        {record.status.label}
                                    </StatusBadge>
                                    {record.next && (
                                        <Button
                                            variant="outline"
                                            disabled={!data.can.coach}
                                            onClick={() =>
                                                setCoachAction({
                                                    reminder: record.reminder,
                                                    action: record.next!.action,
                                                })
                                            }
                                        >
                                            {record.next.label}
                                        </Button>
                                    )}
                                </div>
                            ))
                        ) : (
                            <p className="muted">
                                Create an owned follow-up from the selected
                                trip. Acknowledgement and completion retain the
                                original evidence.
                            </p>
                        )}
                    </section>
                </>
            )}
            {reviewing && trip && (
                <ReviewEventWizard
                    vehicle={label}
                    trip={trip}
                    event={reviewing}
                    people={data.people}
                    onClose={() => setReviewing(null)}
                    onSaved={refreshAll}
                />
            )}
            {policyOpen && (
                <ScorePolicyWizard
                    vehicle={label}
                    policy={data.policy}
                    onClose={() => setPolicyOpen(false)}
                    onSaved={refreshAll}
                />
            )}
            {driverOpen && detail && (
                <ConfirmDriverWizard
                    workspace={workspace}
                    detail={detail}
                    onClose={() => setDriverOpen(false)}
                    onSaved={refreshAll}
                />
            )}
            {coaching && trip && (
                <TripDetailGate
                    vehicleId={vehicle.id}
                    tripId={trip.id}
                    title="Create coaching follow-up"
                    onClose={() => setCoaching(false)}
                >
                    {(loaded) => (
                        <CoachingWizard
                            workspace={workspace}
                            detail={loaded}
                            onClose={() => setCoaching(false)}
                            onSaved={onChanged}
                        />
                    )}
                </TripDetailGate>
            )}
            {coachAction && (
                <ReminderActionDialog
                    vehicleId={vehicle.id}
                    reminder={coachAction.reminder}
                    action={coachAction.action}
                    onClose={() => setCoachAction(null)}
                    onSaved={onChanged}
                />
            )}
        </div>
    );
}

const NO_ROAD = '__no_road__';

function SpeedLimitsWorkspace({
    workspace,
    onNavigate,
}: Omit<Props, 'onChanged'>) {
    const vehicle = workspace.vehicle;
    const { data, load, reload } = useWorkspaceJson<SpeedLimits>(
        `/fleet-assets/vehicles/${vehicle.id}/driving/speed-limits`,
    );
    const [episodeKey, setEpisodeKey] = useState('');
    const [segment, setSegment] = useState(NO_ROAD);
    const [direction, setDirection] = useState('Both directions');
    const [result, setResult] = useState('');
    const [adding, setAdding] = useState(false);
    const [deciding, setDeciding] = useState<{
        limit: SpeedLimit;
        action: 'approve' | 'retire';
    } | null>(null);
    const [evidenceFor, setEvidenceFor] = useState<SpeedLimit | null>(null);
    const command = useVehicleRecordCommand(isJsonObject);

    if (!data)
        return (
            <Loading load={load} onRetry={reload} what="Speed limit sources" />
        );

    const label = vehicleLabel(workspace);
    const segments = limitSegments(data.limits);
    const episode =
        data.episodes.find((item) => item.source_key === episodeKey) ??
        data.episodes[0] ??
        null;
    const road = segment === NO_ROAD ? '' : segment;
    const evaluation = episode
        ? evaluateEpisode(episode, data.limits, data.rule, road, direction)
        : null;
    const evaluate = async () => {
        if (!episode || !evaluation) return;
        // A refusal that needs fresh records (the rule or episode changed) is
        // settled by loading them; the person then evaluates again.
        if (command.requiresReload) {
            command.reset();
            reload();
            setResult(
                'Loaded the latest rule and evidence. Review them, then evaluate again.',
            );
            return;
        }
        if (!evaluation.qualifies) {
            setResult(
                'No qualifying overspeed episode for the selected evidence and rule. Nothing was sent.',
            );
            return;
        }
        const response = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}/alerts/route`,
            {
                kind: 'overspeed',
                trip_id: episode.trip_id,
                event_key: episode.event_key,
                evaluation: { segment: road || 'No recorded road', direction },
            },
        );
        if (response) {
            setResult(
                typeof response.message === 'string'
                    ? response.message
                    : 'Sent to Control Room.',
            );
            onNavigate({ tab: 'map', view: 'alerts' });
        }
    };
    // A server refusal (for example a rule changed since this page loaded) explains itself.
    const notice =
        result ||
        command.errors.evaluation ||
        command.errors.event_key ||
        command.message;

    return (
        <div className="telemetry-studio">
            <div className="activity-title">
                <div>
                    <span className="studio-eyebrow">SPEED LIMIT SOURCES</span>
                    <h2 className="text-section-title">
                        Mapped limits, with reviewed local evidence
                    </h2>
                    <p className="muted">
                        Road limits and fleet policy remain separate. Every
                        decision retains its source.
                    </p>
                </div>
                <Button
                    disabled={!data.can.manage}
                    onClick={() => setAdding(true)}
                >
                    Add manual speed limit
                </Button>
            </div>
            <StudioNotice title="No speed-limit provider is connected">
                Road speed limits are not checked automatically, and the map
                imagery alone cannot supply a verified speed limit. Approved
                manual limits below are the only road-limit source; otherwise
                the fleet threshold applies and the evidence says so.
            </StudioNotice>
            <details className="speed-source-notes">
                <summary>OpenStreetMap, Google and temporary limits</summary>
                <p>
                    <strong>OpenStreetMap:</strong> road data can include
                    maxspeed, direction and conditional limits. Missing or
                    uncertain matches stay unknown. Map tiles do not contain
                    this usable road data; a road-matching service and dated
                    data feed are required.{' '}
                    <a
                        href="https://wiki.openstreetmap.org/wiki/Maxspeed"
                        target="_blank"
                        rel="noreferrer"
                    >
                        OSM speed data
                    </a>
                </p>
                <p>
                    <strong>Google Roads:</strong> speed limits require an Asset
                    Tracking licence, are not real-time and may be outdated.
                    Google restricts displaying Roads results on non-Google
                    maps; the vehicle map uses OpenStreetMap and does not mix
                    Google road data into it.{' '}
                    <a
                        href="https://developers.google.com/maps/documentation/roads/speed-limits"
                        target="_blank"
                        rel="noreferrer"
                    >
                        Google speed limits
                    </a>
                </p>
                <p>
                    <strong>New Zealand:</strong> validate the baseline against
                    NZTA's register. Temporary restrictions need a suitable feed
                    or reviewed manual evidence with road, direction, start and
                    expiry. If a road limit is unknown, the fleet threshold
                    still applies and the alert says so.
                </p>
            </details>
            <div className="telemetry-two">
                <section className="studio-card">
                    <div className="activity-title">
                        <h3>
                            <MapPin
                                className="inline size-[18px]"
                                aria-hidden
                            />{' '}
                            Mapped road data
                        </h3>
                        <StatusBadge variant="warning">
                            Not connected
                        </StatusBadge>
                    </div>
                    <StudioRow
                        title="Road-limit provider"
                        value="Not connected"
                    />
                    <StudioRow title="Match confidence" value="Not available" />
                    <StudioRow title="Source record" value="None" />
                    <p>
                        Live implementation: road matching + speed-limit
                        provider, source freshness and effective-time checks.
                        Public NZTA data can support the baseline; temporary
                        limits need another source or reviewed local evidence.
                    </p>
                    <details>
                        <summary>Source selection rules</summary>
                        <ol>
                            <li>
                                Approved, unexpired manual entry for this road
                                and direction.
                            </li>
                            <li>
                                Fresh provider limit for a confident match (no
                                provider is connected).
                            </li>
                            <li>
                                Road limit unknown. The fleet threshold may
                                still create a fleet-policy alert.
                            </li>
                        </ol>
                        <p>
                            The lower applicable road/fleet threshold is used,
                            plus tolerance. Conflicting approved entries make
                            the road limit unknown. New rules never rewrite
                            prior alert evidence.
                        </p>
                    </details>
                </section>
                <section className="studio-card speed-evaluation">
                    <div className="activity-title">
                        <h3>
                            <Gauge className="inline size-[19px]" aria-hidden />{' '}
                            Evaluate a recorded episode
                        </h3>
                        <StatusBadge variant="neutral">
                            {data.rule.source === 'plan'
                                ? `Draft plan v${data.rule.plan_version}`
                                : 'Fleet setting'}
                        </StatusBadge>
                    </div>
                    {episode ? (
                        <>
                            <div className="speed-form-grid">
                                <label className="speed-field speed-field-wide">
                                    Recorded overspeed episode
                                    <select
                                        aria-label="Recorded overspeed episode"
                                        value={episode.source_key}
                                        onChange={(change) => {
                                            setEpisodeKey(change.target.value);
                                            setResult('');
                                        }}
                                    >
                                        {data.episodes.map((item) => (
                                            <option
                                                key={item.source_key}
                                                value={item.source_key}
                                            >
                                                {item.trip_reference} ·{' '}
                                                {whenLabel(item.at)} ·{' '}
                                                {item.peak_kph === null
                                                    ? 'peak not recorded'
                                                    : `${Math.round(item.peak_kph)} km/h`}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="speed-field">
                                    Road segment
                                    <select
                                        aria-label="Speed sample road segment"
                                        value={segment}
                                        onChange={(change) => {
                                            setSegment(change.target.value);
                                            setResult('');
                                        }}
                                    >
                                        <option value={NO_ROAD}>
                                            No recorded road · fleet threshold
                                        </option>
                                        {segments.map((item) => (
                                            <option key={item} value={item}>
                                                {item}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="speed-field">
                                    Direction
                                    <select
                                        aria-label="Speed sample direction"
                                        value={direction}
                                        onChange={(change) => {
                                            setDirection(change.target.value);
                                            setResult('');
                                        }}
                                    >
                                        {data.directions.map((item) => (
                                            <option key={item} value={item}>
                                                {item}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="speed-field">
                                    Observed at
                                    <input
                                        readOnly
                                        value={whenLabel(episode.at)}
                                        aria-label="Episode observed at"
                                    />
                                </label>
                                <label className="speed-field">
                                    Peak speed · km/h
                                    <input
                                        readOnly
                                        value={
                                            episode.peak_kph === null
                                                ? 'Not recorded'
                                                : Math.round(episode.peak_kph)
                                        }
                                        aria-label="Episode peak speed"
                                    />
                                </label>
                                <label className="speed-field">
                                    Continuous time above threshold · seconds
                                    <input
                                        readOnly
                                        value={
                                            episode.seconds ?? 'Not recorded'
                                        }
                                        aria-label="Episode duration"
                                    />
                                </label>
                            </div>
                            <StudioRow
                                title="Road limit"
                                value={
                                    evaluation?.road === null || !evaluation
                                        ? 'Unknown'
                                        : `${evaluation.road} km/h`
                                }
                            />
                            <StudioRow
                                title="Applied trigger"
                                value={
                                    evaluation
                                        ? `${evaluation.trigger} km/h`
                                        : '—'
                                }
                            />
                            <StudioRow
                                title="Source"
                                value={evaluation?.source ?? '—'}
                            />
                            <p className="muted">
                                {evaluation?.confidence} · {evaluation?.record}.{' '}
                                {data.rule.source === 'plan'
                                    ? `Draft plan: ${data.rule.threshold_kph} km/h + ${data.rule.tolerance_kph} km/h tolerance for at least ${data.rule.min_seconds} seconds.`
                                    : `Fleet setting ${data.rule.threshold_kph} km/h with no tolerance; draft a response plan to set one.`}{' '}
                                Duration comes from the recorded samples.
                            </p>
                            <Button
                                disabled={!data.can.route || command.processing}
                                onClick={evaluate}
                            >
                                <ShieldAlert className="size-4" />
                                Evaluate & route to Control Room
                            </Button>
                            {notice && <StudioNotice title={notice} />}
                        </>
                    ) : (
                        <p className="muted">
                            No overspeed episodes were recorded on business
                            trips in the last 30 days.
                        </p>
                    )}
                </section>
            </div>
            <section className="studio-card manual-limit-list">
                <h3>Manual limits & approvals</h3>
                {data.limits.map((limit) => {
                    const badge = limitBadge(limit);
                    return (
                        <div className="coaching-record" key={limit.id}>
                            <div>
                                <strong>
                                    {limit.limit_kph} km/h ·{' '}
                                    {limit.road_segment} · {limit.direction}
                                </strong>
                                <p>
                                    {formatDateTime(limit.effective_from)} →{' '}
                                    {formatDateTime(limit.expires_at)}
                                </p>
                                <p>{limit.reason}</p>
                                <div className="source-files">
                                    {limit.files.map((file) =>
                                        file.url ? (
                                            <a
                                                key={file.id}
                                                href={file.url}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                View {file.name} ↗
                                            </a>
                                        ) : (
                                            <span key={file.id}>
                                                {file.name} · waiting for a
                                                virus check
                                            </span>
                                        ),
                                    )}
                                </div>
                                <small>
                                    {plural(
                                        limit.files.length,
                                        'evidence file',
                                    )}{' '}
                                    · {limit.reference}
                                    {limit.proposed_by
                                        ? ` · proposed by ${limit.proposed_by}`
                                        : ''}
                                </small>
                                <details>
                                    <summary>Review history</summary>
                                    {limit.history.map((event) => (
                                        <p key={event.id}>
                                            {formatDateTime(event.at)} ·{' '}
                                            {event.actor ?? 'Former user'} ·{' '}
                                            {event.action}: {event.note}
                                        </p>
                                    ))}
                                </details>
                            </div>
                            <StatusBadge variant={badge.variant}>
                                {badge.label}
                            </StatusBadge>
                            {limit.status === 'pending' && (
                                <Button
                                    disabled={
                                        !data.can.manage || limit.proposed_by_me
                                    }
                                    title={
                                        limit.proposed_by_me
                                            ? 'Someone other than the proposer must approve it.'
                                            : undefined
                                    }
                                    onClick={() =>
                                        setDeciding({
                                            limit,
                                            action: 'approve',
                                        })
                                    }
                                >
                                    Review limit
                                </Button>
                            )}
                            {limit.status === 'approved' && !limit.expired && (
                                <Button
                                    variant="outline"
                                    disabled={!data.can.manage}
                                    onClick={() =>
                                        setDeciding({ limit, action: 'retire' })
                                    }
                                >
                                    Retire
                                </Button>
                            )}
                            {limit.status !== 'retired' &&
                                !limit.expired &&
                                data.can.upload_evidence && (
                                    <Button
                                        variant="ghost"
                                        onClick={() => setEvidenceFor(limit)}
                                    >
                                        Add evidence
                                    </Button>
                                )}
                        </div>
                    );
                })}
                {!data.limits.length && (
                    <p>
                        No manual limits. Add a temporary sign, authority update
                        or approved site-road limit with evidence and an expiry.
                    </p>
                )}
            </section>
            {adding && (
                <ManualLimitWizard
                    vehicle={label}
                    directions={data.directions}
                    segments={segments}
                    canUpload={data.can.upload_evidence}
                    onClose={() => setAdding(false)}
                    onSaved={reload}
                />
            )}
            {deciding && (
                <LimitDecisionWizard
                    vehicle={label}
                    limit={deciding.limit}
                    action={deciding.action}
                    onClose={() => setDeciding(null)}
                    onSaved={reload}
                />
            )}
            {evidenceFor && (
                <LimitEvidenceDialog
                    vehicle={label}
                    limit={evidenceFor}
                    onClose={() => setEvidenceFor(null)}
                    onSaved={reload}
                />
            )}
        </div>
    );
}
