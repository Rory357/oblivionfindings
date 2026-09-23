import { readFiles } from './evidence-field';
import type { Action, Store } from './operations';
import { type Journey } from './trip-data';

export type EventReview = {
    outcome: string;
    reason: string;
    reviewer: string;
    history: string[];
};
export type DriverSegment = { point: number; driver: string; reason: string };
export type SpeedOverride = {
    id: string;
    segment: string;
    direction: string;
    limit: number;
    from: string;
    until: string;
    reason: string;
    status: 'Pending review' | 'Approved' | 'Retired';
    history: string[];
};
export type Coaching = {
    id: string;
    trip: string;
    owner: string;
    due: string;
    status: string;
    outcome: string;
    history: string[];
};
export type DrivingState = {
    assignments: Record<string, DriverSegment[]>;
    assignmentHistory: Record<string, string[]>;
    reviews: Record<string, EventReview>;
    coaching: Coaching[];
    overrides: SpeedOverride[];
    provider:
        | 'Fresh matched response'
        | 'Stale response'
        | 'Ambiguous road match'
        | 'Unavailable';
    policy: {
        version: number;
        braking: number;
        acceleration: number;
        speed: number;
        idle: number;
        coverage: number;
        trips: number;
        distance: number;
        history: string[];
    };
    minutes: number;
};
export const drivingSeed = (): DrivingState => ({
    assignments: {},
    assignmentHistory: {},
    reviews: {},
    coaching: [],
    overrides: [],
    provider: 'Unavailable',
    policy: {
        version: 2,
        braking: 5,
        acceleration: 3,
        speed: 8,
        idle: 0.5,
        coverage: 90,
        trips: 5,
        distance: 100,
        history: [],
    },
    minutes: 0,
});
export const eventKey = (trip: Journey, index: number) =>
    trip.id + '-event-' + index;
export function reviewedScore(trip: Journey, state: DrivingState) {
    if (trip.coverage < state.policy.coverage) return null;
    const driving = trip.events
        .map((e, i) => ({ ...e, review: state.reviews[eventKey(trip, i)] }))
        .filter((e) => ['driving', 'speed'].includes(e.kind));
    if (driving.some((e) => e.review?.outcome === 'Disputed')) return null;
    const kept = driving.filter((e) => e.review?.outcome !== 'Dismissed');
    return Math.max(
        0,
        Math.round(
            100 -
                kept.reduce(
                    (n, e) =>
                        n +
                        (e.kind === 'speed'
                            ? state.policy.speed
                            : e.title === 'Harsh braking'
                              ? state.policy.braking
                              : state.policy.acceleration),
                    0,
                ) -
                Math.round(trip.idle * state.policy.idle),
        ),
    );
}
export const confirmedDriver = (trip: Journey, state: DrivingState) => {
    const s = state.assignments[trip.id];
    return s?.[0]?.point === 0 && s.every((x) => x.driver === s[0].driver)
        ? s[0].driver
        : null;
};
export const driverAt = (trip: Journey, state: DrivingState, point: number) =>
    [...(state.assignments[trip.id] || [])]
        .sort((a, b) => b.point - a.point)
        .find((s) => s.point <= point)?.driver;
export function resolveSpeedLimit(
    state: DrivingState,
    at: string,
    segment: string,
    direction: string,
    fleet: number,
) {
    const matches = state.overrides.filter(
        (x) =>
            x.status === 'Approved' &&
            x.segment === segment &&
            x.direction === direction &&
            x.from <= at &&
            at < x.until,
    );
    if (matches.length > 1)
        return {
            road: null,
            limit: fleet,
            source: 'Conflicting manual records · fleet threshold only',
            confidence: 'Needs review',
            record: 'Conflict',
        };
    const manual = matches[0];
    if (manual)
        return {
            road: manual.limit,
            limit: Math.min(manual.limit, fleet),
            source: 'Approved manual limit',
            confidence: 'Evidence reviewed',
            record: manual.id,
        };
    if (
        state.provider === 'Fresh matched response' &&
        segment === 'SEG-DEMO-12' &&
        direction === 'Outbound' &&
        at.startsWith('2026-09-21')
    )
        return {
            road: 60,
            limit: Math.min(60, fleet),
            source: 'Mapped road limit · synthetic provider response',
            confidence: 'Matched road + direction · 96% example confidence',
            record: 'MAP-DEMO-2026-09-21',
        };
    return {
        road: null,
        limit: fleet,
        source:
            (state.provider === 'Fresh matched response'
                ? 'No eligible source for this segment / direction / time'
                : state.provider) + ' · fleet threshold only',
        confidence: 'Road limit unknown',
        record: 'No eligible road-limit source',
    };
}
export function drivingActions(
    data: Store,
    open: (a: Action) => void,
    patch: (f: (d: Store) => Store, msg: string) => void,
) {
    const field = (
        key: string,
        label: string,
        type: Action['sections'][number]['fields'][number]['type'] = 'text',
        required = true,
    ) => ({ key, label, type, required });
    const choice = (key: string, label: string, values: string[]) => ({
        ...field(key, label, 'record'),
        records: values.map((name) => ({
            id: name,
            name,
            detail: 'Synthetic review option',
        })),
    });
    const drivers = ['Jamie Taylor', 'Alex Morgan', 'Casey Lee'];
    const assign = (trip: Journey) =>
        open({
            title: 'Confirm driver & handover',
            verb: 'Save driver evidence',
            values: {
                driver:
                    confirmedDriver(trip, data.driving) ||
                    trip.driver.replace('Unassigned', ''),
                point: '0',
                reason: '',
                files: '[]',
                confirmed: 'false',
            },
            sections: [
                {
                    title: 'Driver and trip',
                    description:
                        trip.id +
                        ' · Confirm the person who actually drove this part of the trip.',
                    fields: [
                        choice('driver', 'Actual driver', drivers),
                        {
                            ...field(
                                'point',
                                'Applies from recorded point',
                                'record',
                            ),
                            records: trip.path.map((_, i) => ({
                                id: String(i),
                                name: `Point ${i + 1}${i === 0 ? ' · whole trip from departure' : ''}`,
                                detail: trip.id,
                            })),
                        },
                        field(
                            'reason',
                            'Checkout or handover evidence',
                            'textarea',
                        ),
                        field('files', 'Supporting evidence', 'files', false),
                        field(
                            'confirmed',
                            'I verified the actual driver and handover point',
                            'check',
                        ),
                    ],
                },
            ],
            save: (v) =>
                patch((d) => {
                    const segments = [
                        ...(d.driving.assignments[trip.id] || []).filter(
                            (s) => s.point !== +v.point,
                        ),
                        { point: +v.point, driver: v.driver, reason: v.reason },
                    ].sort((a, b) => a.point - b.point);
                    return {
                        ...d,
                        driving: {
                            ...d.driving,
                            assignments: {
                                ...d.driving.assignments,
                                [trip.id]: segments,
                            },
                            assignmentHistory: {
                                ...d.driving.assignmentHistory,
                                [trip.id]: [
                                    ...(d.driving.assignmentHistory[trip.id] ||
                                        []),
                                    `Alex Morgan · ${v.driver} from point ${+v.point + 1}: ${v.reason}`,
                                ],
                            },
                        },
                        documents: [
                            ...d.documents,
                            ...readFiles(v.files).map((f) => ({
                                ...f,
                                owner: trip.id,
                            })),
                        ],
                    };
                }, 'Driver evidence saved; original assignment retained.'),
            success:
                'Event attribution now follows the confirmed point range. A trip with multiple drivers is excluded from a whole-trip personal score until segment mileage is available.',
        });
    const review = (trip: Journey, index: number) => {
        const key = eventKey(trip, index),
            event = trip.events[index],
            old = data.driving.reviews[key];
        open({
            title: 'Review driving event',
            verb: 'Save review & recalculate',
            values: {
                outcome: old?.outcome || '',
                reviewer: 'Operations Manager',
                reason: '',
                files: '[]',
                confirmed: 'false',
            },
            sections: [
                {
                    title: 'Event evidence',
                    description: `${trip.id} · ${event.title} · ${event.at || trip.start}. Original telemetry stays intact.`,
                    fields: [
                        choice('outcome', 'Review outcome', [
                            'Confirmed',
                            'Dismissed',
                            'Disputed',
                        ]),
                        field('reviewer', 'Review owner', 'person'),
                        field(
                            'reason',
                            'Evidence and review reason',
                            'textarea',
                        ),
                        field('files', 'Supporting files', 'files', false),
                        field(
                            'confirmed',
                            'I reviewed the event evidence and scoring impact',
                            'check',
                        ),
                    ],
                },
            ],
            save: (v) =>
                patch(
                    (d) => ({
                        ...d,
                        driving: {
                            ...d.driving,
                            reviews: {
                                ...d.driving.reviews,
                                [key]: {
                                    outcome: v.outcome,
                                    reason: v.reason,
                                    reviewer: v.reviewer,
                                    history: [
                                        ...(old?.history || []),
                                        `${v.reviewer} · ${v.outcome}: ${v.reason}`,
                                    ],
                                },
                            },
                        },
                        documents: [
                            ...d.documents,
                            ...readFiles(v.files).map((f) => ({
                                ...f,
                                owner: key,
                            })),
                        ],
                    }),
                    'Event review recorded and illustrative score recalculated.',
                ),
            success:
                'Dismissed events no longer deduct points. Disputed events withhold the trip score. Control Room alerts stay open until their separate response is resolved.',
        });
    };
    const coach = (trip: Journey) =>
        open({
            title: 'Create coaching follow-up',
            verb: 'Create coaching record',
            values: {
                owner: 'Operations Manager',
                due: '2026-09-29',
                reason: '',
                files: '[]',
            },
            sections: [
                {
                    title: 'Outcome and ownership',
                    description:
                        trip.id +
                        ' · Record the concern and intended outcome with the source trip retained.',
                    fields: [
                        field('owner', 'Coach or review owner', 'person'),
                        field('due', 'Review date', 'date'),
                        field(
                            'reason',
                            'Expected outcome and actions',
                            'textarea',
                        ),
                        field('files', 'Supporting files', 'files', false),
                    ],
                },
            ],
            save: (v) =>
                patch((d) => {
                    const id = 'COACH-DEMO-' + (d.driving.coaching.length + 1);
                    return {
                        ...d,
                        followups: [
                            {
                                id: 'REM-' + id,
                                title: 'Coaching review · ' + trip.id,
                                source: trip.id,
                                at: v.due + 'T09:00',
                                owner: v.owner,
                                backup: 'Shift lead',
                                repeat: 0,
                                notes: v.reason,
                                status: 'Active',
                                history: ['Created from ' + id],
                            },
                            ...d.followups,
                        ],
                        driving: {
                            ...d.driving,
                            coaching: [
                                {
                                    id,
                                    trip: trip.id,
                                    owner: v.owner,
                                    due: v.due,
                                    status: 'Assigned',
                                    outcome: v.reason,
                                    history: [
                                        'Alex Morgan · Assigned to ' + v.owner,
                                    ],
                                },
                                ...d.driving.coaching,
                            ],
                        },
                        documents: [
                            ...d.documents,
                            ...readFiles(v.files).map((f) => ({
                                ...f,
                                owner: id,
                            })),
                        ],
                    };
                }, 'Coaching follow-up linked to the trip.'),
        });
    const coachAction = (id: string, action: 'Acknowledge' | 'Complete') => {
        const item = data.driving.coaching.find((x) => x.id === id);
        if (!item) return;
        open({
            title: action + ' coaching',
            verb: action,
            values: { reason: '', files: '[]', confirmed: 'false' },
            sections: [
                {
                    title: 'Review outcome',
                    description: `${id} · ${item.trip} · ${item.owner}`,
                    fields: [
                        field(
                            'reason',
                            action === 'Complete'
                                ? 'Outcome and follow-up evidence'
                                : 'Acknowledgement notes',
                            'textarea',
                        ),
                        field('files', 'Evidence', 'files', false),
                        field(
                            'confirmed',
                            action === 'Complete'
                                ? 'I reviewed the outcome and any remaining actions'
                                : 'I accept responsibility for this follow-up',
                            'check',
                        ),
                    ],
                },
            ],
            save: (v) =>
                patch(
                    (d) => ({
                        ...d,
                        followups: d.followups.map((f) =>
                            f.id === 'REM-' + id
                                ? {
                                      ...f,
                                      status:
                                          action === 'Complete'
                                              ? 'Completed'
                                              : 'Acknowledged',
                                      history: [
                                          ...f.history,
                                          'Coaching ' +
                                              action.toLowerCase() +
                                              ': ' +
                                              v.reason,
                                      ],
                                  }
                                : f,
                        ),
                        driving: {
                            ...d.driving,
                            coaching: d.driving.coaching.map((x) =>
                                x.id === id
                                    ? {
                                          ...x,
                                          status:
                                              action === 'Complete'
                                                  ? 'Completed'
                                                  : 'Acknowledged',
                                          history: [
                                              ...x.history,
                                              `Alex Morgan · ${action}: ${v.reason}`,
                                          ],
                                      }
                                    : x,
                            ),
                        },
                        documents: [
                            ...d.documents,
                            ...readFiles(v.files).map((f) => ({
                                ...f,
                                owner: id,
                            })),
                        ],
                    }),
                    'Coaching ' +
                        action.toLowerCase() +
                        ' recorded; source events unchanged.',
                ),
        });
    };
    const policy = () =>
        open({
            title: 'Review score policy',
            verb: 'Publish preview policy',
            values: {
                ...Object.fromEntries(
                    Object.entries(data.driving.policy)
                        .filter(([k]) => !['history', 'version'].includes(k))
                        .map(([k, v]) => [k, String(v)]),
                ),
                reason: '',
                confirmed: 'false',
            },
            sections: [
                {
                    title: 'Weights',
                    description:
                        'Illustrative trip deductions. A new version recalculates this preview and retains the previous policy in history.',
                    fields: [
                        field('braking', 'Braking points per event', 'number'),
                        field(
                            'acceleration',
                            'Acceleration points per event',
                            'number',
                        ),
                        field(
                            'speed',
                            'Overspeed points per episode',
                            'number',
                        ),
                        field('idle', 'Points per idle minute', 'number'),
                    ],
                },
                {
                    title: 'Eligibility',
                    description:
                        'Person scores require confirmed whole-trip attribution, reviewed events and sufficient comparable data.',
                    fields: [
                        field('coverage', 'Minimum trip coverage %', 'number'),
                        field('trips', 'Minimum confirmed trips', 'number'),
                        field(
                            'distance',
                            'Minimum confirmed distance · km',
                            'number',
                        ),
                        field(
                            'reason',
                            'Reason for the new version',
                            'textarea',
                        ),
                        field(
                            'confirmed',
                            'I reviewed the scoring impact in this synthetic preview',
                            'check',
                        ),
                    ],
                },
            ],
            validate: (v) =>
                ['braking', 'acceleration', 'speed', 'idle'].some(
                    (k) => !Number.isFinite(+v[k]) || +v[k] < 0,
                ) ||
                +v.coverage < 50 ||
                +v.coverage > 100 ||
                !Number.isInteger(+v.trips) ||
                +v.trips < 1 ||
                +v.distance < 1
                    ? 'Use non-negative weights, coverage 50–100%, and positive sample minimums.'
                    : '',
            save: (v) =>
                patch(
                    (d) => ({
                        ...d,
                        driving: {
                            ...d.driving,
                            policy: {
                                version: d.driving.policy.version + 1,
                                braking: +v.braking,
                                acceleration: +v.acceleration,
                                speed: +v.speed,
                                idle: +v.idle,
                                coverage: +v.coverage,
                                trips: +v.trips,
                                distance: +v.distance,
                                history: [
                                    ...d.driving.policy.history,
                                    `v${d.driving.policy.version}: ${JSON.stringify({ ...d.driving.policy, history: undefined })} · superseded by Alex Morgan: ${v.reason}`,
                                ],
                            },
                        },
                    }),
                    'Preview scoring policy version published.',
                ),
        });
    const manualLimit = () =>
        open({
            title: 'Add manual speed limit',
            verb: 'Submit for review',
            values: {
                segment: 'SEG-DEMO-12',
                direction: 'Outbound',
                limit: '30',
                from: '2026-09-21T00:00',
                until: '2026-09-23T23:59',
                reason: '',
                files: '[]',
            },
            sections: [
                {
                    title: 'Road and validity',
                    description:
                        'Use an evidence-backed road segment, direction and effective window. This entry will remain pending until reviewed.',
                    fields: [
                        choice('segment', 'Road segment', [
                            'SEG-DEMO-12',
                            'SEG-DEMO-11',
                        ]),
                        choice('direction', 'Direction', [
                            'Outbound',
                            'Inbound',
                        ]),
                        {
                            ...field(
                                'limit',
                                'Posted or temporary limit · km/h',
                                'catalog-number',
                            ),
                            options: [
                                '20',
                                '30',
                                '40',
                                '50',
                                '60',
                                '80',
                                '100',
                            ],
                        },
                        field('from', 'Effective from', 'datetime'),
                        field('until', 'Expires at', 'datetime'),
                    ],
                },
                {
                    title: 'Evidence',
                    description:
                        'Record the sign, road authority notice or approved source. An unsupported manual entry cannot override the mapped limit.',
                    fields: [
                        field(
                            'reason',
                            'Authority reference and reason',
                            'textarea',
                        ),
                        field(
                            'files',
                            'Sign photo or authority document',
                            'files',
                        ),
                    ],
                },
            ],
            validate: (v) =>
                +v.limit < 5 || +v.limit > 150 || v.until <= v.from
                    ? 'Choose a limit between 5 and 150 km/h and an expiry after the start.'
                    : '',
            save: (v) =>
                patch((d) => {
                    const id = 'LIMIT-DEMO-' + (d.driving.overrides.length + 1);
                    return {
                        ...d,
                        driving: {
                            ...d.driving,
                            overrides: [
                                {
                                    id,
                                    segment: v.segment,
                                    direction: v.direction,
                                    limit: +v.limit,
                                    from: v.from,
                                    until: v.until,
                                    reason: v.reason,
                                    status: 'Pending review',
                                    history: [
                                        'Alex Morgan · Proposed: ' + v.reason,
                                    ],
                                },
                                ...d.driving.overrides,
                            ],
                        },
                        documents: [
                            ...d.documents,
                            ...readFiles(v.files).map((f) => ({
                                ...f,
                                owner: id,
                            })),
                        ],
                    };
                }, 'Manual speed limit submitted; not active until reviewed.'),
        });
    const limitAction = (id: string, retire = false) => {
        const item = data.driving.overrides.find((x) => x.id === id);
        if (!item) return;
        open({
            title: retire ? 'Retire manual limit' : 'Review manual speed limit',
            verb: retire ? 'Retire limit' : 'Approve preview limit',
            values: {
                reviewer: 'Operations Manager',
                reason: '',
                confirmed: 'false',
            },
            sections: [
                {
                    title: 'Scope and source review',
                    description: `${id} · ${item.segment} ${item.direction} · ${item.limit} km/h · ${item.from} to ${item.until}. Evidence: ${item.reason}`,
                    fields: [
                        field('reviewer', 'Reviewer', 'person'),
                        field(
                            'reason',
                            'Review decision and reason',
                            'textarea',
                        ),
                        field(
                            'confirmed',
                            'I verified the authority, segment, direction and dates',
                            'check',
                        ),
                    ],
                },
            ],
            validate: () =>
                !retire &&
                data.driving.overrides.some(
                    (x) =>
                        x.id !== id &&
                        x.status === 'Approved' &&
                        x.segment === item.segment &&
                        x.direction === item.direction &&
                        x.from < item.until &&
                        item.from < x.until,
                )
                    ? 'An approved entry overlaps this road and time. Retire or correct it before approval.'
                    : '',
            save: (v) =>
                patch(
                    (d) => ({
                        ...d,
                        driving: {
                            ...d.driving,
                            overrides: d.driving.overrides.map((x) =>
                                x.id === id
                                    ? {
                                          ...x,
                                          status: retire
                                              ? 'Retired'
                                              : 'Approved',
                                          history: [
                                              ...x.history,
                                              `${v.reviewer} · ${retire ? 'Retired' : 'Approved'}: ${v.reason}`,
                                          ],
                                      }
                                    : x,
                            ),
                        },
                    }),
                    'Manual limit ' +
                        (retire ? 'retired' : 'approved') +
                        ' in the preview.',
                ),
        });
    };
    return {
        assign,
        review,
        coach,
        coachAction,
        policy,
        manualLimit,
        limitAction,
        setProvider: (provider: DrivingState['provider']) =>
            patch(
                (d) => ({ ...d, driving: { ...d.driving, provider } }),
                'Synthetic map-source state changed.',
            ),
        advanceTime: () =>
            patch((d) => {
                const minutes = d.driving.minutes + 5;
                return {
                    ...d,
                    driving: { ...d.driving, minutes },
                    alerts: d.alerts.map((a) =>
                        !['Resolved', 'Delivery failed'].includes(a.status) &&
                        !a.acknowledged &&
                        minutes >=
                            (a.receivedMinute ?? 0) + (a.ackWithin ?? 10) &&
                        !a.autoEscalated
                            ? {
                                  ...a,
                                  status: 'Escalated',
                                  owner: d.alertPlan.backup,
                                  autoEscalated: true,
                                  history: [
                                      ...a.history,
                                      `Preview clock +${minutes} min · acknowledgement overdue; escalated to ${d.alertPlan.backup}`,
                                  ],
                              }
                            : a,
                    ),
                };
            }, 'Preview clock advanced five minutes; due escalations checked.'),
    };
}
