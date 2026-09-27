import { toDatetimeLocal } from '@/lib/datetime';

/** Shared calendar dates represent the displayed wall clock, not a source instant. */
export function calendarLocal(date: Date): string {
    const pad = (value: number) => String(value).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function calendarDate(instant: string | Date): Date {
    return new Date(toDatetimeLocal(instant));
}
