import { actualEventInstant } from '@/components/clinical/actual-event-time-field';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { formatDateTimeLong } from '@/lib/datetime';
import { DRILL_REGISTER } from '@/lib/health-safety/drill-navigation';
import { outcomeMeta } from '@/pages/health-safety/drills/shared';
import { DrillCommandFooter, useDrillCommandForm } from './drill-command-form';
import { DrillTimeField } from './drill-time-field';
import type { DrillPickers, DrillWorkspaceDetail } from './types';

export function DrillCompletionForm({
    detail,
    pickers,
    returnTo,
    onSaved,
    onCancel,
}: {
    detail: DrillWorkspaceDetail;
    pickers: DrillPickers;
    returnTo: string;
    onSaved: () => void;
    onCancel: () => void;
}) {
    const { form, guards, save, failure, setFailure } = useDrillCommandForm(
        {
            completed_at: '',
            time_offset: '',
            outcome: '',
            duration_minutes: '',
            evacuation_time_seconds: '',
            weather_conditions: '',
            total_participants: '',
            residents_evacuated: '',
            observer_notes: '',
            improvements_identified: '',
            all_areas_checked: false,
            assembly_point_reached: false,
            roll_call_completed: false,
        },
        detail,
        onSaved,
    );
    if (!detail.can.complete)
        return (
            <p role="alert">This drill is no longer available to complete.</p>
        );
    return (
        <form
            className="space-y-5"
            onSubmit={(event) => {
                event.preventDefault();
                const instant = actualEventInstant(
                    form.data.completed_at,
                    form.data.time_offset,
                );
                if (!instant || !form.data.outcome) {
                    setFailure(
                        'Record a valid New Zealand completion time and choose the actual outcome.',
                    );
                    return;
                }
                if (
                    detail.started_at &&
                    new Date(instant).getTime() <
                        new Date(detail.started_at).getTime()
                ) {
                    setFailure(
                        'Completion cannot be earlier than the recorded start.',
                    );
                    return;
                }
                form.transform(({ time_offset: _offset, ...data }) => ({
                    ...data,
                    ...guards,
                    return_to: returnTo,
                    section: 'run',
                    action: 'complete',
                    completed_at: instant,
                    duration_minutes:
                        data.duration_minutes === ''
                            ? null
                            : Number(data.duration_minutes),
                    evacuation_time_seconds:
                        data.evacuation_time_seconds === ''
                            ? null
                            : Number(data.evacuation_time_seconds),
                    total_participants:
                        data.total_participants === ''
                            ? null
                            : Number(data.total_participants),
                    residents_evacuated:
                        data.residents_evacuated === ''
                            ? null
                            : Number(data.residents_evacuated),
                }));
                save('post', `${DRILL_REGISTER}/${detail.id}/complete`);
            }}
        >
            <h2 className="text-section-title">Record drill completion</h2>
            <p className="text-subtle">
                Started{' '}
                {formatDateTimeLong(detail.started_at, 'at an unrecorded time')}
                . Record what happened, including unsuccessful checks. Findings
                and corrective actions remain open until their own work is
                resolved.
            </p>
            <DrillTimeField
                label="Completed at"
                value={form.data.completed_at}
                offset={form.data.time_offset}
                onChange={(value, offset) =>
                    form.setData((data) => ({
                        ...data,
                        completed_at: value,
                        time_offset: offset,
                    }))
                }
            />
            <Field label="Actual outcome" required>
                <SelectInput
                    frontline
                    value={form.data.outcome}
                    placeholder="Choose the recorded outcome"
                    onChange={(value) => form.setData('outcome', value)}
                    options={pickers.outcomes.map((value) => ({
                        value,
                        label: outcomeMeta(value)?.label ?? value,
                    }))}
                />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
                {(
                    [
                        ['duration_minutes', 'Duration (minutes)', 1440],
                        [
                            'evacuation_time_seconds',
                            'Evacuation time (seconds)',
                            86400,
                        ],
                        [
                            'total_participants',
                            'Total participants recorded',
                            9999,
                        ],
                        [
                            'residents_evacuated',
                            'Residents evacuated — recorded count',
                            9999,
                        ],
                    ] as const
                ).map(([key, label, max]) => (
                    <Field key={key} label={label}>
                        <Input
                            type="number"
                            className="frontline-tap"
                            min={0}
                            max={max}
                            step={1}
                            value={form.data[key]}
                            onChange={(event) =>
                                form.setData(key, event.target.value)
                            }
                        />
                    </Field>
                ))}
            </div>
            <p className="text-subtle">
                Use observed counts. Leave unknown values blank. Participant
                totals alone do not establish that every resident was evacuated.
            </p>
            <fieldset className="space-y-2">
                <legend className="mb-2 font-medium">
                    Checks confirmed during this drill
                </legend>
                {(
                    [
                        ['assembly_point_reached', 'Assembly point reached'],
                        ['roll_call_completed', 'Roll-call completed'],
                        ['all_areas_checked', 'All areas checked'],
                    ] as const
                ).map(([key, label]) => (
                    <label
                        key={key}
                        className="frontline-tap flex items-center gap-3"
                    >
                        <Checkbox
                            checked={form.data[key]}
                            onCheckedChange={(value) =>
                                form.setData(key, value === true)
                            }
                        />
                        {label}
                    </label>
                ))}
            </fieldset>
            <p className="text-subtle">
                Unchecked items will be recorded as not confirmed.
            </p>
            <Field label="Weather conditions">
                <Input
                    className="frontline-tap"
                    maxLength={255}
                    value={form.data.weather_conditions}
                    onChange={(event) =>
                        form.setData('weather_conditions', event.target.value)
                    }
                />
            </Field>
            <Field label="Observer notes">
                <Textarea
                    rows={4}
                    maxLength={5000}
                    value={form.data.observer_notes}
                    onChange={(event) =>
                        form.setData('observer_notes', event.target.value)
                    }
                />
            </Field>
            <Field label="Improvements identified">
                <Textarea
                    rows={4}
                    maxLength={5000}
                    value={form.data.improvements_identified}
                    onChange={(event) =>
                        form.setData(
                            'improvements_identified',
                            event.target.value,
                        )
                    }
                />
            </Field>
            <p className="text-subtle">
                Use Findings to assign each improvement and track its
                resolution. Completion records the run; it does not confirm
                readiness or that someone has responded.
            </p>
            <DrillCommandFooter
                label="Record completion"
                processing={form.processing}
                failure={failure}
                errors={form.errors}
                onCancel={onCancel}
            />
        </form>
    );
}
