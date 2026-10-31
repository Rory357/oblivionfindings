import { useEffect, useState } from 'react';

const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

/** "4m", "5h", "76d": the number inside a countdown lens. */
export function shortDuration(ms: number): string {
    const abs = Math.max(0, ms);
    if (abs < HOUR) return `${Math.max(1, Math.round(abs / MINUTE))}m`;
    if (abs < 2 * DAY) return `${Math.floor(abs / HOUR)}h`;
    return `${Math.floor(abs / DAY)}d`;
}

/** "4 min", "5 hours", "76 days": the same span in words. */
export function longDuration(ms: number): string {
    const abs = Math.max(0, ms);
    if (abs < HOUR) {
        const m = Math.max(1, Math.round(abs / MINUTE));
        return `${m} min`;
    }
    if (abs < 2 * DAY) {
        const h = Math.floor(abs / HOUR);
        return `${h} ${h === 1 ? 'hour' : 'hours'}`;
    }
    const d = Math.floor(abs / DAY);
    return `${d} days`;
}

export type ResponseClockTone = 'brand' | 'warning' | 'critical' | 'neutral';

export type ResponseClock = {
    /** countdown — a target is coming; late — it passed; waiting — no target, time since raised. */
    mode: 'countdown' | 'late' | 'waiting' | 'none';
    tone: ResponseClockTone;
    /** Share of the target time still left, 0–1 (drawn as the lens ring). */
    left: number;
    /** Inside the lens: "4m", "Late", "76d". */
    short: string;
    /** In words: "due in 4 min", "missed by 12 min", "waiting 76 days". */
    words: string;
};

/**
 * How an alert stands against time — the one reading every Live response
 * surface shares (row lens, "Needs you now", preview). A target that is
 * coming counts down; a missed one says by how much; an alert with no
 * target shows how long it has waited.
 */
export function responseClock(
    {
        raisedAt,
        deadlineAt,
        targetStatus,
    }: {
        raisedAt: string | null;
        deadlineAt: string | null;
        targetStatus?: string | null;
    },
    now: number,
): ResponseClock {
    const raised = raisedAt ? new Date(raisedAt).getTime() : NaN;
    const deadline = deadlineAt ? new Date(deadlineAt).getTime() : NaN;

    if (Number.isFinite(deadline)) {
        if (deadline <= now || targetStatus === 'breached') {
            const over = Math.max(0, now - deadline);
            return {
                mode: 'late',
                tone: 'critical',
                left: 0,
                short: 'Late',
                words: over > 0 ? `missed by ${longDuration(over)}` : 'target missed',
            };
        }
        const remaining = deadline - now;
        const total = Number.isFinite(raised) ? deadline - raised : remaining;
        const left = total > 0 ? Math.min(1, remaining / total) : 1;
        return {
            mode: 'countdown',
            tone: left < 0.25 || targetStatus === 'at_risk' ? 'warning' : 'brand',
            left,
            short: shortDuration(remaining),
            words: `due in ${longDuration(remaining)}`,
        };
    }

    if (Number.isFinite(raised)) {
        const waited = Math.max(0, now - raised);
        return {
            mode: 'waiting',
            tone: 'neutral',
            left: 1,
            short: shortDuration(waited),
            words: `waiting ${longDuration(waited)}`,
        };
    }

    return { mode: 'none', tone: 'neutral', left: 1, short: '–', words: 'no time recorded' };
}

/** "raised 3 min ago" / "raised 76 days ago". */
export function raisedWords(raisedAt: string | null, now: number): string | null {
    if (!raisedAt) return null;
    const ms = now - new Date(raisedAt).getTime();
    if (!Number.isFinite(ms)) return null;
    if (ms < MINUTE) return 'raised just now';
    return `raised ${longDuration(ms)} ago`;
}

/** A clock that ticks, so countdowns move without a reload. */
export function useNow(intervalMs = 15_000): number {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const id = window.setInterval(() => setNow(Date.now()), intervalMs);
        return () => window.clearInterval(id);
    }, [intervalMs]);
    return now;
}
