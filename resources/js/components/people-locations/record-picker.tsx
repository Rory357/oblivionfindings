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
import { Check, ChevronsUpDown } from 'lucide-react';
import { useState } from 'react';

export function RecordPicker({
    label,
    value,
    options,
    onChange,
    disabled = false,
}: {
    disabled?: boolean;
    label: string;
    value: string;
    options: { value: string; label: string; description?: string }[];
    onChange: (value: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const selected = options.find((option) => option.value === value);
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    disabled={disabled}
                    variant="outline"
                    role="combobox"
                    aria-label={label}
                    aria-expanded={open}
                    className="w-full justify-between text-left"
                >
                    <span className="truncate">
                        {selected?.label ?? `Choose ${label.toLowerCase()}`}
                    </span>
                    <ChevronsUpDown className="size-4 shrink-0" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-[var(--radix-popover-trigger-width)] max-w-[90vw] min-w-72 p-0"
            >
                <Command>
                    <CommandInput
                        placeholder={`Search ${label.toLowerCase()}…`}
                        aria-label={`Search ${label.toLowerCase()}`}
                    />
                    <CommandList>
                        <CommandEmpty>No permitted records match.</CommandEmpty>
                        {options.map((option) => (
                            <CommandItem
                                key={option.value}
                                value={`${option.label} ${option.description ?? ''} ${option.value}`}
                                onSelect={() => {
                                    onChange(option.value);
                                    setOpen(false);
                                }}
                            >
                                <Check
                                    className={
                                        value === option.value
                                            ? 'opacity-100'
                                            : 'opacity-0'
                                    }
                                />
                                <span>
                                    <span className="block font-medium">
                                        {option.label}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {option.description}
                                    </span>
                                </span>
                            </CommandItem>
                        ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
