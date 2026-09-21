import type { ClientLocationData } from '@/components/client-location-tab';
import { formatDateTime } from '@/lib/datetime';
import { CircleHelp, ClipboardCheck, Siren } from 'lucide-react';

export default function TrackerPanic({
    panic_active,
    panic_acknowledged_at,
    last_safety_event_at,
}: Pick<
    NonNullable<ClientLocationData['tracker']>,
    'panic_active' | 'panic_acknowledged_at' | 'last_safety_event_at'
>) {
    const active = panic_active === true;
    const eventTime =
        last_safety_event_at &&
        Number.isFinite(Date.parse(last_safety_event_at))
            ? last_safety_event_at
            : null;
    const ackTime =
        panic_acknowledged_at &&
        Number.isFinite(Date.parse(panic_acknowledged_at))
            ? panic_acknowledged_at
            : null;
    const acknowledged =
        panic_active === false &&
        ackTime &&
        (!eventTime || Date.parse(ackTime) >= Date.parse(eventTime));
    return (
        <section
            className="tracker-status-card tracker-panic"
            aria-label="Tracker panic status"
            data-active={active}
        >
            <div className="tracker-battery-heading">
                <span>PANIC / SOS</span>
                <span>
                    {active
                        ? 'Needs response'
                        : acknowledged
                          ? 'Acknowledged'
                          : 'Unconfirmed'}
                </span>
            </div>
            <div className="tracker-status-reading">
                <span className="tracker-status-icon" aria-hidden="true">
                    {active ? (
                        <Siren />
                    ) : acknowledged ? (
                        <ClipboardCheck />
                    ) : (
                        <CircleHelp />
                    )}
                </span>
                <div>
                    <strong>
                        {active
                            ? 'Panic alert active'
                            : acknowledged
                              ? 'Alert acknowledged'
                              : 'Status unconfirmed'}
                    </strong>
                    <p>
                        {active
                            ? 'Follow the response plan'
                            : acknowledged
                              ? 'Acknowledgement recorded'
                              : 'No confirmed alert status'}
                    </p>
                </div>
            </div>
            <p className="tracker-status-note">
                {acknowledged
                    ? `Acknowledged ${formatDateTime(ackTime)}`
                    : eventTime
                      ? `Last safety event ${formatDateTime(eventTime)}`
                      : 'Alert time not recorded'}
            </p>
            <p className="tracker-status-note">
                {active
                    ? 'Review and acknowledge the alert above.'
                    : 'This status does not confirm wellbeing.'}
            </p>
        </section>
    );
}
