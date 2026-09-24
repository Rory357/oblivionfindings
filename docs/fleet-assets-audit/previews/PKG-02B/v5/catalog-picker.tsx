import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Check, ChevronsUpDown, Plus } from 'lucide-react';
import { useState } from 'react';

const customCatalogs = new Map<string, string[]>();
// Catalog options may be created explicitly. Record relationships use Picker instead.
export function CatalogPicker({
    label,
    value,
    onChange,
    options,
    unit = '',
    numeric = false,
}: {
    label: string;
    value: string;
    onChange: (v: string) => void;
    options: string[];
    unit?: string;
    numeric?: boolean;
}) {
    const [open, setOpen] = useState(false),
        [query, setQuery] = useState('');
    const choices = Array.from(
        new Set([
            ...options,
            ...(customCatalogs.get(label) || []),
            ...(value ? [value] : []),
        ]),
    );
    const candidate = query.trim().replace(/\s+/g, ' ');
    const exists = choices.some(
        (x) => x.toLowerCase() === candidate.toLowerCase(),
    );
    const valid =
        candidate.length > 0 &&
        (!numeric ||
            (/^\d+$/.test(candidate) &&
                +candidate > 0 &&
                +candidate <= 1000000));
    const format = (x: string) =>
        x === '0' && unit === 'months'
            ? 'One-off'
            : x +
              (unit
                  ? ' ' + (x === '1' && unit === 'months' ? 'month' : unit)
                  : '');
    const choose = (v: string) => {
        if (!options.some((x) => x.toLowerCase() === v.toLowerCase()))
            customCatalogs.set(
                label,
                Array.from(new Set([...(customCatalogs.get(label) || []), v])),
            );
        onChange(v);
        setOpen(false);
        setQuery('');
    };
    return (
        <div className="field">
            <label>{label}</label>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        variant="outline"
                        role="combobox"
                        aria-label={label}
                        aria-expanded={open}
                        className="picker-button"
                    >
                        <span>
                            {value === '0' && unit === 'months'
                                ? 'One-off'
                                : value
                                  ? `${value}${unit ? ' ' + unit : ''}`
                                  : 'Choose or add custom…'}
                        </span>
                        <ChevronsUpDown size={15} />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    className="p-0"
                    align="start"
                    style={{ width: 'min(80vw,410px)' }}
                >
                    <Command shouldFilter={false}>
                        <CommandInput
                            aria-label={`Search ${label.toLowerCase()}`}
                            placeholder={
                                numeric
                                    ? 'Search or enter a whole number…'
                                    : 'Search or enter a custom name…'
                            }
                            value={query}
                            onValueChange={setQuery}
                        />
                        <CommandList>
                            <CommandEmpty>No matching options.</CommandEmpty>
                            {choices
                                .filter((x) =>
                                    format(x)
                                        .toLowerCase()
                                        .includes(query.toLowerCase()),
                                )
                                .map((x) => (
                                    <CommandItem
                                        key={x}
                                        value={x}
                                        onSelect={() => choose(x)}
                                    >
                                        <Check
                                            size={14}
                                            style={{
                                                opacity: x === value ? 1 : 0,
                                            }}
                                        />
                                        {format(x)}
                                    </CommandItem>
                                ))}
                            {valid && !exists && (
                                <CommandItem
                                    value={'create ' + candidate}
                                    onSelect={() => choose(candidate)}
                                >
                                    <Plus size={15} />
                                    Add custom: {candidate} {unit}
                                </CommandItem>
                            )}
                        </CommandList>
                        <p className="catalog-hint">
                            {numeric
                                ? 'Positive whole numbers. Presets are examples; confirm the appropriate value.'
                                : 'Custom options are local to this preview; catalogue permissions apply in the application.'}
                        </p>
                    </Command>
                </PopoverContent>
            </Popover>
        </div>
    );
}
