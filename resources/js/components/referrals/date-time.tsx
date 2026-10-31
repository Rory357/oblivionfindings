import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import { Input } from '@/components/ui/input';
import { Field, FieldErr, SelectInput } from '@/components/wizard/primitives';
import { workerTimeOffsets } from '@/lib/datetime';

export function validReferralDateTime(value: string): boolean {
    const match = value.match(
        /^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2})(?::[0-5]\d)?([+-]\d{2}:\d{2})?$/,
    );
    if (!match) return false;
    const offsets = workerTimeOffsets(match[1]);
    return match[2] ? offsets.includes(match[2]) : offsets.length === 1;
}

export function ReferralDateTime({
    id,
    label,
    value,
    onChange,
    error,
    includeSeconds = false,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    includeSeconds?: boolean;
}) {
    const local = value.slice(0, 16);
    const offsets = workerTimeOffsets(local);
    const seconds = /^:\d{2}/.test(value.slice(16))
        ? value.slice(17, 19)
        : '00';
    const selected = value.match(/[+-]\d{2}:\d{2}$/)?.[0] ?? '';
    const precision = includeSeconds ? `:${seconds}` : '';
    return (
        <div className="space-y-2">
            <DateTimeField
                id={id}
                label={label}
                value={local}
                onChange={(next) => {
                    const choices = workerTimeOffsets(next);
                    onChange(
                        next +
                            precision +
                            (choices.length === 1 ? choices[0] : ''),
                    );
                }}
                error={error}
                clearable={false}
            />
            {includeSeconds && (
                <Field label={`${label} seconds`}>
                    <Input
                        type="number"
                        min={0}
                        max={59}
                        step={1}
                        value={seconds}
                        onChange={(event) => {
                            const next = event.target.value;
                            if (/^\d{1,2}$/.test(next) && Number(next) < 60)
                                onChange(
                                    `${local}:${next.padStart(2, '0')}${selected}`,
                                );
                        }}
                    />
                </Field>
            )}
            {/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/.test(local) &&
                offsets.length === 0 && (
                    <FieldErr>
                        This time does not exist in NZ time (a daylight-saving
                        change). Choose another date or time.
                    </FieldErr>
                )}
            {offsets.length > 1 && (
                <Field label="Which occurrence of this time?">
                    <SelectInput
                        ariaLabel={`${label}: daylight-saving occurrence`}
                        value={selected}
                        placeholder="Choose the first or second occurrence"
                        options={offsets.map((offset, index) => ({
                            value: offset,
                            label: `${index === 0 ? 'First' : 'Second'} occurrence (UTC${offset})`,
                        }))}
                        onChange={(offset) =>
                            onChange(local + precision + offset)
                        }
                    />
                </Field>
            )}
        </div>
    );
}
