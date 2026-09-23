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

const storageKey = 'pkg02b.catalogues.v1';
function readCatalogs(): Record<string, string[]> {
    try {
        const raw = JSON.parse(localStorage.getItem(storageKey) || '{}');
        return Object.fromEntries(
            Object.entries(raw).filter(
                ([, v]) =>
                    Array.isArray(v) &&
                    v.every((x) => typeof x === 'string' && x.length <= 80),
            ),
        ) as Record<string, string[]>;
    } catch {
        return {};
    }
}
// Catalog options may be created explicitly. Record relationships use Picker instead.
export function CatalogPicker({
    label,
    value,
    onChange,
    options,
    unit = '',
    numeric = false,
    catalogKey,
    allowCreate = true,
}: {
    label: string;
    value: string;
    onChange: (v: string) => void;
    options: string[];
    unit?: string;
    numeric?: boolean;
    catalogKey?: string;
    allowCreate?: boolean;
}) {
    const [open, setOpen] = useState(false),
        [query, setQuery] = useState(''),
        [error, setError] = useState('');
    const key = catalogKey || label.replace(/ \(optional\)$/, '').toLowerCase();
    const choices = Array.from(
        new Set([
            ...options,
            ...(readCatalogs()[key] || []),
            ...(value ? [value] : []),
        ]),
    );
    const candidate = query.trim().replace(/\s+/g, ' ');
    const exists = choices.some(
        (x) => x.toLowerCase() === candidate.toLowerCase(),
    );
    const valid =
        candidate.length > 0 &&
        candidate.length <= 80 &&
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
    const choose = (v: string, create = false) => {
        if (create) {
            try {
                const catalogs = readCatalogs();
                const old = catalogs[key] || [];
                catalogs[key] = old.some(
                    (x) => x.toLowerCase() === v.toLowerCase(),
                )
                    ? old
                    : [...old, v];
                localStorage.setItem(storageKey, JSON.stringify(catalogs));
            } catch {
                setError(
                    'This browser could not save the new option. Retry or choose an existing option.',
                );
                return;
            }
        }
        onChange(v);
        setOpen(false);
        setQuery('');
        setError('');
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
                            {allowCreate && valid && !exists && (
                                <CommandItem
                                    value={'create ' + candidate}
                                    onSelect={() => choose(candidate, true)}
                                >
                                    <Plus size={15} />
                                    Add new: {candidate} {unit}
                                </CommandItem>
                            )}
                        </CommandList>
                        <p className="catalog-hint">
                            {!allowCreate
                                ? 'Choose an existing option. Catalogue additions require coordinator access.'
                                : numeric
                                  ? 'Positive whole numbers. Presets are examples; confirm the appropriate value.'
                                  : 'New options are saved in this browser for future selections, including after refresh.'}
                        </p>
                        {candidate.length > 80 && (
                            <p role="alert" className="catalog-hint">
                                Use 80 characters or fewer.
                            </p>
                        )}
                        {error && (
                            <p role="alert" className="catalog-hint">
                                {error}
                            </p>
                        )}
                    </Command>
                </PopoverContent>
            </Popover>
        </div>
    );
}
