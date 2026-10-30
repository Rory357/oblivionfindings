import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { workerTimeOffsets } from '@/lib/datetime';

export function actualEventInstant(
    local: string,
    selectedOffset: string,
): string {
    const offsets = workerTimeOffsets(local);
    const offset =
        offsets.length === 1
            ? offsets[0]
            : offsets.includes(selectedOffset)
              ? selectedOffset
              : '';
    return offset ? local + ':00' + offset : '';
}

export function ActualEventTimeField({
    id,
    label,
    value,
    offset,
    onChange,
    error,
}: {
    id: string;
    label: string;
    value: string;
    offset: string;
    onChange: (value: string, offset: string) => void;
    error?: string;
}) {
    const offsets = workerTimeOffsets(value);
    return (
        <div className="space-y-2">
            <DateTimeField
                compact
                id={id}
                label={label}
                value={value}
                error={error}
                onChange={(next) => onChange(next, '')}
            />
            {offsets.length > 1 && (
                <div className="space-y-1.5">
                    <Label htmlFor={id + '-offset'}>
                        Which occurrence of this time?
                    </Label>
                    <Select
                        value={offset}
                        onValueChange={(next) => onChange(value, next)}
                    >
                        <SelectTrigger id={id + '-offset'}>
                            <SelectValue placeholder="Choose the clock reading" />
                        </SelectTrigger>
                        <SelectContent>
                            {offsets.map((item, index) => (
                                <SelectItem key={item} value={item}>
                                    {index === 0 ? 'First' : 'Second'}{' '}
                                    occurrence · UTC{item}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            )}
            {value && offsets.length === 0 && (
                <p className="text-caption text-status-critical" role="alert">
                    Choose a valid New Zealand time. This clock reading does not
                    exist.
                </p>
            )}
        </div>
    );
}
