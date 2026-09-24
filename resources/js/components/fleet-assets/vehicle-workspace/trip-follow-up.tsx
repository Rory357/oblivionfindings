import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateOnly, formatTime } from '@/lib/datetime';
import { Clock3, FileCheck2, ShieldAlert, UserRound } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PersonPicker } from './choice-picker';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import { endLabel, nextStepTitle, plural, startLabel } from './trip-model';
import type { TripDetail } from './trip-types';
import type { VehicleWorkspace } from './types';
import {
    fieldProps,
    WizardField,
    WizardSuccess,
    WorkspaceWizard,
} from './wizard-kit';
import { todayInAuckland } from './workspace-model';

const number = (value: number) =>
    value.toLocaleString('en-NZ', { maximumFractionDigits: 2 });

/** "Act on the evidence": follow-up, Control Room and the scoring basis. */
export function TripNext({
    detail,
    canCoach,
    coachBlocked,
    onCoach,
    onOpenAlerts,
}: {
    detail: TripDetail;
    canCoach: boolean;
    /** Why coaching is unavailable for this trip, when it is. */
    coachBlocked: string | null;
    onCoach: () => void;
    onOpenAlerts: () => void;
}) {
    const { behaviour, policy } = detail;
    const overspeed = detail.events.find((event) => event.type === 'overspeed');
    const weights = policy.weights;

    return (
        <section
            className="studio-card trip-next"
            aria-label="Act on the evidence"
        >
            <span className="studio-eyebrow">Act on the evidence</span>
            <h3>{nextStepTitle(behaviour)}</h3>
            <p>
                Keep the trip, sensor event and response together. A fault goes
                to Control Room for assessment before a Maintenance decision.
            </p>
            <Button
                variant="outline"
                disabled={!canCoach || !!coachBlocked}
                title={coachBlocked ?? undefined}
                onClick={onCoach}
            >
                <Clock3 size={16} />
                Create coaching follow-up
            </Button>
            {coachBlocked && canCoach && <p>{coachBlocked}</p>}
            {behaviour.faults > 0 && (
                <Button onClick={onOpenAlerts}>
                    <ShieldAlert size={16} />
                    Open Alerts & Control Room
                </Button>
            )}
            {overspeed && behaviour.overspeed_episodes > 0 && (
                <>
                    <p>
                        {plural(
                            behaviour.overspeed_episodes,
                            'overspeed episode',
                        )}
                        . {overspeed.detail}
                    </p>
                    {behaviour.faults === 0 && (
                        <Button onClick={onOpenAlerts}>
                            <ShieldAlert size={16} />
                            Open Alerts & Control Room
                        </Button>
                    )}
                </>
            )}
            <details>
                <summary>Score, coverage and distance</summary>
                <p>
                    Current fleet settings: 100 − braking ×{' '}
                    {number(weights.braking)} − acceleration ×{' '}
                    {number(weights.acceleration)} − other harsh events ×{' '}
                    {number(weights.other_harsh)} − overspeed episodes ×{' '}
                    {number(weights.overspeed)} − round(idle minutes ×{' '}
                    {number(weights.idle_per_minute)}). Overspeed uses the fleet
                    threshold of {number(policy.speed_threshold_kph)} km/h, not
                    road limits. Coverage counts the share of the trip with a
                    recorded position at least every{' '}
                    {number(policy.coverage_gap_seconds / 60)} min; scores are
                    withheld below {policy.min_score_coverage_pct}% coverage,
                    for personal trips and while a trip is in progress. GPS
                    distance and dashboard odometer readings remain separate.
                </p>
            </details>
        </section>
    );
}

const STEPS = [
    {
        key: 'outcome',
        label: 'Outcome and ownership',
        blurb: 'Owner, review date and actions',
        icon: UserRound,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check the follow-up',
        icon: FileCheck2,
    },
];

function addDays(day: string, days: number): string {
    const date = new Date(`${day}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
}

/**
 * A coaching follow-up is a vehicle reminder for its owner, linked to the
 * trip by its title and notes. The trip and its events are not changed.
 */
export function CoachingWizard({
    workspace,
    detail,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    detail: TripDetail;
    onClose: () => void;
    onSaved: () => void;
}) {
    const vehicle = workspace.vehicle;
    const trip = detail.trip;
    const today = todayInAuckland();
    const [initial] = useState(() => ({
        owner: vehicle.responsible?.id ?? null,
        date: addDays(today, 7),
        outcome: '',
    }));
    const [form, setForm] = useState(initial);
    const [step, setStep] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [saved, setSaved] = useState(false);
    const command = useVehicleRecordCommand(isJsonObject);
    const serverErrors = command.errors;
    const errors = {
        owner: localErrors.owner ?? serverErrors.owner_user_id,
        date: localErrors.date ?? serverErrors.remind_local,
        outcome:
            localErrors.outcome ??
            serverErrors.action_text ??
            serverErrors.title,
    };
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((old) => ({ ...old, [key]: value }));
        setLocalErrors((old) => {
            const next = { ...old };
            delete next[key as string];
            return next;
        });
        command.clearError(
            key === 'owner'
                ? 'owner_user_id'
                : key === 'date'
                  ? 'remind_local'
                  : 'action_text',
        );
    };
    const tripLine = `${trip.reference} · ${formatDateOnly(trip.local_date)}, ${trip.started_at ? formatTime(trip.started_at) : ''} · ${startLabel(trip)} → ${endLabel(trip)}`;
    const validate = (): boolean => {
        const found: Record<string, string> = {};
        if (!form.owner) found.owner = 'Choose the coach or review owner.';
        if (!/^\d{4}-\d{2}-\d{2}$/.test(form.date))
            found.date = 'Choose the review date.';
        else if (form.date < today)
            found.date = 'Choose today or a later date.';
        if (!form.outcome.trim())
            found.outcome = 'Record the expected outcome and actions.';
        setLocalErrors(found);
        return Object.keys(found).length === 0;
    };
    const submit = async () => {
        if (!command.uncertain && !validate()) {
            setStep(0);
            return;
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}/reminders`,
            {
                title: `Coaching review · ${trip.reference}`,
                action_text: `${form.outcome.trim()}\n\n${tripLine}`.slice(
                    0,
                    2000,
                ),
                source_type: 'vehicle',
                source_id: null,
                remind_local: `${form.date}T09:00`,
                owner_user_id: form.owner,
                backup_user_id: null,
                repeat_months: 0,
            },
        );
        if (result) {
            setSaved(true);
            onSaved();
        }
    };
    // Server field errors belong to the first step.
    useEffect(() => {
        if (Object.keys(serverErrors).length) setStep(0);
    }, [serverErrors]);
    const owner = workspace.people.find((person) => person.id === form.owner);

    return (
        <WorkspaceWizard
            title="Create coaching follow-up"
            description={`${vehicle.name}: a follow-up for the person who reviews this trip with the driver.`}
            railIcon={Clock3}
            railSub={vehicle.registration_number ?? vehicle.asset_tag ?? ''}
            steps={STEPS}
            step={step}
            setStep={setStep}
            pct={Math.round(
                ([!!form.owner, !!form.date, !!form.outcome.trim()].filter(
                    Boolean,
                ).length /
                    3) *
                    100,
            )}
            context={{ name: vehicle.name, detail: tripLine }}
            command={command}
            dirty={JSON.stringify(form) !== JSON.stringify(initial)}
            saved={saved}
            submitLabel="Create coaching record"
            onValidateStep={(at) => (at === 0 ? validate() : true)}
            onSubmit={submit}
            onClose={onClose}
            onReload={onClose}
            errorKey={JSON.stringify(errors)}
            success={
                <WizardSuccess
                    title="Coaching follow-up created"
                    blurb={`A reminder is set for ${owner?.name ?? 'the owner'} on ${formatDateOnly(form.date)} at 9:00 am. It shows in this vehicle's reminders and in All Tasks. The trip and its events are unchanged.`}
                    onClose={onClose}
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Record the concern and the outcome you expect, with the
                        source trip kept alongside.
                    </p>
                    <div className="vehicle-wizard-fields">
                        <WizardField
                            id="coach-owner"
                            label="Coach or review owner"
                            error={errors.owner}
                            hint="Current staff at this vehicle's site."
                        >
                            <PersonPicker
                                id="coach-owner"
                                label="Coach or review owner"
                                value={form.owner}
                                people={workspace.people}
                                onChange={(value) => update('owner', value)}
                                invalid={!!errors.owner}
                                describedBy={
                                    errors.owner
                                        ? 'coach-owner-error'
                                        : undefined
                                }
                            />
                        </WizardField>
                        <WizardField
                            id="coach-date"
                            label="Review date"
                            error={errors.date}
                            hint="The reminder is due at 9:00 am, Pacific/Auckland."
                        >
                            <DatePicker
                                id="coach-date"
                                label="Review date"
                                value={form.date}
                                onChange={(value) => update('date', value)}
                                invalid={!!errors.date}
                                describedBy={
                                    errors.date ? 'coach-date-error' : undefined
                                }
                            />
                        </WizardField>
                    </div>
                    <WizardField
                        id="coach-outcome"
                        label="Expected outcome and actions"
                        error={errors.outcome}
                    >
                        <Textarea
                            {...fieldProps('coach-outcome', errors.outcome)}
                            rows={4}
                            maxLength={1500}
                            value={form.outcome}
                            onChange={(event) =>
                                update('outcome', event.target.value)
                            }
                        />
                    </WizardField>
                </div>
            )}
            {step === 1 && (
                <ReviewCard
                    icon={Clock3}
                    title="Coaching follow-up"
                    onEdit={() => setStep(0)}
                >
                    <ReviewRow label="Trip" value={tripLine} />
                    <ReviewRow label="Owner" value={owner?.name} />
                    <ReviewRow
                        label="Review"
                        value={
                            form.date
                                ? `${formatDateOnly(form.date)}, 9:00 am · Pacific/Auckland`
                                : undefined
                        }
                    />
                    <ReviewRow
                        label="Expected outcome"
                        value={form.outcome.trim() || undefined}
                    />
                </ReviewCard>
            )}
        </WorkspaceWizard>
    );
}
