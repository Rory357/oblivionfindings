/**
 * Camera privacy hours in words (S&D rebuild Phase E). Ranges are weekly, in NZ wall-clock time:
 * days 1–7 (Monday first) and "HH:MM" times. A range whose end is not after its start runs to the
 * next morning; equal times cover 24 hours. One way of writing them on every screen (W4).
 */
import { formatDateTime, formatTime } from '@/lib/datetime';

export type PrivacyRangeData = { days: number[]; from: string; to: string };

export const PRIVACY_DAYS: { value: number; label: string }[] = [
    { value: 1, label: 'Mon' },
    { value: 2, label: 'Tue' },
    { value: 3, label: 'Wed' },
    { value: 4, label: 'Thu' },
    { value: 5, label: 'Fri' },
    { value: 6, label: 'Sat' },
    { value: 7, label: 'Sun' },
];

const dayLabel = (day: number) => PRIVACY_DAYS.find((option) => option.value === day)?.label ?? '';

/** "22:00" → "10:00 pm" (a wall-clock time, so no time zone is applied). */
export function clockWords(hhmm: string): string {
    const match = /^(\d{2}):(\d{2})$/.exec(hhmm);
    if (!match) return '—';
    const hour = Number(match[1]);
    return `${hour % 12 || 12}:${match[2]} ${hour >= 12 ? 'pm' : 'am'}`;
}

/** [1,2,3,4,5,6,7] → "Every day"; [1..5] → "Mon – Fri"; [1,3,5] → "Mon, Wed and Fri". */
export function daysWords(days: number[]): string {
    const sorted = [...new Set(days)].filter((day) => day >= 1 && day <= 7).sort((a, b) => a - b);
    if (sorted.length === 7) return 'Every day';
    if (sorted.length === 0) return 'No days';
    const runs: number[][] = [];
    for (const day of sorted) {
        const last = runs[runs.length - 1];
        if (last && day === last[last.length - 1] + 1) last.push(day);
        else runs.push([day]);
    }
    const parts = runs.flatMap((run) =>
        run.length >= 3 ? [`${dayLabel(run[0])} – ${dayLabel(run[run.length - 1])}`] : run.map(dayLabel),
    );
    return parts.length === 1 ? parts[0] : `${parts.slice(0, -1).join(', ')} and ${parts[parts.length - 1]}`;
}

/** "Every day, 10:00 pm – 6:00 am"; equal times: "Sat, all day" or "Sat, 24 hours from 6:00 pm". */
export function rangeWords(range: PrivacyRangeData): string {
    const days = daysWords(range.days);
    if (range.from === range.to) {
        return range.from === '00:00' ? `${days}, all day` : `${days}, 24 hours from ${clockWords(range.from)}`;
    }
    return `${days}, ${clockWords(range.from)} – ${clockWords(range.to)}`;
}

/** Whether a range ends the next morning (its end is not after its start). */
export function endsNextDay(range: Pick<PrivacyRangeData, 'from' | 'to'>): boolean {
    return /^\d{2}:\d{2}$/.test(range.from) && /^\d{2}:\d{2}$/.test(range.to) && range.to <= range.from;
}

/**
 * When privacy hours end or start, as the screens say it: just the time within the next 24 hours
 * ("6:00 am"), otherwise the day too ("Tue 27 Oct, 6:00 am").
 */
export function privacyTime(iso: string, now: Date = new Date()): string {
    const at = new Date(iso);
    if (Number.isNaN(at.getTime())) return '—';
    const ahead = at.getTime() - now.getTime();
    return ahead >= 0 && ahead < 24 * 60 * 60 * 1000 ? formatTime(at) : formatDateTime(at);
}

/** "Privacy hours until 6:00 am". */
export function privacyUntilWords(iso: string | null | undefined, now: Date = new Date()): string {
    return iso ? `Privacy hours until ${privacyTime(iso, now)}` : 'Privacy hours';
}

/** Every range in one line, joined with "; ". */
export function rangesWords(ranges: PrivacyRangeData[]): string {
    return ranges.map(rangeWords).join('; ');
}
