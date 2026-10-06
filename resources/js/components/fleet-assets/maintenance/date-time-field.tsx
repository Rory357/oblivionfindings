import { Button } from '@/components/ui/button';
import { useCompactDateTime } from '@/components/ui/date-time-presentation';
import { formatDateOnly } from '@/lib/datetime';
import { DatePicker } from './date-picker';
import { TimePicker, displayTime } from './time-picker';

// Preserve local date/time parts without converting through the browser timezone.
// Partial values are form drafts only; the parent rejects them before recording.
export const validLocalDateTime = (value: string) =>
    /^\d{4}-\d{2}-\d{2}T([01]\d|2[0-3]):[0-5]\d$/.test(value);

export function localDateTimeLabel(value?: string) {
    if (!value) return 'Not provided';
    if (!validLocalDateTime(value))
        return 'Incomplete — choose a date and time';
    const [date, time] = value.split('T');
    return `${formatDateOnly(date)} · ${displayTime(time)} · Pacific/Auckland`;
}

export function DateTimeField({
    id,
    label,
    value,
    onChange,
    error,
    hint,
    clearable = true,
    compact: compactOverride,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    hint?: string;
    /** Show "Clear date and time" once there is a value (the default). Pass
     *  false for a required date and time that must not be emptied. */
    clearable?: boolean;
    /** Defaults to compact inside dialogs and sheets; retains the same pickers. */
    compact?: boolean;
}) {
    const compact = useCompactDateTime(compactOverride);
    const [date = '', time = ''] = value.split('T');
    const update = (nextDate: string, nextTime: string) =>
        onChange(nextDate || nextTime ? `${nextDate}T${nextTime}` : '');
    const description =
        [hint && `${id}-hint`, error && `${id}-error`]
            .filter(Boolean)
            .join(' ') || undefined;
    return (
        <fieldset
            className="date-time-field"
            data-compact={compact || undefined}
        >
            <legend>
                {label} <span>Pacific/Auckland</span>
            </legend>
            <div className="inline-fields">
                <div className="field">
                    <label htmlFor={`${id}-date`}>
                        {compact ? 'Date' : `${label} date`}
                    </label>
                    <DatePicker
                        compact={compact}
                        id={`${id}-date`}
                        label={`${label} date`}
                        value={date}
                        invalid={!!error && !date}
                        describedBy={description}
                        onChange={(nextDate) => update(nextDate, time)}
                    />
                </div>
                <div className="field">
                    <label htmlFor={`${id}-time`}>
                        {compact ? 'Time' : `${label} time`}
                    </label>
                    <TimePicker
                        compact={compact}
                        id={`${id}-time`}
                        label={`${label} time`}
                        value={time}
                        invalid={!!error && !time}
                        describedBy={description}
                        onChange={(nextTime) => update(date, nextTime)}
                    />
                </div>
            </div>
            {clearable && value && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="mt-2"
                    aria-label={`${label}: Clear date and time`}
                    onClick={() => onChange('')}
                >
                    Clear date and time
                </Button>
            )}
            {hint && (
                <p id={`${id}-hint`} className="muted">
                    {hint}
                </p>
            )}
            {error && (
                <p id={`${id}-error`} className="upload-error" role="alert">
                    {error}
                </p>
            )}
        </fieldset>
    );
}
