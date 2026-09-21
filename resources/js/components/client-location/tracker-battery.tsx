import type { ClientLocationData } from '@/components/client-location-tab';
import { formatDateTime } from '@/lib/datetime';
import { Check, Plug, Zap } from 'lucide-react';
import { useSyncExternalStore } from 'react';

const motionQuery = '(prefers-reduced-motion: reduce)';
const subscribeMotion = (onChange: () => void) => {
    const preference = window.matchMedia(motionQuery);
    preference.addEventListener('change', onChange);
    return () => preference.removeEventListener('change', onChange);
};
const readReducedMotion = () => window.matchMedia(motionQuery).matches;

type BatteryProps = Pick<
    NonNullable<ClientLocationData['tracker']>,
    | 'battery'
    | 'battery_low_threshold'
    | 'battery_updated_at'
    | 'charging_status'
    | 'external_power'
>;

export default function TrackerBattery({
    battery,
    battery_low_threshold = 20,
    battery_updated_at,
    charging_status,
    external_power,
}: BatteryProps) {
    const reducedMotion = useSyncExternalStore(
        subscribeMotion,
        readReducedMotion,
        () => true,
    );
    const level =
        battery != null &&
        Number.isFinite(battery) &&
        battery >= 0 &&
        battery <= 100
            ? Math.round(battery)
            : null;
    const charging = charging_status === 'charging';
    const full = charging_status === 'charge_full';
    const stopped = ['stopped_charging', 'not_charging'].includes(
        charging_status ?? '',
    );
    const low = level != null && level <= (battery_low_threshold ?? 20);
    const status = charging
        ? 'Charging'
        : full
          ? 'Fully charged'
          : stopped
            ? 'Not charging'
            : external_power === true
              ? 'External power connected'
              : 'Charging not reported';

    return (
        <section
            className="tracker-battery"
            aria-label="Tracker battery"
            data-charging={charging}
            data-animated={charging && !reducedMotion}
            data-low={low}
            data-unknown={level === null}
        >
            <div className="tracker-battery-heading">
                <span>BATTERY</span>
                {low ? (
                    <span className="tracker-battery-low">Low battery</span>
                ) : (
                    <span>Last reported</span>
                )}
            </div>
            <div className="tracker-battery-reading">
                <span className="tracker-battery-shell" aria-hidden="true">
                    <span
                        className="tracker-battery-fill"
                        style={{ width: `${level ?? 0}%` }}
                    />
                    {level === null && (
                        <span className="tracker-battery-unknown">?</span>
                    )}
                </span>
                <strong>
                    {level === null ? (
                        'Unknown'
                    ) : (
                        <>
                            {level}
                            <span className="tracker-battery-percent">%</span>
                        </>
                    )}
                </strong>
                {charging && (
                    <span className="tracker-battery-charge" aria-hidden="true">
                        <Zap />
                    </span>
                )}
                {full && !charging && (
                    <Check
                        className="tracker-battery-complete"
                        aria-hidden="true"
                    />
                )}
            </div>
            <div className="tracker-battery-status">
                {external_power === true && !charging && !full && (
                    <Plug aria-hidden="true" />
                )}
                <span>{status}</span>
                {(charging || full || stopped) && (
                    <span className="tracker-battery-reported">
                        last reported
                    </span>
                )}
            </div>
            {external_power === true && !charging && !full && !stopped && (
                <p className="tracker-battery-note">
                    Charging status not reported by this unit.
                </p>
            )}
            <p className="tracker-battery-measured">
                Battery measured{' '}
                {battery_updated_at
                    ? formatDateTime(battery_updated_at)
                    : '— time not reported'}
            </p>
        </section>
    );
}
