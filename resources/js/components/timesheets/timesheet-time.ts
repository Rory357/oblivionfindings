import { WORKER_TIMEZONE } from '@/lib/datetime';
import { shiftInputInstant } from '@/lib/workforce-time-input';

/** Worker calendar dates stay separate; persistence receives unambiguous UTC instants. */
export function timesheetTimeRange(
    start: string,
    end: string,
    originals?: { starts_at: string; ends_at: string },
    zone = WORKER_TIMEZONE,
) {
    const starts_at = shiftInputInstant(start, zone, originals?.starts_at);
    const ends_at = shiftInputInstant(end, zone, originals?.ends_at);
    const minutes = (Date.parse(ends_at) - Date.parse(starts_at)) / 60000;
    if (minutes <= 0)
        throw new Error(
            'End must be after start. For overnight work, choose the following end date.',
        );
    return { starts_at, ends_at, minutes };
}
