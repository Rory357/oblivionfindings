import { Check, ChevronDown, MapPin, X } from 'lucide-react';
import { useMemo, useState } from 'react';

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
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export type SiteOption = {
    id: number;
    name: string;
    type?: string | null;
};

export type SiteFilterProps = {
    sites: SiteOption[];
    /** Currently selected site IDs. Empty array = "All sites". */
    value: number[];
    onChange: (next: number[]) => void;
    /** Use the dark hero palette */
    onDark?: boolean;
    className?: string;
};

export function SiteFilter({
    sites,
    value,
    onChange,
    onDark = false,
    className,
}: SiteFilterProps) {
    const [open, setOpen] = useState(false);
    const selectedSet = useMemo(() => new Set(value), [value]);
    const selectedCount = value.length;
    const allSelected = selectedCount === 0;

    const triggerLabel = useMemo(() => {
        if (allSelected) return `All sites · ${sites.length}`;
        if (selectedCount === 1) {
            const s = sites.find((x) => x.id === value[0]);
            return s ? s.name : '1 site';
        }
        return `${selectedCount} sites`;
    }, [allSelected, selectedCount, sites, value]);

    const toggle = (id: number) => {
        if (selectedSet.has(id)) {
            onChange(value.filter((v) => v !== id));
        } else {
            onChange([...value, id]);
        }
    };

    const selectAll = () => onChange([]);
    const clearAll = () => onChange([]);

    const triggerClass = onDark
        ? cn(
              'inline-flex items-center gap-1.5 rounded-full border border-primary-foreground/30 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20',
              !allSelected &&
                  'border-primary-foreground bg-primary-foreground text-primary',
          )
        : cn(
              'inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-3 py-1.5 text-xs font-semibold text-foreground transition-colors hover:bg-accent',
              !allSelected && 'border-primary bg-primary text-primary-foreground',
          );

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    aria-haspopup="listbox"
                    aria-expanded={open}
                    className={cn(triggerClass, className)}
                >
                    <MapPin className="h-3.5 w-3.5" aria-hidden="true" />
                    <span className="max-w-[200px] truncate">{triggerLabel}</span>
                    {!allSelected ? (
                        <button
                            type="button"
                            aria-label="Clear site filter"
                            className={cn(
                                'ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full',
                                onDark
                                    ? 'hover:bg-primary/30'
                                    : 'hover:bg-primary-foreground/20',
                            )}
                            onClick={(e) => {
                                e.stopPropagation();
                                clearAll();
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
                        placeholder={`Search ${sites.length} site${sites.length === 1 ? '' : 's'}…`}
                    />
                    <CommandList>
                        <CommandEmpty>No sites match.</CommandEmpty>
                        <CommandGroup>
                            <CommandItem
                                value="__all__ all sites"
                                onSelect={selectAll}
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
                                    All sites
                                </span>
                                <span className="text-[10px] tabular-nums text-muted-foreground">
                                    {sites.length}
                                </span>
                            </CommandItem>
                        </CommandGroup>
                        {sites.length > 0 ? (
                            <>
                                <CommandSeparator />
                                <CommandGroup
                                    heading={
                                        selectedCount > 0
                                            ? `Sites · ${selectedCount} selected`
                                            : 'Sites'
                                    }
                                >
                                    {sites.map((s) => {
                                        const checked = selectedSet.has(s.id);
                                        return (
                                            <CommandItem
                                                key={s.id}
                                                value={`${s.name} ${s.type ?? ''}`}
                                                onSelect={() => toggle(s.id)}
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
                                                        {s.name}
                                                    </span>
                                                    {s.type ? (
                                                        <span className="block truncate text-[10.5px] text-muted-foreground">
                                                            {s.type}
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
                    {selectedCount > 0 ? (
                        <div className="flex items-center justify-between border-t border-border px-2.5 py-2 text-[11px]">
                            <span className="text-muted-foreground tabular-nums">
                                {selectedCount} of {sites.length} selected
                            </span>
                            <button
                                type="button"
                                onClick={clearAll}
                                className="font-semibold text-primary hover:underline"
                            >
                                Clear
                            </button>
                        </div>
                    ) : null}
                </Command>
            </PopoverContent>
        </Popover>
    );
}

export default SiteFilter;
