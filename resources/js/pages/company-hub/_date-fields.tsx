import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import { useId } from 'react';

export function HubDateField({
    label,
    value,
    onChange,
    disabled = false,
    id,
    describedBy,
    allowClear = true,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    disabled?: boolean;
    id?: string;
    describedBy?: string;
    allowClear?: boolean;
}) {
    const generated = useId();
    return (
        <DatePicker
            compact
            id={id ?? generated}
            label={label}
            value={value}
            onChange={(next) => {
                if (!disabled) onChange(next);
            }}
            disabled={disabled}
            describedBy={describedBy}
            allowClear={allowClear}
        />
    );
}

export function HubDateTimeField({
    label,
    value,
    onChange,
    disabled = false,
    id,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    disabled?: boolean;
    id?: string;
}) {
    const generated = useId();
    return (
        <fieldset disabled={disabled} className="hub-date-time-control min-w-0">
            <DateTimeField
                compact
                id={id ?? generated}
                label={label}
                value={value}
                onChange={(next) => {
                    if (!disabled) onChange(next);
                }}
            />
        </fieldset>
    );
}
