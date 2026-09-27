import type { ZoneSchedule } from '@/components/client-location/types';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import {
    scheduleError,
    WEEKDAY_BUTTONS,
} from '@/components/fleet-assets/vehicle-workspace/map-model';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    ArrowLeft,
    Bell,
    ClipboardCheck,
    ClipboardList,
    Clock,
    Plus,
    Save,
    Shapes,
    SlidersHorizontal,
} from 'lucide-react';
import { useState } from 'react';
import { base, query, request, RequestError } from './api';
import type { BoundaryRecord, ResourceRecord, RuleRecord } from './data';
import { BoundaryMap } from './map';
import { PolicySummary } from './policy-summary';
import { RemotePicker } from './remote-picker';
import {
    directionLabel,
    normalisePolicy,
    overlappingWindows,
    policyError,
    sampleOutcome,
    type RulePolicy,
} from './rule-policy';
import { Button, Facts, Field, Notice } from './ui';
const steps = [
    {
        key: 'boundary',
        label: 'Boundary',
        blurb: 'Choose the shared area',
        icon: Shapes,
    },
    {
        key: 'purpose',
        label: 'Purpose & record',
        blurb: 'Choose who and what',
        icon: ClipboardList,
    },
    {
        key: 'timing',
        label: 'Timing',
        blurb: 'Days, windows and exceptions',
        icon: Clock,
    },
    {
        key: 'detection',
        label: 'Detection',
        blurb: 'Quality and confirmation',
        icon: SlidersHorizontal,
    },
    {
        key: 'response',
        label: 'Response',
        blurb: 'Responsibility and targets',
        icon: Bell,
    },
    {
        key: 'review',
        label: 'Review & test',
        blurb: 'Save an inactive proposal',
        icon: ClipboardCheck,
    },
];
const blankSchedule: ZoneSchedule = {
    timezone: 'Pacific/Auckland',
    weekdays: [],
    start: '08:00',
    end: '18:00',
    following_day: false,
    first_date: '',
    last_date: '',
    exception_dates: [],
};
export function RuleWizard({
    existing,
    initialBoundary,
    initialResource,
    onClose,
    onSaved,
}: {
    existing?: RuleRecord;
    initialBoundary?: BoundaryRecord;
    initialResource?: ResourceRecord;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [step, setStep] = useState(0),
        [boundary, setBoundary] = useState(initialBoundary),
        [resource, setResource] = useState<
            { id: number; name: string } | undefined
        >(
            initialResource ??
                (existing
                    ? { id: existing.asset_id, name: existing.resource }
                    : undefined),
        ),
        [name, setName] = useState(existing?.label ?? ''),
        [purpose, setPurpose] = useState(existing?.purpose ?? ''),
        [response, setResponse] = useState(existing?.response_proposal ?? ''),
        [owner, setOwner] = useState(existing?.policy?.owner ?? ''),
        [direction, setDirection] = useState(
            existing?.policy?.direction ?? 'exit',
        ),
        [policy, setPolicy] = useState<RulePolicy>(
            normalisePolicy(existing?.policy),
        ),
        [schedule, setSchedule] = useState<ZoneSchedule>(
            structuredClone(existing?.schedule ?? blankSchedule),
        ),
        [exception, setException] = useState(''),
        [reason, setReason] = useState(''),
        [reviewed, setReviewed] = useState(false),
        [dirty, setDirty] = useState(false),
        [discard, setDiscard] = useState(false),
        [busy, setBusy] = useState(false),
        [saved, setSaved] = useState(false),
        [error, setError] = useState(''),
        [sample, setSample] = useState('first'),
        [seconds, setSeconds] = useState('0'),
        [key, setKey] = useState(() => crypto.randomUUID()),
        [expected, setExpected] = useState(existing?.revision),
        [latestRule, setLatestRule] = useState<RuleRecord | null>(null);
    const mark = () => {
        setDirty(true);
        setReviewed(false);
    };
    const set = <K extends keyof RulePolicy>(k: K, v: RulePolicy[K]) => {
        setPolicy((p) => ({ ...p, [k]: v }));
        mark();
    };
    const close = () => {
        if (busy) return;
        if (dirty && !saved) setDiscard(true);
        else onClose();
    };
    const problem = (s: number) => {
        if (s === 0)
            return !boundary
                ? 'Choose a current boundary.'
                : boundary.retired_at
                  ? 'Choose an available boundary.'
                  : null;
        if (s === 1)
            return !resource
                ? 'Choose a vehicle or asset.'
                : !name.trim() || !purpose.trim()
                  ? 'Enter a rule name and purpose.'
                  : null;
        if (s === 2 && policy.timing === 'scheduled') {
            if (!policy.windows.length) return 'Add a time window.';
            for (const w of policy.windows) {
                const error = scheduleError({ ...schedule, ...w });
                if (error) return error;
            }
            if (overlappingWindows(schedule, policy.windows))
                return 'Windows overlap across the selected days.';
        }
        if (s === 3) return policyError(policy, 'detection');
        if (s === 4) return policyError(policy, 'response');
        if (s === 5)
            return reason.trim().length < 3
                ? 'Record a reason with at least three characters.'
                : !reviewed
                  ? 'Confirm the review.'
                  : null;
        return null;
    };
    const next = () => {
        const p = problem(step);
        if (p) {
            setError(p);
            return;
        }
        setError('');
        setStep(step + 1);
    };
    const save = async () => {
        for (let i = 0; i <= 5; i++) {
            const p = problem(i);
            if (p) {
                setStep(i);
                setError(p);
                return;
            }
        }
        setBusy(true);
        setError('');
        try {
            await request(
                base + '/rules' + (existing ? '/' + existing.id : ''),
                existing ? 'PUT' : 'POST',
                {
                    asset_id: resource!.id,
                    boundary_id: boundary!.id,
                    boundary_revision: boundary!.revision,
                    label: name,
                    purpose,
                    response_proposal: response,
                    reason,
                    policy: { ...policy, owner, direction },
                    schedule:
                        policy.timing === 'scheduled'
                            ? { ...schedule, ...policy.windows[0] }
                            : null,
                    request_key: key,
                    ...(existing ? { expected_version: expected } : {}),
                },
            );
            setSaved(true);
            setDirty(false);
            onSaved();
        } catch (e) {
            setError((e as Error).message);
            if (e instanceof RequestError && e.status === 409 && existing) {
                const current = await request<{ data: RuleRecord[] }>(
                    query('/rules', { id: existing.id }),
                ).catch(() => null);
                setLatestRule(current?.data[0] ?? null);
            }
        } finally {
            setBusy(false);
        }
    };
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={
                    existing ? 'Edit inactive rule' : 'Create a purpose rule'
                }
                description="Save a versioned proposal. Monitoring and notification delivery remain inactive."
                railIcon={ClipboardList}
                railTitle="Purpose rule"
                railSub="Independent timing and response"
                steps={steps}
                stepIndex={step}
                onStepClick={(n) => {
                    if (!busy) {
                        setStep(n);
                        setError('');
                    }
                }}
                maxWidth="min(92vw, 1100px)"
                maxHeight="min(88vh, 820px)"
                footerStart={
                    <>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={close}
                        >
                            Cancel
                        </Button>
                        {step > 0 && (
                            <Button
                                variant="ghost"
                                disabled={busy}
                                onClick={() => setStep(step - 1)}
                            >
                                <ArrowLeft />
                                Back
                            </Button>
                        )}
                    </>
                }
                footerEnd={
                    step < 5 ? (
                        <Button onClick={next} disabled={busy}>
                            Continue
                        </Button>
                    ) : (
                        <Button onClick={save} disabled={busy}>
                            <Save />
                            {busy ? 'Saving…' : 'Save inactive rule'}
                        </Button>
                    )
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title="Inactive rule saved"
                            blurb="The reviewed geometry and proposal are retained. Monitoring has not started."
                            actions={
                                <Button onClick={onClose}>Back to rules</Button>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    <fieldset disabled={busy} className="bnd-form flow-stack">
                        {error && <Notice tone="critical" title={error} />}
                        {latestRule && (
                            <Notice
                                tone="warning"
                                title={
                                    'Current saved rule · version ' +
                                    latestRule.revision
                                }
                            >
                                <p>
                                    {latestRule.label} · {latestRule.purpose}
                                </p>
                                <details>
                                    <summary>Review saved settings</summary>
                                    <PolicySummary
                                        schedule={latestRule.schedule}
                                        policy={latestRule.policy}
                                    />
                                </details>
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setExpected(latestRule.revision);
                                        setLatestRule(null);
                                        setKey(crypto.randomUUID());
                                        setReviewed(false);
                                        setStep(0);
                                        setError(
                                            'Review the boundary selection before saving your retained draft.',
                                        );
                                    }}
                                >
                                    I reviewed this version; keep my draft
                                </Button>
                            </Notice>
                        )}
                        {step === 0 && (
                            <>
                                <h2 className="text-section-title">
                                    Choose shared geometry
                                </h2>
                                <RemotePicker<BoundaryRecord>
                                    label="Boundary"
                                    value={boundary?.name}
                                    url={(q) =>
                                        query('/catalogue', { q, size: 20 })
                                    }
                                    describe={(b) => ({
                                        id: b.id,
                                        name: b.name,
                                        detail: `BG-${b.id} · ${b.site ?? 'Owning resource'} · v${b.geometry_version}`,
                                    })}
                                    onSelect={(b) => {
                                        setBoundary(b);
                                        mark();
                                    }}
                                />
                                {boundary && (
                                    <>
                                        <BoundaryMap
                                            shape={boundary.geometry}
                                            className="location-map"
                                        />
                                        <Notice
                                            title={`BG-${boundary.id} · geometry v${boundary.geometry_version}`}
                                        >
                                            This assignment keeps its own
                                            reviewed snapshot. Changing the
                                            shared area requires a new review.
                                        </Notice>
                                    </>
                                )}
                            </>
                        )}
                        {step === 1 && (
                            <>
                                <RemotePicker<ResourceRecord>
                                    label="Vehicle or asset"
                                    disabled={!!existing}
                                    value={resource?.name}
                                    url={(q) =>
                                        query('/resources', { q, size: 20 })
                                    }
                                    describe={(r) => ({
                                        id: r.id,
                                        name: r.name,
                                        detail: [r.kind, r.tag, r.site]
                                            .filter(Boolean)
                                            .join(' · '),
                                    })}
                                    onSelect={(r) => {
                                        setResource(r);
                                        mark();
                                    }}
                                />
                                <Field label="Rule name" required>
                                    <Input
                                        value={name}
                                        maxLength={120}
                                        onChange={(e) => {
                                            setName(e.target.value);
                                            mark();
                                        }}
                                    />
                                </Field>
                                <Field label="Purpose" required>
                                    <Textarea
                                        value={purpose}
                                        maxLength={2000}
                                        onChange={(e) => {
                                            setPurpose(e.target.value);
                                            mark();
                                        }}
                                    />
                                </Field>
                                <Field label="Movement to assess">
                                    <select
                                        className="rounded border bg-background p-2"
                                        value={direction}
                                        onChange={(e) => {
                                            setDirection(e.target.value);
                                            mark();
                                        }}
                                    >
                                        {['entry', 'exit', 'both', 'dwell'].map(
                                            (v) => (
                                                <option key={v} value={v}>
                                                    {directionLabel(v)}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </Field>
                            </>
                        )}
                        {step === 2 && (
                            <>
                                <Field label="Timing mode">
                                    <select
                                        className="rounded border bg-background p-2"
                                        value={policy.timing}
                                        onChange={(e) =>
                                            set(
                                                'timing',
                                                e.target
                                                    .value as RulePolicy['timing'],
                                            )
                                        }
                                    >
                                        <option value="unconfigured">
                                            Unconfigured
                                        </option>
                                        <option value="scheduled">
                                            Scheduled windows
                                        </option>
                                        <option value="always">
                                            At all times
                                        </option>
                                    </select>
                                </Field>
                                {policy.timing === 'scheduled' && (
                                    <>
                                        <div className="weekday-row">
                                            {WEEKDAY_BUTTONS.map((d) => (
                                                <Button
                                                    key={d.day}
                                                    variant={
                                                        schedule.weekdays.includes(
                                                            d.day,
                                                        )
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                    aria-pressed={schedule.weekdays.includes(
                                                        d.day,
                                                    )}
                                                    onClick={() => {
                                                        setSchedule((s) => ({
                                                            ...s,
                                                            weekdays:
                                                                s.weekdays.includes(
                                                                    d.day,
                                                                )
                                                                    ? s.weekdays.filter(
                                                                          (v) =>
                                                                              v !==
                                                                              d.day,
                                                                      )
                                                                    : [
                                                                          ...s.weekdays,
                                                                          d.day,
                                                                      ],
                                                        }));
                                                        mark();
                                                    }}
                                                >
                                                    {d.label}
                                                </Button>
                                            ))}
                                        </div>
                                        {policy.windows.map((w, i) => (
                                            <div
                                                key={i}
                                                className="flow-stack compact rounded border p-3"
                                            >
                                                <div className="two-fields">
                                                    <TimePicker
                                                        id={
                                                            'rule-window-' +
                                                            i +
                                                            '-start'
                                                        }
                                                        label={
                                                            'Window ' +
                                                            (i + 1) +
                                                            ' start'
                                                        }
                                                        value={w.start}
                                                        onChange={(v) =>
                                                            set(
                                                                'windows',
                                                                policy.windows.map(
                                                                    (x, j) =>
                                                                        i === j
                                                                            ? {
                                                                                  ...x,
                                                                                  start: v,
                                                                              }
                                                                            : x,
                                                                ),
                                                            )
                                                        }
                                                    />
                                                    <TimePicker
                                                        id={
                                                            'rule-window-' +
                                                            i +
                                                            '-end'
                                                        }
                                                        label={
                                                            'Window ' +
                                                            (i + 1) +
                                                            ' end'
                                                        }
                                                        value={w.end}
                                                        onChange={(v) =>
                                                            set(
                                                                'windows',
                                                                policy.windows.map(
                                                                    (x, j) =>
                                                                        i === j
                                                                            ? {
                                                                                  ...x,
                                                                                  end: v,
                                                                              }
                                                                            : x,
                                                                ),
                                                            )
                                                        }
                                                    />
                                                </div>
                                                <label className="check-row">
                                                    <Checkbox
                                                        checked={
                                                            w.following_day
                                                        }
                                                        onCheckedChange={(v) =>
                                                            set(
                                                                'windows',
                                                                policy.windows.map(
                                                                    (x, j) =>
                                                                        i === j
                                                                            ? {
                                                                                  ...x,
                                                                                  following_day:
                                                                                      v ===
                                                                                      true,
                                                                              }
                                                                            : x,
                                                                ),
                                                            )
                                                        }
                                                    />
                                                    Ends the following day
                                                </label>
                                                <Button
                                                    variant="ghost"
                                                    disabled={
                                                        policy.windows
                                                            .length === 1
                                                    }
                                                    onClick={() =>
                                                        set(
                                                            'windows',
                                                            policy.windows.filter(
                                                                (_, j) =>
                                                                    j !== i,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    Remove window
                                                </Button>
                                            </div>
                                        ))}
                                        <Button
                                            variant="outline"
                                            disabled={
                                                policy.windows.length >= 7
                                            }
                                            onClick={() =>
                                                set('windows', [
                                                    ...policy.windows,
                                                    {
                                                        start: '08:00',
                                                        end: '18:00',
                                                        following_day: false,
                                                    },
                                                ])
                                            }
                                        >
                                            <Plus />
                                            Add time window
                                        </Button>
                                        <div className="two-fields">
                                            <DatePicker
                                                id="rule-first-date"
                                                label="First date (required)"
                                                value={schedule.first_date}
                                                onChange={(v) => {
                                                    setSchedule((s) => ({
                                                        ...s,
                                                        first_date: v,
                                                    }));
                                                    mark();
                                                }}
                                            />
                                            <DatePicker
                                                id="rule-last-date"
                                                label="Last date (required)"
                                                value={schedule.last_date}
                                                onChange={(v) => {
                                                    setSchedule((s) => ({
                                                        ...s,
                                                        last_date: v,
                                                    }));
                                                    mark();
                                                }}
                                            />
                                        </div>
                                        <DatePicker
                                            id="rule-exception-date"
                                            label="Exception date"
                                            value={exception}
                                            onChange={setException}
                                        />
                                        <Button
                                            variant="outline"
                                            disabled={!exception}
                                            onClick={() => {
                                                setSchedule((s) => ({
                                                    ...s,
                                                    exception_dates: [
                                                        ...new Set([
                                                            ...s.exception_dates,
                                                            exception,
                                                        ]),
                                                    ],
                                                }));
                                                setException('');
                                                mark();
                                            }}
                                        >
                                            Exclude date
                                        </Button>
                                        <div className="inline-row">
                                            {schedule.exception_dates.map(
                                                (d) => (
                                                    <Button
                                                        key={d}
                                                        variant="outline"
                                                        onClick={() => {
                                                            setSchedule(
                                                                (s) => ({
                                                                    ...s,
                                                                    exception_dates:
                                                                        s.exception_dates.filter(
                                                                            (
                                                                                v,
                                                                            ) =>
                                                                                v !==
                                                                                d,
                                                                        ),
                                                                }),
                                                            );
                                                            mark();
                                                        }}
                                                    >
                                                        Remove {d}
                                                    </Button>
                                                ),
                                            )}
                                        </div>
                                        <Notice title="Pacific/Auckland local time">
                                            Weekdays and exceptions identify the
                                            start date. Overnight windows
                                            continue into the next day. These
                                            settings remain proposals.
                                        </Notice>
                                    </>
                                )}
                            </>
                        )}
                        {step === 3 && (
                            <>
                                <h2 className="text-section-title">
                                    Detection proposal
                                </h2>
                                <Notice title="Leave unapproved settings blank">
                                    Missing, stale or uncertain evidence cannot
                                    confirm an entry or exit.
                                </Notice>
                                <div className="two-fields">
                                    {(
                                        [
                                            [
                                                'accuracyM',
                                                'Maximum accuracy error (m)',
                                            ],
                                            [
                                                'confirmationSeconds',
                                                'Confirmation time (seconds)',
                                            ],
                                            [
                                                'bufferM',
                                                'Boundary edge buffer (m)',
                                            ],
                                            [
                                                'dwellSeconds',
                                                'Dwell duration (seconds)',
                                            ],
                                            [
                                                'repeatMinutes',
                                                'Repeat interval (minutes)',
                                            ],
                                        ] as const
                                    ).map(([k, label]) => (
                                        <Field label={label} key={k}>
                                            <Input
                                                type="number"
                                                min={0}
                                                value={policy[k]}
                                                onChange={(e) =>
                                                    set(k, e.target.value)
                                                }
                                            />
                                        </Field>
                                    ))}
                                </div>
                            </>
                        )}
                        {step === 4 && (
                            <>
                                <Field label="Responsible team proposal">
                                    <Input
                                        value={owner}
                                        maxLength={120}
                                        onChange={(e) => {
                                            setOwner(e.target.value);
                                            mark();
                                        }}
                                    />
                                </Field>
                                <Field label="Priority proposal">
                                    <select
                                        className="rounded border bg-background p-2"
                                        value={policy.priority}
                                        onChange={(e) =>
                                            set('priority', e.target.value)
                                        }
                                    >
                                        <option value="">Not proposed</option>
                                        {[
                                            'low',
                                            'medium',
                                            'high',
                                            'critical',
                                        ].map((p) => (
                                            <option key={p}>{p}</option>
                                        ))}
                                    </select>
                                </Field>
                                <div className="two-fields">
                                    <Field label="Acknowledgement target (minutes)">
                                        <Input
                                            type="number"
                                            value={policy.acknowledgeMinutes}
                                            onChange={(e) =>
                                                set(
                                                    'acknowledgeMinutes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Escalation delay (minutes)">
                                        <Input
                                            type="number"
                                            value={policy.escalateMinutes}
                                            onChange={(e) =>
                                                set(
                                                    'escalateMinutes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                                <Field label="Escalation team proposal">
                                    <Input
                                        value={policy.escalationTeam}
                                        onChange={(e) =>
                                            set(
                                                'escalationTeam',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field label="Response instructions">
                                    <Textarea
                                        value={response}
                                        maxLength={2000}
                                        onChange={(e) => {
                                            setResponse(e.target.value);
                                            mark();
                                        }}
                                    />
                                </Field>
                                <Notice title="Existing Control Room response">
                                    These are proposed responsibilities. Saving
                                    does not create recipients, send a
                                    notification or activate a rule.
                                </Notice>
                            </>
                        )}
                        {step === 5 && (
                            <>
                                <Facts
                                    rows={[
                                        ['Rule', name],
                                        ['Resource', resource?.name],
                                        ['Boundary', boundary?.name],
                                        ['Purpose', purpose],
                                        [
                                            'Response instructions',
                                            response || 'Not proposed',
                                        ],
                                        [
                                            'Assessment',
                                            directionLabel(direction),
                                        ],
                                    ]}
                                />
                                <PolicySummary
                                    schedule={
                                        policy.timing === 'scheduled'
                                            ? {
                                                  ...schedule,
                                                  ...policy.windows[0],
                                              }
                                            : null
                                    }
                                    policy={{ ...policy, owner, direction }}
                                />
                                <Field label="Scenario preview">
                                    <select
                                        className="rounded border bg-background p-2"
                                        value={sample}
                                        onChange={(e) =>
                                            setSample(e.target.value)
                                        }
                                    >
                                        {[
                                            'first',
                                            'entry',
                                            'exit',
                                            'dwell',
                                            'stale',
                                            'uncertain',
                                            'outside-hours',
                                        ].map((s) => (
                                            <option key={s}>{s}</option>
                                        ))}
                                    </select>
                                </Field>
                                <Field label="Time observed in this state (seconds)">
                                    <Input
                                        type="number"
                                        value={seconds}
                                        onChange={(e) =>
                                            setSeconds(e.target.value)
                                        }
                                    />
                                </Field>
                                <Notice title="Proposal preview">
                                    {sampleOutcome(
                                        direction,
                                        policy,
                                        sample,
                                        Math.max(0, Number(seconds) || 0),
                                    )}
                                </Notice>
                                <Field label="Reason for saving" required>
                                    <Textarea
                                        value={reason}
                                        maxLength={1000}
                                        onChange={(e) =>
                                            setReason(e.target.value)
                                        }
                                    />
                                </Field>
                                <label className="check-row">
                                    <Checkbox
                                        checked={reviewed}
                                        onCheckedChange={(v) =>
                                            setReviewed(v === true)
                                        }
                                    />
                                    I reviewed the current geometry and inactive
                                    proposal
                                </label>
                            </>
                        )}
                    </fieldset>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                title="Discard this rule draft?"
                description="Unsaved changes will be lost."
                confirmText="Discard draft"
                onConfirm={onClose}
            />
        </>
    );
}
