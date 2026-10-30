import type { ZoneSchedule } from '@/components/client-location/types';
export type DailyWindow = {
    start: string;
    end: string;
    following_day: boolean;
};
export type RulePolicy = {
    timing: 'unconfigured' | 'scheduled' | 'always';
    windows: DailyWindow[];
    accuracyM: string;
    confirmationSeconds: string;
    bufferM: string;
    dwellSeconds: string;
    repeatMinutes: string;
    priority: string;
    acknowledgeMinutes: string;
    escalateMinutes: string;
    escalationTeam: string;
};
export const emptyPolicy: RulePolicy = {
    timing: 'unconfigured',
    windows: [{ start: '08:00', end: '18:00', following_day: false }],
    accuracyM: '',
    confirmationSeconds: '',
    bufferM: '',
    dwellSeconds: '',
    repeatMinutes: '',
    priority: '',
    acknowledgeMinutes: '',
    escalateMinutes: '',
    escalationTeam: '',
};
export function normalisePolicy(
    value: Partial<RulePolicy> | null | undefined,
): RulePolicy {
    const policy = structuredClone(emptyPolicy);
    for (const key of Object.keys(policy) as (keyof RulePolicy)[]) {
        if (key === 'windows')
            policy.windows = structuredClone(
                value?.windows?.length ? value.windows : emptyPolicy.windows,
            );
        else if (key === 'timing')
            policy.timing = value?.timing ?? 'unconfigured';
        else policy[key] = String(value?.[key] ?? '');
    }
    return policy;
}
export const directionLabel = (direction: string) =>
    ({
        entry: 'Enters the area',
        exit: 'Leaves the area',
        both: 'Enters or leaves',
        dwell: 'Stays inside',
    })[direction] ?? 'Not selected';
export function policyError(
    p: RulePolicy,
    section: 'all' | 'detection' | 'response' = 'all',
) {
    for (const [key, label, zero] of [
        ['accuracyM', 'Maximum fix accuracy', false],
        ['confirmationSeconds', 'Confirmation time', true],
        ['bufferM', 'Edge buffer', true],
        ['dwellSeconds', 'Dwell time', false],
        ['repeatMinutes', 'Repeat interval', true],
        ['acknowledgeMinutes', 'Acknowledgement target', false],
        ['escalateMinutes', 'Escalation delay', false],
    ] as const) {
        const response = ['acknowledgeMinutes', 'escalateMinutes'].includes(
            key,
        );
        if (
            (section === 'detection' && response) ||
            (section === 'response' && !response)
        )
            continue;
        if (
            p[key] !== '' &&
            (!/^\d+(\.\d+)?$/.test(p[key]) ||
                !Number.isFinite(Number(p[key])) ||
                Number(p[key]) > 1e6 ||
                (zero ? Number(p[key]) < 0 : Number(p[key]) <= 0))
        )
            return `${label} must be ${zero ? 'zero or a positive' : 'a positive'} finite number no greater than 1,000,000.`;
    }
    if (
        section !== 'detection' &&
        p.escalateMinutes &&
        p.acknowledgeMinutes &&
        Number(p.escalateMinutes) < Number(p.acknowledgeMinutes)
    )
        return 'Escalation cannot precede the acknowledgement target.';
    if (section !== 'detection' && p.escalateMinutes && !p.escalationTeam)
        return 'Choose an authorised escalation team for the proposed delay.';
    return '';
}
export function overlappingWindows(
    schedule: ZoneSchedule,
    windows: DailyWindow[],
) {
    const minutes = (v: string) =>
        Number(v.slice(0, 2)) * 60 + Number(v.slice(3));
    const intervals = schedule.weekdays.flatMap((day) =>
        windows.map((w, i) => ({
            start: (day - 1) * 1440 + minutes(w.start),
            end:
                (day - 1) * 1440 +
                minutes(w.end) +
                (w.following_day ? 1440 : 0),
            window: i,
        })),
    );
    return intervals.some((a, i) =>
        intervals.some(
            (b, j) =>
                i !== j &&
                [-10080, 0, 10080].some(
                    (offset) =>
                        a.start < b.end + offset && b.start + offset < a.end,
                ),
        ),
    );
}
export function readiness(
    p: RulePolicy,
    owner: string,
    source: boolean,
    direction: string,
) {
    return [
        { label: 'Timing proposed', ready: p.timing !== 'unconfigured' },
        { label: 'Location source available', ready: source },
        {
            label: 'Accuracy, confirmation and edge buffer proposed',
            ready:
                p.accuracyM !== '' &&
                p.confirmationSeconds !== '' &&
                p.bufferM !== '',
        },
        {
            label: 'Dwell and repeat behaviour proposed',
            ready:
                p.repeatMinutes !== '' &&
                (direction !== 'dwell' || p.dwellSeconds !== ''),
        },
        {
            label: 'Responsible team, priority and response target proposed',
            ready: !!owner && !!p.priority && !!p.acknowledgeMinutes,
        },
    ];
}
export function sampleOutcome(
    direction: string,
    p: RulePolicy,
    sample: string,
    seconds: number,
) {
    if (sample === 'first')
        return 'Starting position only. No entry or exit can be inferred from the first observation.';
    if (sample === 'stale')
        return 'Held: the observation is out of date. Current location is unknown; use the separate device-health workflow.';
    if (sample === 'uncertain')
        return 'Held: accuracy reaches the boundary edge. Do not confirm a crossing from this observation.';
    if (sample === 'outside-hours' && p.timing === 'scheduled')
        return 'Outside the proposed time window. No boundary notification from this rule; other rules and device events remain independent.';
    if (
        p.timing === 'unconfigured' ||
        !p.accuracyM ||
        p.confirmationSeconds === '' ||
        p.bufferM === ''
    )
        return 'Incomplete proposal: set timing and detection values before previewing this case.';
    if (sample === 'outside-hours')
        return 'The proposal applies at all times. A confirmed movement observation is still required.';
    if (direction !== sample && direction !== 'both')
        return `No match: this rule assesses ${directionLabel(direction).toLowerCase()}.`;
    if (direction === 'both' && !['entry', 'exit'].includes(sample))
        return 'No match: this rule assesses entry and exit, not dwell.';
    const wait =
        sample === 'dwell'
            ? Number(p.dwellSeconds)
            : Number(p.confirmationSeconds);
    if (sample === 'dwell' && !p.dwellSeconds)
        return 'Incomplete proposal: set a dwell duration.';
    if (seconds < wait)
        return `Pending confirmation: ${wait - seconds} more seconds would be required by this proposal.`;
    return 'Candidate for rule assessment under the proposed timing. Production must still validate source versions, authority, replay/order and delivery. No event has been created.';
}
