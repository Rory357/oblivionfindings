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

/** Search the exact server-authorized choices; never synthesize a recipient. */
export function HazardStaffPicker({
    id,
    value,
    onChange,
    options,
    placeholder,
    disabled = false,
    searchLabel = 'Search eligible staff',
    listLabel = 'Eligible hazard staff',
    emptyLabel = 'No eligible staff match this search.',
    selectedLabel,
    ariaDescribedBy,
}: {
    id?: string;
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
    placeholder: string;
    disabled?: boolean;
    searchLabel?: string;
    listLabel?: string;
    emptyLabel?: string;
    /** Display-only resolution for a saved value outside the permitted choices. */
    selectedLabel?: string;
    ariaDescribedBy?: string;
}) {
    const [open, setOpen] = useState(false);
    const selected = options.find((option) => option.value === value);
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    aria-label={placeholder}
                    aria-describedby={ariaDescribedBy}
                    disabled={disabled}
                    className="frontline-tap h-auto w-full justify-between text-left whitespace-normal"
                >
                    {selected?.label ??
                        selectedLabel ??
                        (value
                            ? 'Current selection is no longer available'
                            : placeholder)}
                    <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-[var(--radix-popover-trigger-width)] max-w-[calc(100vw-2rem)] p-0"
            >
                <Command label={listLabel}>
                    <CommandInput
                        placeholder={`${searchLabel}…`}
                        aria-label={searchLabel}
                    />
                    <CommandList aria-label={listLabel}>
                        <CommandEmpty>{emptyLabel}</CommandEmpty>
                        {options.map((option) => (
                            <CommandItem
                                key={option.value}
                                value={option.value}
                                keywords={[option.label]}
                                onSelect={() => {
                                    onChange(option.value);
                                    setOpen(false);
                                }}
                                className="frontline-tap"
                            >
                                <Check
                                    aria-hidden="true"
                                    className={`mr-2 h-4 w-4 ${value === option.value ? 'opacity-100' : 'opacity-0'}`}
                                />
                                {option.label}
                            </CommandItem>
                        ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
