import { describe, expect, it } from 'vitest';
import { longDuration, raisedWords, responseClock, shortDuration } from './response-clock';

const NOW = Date.parse('2026-10-24T09:00:00Z');
const at = (minutes: number) => new Date(NOW + minutes * 60_000).toISOString();

describe('responseClock', () => {
    it('counts down to a target that is coming', () => {
        const clock = responseClock({ raisedAt: at(-10), deadlineAt: at(20) }, NOW);
        expect(clock).toMatchObject({ mode: 'countdown', tone: 'brand', short: '20m', words: 'due in 20 min' });
        expect(clock.left).toBeCloseTo(20 / 30);
    });

    it('turns amber in the last quarter, or when the server says at risk', () => {
        expect(responseClock({ raisedAt: at(-27), deadlineAt: at(3) }, NOW).tone).toBe('warning');
        expect(
            responseClock({ raisedAt: at(-1), deadlineAt: at(29), targetStatus: 'at_risk' }, NOW).tone,
        ).toBe('warning');
    });

    it('says by how much a target was missed', () => {
        const clock = responseClock({ raisedAt: at(-30), deadlineAt: at(-12) }, NOW);
        expect(clock).toMatchObject({ mode: 'late', tone: 'critical', short: 'Late', words: 'missed by 12 min' });
    });

    it('shows how long an alert has waited when it has no target', () => {
        const clock = responseClock({ raisedAt: at(-60 * 24 * 76), deadlineAt: null }, NOW);
        expect(clock).toMatchObject({ mode: 'waiting', tone: 'neutral', short: '76d', words: 'waiting 76 days' });
    });
});

describe('durations', () => {
    it('reads short and long', () => {
        expect(shortDuration(4 * 60_000)).toBe('4m');
        expect(shortDuration(5 * 3_600_000)).toBe('5h');
        expect(longDuration(60 * 60_000)).toBe('1 hour');
        expect(longDuration(3 * 24 * 3_600_000)).toBe('3 days');
        expect(raisedWords(at(-3), NOW)).toBe('raised 3 min ago');
        expect(raisedWords(at(0), NOW)).toBe('raised just now');
    });
});
