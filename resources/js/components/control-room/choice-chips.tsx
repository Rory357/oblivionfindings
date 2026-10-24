import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Check } from 'lucide-react';

/** Every known option, plus any value already saved, so a save never drops it. */
export function withSaved(
    options: readonly string[],
    saved: readonly string[],
): string[] {
    return [...options, ...saved.filter((value) => !options.includes(value))];
}

/**
 * Tick chips for choosing any number of options — the same chips as
 * Control Room › Response targets. A chosen chip shows a tick as well as the
 * brand tint, so colour is never the only sign. What "none chosen" means is
 * the caller's to say.
 */
export function ChoiceChips({
    id,
    label,
    labelledBy,
    describedBy,
    invalid = false,
    options,
    value,
    onChange,
    format,
}: {
    id?: string;
    /** Names the group when no visible label is linked by `labelledBy`. */
    label: string;
    labelledBy?: string;
    describedBy?: string;
    invalid?: boolean;
    options: readonly string[];
    value: string[];
    onChange: (next: string[]) => void;
    format: (value: string) => string;
}) {
    return (
        <ToggleGroup
            id={id}
            type="multiple"
            variant="outline"
            aria-label={labelledBy ? undefined : label}
            aria-labelledby={labelledBy}
            aria-describedby={describedBy}
            aria-invalid={invalid || undefined}
            value={value}
            onValueChange={onChange}
            className="flex flex-wrap justify-start gap-1.5 rounded-none shadow-none"
        >
            {options.map((option) => (
                <ToggleGroupItem
                    key={option}
                    value={option}
                    className="rounded-full! border! px-3 data-[state=on]:border-primary"
                >
                    {value.includes(option) ? (
                        <Check aria-hidden="true" className="size-3.5" />
                    ) : null}
                    {format(option)}
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}
