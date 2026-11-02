/** Shared bits of the Access page: server lines in NZ time, and a status with its note. */
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDate, formatDateOnly } from '@/lib/datetime';
import { timedText } from '@/lib/security-devices/device-time';
import { Fragment, type ReactNode } from 'react';
import type { Badge, TimedLine } from './_types';

/** "{time}" / "{ago}" become NZ times, "{date}" a date ("Fri 13 Nov", with the year when it isn't this year). */
export function lineText(line: TimedLine | null | undefined, now: Date): string {
    if (!line) return '';
    if (line.text.includes('{date}')) {
        const at = line.at;
        let date = '—';
        if (at && /^\d{4}-\d{2}-\d{2}$/.test(at)) {
            // A calendar day (an HR end date): midnight UTC is the afternoon of the same day in New Zealand.
            date = at.slice(0, 4) === String(now.getFullYear()) ? formatDate(`${at}T00:00:00Z`) : formatDateOnly(at);
        } else if (at) {
            date = formatDate(at);
        }
        return line.text.replace('{date}', date);
    }
    return timedText(line, now);
}

/** "**bold**" in a server line becomes the figure. */
export function Bold({ text }: { text: string }): ReactNode {
    const parts = text.split(/\*\*(.+?)\*\*/g);
    return (
        <>
            {parts.map((part, index) => (index % 2 === 1 ? <b key={index} className="font-semibold text-foreground">{part}</b> : <Fragment key={index}>{part}</Fragment>))}
        </>
    );
}

/** A status (always a StatusBadge) with its note under it. */
export function StatusCell({ badge, note, now }: { badge: Badge; note?: TimedLine | null; now: Date }) {
    return (
        <span className="flex min-w-0 flex-col items-start gap-1 py-1">
            <StatusBadge variant={badge.tone} size="sm">
                {lineText({ text: badge.text, at: badge.at ?? null }, now)}
            </StatusBadge>
            {note ? <span className="max-w-full text-xs text-muted-foreground">{lineText(note, now)}</span> : null}
        </span>
    );
}
