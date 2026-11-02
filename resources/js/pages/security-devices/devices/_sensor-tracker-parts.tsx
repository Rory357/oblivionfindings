/**
 * Parts the sensor page (pg-sdr-7) and the care tracker page (pg-sdr-8) share: the clock that keeps
 * "3 min ago" fresh, server lines with "{date}", "{time}" or "{ago}" filled in NZ time, fact rows
 * and alert rows that open in Alerts.
 */
import { FactRows, Log, QuietLine } from '@/components/signature/blocks';
import type { PopupDevice } from '@/components/security-devices/device-popups';
import { formatDateOnly, WORKER_TIMEZONE } from '@/lib/datetime';
import { timedText } from '@/lib/security-devices/device-time';
import { useEffect, useState } from 'react';
import type { TimedText } from './_types';
import { logTime } from './_device-page-parts';
import type { AlertLine, FactLine, PageDevice } from './_sensor-tracker-types';

export function useNow(): Date {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 60_000);
        return () => window.clearInterval(timer);
    }, []);
    return now;
}

/** "31 Mar 2027": the NZ calendar day of an instant. */
export function nzDate(iso: string): string {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '—';
    return formatDateOnly(date.toLocaleDateString('en-CA', { timeZone: WORKER_TIMEZONE }));
}

/** A server line with its "{date}", "{time}" or "{ago}" filled in. */
export function fill(line: TimedText | null | undefined, now: Date): string {
    if (!line) return '';
    if (line.at && line.text.includes('{date}')) return line.text.replace('{date}', nzDate(line.at));
    return timedText(line, now);
}

export function popupDevice(device: PageDevice): PopupDevice {
    return {
        id: device.id,
        name: device.name,
        sub: device.model,
        kind: device.kind,
        state: device.state,
        address: device.address,
        site: device.site,
        site_id: device.site_id,
        watching: device.watching,
    };
}

export function FactLines({ title, sub, rows, now, action }: { title: string; sub?: string; rows: FactLine[]; now: Date; action?: { label: string; href: string } | null }) {
    return <FactRows title={title} sub={sub} action={action ?? undefined} rows={rows.map((row) => [row.label, fill(row, now)])} />;
}

/** Alerts as log rows, newest first; each opens in Alerts. */
export function AlertLog({ title, sub, entries, now, empty }: { title: string; sub?: string; entries: AlertLine[]; now: Date; empty: string }) {
    if (entries.length === 0) return <QuietLine title={title} sub={sub} text={empty} />;
    return (
        <Log
            title={title}
            sub={sub}
            limit={entries.length}
            items={entries.map((entry) => ({
                key: entry.key,
                time: entry.at ? logTime(entry.at, now) : '—',
                tone: entry.tone,
                title: entry.title,
                sub: entry.sub ?? undefined,
                action: entry.href ? { label: 'Open', href: entry.href, ariaLabel: `Open alert: ${entry.title}` } : undefined,
            }))}
        />
    );
}
