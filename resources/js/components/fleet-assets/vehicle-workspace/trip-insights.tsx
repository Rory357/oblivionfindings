import { Button } from '@/components/ui/button';
import {
    Activity,
    ArrowUpRight,
    Clock3,
    Gauge,
    Navigation,
    UserRound,
    Zap,
    type LucideIcon,
} from 'lucide-react';
import { useMemo } from 'react';
import {
    driverCaption,
    driverName,
    formatDistance,
    formatIdle,
    formatSpeed,
    plural,
    scoreCaption,
    scoreHeadline,
    speedChart,
    tripMinutes,
} from './trip-model';
import type { TripDetail } from './trip-types';

/** The narrow inspector beside the recorded journey (approved v13 layout). */
export function TripInsights({
    detail,
    sample,
    canConfirmDriver,
    onConfirmDriver,
    onSource,
}: {
    detail: TripDetail;
    sample: number;
    canConfirmDriver: boolean;
    onConfirmDriver: () => void;
    onSource: () => void;
}) {
    const { behaviour, policy, trip } = detail;
    const otherHarsh = behaviour.harsh.cornering + behaviour.harsh.unclassified;
    const facts: Array<[LucideIcon, string, string]> = [
        [Navigation, 'Distance', formatDistance(trip.distance_km)],
        [Clock3, 'Duration', `${tripMinutes(trip.duration_s)} min`],
        [Gauge, 'Maximum speed', formatSpeed(behaviour.max_speed_kph)],
        [Zap, 'Idle time', formatIdle(behaviour.idle_minutes)],
        [Activity, 'Braking', String(behaviour.harsh.braking)],
        [Gauge, 'Overspeed episodes', String(behaviour.overspeed_episodes)],
        [
            Clock3,
            'Over threshold',
            behaviour.overspeed_seconds === null
                ? 'Not recorded'
                : `${behaviour.overspeed_seconds} sec`,
        ],
        [Activity, 'Acceleration', String(behaviour.harsh.acceleration)],
    ];

    return (
        <div className="journey-insights">
            <section
                className="studio-card trip-score-card"
                aria-label="Trip behaviour"
            >
                <div className="trip-score-number">
                    {behaviour.score ?? '—'}
                    <small>/100</small>
                </div>
                <div>
                    <span className="studio-eyebrow">Trip behaviour</span>
                    <h3>{scoreHeadline(behaviour)}</h3>
                    <p>{scoreCaption(behaviour, policy)}</p>
                </div>
            </section>
            <section className="studio-card trip-facts" aria-label="Trip facts">
                {facts.map(([Icon, label, value]) => (
                    <div key={label}>
                        <Icon size={18} aria-hidden="true" />
                        <small>{label}</small>
                        <strong>{value}</strong>
                    </div>
                ))}
                {otherHarsh > 0 && (
                    <p>
                        {plural(otherHarsh, 'other harsh event')} (cornering, or
                        the tracker did not report the type).
                    </p>
                )}
                {behaviour.events_per_100km !== null && (
                    <p>
                        {behaviour.events_per_100km.toLocaleString('en-NZ')}{' '}
                        driving events per 100 km
                    </p>
                )}
            </section>
            <Button
                variant="outline"
                disabled={!canConfirmDriver}
                title={
                    canConfirmDriver
                        ? undefined
                        : 'Confirming a driver needs fleet or trip management access.'
                }
                onClick={onConfirmDriver}
            >
                Confirm driver / handover
            </Button>
            <SpeedThroughTrip detail={detail} sample={sample} />
            <section
                className="studio-card trip-driver"
                aria-label="Trip driver"
            >
                <UserRound size={21} aria-hidden="true" />
                <div>
                    <strong>{driverName(detail.driver)}</strong>
                    <small>{driverCaption(detail.driver)}</small>
                </div>
                <Button variant="ghost" size="sm" onClick={onSource}>
                    Source <ArrowUpRight size={13} aria-hidden="true" />
                </Button>
            </section>
        </div>
    );
}

function SpeedThroughTrip({
    detail,
    sample,
}: {
    detail: TripDetail;
    sample: number;
}) {
    const { points, policy, trip } = detail;
    const chart = useMemo(
        () => speedChart(points, sample, policy),
        [points, sample, policy],
    );
    const selectedSpeed = points[sample]?.speed_kph ?? null;
    const threshold = Math.round(policy.speed_threshold_kph);

    return (
        <section className="studio-card trip-speed" aria-label="Speed chart">
            <div>
                <h3>Speed through the trip</h3>
                <small>Recorded samples · km/h</small>
            </div>
            {chart.samples ? (
                <svg
                    viewBox="0 0 320 105"
                    role="img"
                    aria-label={`Recorded speed through the trip: ${chart.samples} samples, highest ${formatSpeed(chart.maxSpeed)}; selected point ${formatSpeed(selectedSpeed)}.`}
                >
                    <path
                        d="M15 85H305M15 45H305M15 5H305"
                        stroke="var(--border)"
                        fill="none"
                    />
                    {chart.lines.map((line, index) => (
                        <polyline
                            key={index}
                            points={line}
                            fill="none"
                            stroke="var(--primary)"
                            strokeWidth="3"
                            strokeLinejoin="round"
                            strokeLinecap="round"
                        />
                    ))}
                    {chart.dots.map((dot) => (
                        <circle
                            key={dot.index}
                            cx={dot.cx}
                            cy={dot.cy}
                            r={3}
                            fill="var(--primary)"
                        />
                    ))}
                    {chart.selected && (
                        <circle
                            cx={chart.selected.cx}
                            cy={chart.selected.cy}
                            r={6}
                            fill="var(--primary)"
                        />
                    )}
                    <text x="15" y="103">
                        Departure
                    </text>
                    {chart.threshold && (
                        <>
                            <line
                                x1="15"
                                x2="305"
                                y1={chart.threshold.y}
                                y2={chart.threshold.y}
                                stroke="var(--status-warning)"
                                strokeDasharray="4 3"
                            />
                            <text x="16" y="12">
                                {chart.threshold.label}
                            </text>
                        </>
                    )}
                    <text x="305" y="103" textAnchor="end">
                        {trip.in_progress ? 'Latest' : 'Arrival'}
                    </text>
                </svg>
            ) : (
                <p>Speed was not reported by this tracker for this trip.</p>
            )}
            <details>
                <summary>Speed source & threshold</summary>
                <p>
                    Selected sample: {formatSpeed(selectedSpeed)}. Speeds are
                    the tracker&apos;s GPS readings; no road speed limit is
                    inferred.{' '}
                    {chart.threshold
                        ? `Dashed line: the fleet threshold of ${threshold} km/h.`
                        : `The fleet threshold is ${threshold} km/h.`}{' '}
                    Overspeed durations are measured between recorded samples,
                    and the line breaks where reports are missing.
                </p>
            </details>
        </section>
    );
}
