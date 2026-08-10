import { Check, ChevronDown, Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Button as GuardrailButton } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export type MultiEntityItem = {
    id: number;
    name: string;
    description?: string | null;
};

export type MultiEntityFilterProps = {
    /** Singular noun in headings/placeholders (e.g. "staff", "client"). */
    label: string;
    /** Pill label when nothing is selected (e.g. "All staff"). */
    allLabel: string;
    /**
     * Plural label used in placeholders/empty states. Defaults to `label + 's'`
     * (e.g. "client" → "clients"). English uncountables ("staff") need an explicit override.
     */
    pluralLabel?: string;
    items: MultiEntityItem[];
    /** Currently selected ids. Empty array = "All". */
    value: number[];
    onChange: (next: number[]) => void;
    /** Use the dark hero palette. */
    onDark?: boolean;
    className?: string;
};

/**
 * Multi-select pill filter modelled on {@link SiteFilter}, but generic over
 * any entity with an {id, name, description?} shape. Used to keep the
 * Shifts and Rostering hero footers visually identical.
 */
export function MultiEntityFilter({
    label,
    allLabel,
    pluralLabel,
    items,
    value,
    onChange,
    onDark = false,
    className,
}: MultiEntityFilterProps) {
    const [open, setOpen] = useState(false);
    const selectedSet = useMemo(() => new Set(value), [value]);
    const selectedCount = value.length;
    const allSelected = selectedCount === 0;
    const singular = label.toLowerCase();
    const plural = (pluralLabel ?? `${label}s`).toLowerCase();

    const triggerLabel = useMemo(() => {
        if (allSelected) return `${allLabel} · ${items.length}`;
        if (selectedCount === 1) {
            const found = items.find((item) => item.id === value[0]);
            return found ? found.name : `1 ${singular}`;
        }
        return `${selectedCount} ${plural}`;
    }, [allLabel, allSelected, items, plural, selectedCount, singular, value]);

    const toggle = (id: number) => {
        if (selectedSet.has(id)) {
            onChange(value.filter((v) => v !== id));
        } else {
            onChange([...value, id]);
        }
    };

    const clearAll = () => onChange([]);

    const triggerClass = onDark
        ? cn(
              'inline-flex items-center gap-1.5 rounded-full border border-primary-foreground/30 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20',
              !allSelected &&
                  'border-primary-foreground bg-primary-foreground text-primary',
          )
        : cn(
              'inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-3 py-1.5 text-xs font-semibold text-foreground transition-colors hover:bg-accent',
              !allSelected &&
                  'border-primary bg-primary text-primary-foreground',
          );

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverAnchor asChild>
                {/* The pill is a wrapper, not a button, so the clear action can
                    sit as a sibling of the trigger rather than nested inside it
                    (nested <button>s are invalid HTML and break hydration). */}
                <div className={cn(triggerClass, className)}>
                    <PopoverTrigger asChild>
                        <button
                            type="button"
                            aria-haspopup="listbox"
                            aria-expanded={open}
                            aria-label={`${label} filter: ${triggerLabel}`}
                            className="inline-flex items-center gap-1.5 rounded-full"
                        >
                            <Search
                                className="h-3.5 w-3.5"
                                aria-hidden="true"
                            />
                            <span className="max-w-[200px] truncate">
                                {triggerLabel}
                            </span>
                            {allSelected ? (
                                <ChevronDown className="h-3 w-3 opacity-70" />
                            ) : null}
                        </button>
                    </PopoverTrigger>
                    {!allSelected ? (
                        <GuardrailButton
                            unstyled
                            type="button"
                            aria-label={`Clear ${label} filter`}
                            className={cn(
                                'inline-flex h-4 w-4 items-center justify-center rounded-full',
                                onDark
                                    ? 'hover:bg-primary/30'
                                    : 'hover:bg-primary-foreground/20',
                            )}
                            onClick={clearAll}
                        >
                            <X className="h-3 w-3" />
                        </GuardrailButton>
                    ) : null}
                </div>
            </PopoverAnchor>
            <PopoverContent
                align="end"
                className="w-[300px] p-0"
                sideOffset={6}
            >
                <Command>
                    <CommandInput
                        placeholder={`Search ${items.length} ${items.length === 1 ? singular : plural}…`}
                    />
                    <CommandList>
                        <CommandEmpty>No {plural} match.</CommandEmpty>
                        <CommandGroup>
                            <CommandItem
                                value={`__all__ ${allLabel}`}
                                onSelect={clearAll}
                                className="flex items-center gap-2"
                            >
                                <span
                                    className={cn(
                                        'flex h-4 w-4 shrink-0 items-center justify-center rounded-sm border',
                                        allSelected
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-input bg-background',
                                    )}
                                    aria-hidden="true"
                                >
                                    {allSelected ? (
                                        <Check className="h-3 w-3" />
                                    ) : null}
                                </span>
                                <span className="flex-1 font-medium">
                                    {allLabel}
                                </span>
                                <span className="text-[10px] text-muted-foreground tabular-nums">
                                    {items.length}
                                </span>
                            </CommandItem>
                        </CommandGroup>
                        {items.length > 0 ? (
                            <>
                                <CommandSeparator />
                                <CommandGroup
                                    heading={
                                        selectedCount > 0
                                            ? `${label} · ${selectedCount} selected`
                                            : label
                                    }
                                >
                                    {items.map((item) => {
                                        const checked = selectedSet.has(
                                            item.id,
                                        );
                                        return (
                                            <CommandItem
                                                key={item.id}
                                                value={`${item.name} ${item.description ?? ''}`}
                                                onSelect={() => toggle(item.id)}
                                                className="flex items-center gap-2"
                                            >
                                                <span
                                                    className={cn(
                                                        'flex h-4 w-4 shrink-0 items-center justify-center rounded-sm border',
                                                        checked
                                                            ? 'border-primary bg-primary text-primary-foreground'
                                                            : 'border-input bg-background',
                                                    )}
                                                    aria-hidden="true"
                                                >
                                                    {checked ? (
                                                        <Check className="h-3 w-3" />
                                                    ) : null}
                                                </span>
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
                                        );
                                    })}
                                </CommandGroup>
                            </>
                        ) : null}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

export default MultiEntityFilter;
