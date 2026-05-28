import { Check, ChevronDown, Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';

import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export type EntityFilterOption = {
    id: number;
    name: string;
    description?: string | null;
};

export type EntityFilterProps = {
    label: string;
    allLabel: string;
    items: EntityFilterOption[];
    value: number | null;
    onChange: (next: number | null) => void;
    onDark?: boolean;
    className?: string;
    /**
     * Plural label used in placeholders/empty states.
     * Defaults to `label + 's'` (e.g. "client" → "clients"),
     * but English uncountables ("staff") need an explicit override.
     */
    pluralLabel?: string;
};

export function EntityFilter({
    label,
    allLabel,
    items,
    value,
    onChange,
    onDark = false,
    className,
    pluralLabel,
}: EntityFilterProps) {
    const [open, setOpen] = useState(false);
    const selected = useMemo(
        () => items.find((item) => item.id === value) ?? null,
        [items, value],
    );
    const singular = label.toLowerCase();
    const plural = (pluralLabel ?? `${label}s`).toLowerCase();
    const noun = items.length === 1 ? singular : plural;

    const triggerClass = onDark
        ? cn(
              'inline-flex items-center gap-1.5 rounded-full border border-primary-foreground/30 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20',
              selected &&
                  'border-primary-foreground bg-primary-foreground text-primary',
          )
        : cn(
              'inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-3 py-1.5 text-xs font-semibold text-foreground transition-colors hover:bg-accent',
              selected && 'border-primary bg-primary text-primary-foreground',
          );

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    aria-haspopup="listbox"
                    aria-expanded={open}
                    aria-label={`${label} filter: ${selected ? selected.name : `${allLabel} · ${items.length}`}`}
                    className={cn(triggerClass, className)}
                >
                    <Search className="h-3.5 w-3.5" aria-hidden="true" />
                    <span className="max-w-[200px] truncate">
                        {selected
                            ? selected.name
                            : `${allLabel} · ${items.length}`}
                    </span>
                    {selected ? (
                        <button
                            type="button"
                            aria-label={`Clear ${label} filter`}
                            className={cn(
                                'ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full',
                                onDark
                                    ? 'hover:bg-primary/30'
                                    : 'hover:bg-primary-foreground/20',
                            )}
                            onClick={(e) => {
                                e.stopPropagation();
                                onChange(null);
                            }}
                        >
                            <X className="h-3 w-3" />
                        </button>
                    ) : (
                        <ChevronDown className="h-3 w-3 opacity-70" />
                    )}
                </button>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                className="w-[300px] p-0"
                sideOffset={6}
            >
                <Command>
                    <CommandInput
                        placeholder={`Search ${items.length} ${noun}…`}
                    />
                    <CommandList>
                        <CommandEmpty>No {plural} match.</CommandEmpty>
                        <CommandGroup>
                            <CommandItem
                                value={`__all__ ${allLabel}`}
                                onSelect={() => {
                                    onChange(null);
                                    setOpen(false);
                                }}
                                className="flex items-center gap-2"
                            >
                                <Check
                                    className={cn(
                                        'h-4 w-4',
                                        selected
                                            ? 'opacity-0'
                                            : 'text-primary opacity-100',
                                    )}
                                />
                                <span className="font-medium">{allLabel}</span>
                            </CommandItem>
                            {items.map((item) => (
                                <CommandItem
                                    key={item.id}
                                    value={`${item.name} ${item.description ?? ''}`}
                                    onSelect={() => {
                                        onChange(item.id);
                                        setOpen(false);
                                    }}
                                    className="flex items-center gap-2"
                                >
                                    <Check
                                        className={cn(
                                            'h-4 w-4',
                                            selected?.id === item.id
                                                ? 'text-primary opacity-100'
                                                : 'opacity-0',
                                        )}
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-[13px] leading-tight">
                                            {item.name}
                                        </span>
                                        {item.description ? (
                                            <span className="block truncate text-[10.5px] text-muted-foreground">
                                                {item.description}
                                            </span>
                                        ) : null}
                                    </span>
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

export default EntityFilter;
