/**
 * Logbook lines as the drawings write them (S&D v13, pg-sdr-3e and the Retired view's last lines in
 * 2f): the time first in NZ time, a quiet time's span in its title, and each line's own action
 * (Compare a settings change, See commands of a terminal session, open an alert).
 */
import type { LogItem } from '@/components/signature/blocks';
import { formatTime, WORKER_TIMEZONE } from '@/lib/datetime';
import { seenTime } from '@/lib/security-devices/device-time';
import type { LogbookLine } from './_types';

const nzDay = (date: Date) => date.toLocaleDateString('en-CA', { timeZone: WORKER_TIMEZONE });
const nzMonth = (date: Date) => nzDay(date).slice(0, 7);

/** A line's time: "9:31 am" today, "Wed 28 Oct, 3:12 pm" before. */
export function lineTime(iso: string, now: Date): string {
    return seenTime(iso, now);
}

/** "10:00 am – 11:00 am", or with the day when it isn't today. */
export function spanWords(span: { from: string | null; to: string | null }, now: Date): string | null {
    if (!span.from || !span.to) return null;
    const from = new Date(span.from);
    const to = new Date(span.to);
    if (Number.isNaN(from.getTime()) || Number.isNaN(to.getTime())) return null;
    const end = nzDay(from) === nzDay(to) ? formatTime(to) : seenTime(span.to, now);
    return `${seenTime(span.from, now)} – ${end}`;
}

export function lineTitle(line: LogbookLine, now: Date): string {
    const span = line.span ? spanWords(line.span, now) : null;
    return span ? `${line.title} · ${span}` : line.title;
}

export type LineActions = {
    onCompare?: ((snapshotId: number) => void) | null;
    onTerminal?: (() => void) | null;
};

/** Logbook lines as Log rows. Only the actions the page can really do are offered. */
export function logbookItems(lines: LogbookLine[], now: Date, actions: LineActions = {}): LogItem[] {
    return lines.map((line) => {
        let action: LogItem['action'] = null;
        if (line.compare_id !== null && actions.onCompare) {
            const id = line.compare_id;
            const compare = actions.onCompare;
            action = { label: 'Compare', onClick: () => compare(id), ariaLabel: `Compare: ${line.title}` };
        } else if (line.tab === 'terminal' && actions.onTerminal) {
            action = { label: 'See commands', onClick: actions.onTerminal };
        } else if (line.href) {
            action = { label: 'Open alert', href: line.href, ariaLabel: `Open alert: ${line.title}` };
        }
        return {
            key: line.key,
            time: lineTime(line.at, now),
            tone: line.tone,
            title: lineTitle(line, now),
            sub: line.sub ?? undefined,
            action,
        };
    });
}

export type LineGroup = { key: string; title: string; lines: LogbookLine[] };

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/** Today, Earlier this week (the six days before), then each month, newest first. */
export function groupLines(lines: LogbookLine[], now: Date): LineGroup[] {
    const today = nzDay(now);
    const weekStart = nzDay(new Date(now.getTime() - 6 * 86_400_000));
    const thisYear = today.slice(0, 4);
    const groups: LineGroup[] = [];
    for (const line of lines) {
        const at = new Date(line.at);
        if (Number.isNaN(at.getTime())) continue;
        const day = nzDay(at);
        let key: string;
        let title: string;
        if (day === today) {
            key = 'today';
            title = 'Today';
        } else if (day >= weekStart && day < today) {
            key = 'week';
            title = 'Earlier this week';
        } else {
            key = nzMonth(at);
            const month = MONTHS[Number(key.slice(5, 7)) - 1] ?? key;
            title = key.slice(0, 4) === thisYear ? month : `${month} ${key.slice(0, 4)}`;
        }
        const last = groups[groups.length - 1];
        if (last && last.key === key) last.lines.push(line);
        else groups.push({ key, title, lines: [line] });
    }
    return groups;
}

const KIND_WORDS: Record<LogbookLine['kind'], string> = {
    alerts: 'alerts and checks',
    settings: 'settings backups',
    quiet: 'quiet times',
    logins: 'the saved login',
    moves: 'moves and renames',
    terminal: 'the terminal',
};

function sentence(parts: string[]): string {
    if (parts.length <= 1) return parts[0] ?? '';
    return `${parts.slice(0, -1).join(', ')} and ${parts[parts.length - 1]}`;
}

/** "Who did what this month": each person (and Oblivion) with the kinds of thing they did. */
export function whoDidWhat(lines: LogbookLine[], now: Date): [string, string][] {
    const month = nzMonth(now);
    const byWho = new Map<string, LogbookLine['kind'][]>();
    for (const line of lines) {
        if (!line.who) continue;
        const at = new Date(line.at);
        if (Number.isNaN(at.getTime()) || nzMonth(at) !== month) continue;
        const kinds = byWho.get(line.who) ?? [];
        if (!kinds.includes(line.kind)) kinds.push(line.kind);
        byWho.set(line.who, kinds);
    }
    const rows = [...byWho.entries()].map(([who, kinds]): [string, string] => {
        const words = sentence(kinds.map((kind) => KIND_WORDS[kind]));
        return [who, words.charAt(0).toUpperCase() + words.slice(1)];
    });
    // People first, Oblivion's own work last.
    return [...rows.filter(([who]) => who !== 'Oblivion'), ...rows.filter(([who]) => who === 'Oblivion')];
}
