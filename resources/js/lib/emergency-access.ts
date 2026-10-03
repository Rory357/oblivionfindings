import type { EmergencyPolicy } from '@/pages/emergency/_types';

export function emergencyDurationOptions(policy: EmergencyPolicy): number[] {
    return [
        ...new Set([
            30,
            60,
            120,
            240,
            policy.default_minutes,
            policy.max_minutes,
        ]),
    ]
        .filter((minutes) => minutes >= 5 && minutes <= policy.max_minutes)
        .sort((a, b) => a - b);
}
export function emergencyTimeLeft(
    expiresAt: string,
    now: number,
): { ended: boolean; warning: boolean; label: string } {
    const remaining = Math.max(
        0,
        Math.ceil((Date.parse(expiresAt) - now) / 1000),
    );
    const ended = !Number.isFinite(remaining) || remaining === 0;
    return {
        ended,
        warning: !ended && remaining <= 600,
        label: ended
            ? 'Ended'
            : `${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, '0')}`,
    };
}
