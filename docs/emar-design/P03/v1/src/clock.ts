/* Fixed synthetic clock (checklist §1.3): Monday 28 September 2026, 9:12 am
 * Pacific/Auckland (NZDT, UTC+13 — daylight saving started Sunday 27 Sep).
 * The same moment as the approved P00 v5, P01 v2, P02 v1 and P08a v1, so the
 * people, houses, medicines and follow-ups read the same. Real components that read "now"
 * (DateTimeField future-time checks) see this instant. */
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

/** Local "YYYY-MM-DDTHH:mm" → minutes since today's midnight (negative = earlier day). */
export function localToMinutes(value: string): number | null {
    const m = /^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})$/.exec(value);
    if (!m) return null;
    const dayOffset = Math.round((Date.UTC(+m[1].slice(0, 4), +m[1].slice(5, 7) - 1, +m[1].slice(8, 10)) - Date.UTC(2026, 8, 28)) / 86400000);
    return dayOffset * 1440 + Number(m[2]) * 60 + Number(m[3]);
}
export const NOW_MIN = 9 * 60 + 12;

export function labelOf(min: number): string {
    const day = Math.floor(min / 1440);
    const within = ((min % 1440) + 1440) % 1440;
    const h24 = Math.floor(within / 60);
    const m = within % 60;
    const ap = h24 < 12 ? 'am' : 'pm';
    const h = h24 % 12 || 12;
    return `${h}:${String(m).padStart(2, '0')} ${ap}${day < 0 ? ' Sunday' : day > 0 ? ' Tuesday' : ''}`;
}
