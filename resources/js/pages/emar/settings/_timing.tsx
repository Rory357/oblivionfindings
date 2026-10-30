/* Medication › Settings › Rounds & timing (eMAR P11 v5 `settings.tsx`
 * DoseTiming and RoundsOverview). Dose timing is saved through the save bar
 * like every other setting; the server reads it through DoseTimingSettings.
 * Meds today and the MAR read "Can be given from" and "Shows as due soon"
 * (P01 C6(b)). "Remind staff to offer again" waits for P08a follow-ups, and
 * time-critical medicines for P01 — no stubs. */
import { StatusBadge } from '@/components/ui/status-badge';
import {
    AlarmClock,
    Clock,
    DoorOpen,
    Repeat,
    RotateCcw,
    WifiOff,
} from 'lucide-react';
import { useSettings } from './_context';
import { decisionReviewer, isDirty, savedValue } from './_model';
import { NoMatches, useRow } from './_sections';
import type { RoundTemplate } from './_templates';
import {
    Choice,
    GroupGrid,
    GroupRow,
    NumberInput,
    OnOff,
    Overview,
    Section,
    SettingGroup,
} from './_ui';

const G = 'timing';
/** Lane A: how long a dose saved on a device can wait to be sent (EA-131). */
const OFFLINE = 'offline';
/** Lane A8 (D6): someone left and no return was recorded. */
const AWAY = 'away';

const match = (q: string, ...s: string[]) =>
    !q || s.some((x) => x.toLowerCase().includes(q.toLowerCase()));

export function DoseTiming({
    q,
    show,
    clear,
}: {
    q: string;
    show: string;
    clear: () => void;
}) {
    const { s, value, edit, state, disabled, shown } = useRow(G);
    const offline = useRow(OFFLINE);
    const offlineDef = s.definitions[OFFLINE]?.max_hours;
    const { draft, errors, clearError, open } = useSettings();
    const offlineError = errors[`${OFFLINE}.max_hours`];
    const offlineLabel = 'Can still be sent for';
    const offlineHint =
        'Older than this, it isn’t added to the chart. It goes to paper recovery, and the house lead gets a follow-up.';
    const away = useRow(AWAY);
    const awayCapDef = s.definitions[AWAY]?.cap_hours;
    const awayFollowupDef = s.definitions[AWAY]?.return_followup;
    const awayHospitalDef = s.definitions[AWAY]?.hospital_no_discharge;
    const awayCapError = errors[`${AWAY}.cap_hours`];
    const awayCapLabel = 'With no recorded return, doses stay Away for';
    const awayCapHint =
        'Counted from the end of planned leave or respite (from when they left if there’s no planned end). After this, their doses show as owed again. Recording the return ends Away sooner.';
    const awayFollowupLabel = 'Ask the house lead to record the return';
    const awayFollowupHint =
        'One “Record the return” follow-up for the lead at the house, once the time is up. Recording the return closes it.';
    const awayHospitalLabel = 'In hospital with no discharge recorded';
    const awayHospitalHint =
        'A hospital stay has no planned end. Once the time above has passed since admission, the house lead is asked to record the discharge or confirm they’re still in hospital — and asked again after the same time.';
    const def = (key: string) => s.definitions[G]?.[key];
    const input = (
        key: string,
        label?: string,
        errorId?: string,
        unit?: string,
    ) => {
        const d = def(key);
        const error = errors[`${G}.${key}`];
        return (
            <NumberInput
                id={`tm-${key}`}
                label={label}
                value={value(key)}
                unit={unit ?? (d?.unit ?? '').replace(' the dose time', '')}
                min={d?.range?.[0]}
                max={d?.range?.[1]}
                disabled={disabled}
                error={error}
                errorId={errorId}
                onChange={(v) => {
                    edit(key, v);
                    clearError(`${G}.${key}`);
                }}
            />
        );
    };
    const number = (key: string, label: string, hint: string) =>
        def(key) ? (
            <GroupRow
                key={key}
                id={`tm-${key}`}
                label={label}
                hint={hint}
                state={state(key)}
                error={errors[`${G}.${key}`]}
                errorId={`tm-${key}-error`}
                hidden={!shown(show, q, key, label, hint)}
                control={input(key)}
            />
        ) : null;

    // The two escalation numbers are one decision.
    const pairLabel = 'Escalate repeated refusals';
    const pairHint =
        'Refusals or withholds of the same medicine go to a manager and the GP.';
    const pairDirty =
        isDirty(s, draft, G, 'refusal_count') ||
        isDirty(s, draft, G, 'refusal_days');
    const pairReviewed = !!decisionReviewer(s, G, 'refusal_count');
    const pairShown =
        (show === 'open'
            ? !pairReviewed
            : show === 'changed'
              ? pairDirty
              : true) && match(q, pairLabel, pairHint);

    return (
        <Section
            id="sc-timing"
            title="Dose timing"
            caption="Every house · giving a dose outside its time window needs a reason"
        >
            <GroupGrid empty={<NoMatches q={q} clear={clear} />}>
                <SettingGroup
                    id="due"
                    icon={Clock}
                    title="When a dose is due"
                    caption="What Meds today shows for a scheduled dose"
                >
                    {number(
                        'early',
                        'Can be given from',
                        'Before this, the dose shows as not yet due.',
                    )}
                    {number(
                        'due_soon',
                        'Shows as due soon',
                        'Only changes what Meds today highlights.',
                    )}
                    {number(
                        'late',
                        'Counts as late',
                        'Overdue alerts go out after this.',
                    )}
                </SettingGroup>
                <SettingGroup
                    id="late"
                    icon={AlarmClock}
                    title="Late doses"
                    caption="When a late dose becomes an incident"
                >
                    {number(
                        'late_incident',
                        'Raises an incident after',
                        'Serious after 4 hours.',
                    )}
                </SettingGroup>
                {def('refusal_count') && def('refusal_days') ? (
                    <SettingGroup
                        id="refusals"
                        icon={RotateCcw}
                        title="After a refusal"
                        caption="Repeated refusals"
                    >
                        <GroupRow
                            id="tm-refusal_count"
                            label={pairLabel}
                            hint={pairHint}
                            state={
                                pairDirty
                                    ? 'changed'
                                    : pairReviewed
                                      ? null
                                      : 'default'
                            }
                            error={
                                errors[`${G}.refusal_count`] ||
                                errors[`${G}.refusal_days`]
                            }
                            errorId="tm-refusal-error"
                            hidden={!pairShown}
                        >
                            <span className="inline-flex flex-wrap items-center gap-2">
                                {input(
                                    'refusal_count',
                                    'Number of refusals',
                                    'tm-refusal-error',
                                    'within',
                                )}
                                {input(
                                    'refusal_days',
                                    'Number of days',
                                    'tm-refusal-error',
                                )}
                            </span>
                        </GroupRow>
                    </SettingGroup>
                ) : null}
                {offlineDef ? (
                    <SettingGroup
                        id="offline"
                        icon={WifiOff}
                        title="Saved on a device"
                        caption="A dose recorded with no connection, sent later"
                    >
                        <GroupRow
                            id="tm-offline-max_hours"
                            label={offlineLabel}
                            hint={offlineHint}
                            state={offline.state('max_hours')}
                            error={offlineError}
                            errorId="tm-offline-max_hours-error"
                            hidden={
                                !offline.shown(
                                    show,
                                    q,
                                    'max_hours',
                                    offlineLabel,
                                    offlineHint,
                                    offlineDef.label,
                                )
                            }
                            control={
                                <NumberInput
                                    id="tm-offline-max_hours"
                                    value={offline.value('max_hours')}
                                    unit="hours"
                                    min={offlineDef.range?.[0]}
                                    max={offlineDef.range?.[1]}
                                    disabled={offline.disabled}
                                    error={offlineError}
                                    onChange={(v) => {
                                        offline.edit('max_hours', v);
                                        clearError(`${OFFLINE}.max_hours`);
                                    }}
                                />
                            }
                        />
                    </SettingGroup>
                ) : null}
                {awayCapDef ? (
                    <SettingGroup
                        id="away"
                        icon={DoorOpen}
                        title="Away with no recorded return"
                        caption="Left for hospital, respite or a stay away, and no return recorded"
                    >
                        <GroupRow
                            id="tm-away-cap_hours"
                            label={awayCapLabel}
                            hint={awayCapHint}
                            state={away.state('cap_hours')}
                            error={awayCapError}
                            errorId="tm-away-cap_hours-error"
                            hidden={
                                !away.shown(
                                    show,
                                    q,
                                    'cap_hours',
                                    awayCapLabel,
                                    awayCapHint,
                                    awayCapDef.label,
                                )
                            }
                            control={
                                <NumberInput
                                    id="tm-away-cap_hours"
                                    value={away.value('cap_hours')}
                                    unit="hours"
                                    min={awayCapDef.range?.[0]}
                                    max={awayCapDef.range?.[1]}
                                    disabled={away.disabled}
                                    error={awayCapError}
                                    onChange={(v) => {
                                        away.edit('cap_hours', v);
                                        clearError(`${AWAY}.cap_hours`);
                                    }}
                                />
                            }
                        />
                        {awayFollowupDef ? (
                            <GroupRow
                                id="tm-away-return_followup"
                                label={awayFollowupLabel}
                                hint={awayFollowupHint}
                                state={away.state('return_followup')}
                                hidden={
                                    !away.shown(
                                        show,
                                        q,
                                        'return_followup',
                                        awayFollowupLabel,
                                        awayFollowupHint,
                                        awayFollowupDef.label,
                                    )
                                }
                                control={
                                    <OnOff
                                        id="tm-away-return_followup"
                                        checked={
                                            away.value('return_followup') ===
                                            'yes'
                                        }
                                        disabled={away.disabled}
                                        onChange={(v) =>
                                            away.edit(
                                                'return_followup',
                                                v ? 'yes' : 'no',
                                            )
                                        }
                                    />
                                }
                            />
                        ) : null}
                        {awayHospitalDef ? (
                            <GroupRow
                                id="tm-away-hospital_no_discharge"
                                label={awayHospitalLabel}
                                hint={awayHospitalHint}
                                state={away.state('hospital_no_discharge')}
                                hidden={
                                    !away.shown(
                                        show,
                                        q,
                                        'hospital_no_discharge',
                                        awayHospitalLabel,
                                        awayHospitalHint,
                                        awayHospitalDef.label,
                                    )
                                }
                            >
                                <Choice
                                    value={away.value('hospital_no_discharge')}
                                    disabled={away.disabled}
                                    onChange={(v) =>
                                        away.edit('hospital_no_discharge', v)
                                    }
                                    options={[
                                        ['follow_up', 'Stay Away and ask the lead'],
                                        [
                                            'owed_after_cap',
                                            'Owed after the time above',
                                        ],
                                    ]}
                                />
                            </GroupRow>
                        ) : null}
                    </SettingGroup>
                ) : null}
            </GroupGrid>
            <p className="text-caption">
                A round template has its own window (5–120 minutes) for grouping
                doses into a round. These times decide when a dose is due and
                late.
            </p>
        </Section>
    );
}

export function RoundsOverview({
    q,
    templates,
}: {
    q: string;
    templates: RoundTemplate[];
}) {
    const active = templates.filter((t) => t.status === 'active');
    const paused = templates.filter((t) => t.status === 'paused').length;
    const houses = new Set(active.map((t) => t.site_id ?? 'all')).size;
    const { s, go } = useSettings();
    const v = (key: string) => savedValue(s, G, key);
    const notReviewed = (keys: string[]) => {
        const open = keys.filter((k) => !decisionReviewer(s, G, k)).length;
        return open ? (
            <StatusBadge variant="warning" size="sm">
                {open} not yet reviewed
            </StatusBadge>
        ) : (
            <StatusBadge variant="success" size="sm">
                All reviewed
            </StatusBadge>
        );
    };
    return (
        <Overview
            q={q}
            title="Rounds & timing"
            caption="When doses are given and when they’re late"
            cards={[
                {
                    icon: Repeat,
                    title: 'Round templates',
                    lines: [
                        templates.length
                            ? `${active.length} active across ${houses} ${houses === 1 ? 'house' : 'houses'} · ${paused} paused.`
                            : 'No round templates yet.',
                        'Rounds are created from them at 12:05 am each day.',
                    ],
                    cta: 'Review round templates',
                    onClick: () => go('rounds', 'templates'),
                },
                {
                    icon: Clock,
                    title: 'When a dose is due',
                    lines: [
                        `Can be given from ${v('early')} minutes before. Late after ${v('late')} minutes.`,
                        'Giving a dose outside this time window needs a reason. Other safety checks still apply.',
                    ],
                    badge: notReviewed(['early', 'late', 'due_soon']),
                    cta: 'Review dose timing',
                    onClick: () => go('rounds', 'timing'),
                },
                {
                    icon: RotateCcw,
                    title: 'Refusals and late doses',
                    lines: [
                        `Escalates after ${v('refusal_count')} refusals within ${v('refusal_days')} days. A late dose becomes an incident after ${v('late_incident')} minutes.`,
                    ],
                    badge: notReviewed(['refusal_count', 'late_incident']),
                    cta: 'Review dose timing',
                    onClick: () => go('rounds', 'timing'),
                },
            ]}
        />
    );
}
