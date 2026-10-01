/* Medication › Settings › Rounds & timing (eMAR P11 v5 `settings.tsx`
 * DoseTiming and RoundsOverview). Dose timing is saved through the save bar
 * like every other setting; the server reads it through DoseTimingSettings.
 * "Shows as due soon" waits for Meds today to read its dose states from the
 * dose window resolver (P01 C6), "Remind staff to offer again" for P08a
 * follow-ups, and time-critical medicines for P01 — no stubs. */
import { StatusBadge } from '@/components/ui/status-badge';
import { AlarmClock, Clock, RotateCcw } from 'lucide-react';
import { useSettings } from './_context';
import { decisionReviewer, isDirty, savedValue } from './_model';
import { NoMatches, useRow } from './_sections';
import {
    GroupGrid,
    GroupRow,
    NumberInput,
    Overview,
    Section,
    SettingGroup,
} from './_ui';

const G = 'timing';

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
    const { draft, errors, clearError, open } = useSettings();
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
            caption="Every house · recording is never blocked by these times"
        >
            <GroupGrid empty={<NoMatches q={q} clear={clear} />}>
                <SettingGroup
                    id="due"
                    icon={Clock}
                    title="When a dose is due"
                    caption="The window for giving a scheduled dose"
                >
                    {number(
                        'early',
                        'Can be given from',
                        'Before this, recording asks for a reason.',
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
            </GroupGrid>
            <p className="text-caption">
                A round template has its own window (5–120 minutes) for grouping
                doses into a round. These times decide when a dose is due and
                late.
            </p>
        </Section>
    );
}

export function RoundsOverview({ q }: { q: string }) {
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
                    icon: Clock,
                    title: 'When a dose is due',
                    lines: [
                        `Can be given from ${v('early')} minutes before. Late after ${v('late')} minutes.`,
                        'Recording is never blocked by these times.',
                    ],
                    badge: notReviewed(['early', 'late']),
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
