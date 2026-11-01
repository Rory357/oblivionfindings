import { SideColumn, type LogItem } from '@/components/signature/blocks';
import { formatDate, formatDateOnly, formatTime, WORKER_TIMEZONE } from '@/lib/datetime';
import type { ReactNode } from 'react';
import type { LogEntry } from './_device-page-types';

const nzDay = (date: Date) => date.toLocaleDateString('en-CA', { timeZone: WORKER_TIMEZONE });

/** A log's time column, as drawn: "5:58 am" today, "Fri 23 Oct" this year, "23 Oct 2025" before. */
export function logTime(iso: string, now: Date): string {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '—';
    const day = nzDay(date);
    if (day === nzDay(now)) return formatTime(date);
    return day.slice(0, 4) === nzDay(now).slice(0, 4) ? formatDate(date) : formatDateOnly(day);
}

/** The record template's main + side layout: content, and a 360 px side column. */
export function MainSide({ main, side }: { main: ReactNode; side: ReactNode }) {
    return (
        <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
            <div className="grid min-w-0 content-start gap-5">{main}</div>
            <SideColumn>{side}</SideColumn>
        </div>
    );
}

/** Server log lines as Log rows: the time first, in NZ time. */
export function logItems(entries: LogEntry[], now: Date, act?: (entry: LogEntry) => LogItem['action']): LogItem[] {
    return entries.map((entry) => ({
        key: entry.key,
        time: entry.at ? logTime(entry.at, now) : '—',
        tone: entry.tone,
        title: entry.title,
        sub: entry.sub ?? undefined,
        action: act ? act(entry) : undefined,
    }));
}

export function plural(count: number, one: string, many: string): string {
    return `${count.toLocaleString('en-NZ')} ${count === 1 ? one : many}`;
}
