import { readFiles } from './evidence-field';
import type { Action, Store } from './operations';

export type TrackerState = {
    sample: 'Parked' | 'Moving' | 'Offline' | 'Unplugged';
    sequence: number;
    automatic: boolean;
    baseline: number;
    counter: number;
    readingId: string;
    tolerance: number;
    history: string[];
};
export type VehicleAlert = {
    receivedMinute?: number;
    ackWithin?: number;
    acknowledged?: boolean;
    autoEscalated?: boolean;
    source: string;
    decision?: string;
    detail: string;
    device: string;
    lat: number;
    lng: number;
    id: string;
    kind: string;
    priority: string;
    status: string;
    owner: string;
    observed: string;
    correlation: string;
    duplicates: number;
    history: string[];
    work?: string;
};
export const alertKinds = [
    'Potential collision',
    'Overspeed threshold',
    'Unexpected towing',
    'Power disconnected',
    'Low vehicle voltage',
    'Tracker overdue',
    'Geofence breach',
    'Vehicle diagnostic fault',
];
export const trackerSeed = (): TrackerState => ({
    sample: 'Parked',
    sequence: 0,
    automatic: false,
    baseline: 82460,
    counter: 620,
    readingId: 'ODO-DEMO-03',
    tolerance: 25,
    history: [],
});
export function trackerFacts(data: Store, available: boolean) {
    const t = data.tracker;
    const counter = 632 + t.sequence * 12;
    const estimate = t.baseline + counter - t.counter;
    const verified = data.readings[0]?.value ?? 0;
    const reconciled = t.readingId === data.readings[0]?.id;
    const fresh = available && !['Offline', 'Unplugged'].includes(t.sample);
    const usable = fresh && reconciled && t.automatic;
    return {
        counter,
        estimate,
        verified,
        reconciled,
        fresh,
        usable,
        available,
        planning: usable ? estimate : verified,
        observed: `22 Sep 2026 · 9:${28 + t.sequence} am`,
        difference: estimate - verified,
    };
}
export function trackerActions(
    data: Store,
    available: boolean,
    open: (a: Action) => void,
    patch: (f: (d: Store) => Store, msg: string) => void,
) {
    const facts = trackerFacts(data, available);
    const field = (
        key: string,
        label: string,
        type: Action['sections'][number]['fields'][number]['type'] = 'text',
        required = true,
    ) => ({ key, label, type, required });
    const reconcile = () =>
        open({
            title: 'Reconcile tracker mileage',
            verb: 'Save reconciliation',
            values: {
                value: String(facts.estimate),
                tolerance: String(data.tracker.tolerance),
                automatic: String(data.tracker.automatic),
                reason: '',
                files: '[]',
                confirmed: 'false',
            },
            sections: [
                {
                    title: 'Dashboard cross-check',
                    description: `Compare the dashboard at this sample with the tracker estimate of ${facts.estimate.toLocaleString()} km. Counter ${facts.counter} km · ${facts.observed}.`,
                    fields: [
                        field(
                            'value',
                            'Dashboard reading in kilometres',
                            'number',
                        ),
                        field(
                            'reason',
                            'Observation and discrepancy explanation',
                            'textarea',
                        ),
                        field(
                            'files',
                            'Dashboard photo or supporting document',
                            'files',
                            false,
                        ),
                    ],
                },
                {
                    title: 'Automatic planning',
                    description:
                        'New tracker distance can update service and RUC planning. Recorded dashboard readings, expiry dates and compliance evidence remain separate.',
                    fields: [
                        field(
                            'automatic',
                            'Use calibrated tracker mileage for planning',
                            'check',
                            false,
                        ),
                        field(
                            'tolerance',
                            'Review difference in kilometres',
                            'number',
                        ),
                        field(
                            'confirmed',
                            'I checked this dashboard reading against the selected tracker sample',
                            'check',
                        ),
                    ],
                },
            ],
            validate: (v) =>
                !available || !facts.fresh
                    ? 'A current tracker sample is required. Record a manual reading while the tracker is unavailable.'
                    : (data.readings[0]?.at || '') >
                        `2026-09-22T09:${28 + data.tracker.sequence}`
                      ? 'The dashboard record is newer than this tracker sample. Advance to a matching sample before reconciliation.'
                      : !Number.isInteger(+v.value) || +v.value < facts.verified
                        ? 'Use a whole kilometre reading at least as high as the current record. Correct an incorrect historical reading in Mileage history first.'
                        : !Number.isInteger(+v.tolerance) || +v.tolerance < 1
                          ? 'Choose a positive whole kilometre review threshold.'
                          : '',
            save: (v) =>
                patch((d) => {
                    const id = 'ODO-RECON-' + (d.readings.length + 1);
                    return {
                        ...d,
                        readings: [
                            {
                                id,
                                value: +v.value,
                                at: `2026-09-22T09:${28 + d.tracker.sequence}`,
                                author: 'Alex Morgan',
                                source: 'Dashboard / tracker reconciliation',
                                reason: v.reason,
                            },
                            ...d.readings,
                        ].sort((a, b) => b.at.localeCompare(a.at)),
                        documents: [
                            ...d.documents,
                            ...readFiles(v.files).map((f) => ({
                                ...f,
                                owner: id,
                            })),
                        ],
                        tracker: {
                            ...d.tracker,
                            automatic: v.automatic === 'true',
                            baseline: +v.value,
                            counter: facts.counter,
                            readingId: id,
                            tolerance: +v.tolerance,
                            history: [
                                ...d.tracker.history,
                                `${facts.observed} · Alex Morgan · Dashboard ${v.value} km vs estimate ${facts.estimate} km; difference ${+v.value - facts.estimate} km. ${v.reason}`,
                            ],
                        },
                    };
                }, 'Dashboard reading retained; tracker baseline reconciled.'),
            success:
                'The dashboard observation is retained. Subsequent valid tracker samples update planning only when enabled. A new manual reading, missing sample or tracker change requires another review.',
        });
    const alertAction = (
        id: string,
        action:
            | 'Acknowledge'
            | 'Triage'
            | 'Escalate'
            | 'Resolve'
            | 'Retry delivery',
    ) => {
        const a = data.alerts.find((x) => x.id === id);
        if (!a) return;
        open({
            title: `${action} · ${id}`,
            verb: action,
            values: {
                owner: a.owner,
                reason: '',
                outcome: '',
                decision: a.decision || '',
            },
            sections: [
                {
                    title: 'Assessment & responsibility',
                    description:
                        action === 'Resolve'
                            ? 'Record the human assessment. This does not release the vehicle, close Maintenance work or contact emergency services.'
                            : `${a.kind} · ${a.observed} · KWH014. Synthetic Control Room record.`,
                    fields: [
                        field('owner', 'Responsible person or role', 'person'),
                        ...(action === 'Triage'
                            ? [
                                  {
                                      ...field(
                                          'decision',
                                          'Triage decision',
                                          'record',
                                      ),
                                      records: [
                                          'Maintenance assessment required',
                                          'Monitor and follow up',
                                          'Escalate response',
                                          'No further action · evidence recorded',
                                      ].map((x) => ({
                                          id: x,
                                          name: x,
                                          detail: 'Control Room decision',
                                      })),
                                  },
                              ]
                            : []),
                        ...(action === 'Resolve'
                            ? [
                                  {
                                      ...field(
                                          'outcome',
                                          'Assessment outcome',
                                          'record',
                                      ),
                                      records: [
                                          'Incident confirmed',
                                          'False alarm',
                                          'Duplicate of reviewed incident',
                                          'Unable to confirm · follow-up assigned',
                                      ].map((x) => ({
                                          id: x,
                                          name: x,
                                          detail: 'Human assessment',
                                      })),
                                  },
                              ]
                            : []),
                        field(
                            'reason',
                            action === 'Resolve'
                                ? 'Assessment and follow-up notes'
                                : 'Action notes',
                            'textarea',
                        ),
                    ],
                },
            ],
            save: (v) =>
                patch(
                    (d) => ({
                        ...d,
                        alerts: d.alerts.map((x) =>
                            x.id === id
                                ? {
                                      ...x,
                                      acknowledged:
                                          action === 'Acknowledge' ||
                                          action === 'Triage' ||
                                          action === 'Resolve'
                                              ? true
                                              : x.acknowledged,
                                      owner: v.owner,
                                      decision:
                                          action === 'Triage'
                                              ? v.decision
                                              : x.decision,
                                      status:
                                          action === 'Triage'
                                              ? v.decision ===
                                                'Escalate response'
                                                  ? 'Escalated'
                                                  : 'Triaged'
                                              : action === 'Resolve'
                                                ? 'Resolved'
                                                : action === 'Escalate'
                                                  ? 'Escalated'
                                                  : action === 'Retry delivery'
                                                    ? 'New'
                                                    : 'Acknowledged',
                                      history: [
                                          ...x.history,
                                          `22 Sep · Alex Morgan · ${action} · ${v.owner} · ${v.decision || v.outcome} ${v.reason}`,
                                      ],
                                  }
                                : x,
                        ),
                    }),
                    `${id}: ${action.toLowerCase()} recorded in the preview.`,
                ),
        });
    };
    const simulateAlert = (
        kind: string,
        deliveryFailed: boolean,
        origin?: {
            id: string;
            source: string;
            observed: string;
            lat: number;
            lng: number;
            detail?: string;
        },
    ) => {
        if (!available || !alertKinds.includes(kind)) return;
        patch((d) => {
            const correlation = (origin?.id || kind) + '/sample-01';
            const existing = d.alerts.find(
                (x) => x.correlation === correlation,
            );
            if (existing)
                return {
                    ...d,
                    alerts: d.alerts.map((x) =>
                        x.id === existing.id
                            ? {
                                  ...x,
                                  duplicates: x.duplicates + 1,
                                  history: [
                                      ...x.history,
                                      'Duplicate sample correlated to the same original signal.',
                                  ],
                              }
                            : x,
                    ),
                };
            const id = 'CR-DEMO-' + (410 + d.alerts.length);
            return {
                ...d,
                alerts: [
                    {
                        id,
                        receivedMinute: d.driving.minutes,
                        ackWithin: kind === 'Potential collision' ? 2 : 10,
                        kind,
                        priority:
                            kind === 'Potential collision'
                                ? 'Urgent'
                                : 'Review',
                        status: deliveryFailed ? 'Delivery failed' : 'New',
                        owner: d.alertPlan.owner,
                        observed: origin?.observed || facts.observed,
                        correlation,
                        detail:
                            origin?.detail ||
                            (kind === 'Overspeed threshold'
                                ? `Synthetic episode: ${d.alertPlan.speed + d.alertPlan.speedTolerance + 13} km/h peak; ${d.alertPlan.speedDuration + 27} seconds above ${d.alertPlan.speed + d.alertPlan.speedTolerance} km/h trigger. Fleet threshold ${d.alertPlan.speed} + tolerance ${d.alertPlan.speedTolerance}; road limit unverified. Draft rule snapshot; one episode.`
                                : kind === 'Low vehicle voltage'
                                  ? '11.6 V sample · duration and vehicle battery type need assessment'
                                  : kind === 'Vehicle diagnostic fault'
                                    ? 'DTC-DEMO-01 · synthetic diagnostic provider report; review source evidence'
                                    : kind === 'Potential collision'
                                      ? 'Accelerometer event · human confirmation required'
                                      : 'Review original sensor state, timing and context'),
                        source:
                            origin?.source ||
                            (kind === 'Vehicle diagnostic fault'
                                ? 'External diagnostic source · DEMO'
                                : 'Vehicle profile · GV500CG signal'),
                        device:
                            kind === 'Vehicle diagnostic fault'
                                ? 'External diagnostic provider · synthetic'
                                : 'GV500CG-DEMO-14',
                        lat: origin?.lat ?? -41.2865,
                        lng: origin?.lng ?? 174.7762,
                        duplicates: 0,
                        history: [
                            `${facts.observed} · Sensor event received (synthetic)`,
                            deliveryFailed
                                ? 'Control Room delivery failed · retry retains this signal identity'
                                : 'Control Room preview record created · awaiting acknowledgement',
                        ],
                    },
                    ...d.alerts,
                ],
            };
        }, 'Synthetic alert updated. No external notification sent.');
    };
    return {
        reconcile,
        alertAction,
        simulateAlert,
        setSample: (sample: TrackerState['sample']) =>
            patch(
                (d) => ({ ...d, tracker: { ...d.tracker, sample } }),
                'Tracker preview state changed.',
            ),
        nextSample: () =>
            patch(
                (d) => ({
                    ...d,
                    tracker: {
                        ...d.tracker,
                        sequence: Math.min(2, d.tracker.sequence + 1),
                    },
                }),
                'Next synthetic tracker sample received: +12 km.',
            ),
        pause: () =>
            open({
                title: 'Pause automatic mileage',
                verb: 'Pause planning feed',
                values: { reason: '' },
                sections: [
                    {
                        title: 'Reason',
                        description:
                            'Planning will use the latest recorded dashboard reading. All tracker evidence and baselines are retained.',
                        fields: [
                            field('reason', 'Reason for pausing', 'textarea'),
                        ],
                    },
                ],
                save: (v) =>
                    patch(
                        (d) => ({
                            ...d,
                            tracker: {
                                ...d.tracker,
                                automatic: false,
                                history: [
                                    ...d.tracker.history,
                                    '22 Sep · Alex Morgan · Planning feed paused: ' +
                                        v.reason,
                                ],
                            },
                        }),
                        'Automatic mileage planning paused.',
                    ),
            }),
        rule: () =>
            open({
                title: 'Vehicle alert response plan',
                verb: 'Save draft response plan',
                values: {
                    owner: data.alertPlan.owner,
                    backup: data.alertPlan.backup,
                    offline: String(data.alertPlan.offline),
                    voltage: String(data.alertPlan.voltage),
                    duration: String(data.alertPlan.duration),
                    speed: String(data.alertPlan.speed),
                    speedTolerance: String(data.alertPlan.speedTolerance),
                    speedDuration: String(data.alertPlan.speedDuration),
                    speedCooldown: String(data.alertPlan.speedCooldown),
                    notes: data.alertPlan.notes,
                },
                sections: [
                    {
                        title: 'Responsibility',
                        description:
                            'Collision → urgent review. Towing, disconnection and geofence events → contextual assessment. Draft rules are not live device settings.',
                        fields: [
                            field('owner', 'Primary response owner', 'person'),
                            field('backup', 'Backup response owner', 'person'),
                        ],
                    },
                    {
                        title: 'Overspeed & Control Room',
                        description:
                            'A sustained speed above the fleet threshold plus tolerance creates one Control Room record per episode. This is not a verified road-limit breach. Draft changes apply to future preview events; historical evidence retains its rule.',
                        fields: [
                            {
                                ...field(
                                    'speed',
                                    'Fleet speed threshold · km/h',
                                    'catalog-number',
                                ),
                                options: ['30', '50', '80', '100'],
                            },
                            {
                                ...field(
                                    'speedTolerance',
                                    'Tolerance · km/h',
                                    'catalog-number',
                                ),
                                options: ['3', '5', '10'],
                            },
                            {
                                ...field(
                                    'speedDuration',
                                    'Minimum continuous duration · seconds',
                                    'catalog-number',
                                ),
                                options: ['10', '15', '30', '60'],
                            },
                            {
                                ...field(
                                    'speedCooldown',
                                    'Below-threshold time before a new episode · seconds',
                                    'catalog-number',
                                ),
                                options: ['30', '60', '120'],
                            },
                        ],
                    },
                    {
                        title: 'Thresholds & review',
                        description:
                            'Illustrative vehicle-specific values. Validate firmware, battery type, reporting cadence and operational policy before enabling monitoring.',
                        fields: [
                            field(
                                'offline',
                                'Minutes overdue before review',
                                'number',
                            ),
                            field(
                                'voltage',
                                'Vehicle voltage below which to review',
                                'number',
                            ),
                            field(
                                'duration',
                                'Low voltage duration in minutes',
                                'number',
                            ),
                            field(
                                'notes',
                                'Response, cooldown and escalation instructions',
                                'textarea',
                            ),
                        ],
                    },
                ],
                validate: (v) =>
                    ![
                        v.speed,
                        v.speedTolerance,
                        v.speedDuration,
                        v.speedCooldown,
                    ].every((x) => Number.isFinite(+x) && +x > 0) ||
                    +v.speed > 150 ||
                    +v.speedTolerance > 20
                        ? 'Use positive speed and duration values; fleet threshold up to 150 km/h and tolerance up to 20 km/h.'
                        : +v.offline <= 0 ||
                            +v.voltage < 8 ||
                            +v.voltage > 32 ||
                            +v.duration <= 0
                          ? 'Use positive durations and a vehicle threshold within 8–32 V.'
                          : '',
                save: (v) =>
                    patch(
                        (d) => ({
                            ...d,
                            alertPlan: {
                                speed: +v.speed,
                                speedTolerance: +v.speedTolerance,
                                speedDuration: +v.speedDuration,
                                speedCooldown: +v.speedCooldown,
                                owner: v.owner,
                                backup: v.backup,
                                offline: +v.offline,
                                voltage: +v.voltage,
                                duration: +v.duration,
                                notes: v.notes,
                            },
                        }),
                        'Draft vehicle response plan saved; activation remains pending.',
                    ),
            }),
    };
}
