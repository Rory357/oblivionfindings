/* Fixed synthetic clock (checklist §1.3): Monday 28 September 2026, 9:12 am
 * Pacific/Auckland (NZDT, UTC+13 — daylight saving started Sunday 27 Sep).
 * The same moment as the approved P00 contract, so its wording carries over.
 * Real components that read "now" (DateTimeField future-time checks, the
 * shared calendar's Today rail, datetime helpers) all see this instant. */
export const NOW_UTC = Date.UTC(2026, 8, 27, 20, 12, 0);
export const TODAY = '2026-09-28';
export const NOW_LOCAL = '2026-09-28T09:12';
export const NOW_LABEL = '9:12 am';
export const DAY_LABEL = 'Monday 28 September 2026';

const RealDate = Date;
class FixedDate extends RealDate {
    constructor(...args: unknown[]) {
        if (args.length === 0) super(NOW_UTC);
        // @ts-expect-error — forward any Date constructor form unchanged
        else super(...args);
    }
    static now() {
        return NOW_UTC;
    }
}
(globalThis as unknown as { Date: DateConstructor }).Date =
    FixedDate as unknown as DateConstructor;

/** Minutes since local midnight for an "h:mm am" label. */
export function minutesOf(label: string): number {
    const m = /^(\d{1,2}):(\d{2})\s*(am|pm)$/i.exec(label.trim());
    if (!m) return 0;
    let h = Number(m[1]) % 12;
    if (m[3].toLowerCase() === 'pm') h += 12;
    return h * 60 + Number(m[2]);
}
export const NOW_MIN = minutesOf(NOW_LABEL);

export function labelOf(min: number): string {
    const h24 = Math.floor(min / 60) % 24;
    const m = min % 60;
    const ap = h24 < 12 ? 'am' : 'pm';
    const h = h24 % 12 || 12;
    return `${h}:${String(m).padStart(2, '0')} ${ap}`;
}

/** "HH:mm" (canonical) from minutes. */
export function hhmm(min: number): string {
    return `${String(Math.floor(min / 60)).padStart(2, '0')}:${String(min % 60).padStart(2, '0')}`;
}

/** Local "YYYY-MM-DDTHH:mm" → minutes today (null when another day). */
export function localToMinutesToday(value: string): number | null {
    const m = /^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})$/.exec(value);
    if (!m) return null;
    if (m[1] !== TODAY) return m[1] < TODAY ? -1 : 24 * 60 + 1;
    return Number(m[2]) * 60 + Number(m[3]);
}
