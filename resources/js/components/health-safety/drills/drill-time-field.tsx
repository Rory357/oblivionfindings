import { Input } from '@/components/ui/input';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { workerTimeOffsets } from '@/lib/datetime';

export function DrillTimeField({
    label,
    value,
    offset,
    onChange,
    required = true,
}: {
    label: string;
    value: string;
    offset: string;
    onChange: (value: string, offset: string) => void;
    required?: boolean;
}) {
    const offsets = workerTimeOffsets(value);
    return (
        <div className="space-y-3">
            <Field label={`${label} (New Zealand time)`} required={required}>
                <Input
                    type="datetime-local"
                    className="frontline-tap"
                    required={required}
                    value={value}
                    onChange={(event) => onChange(event.target.value, '')}
                />
            </Field>
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
            {value && offsets.length === 0 && (
                <p role="alert" className="text-sm text-status-critical">
                    Choose a valid New Zealand time. This clock reading does not
                    exist.
                </p>
            )}
        </div>
    );
}
