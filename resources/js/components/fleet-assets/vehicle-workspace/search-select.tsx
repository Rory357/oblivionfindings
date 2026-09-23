import { Button } from '@/components/ui/button';
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
import { Check, ChevronsUpDown, Plus, Search } from 'lucide-react';
import { useId, useState } from 'react';

export type VehicleSelectOption = {
    value: string;
    label: string;
    description?: string;
};

/** Controlled choices come from their canonical server owner. Add new is explicit. */
export function VehicleSearchSelect({
    id,
    label,
    value,
    options,
    onChange,
    onAdd,
    disabled,
    invalid,
    describedBy,
}: {
    id?: string;
    label: string;
    value: string;
    options: VehicleSelectOption[];
    onChange: (value: string) => void;
    onAdd?: (proposedLabel: string) => void;
    disabled?: boolean;
    invalid?: boolean;
    describedBy?: string;
}) {
    const generatedId = useId();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const selected = options.find((option) => option.value === value);
    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (!next) setQuery('');
            }}
        >
            <PopoverTrigger asChild>
                <Button
                    id={id ?? generatedId}
                    variant="outline"
                    role="combobox"
                    aria-label={label}
                    aria-expanded={open}
                    aria-invalid={invalid}
                    aria-describedby={describedBy}
                    disabled={disabled}
                    className="w-full justify-between font-normal"
                >
                    <Search className="size-4 shrink-0 text-muted-foreground" />
                    <span className="min-w-0 flex-1 truncate text-left">
                        {selected?.label ??
                            (value
                                ? 'Selection unavailable'
                                : `Select ${label.toLowerCase()}`)}
                    </span>
                    <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-[var(--radix-popover-trigger-width)] min-w-64 p-0"
                align="start"
            >
                <Command>
                    <CommandInput
                        placeholder={`Search ${label.toLowerCase()}…`}
                        value={query}
                        onValueChange={setQuery}
                    />
                    <CommandList>
                        <CommandEmpty>No matching choices.</CommandEmpty>
                        <CommandGroup>
                            {options.map((option) => (
                                <CommandItem
                                    key={option.value}
                                    value={`${option.value} ${option.label} ${option.description ?? ''}`}
                                    onSelect={() => {
                                        onChange(option.value);
                                        setOpen(false);
                                        setQuery('');
                                    }}
                                >
                                    <div className="min-w-0 flex-1">
                                        <span>{option.label}</span>
                                        {option.description && (
                                            <p className="text-xs text-muted-foreground">
                                                {option.description}
                                            </p>
                                        )}
                                    </div>
                                    {value === option.value && (
                                        <Check className="size-4 text-primary" />
                                    )}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
                {onAdd && (
                    <div className="border-t p-2">
                        <Button
                            variant="ghost"
                            className="w-full justify-start text-primary"
                            onClick={() => {
                                const proposed = query.trim();
                                setOpen(false);
                                setQuery('');
                                onAdd(proposed);
                            }}
                        >
                            <Plus className="size-4" />
                            Add new{query.trim() ? `: ${query.trim()}` : ''}
                        </Button>
                    </div>
                )}
            </PopoverContent>
        </Popover>
    );
}
