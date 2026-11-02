import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { Input } from '@/components/ui/input';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { workerTimeOffsets } from '@/lib/datetime';
import { useId } from 'react';

/**
 * A New Zealand date and time (`YYYY-MM-DDTHH:mm`), with the clock occurrence asked for when daylight saving makes a
 * time happen twice. `pickers` (G4-12) uses the approved calendar button and clock picker side by side, with the
 * time zone shown under them; without it the field keeps its single date-and-time box.
 */
export function DrillTimeField({
    label,
    value,
    offset,
    onChange,
    required = true,
    pickers = false,
}: {
    label: string;
    value: string;
    offset: string;
    onChange: (value: string, offset: string) => void;
    required?: boolean;
    pickers?: boolean;
}) {
    const id = useId();
    const offsets = workerTimeOffsets(value);
    const [day = '', time = ''] = value ? value.split('T') : [];
    // Both halves are kept while one is still missing, so choosing the time first never loses it.
    const join = (nextDay: string, nextTime: string) => onChange(nextDay || nextTime ? `${nextDay}T${nextTime}` : '', '');
    return (
        <div className="space-y-3">
            {pickers ? (
                <fieldset className="space-y-2">
                    <legend className="mb-2 text-sm font-medium">
                        {label}
                        {required ? <span className="text-status-critical"> *</span> : null}
                    </legend>
                    <div className="flex flex-wrap items-start gap-3">
                        <DatePicker id={`${id}-day`} label={`${label} · date`} value={/^\d{4}-\d{2}-\d{2}$/.test(day) ? day : ''} compact onChange={(next) => join(next, time)} />
                        <TimePicker id={`${id}-time`} label={`${label} · time`} value={/^\d{2}:\d{2}$/.test(time) ? time : ''} compact onChange={(next) => join(day, next)} />
                    </div>
                    <p className="text-caption">New Zealand time</p>
                </fieldset>
            ) : (
                <Field label={`${label} (New Zealand time)`} required={required}>
                    <Input
                        type="datetime-local"
                        className="frontline-tap"
                        required={required}
                        value={value}
                        onChange={(event) => onChange(event.target.value, '')}
                    />
                </Field>
            )}
            {offsets.length > 1 && (
                <Field label="Which occurrence of this time?" required>
                    <SelectInput
                        frontline
                        value={offset}
                        placeholder="Choose the clock reading"
                        onChange={(next) => onChange(value, next)}
                        options={offsets.map((item, index) => ({
                            value: item,
                            label: `${index === 0 ? 'First' : 'Second'} occurrence · UTC${item}`,
                        }))}
                    />
                </Field>
            )}
            {value && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value) && offsets.length === 0 && (
                <p role="alert" className="text-sm text-status-critical">
                    Choose a valid New Zealand time. This clock reading does not
                    exist.
                </p>
            )}
        </div>
    );
}
