import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import type { PackStockContext } from '@/components/medications/stock-pack-fields';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { workerTimeOffsets } from '@/lib/datetime';

export type CountCoverage = {
    start: string;
    startOffset: string;
    end: string;
    endOffset: string;
    entries: number[];
};
export const emptyCountCoverage = (): CountCoverage => ({
    start: '',
    startOffset: '',
    end: '',
    endOffset: '',
    entries: [],
});
export function coverageInstant(local: string, selected: string) {
    const options = workerTimeOffsets(local);
    const offset =
        options.length === 1
            ? options[0]
            : options.includes(selected)
              ? selected
              : '';
    return offset ? `${local}:00${offset}` : '';
}
export function coverageError(value: CountCoverage): string | null {
    if (!value.entries.length && !value.start && !value.end) return null;
    const start = coverageInstant(value.start, value.startOffset),
        end = coverageInstant(value.end, value.endOffset);
    if (!value.entries.length)
        return 'Select the paper entries this physical count covers.';
    if (!start || !end)
        return 'Choose both coverage times, including which clock reading if it occurred twice.';
    return Date.parse(start) >= Date.parse(end)
        ? 'The coverage end must follow its start.'
        : null;
}
export function CountCoverageFields({
    stock,
    value,
    onChange,
    id,
}: {
    stock: PackStockContext;
    value: CountCoverage;
    onChange: (value: CountCoverage) => void;
    id: string;
}) {
    if (!stock.paper_entries?.length) return null;
    return (
        <fieldset className="min-w-0 space-y-3 rounded-lg border p-4">
            <legend className="text-section-title">
                Paper recovery covered by this count
            </legend>
            <p className="text-subtle">
                Optional. Select only paper entries checked against this
                physical closing count. They still need their own clinical and
                stock reviews.
            </p>
            {stock.paper_entries.map((entry) => (
                <div key={entry.id} className="flex items-start gap-3">
                    <Checkbox
                        id={`${id}-entry-${entry.id}`}
                        checked={value.entries.includes(entry.id)}
                        onCheckedChange={(checked) =>
                            onChange({
                                ...value,
                                entries:
                                    checked === true
                                        ? [...value.entries, entry.id]
                                        : value.entries.filter(
                                              (item) => item !== entry.id,
                                          ),
                            })
                        }
                    />
                    <Label htmlFor={`${id}-entry-${entry.id}`}>
                        {entry.label}
                    </Label>
                </div>
            ))}
            {(['start', 'end'] as const).map((key) => {
                const offsets = workerTimeOffsets(value[key]);
                const offsetKey = key === 'start' ? 'startOffset' : 'endOffset';
                return (
                    <div key={key} className="space-y-2">
                        <DateTimeField
                            id={`${id}-${key}`}
                            label={`Coverage ${key}`}
                            value={value[key]}
                            onChange={(local) =>
                                onChange({
                                    ...value,
                                    [key]: local,
                                    [offsetKey]: '',
                                })
                            }
                            compact
                        />
                        {offsets.length > 1 && (
                            <>
                                <Label htmlFor={`${id}-${key}-offset`}>
                                    Which clock reading?
                                </Label>
                                <Select
                                    value={value[offsetKey]}
                                    onValueChange={(offset) =>
                                        onChange({
                                            ...value,
                                            [offsetKey]: offset,
                                        })
                                    }
                                >
                                    <SelectTrigger id={`${id}-${key}-offset`}>
                                        <SelectValue placeholder="Choose the confirmed clock reading" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {offsets.map((offset, index) => (
                                            <SelectItem
                                                key={offset}
                                                value={offset}
                                            >
                                                {index === 0
                                                    ? 'First'
                                                    : 'Second'}{' '}
                                                reading · UTC{offset}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </>
                        )}
                        {value[key] && offsets.length === 0 && (
                            <p role="alert" className="text-status-critical">
                                Choose a complete NZ date and time that exists.
                            </p>
                        )}
                    </div>
                );
            })}
        </fieldset>
    );
}
