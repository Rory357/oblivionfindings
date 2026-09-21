import type { ClientLocationData } from '@/components/client-location-tab';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { formatDateTime } from '@/lib/datetime';
import { Footprints, PersonStanding, Radio } from 'lucide-react';

type Tracker = NonNullable<ClientLocationData['tracker']>;
const validTime = (value?: string | null) =>
    value && Number.isFinite(Date.parse(value)) ? value : null;

export default function TrackerIndicators({
    status,
    motion_status,
    motion_reported_at,
    fall_report_type,
    fall_reported_at,
}: Pick<
    Tracker,
    | 'status'
    | 'motion_status'
    | 'motion_reported_at'
    | 'fall_report_type'
    | 'fall_reported_at'
>) {
    const motionTime = validTime(motion_reported_at);
    const motion = motionTime ? motion_status : null;
    const motionLabel =
        motion === 'moving'
            ? 'In motion'
            : motion === 'stationary'
              ? 'Stationary'
              : 'Unknown';
    const fallTime = validTime(fall_reported_at);
    const fall = fallTime ? fall_report_type : null;
    const fallLabel =
        fall === 'fall_detected'
            ? 'Fall reported'
            : fall === 'man_down'
              ? 'Man-down report'
              : 'Unavailable';

    return (
        <div
            className="tracker-indicators"
            role="group"
            aria-label="Tracker status indicators"
        >
            <div
                className="tracker-indicator"
                aria-label={`Device status: ${status}`}
            >
                <Radio aria-hidden="true" />
                <span>
                    <small>Device</small>
                    <strong className="capitalize">{status}</strong>
                </span>
            </div>
            <Popover>
                <PopoverTrigger asChild>
                    <button
                        type="button"
                        className="tracker-indicator frontline-focus"
                        data-motion={motion ?? 'unknown'}
                        aria-label={`Motion: ${motionLabel}. Show report details`}
                    >
                        <Footprints aria-hidden="true" />
                        <span>
                            <small>Motion</small>
                            <strong>{motionLabel}</strong>
                        </span>
                    </button>
                </PopoverTrigger>
                <PopoverContent
                    align="end"
                    className="tracker-indicator-detail"
                >
                    <strong>Movement · last reported</strong>
                    <p>
                        {motion === 'moving'
                            ? 'The tracker reported movement.'
                            : motion === 'stationary'
                              ? 'The tracker reported that it was stationary.'
                              : 'No movement status is available for the current tracking period.'}
                    </p>
                    {motion && motionTime && (
                        <p className="tracker-status-note">
                            Reported {formatDateTime(motionTime)}
                        </p>
                    )}
                    <p className="tracker-status-note">
                        This is the last received report, not a continuous
                        movement check.
                    </p>
                </PopoverContent>
            </Popover>
            <Popover>
                <PopoverTrigger asChild>
                    <button
                        type="button"
                        className="tracker-indicator frontline-focus"
                        data-fall={fall ?? 'unknown'}
                        aria-label={`Fall detection: ${fallLabel}. Show report details`}
                    >
                        <PersonStanding
                            className="tracker-fall-icon"
                            aria-hidden="true"
                        />
                        <span>
                            <small>Fall detection</small>
                            <strong>{fallLabel}</strong>
                        </span>
                    </button>
                </PopoverTrigger>
                <PopoverContent
                    align="end"
                    className="tracker-indicator-detail"
                >
                    <strong>
                        {fall ? fallLabel : 'Fall detection status unavailable'}
                    </strong>
                    <p>
                        {fall === 'fall_detected'
                            ? 'A fall was reported by the device. Check the person and follow their response plan.'
                            : fall === 'man_down'
                              ? 'The tracker reported a man-down event. This is not confirmation of a fall. Follow the response plan.'
                              : 'A compatible tracker and its reported fall data are needed. Sensor support and whether detection is enabled are not confirmed here.'}
                    </p>
                    {fall && fallTime && (
                        <p className="tracker-status-note">
                            Reported {formatDateTime(fallTime)}
                        </p>
                    )}
                    <p className="tracker-status-note">
                        {fall
                            ? 'This records an event; it does not show whether the alert has been resolved.'
                            : 'Unavailable does not mean that no fall has occurred.'}
                    </p>
                </PopoverContent>
            </Popover>
        </div>
    );
}
