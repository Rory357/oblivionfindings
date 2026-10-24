import { toDateInput } from '@/lib/datetime';

/** Calendar week in the organisation's zone, independent of browser timezone.
 * This is a date key for navigation, never a replacement shift timestamp. */
export function handoverCommandWeek(startsAt: string | null | undefined, timeZone: string): string | null {
    if (!startsAt) return null;
    let day: string;
    try { day = toDateInput(startsAt, timeZone); } catch { return null; }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(day)) return null;
    const date = new Date(`${day}T12:00:00Z`);
    if (!Number.isFinite(date.getTime())) return null;
    date.setUTCDate(date.getUTCDate() - (date.getUTCDay() + 6) % 7);
    return date.toISOString().slice(0, 10);
}
