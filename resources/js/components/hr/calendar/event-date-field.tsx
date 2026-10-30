import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import { Label } from '@/components/ui/label';
import { useId } from 'react';

/** Shared Rory controls; values remain NZ wall time until the event save. */
export function EventDateField({
    label,
    value,
    onChange,
    dateOnly = false,
    disabled = false,
    clearable = false,
    error,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    dateOnly?: boolean;
    disabled?: boolean;
    clearable?: boolean;
    error?: string;
}) {
    const id = useId();
    const change = (next: string) => {
        if (!disabled) onChange(next);
    };
    return (
        <fieldset disabled={disabled} className="min-w-0">
            {dateOnly ? (
                <div className="grid gap-2">
                    <Label htmlFor={id}>{label}</Label>
                    <DatePicker
                        compact
                        id={id}
                        label={label}
                        value={value}
                        onChange={change}
                        disabled={disabled}
                        allowClear={clearable}
                        invalid={!!error}
                        describedBy={error ? `${id}-error` : undefined}
                    />
                    {error && (
                        <p
                            id={`${id}-error`}
                            role="alert"
                            className="text-sm text-destructive"
                        >
                            {error}
                        </p>
                    )}
                </div>
            ) : (
                <DateTimeField
                    compact
                    id={id}
                    label={label}
                    value={value}
                    onChange={change}
                    clearable={clearable}
                    error={error}
                />
            )}
        </fieldset>
    );
}
