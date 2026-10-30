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
import { Check, ChevronsUpDown } from 'lucide-react';
import { useEffect, useState } from 'react';

/** A single current, authorised choice; removed options never retain an old label. */
export function HubSearchSelect({
    label,
    value,
    options,
    onChange,
    disabled = false,
    placeholder = 'Choose…',
    searchLabel = 'Search options',
}: {
    label: string;
    value: string;
    options: { value: string; label: string; description?: string }[];
    onChange: (value: string) => void;
    disabled?: boolean;
    placeholder?: string;
    searchLabel?: string;
}) {
    const [open, setOpen] = useState(false);
    const selected = options.find((option) => option.value === value);
    useEffect(() => {
        if (disabled) setOpen(false);
    }, [disabled]);
    return (
        <Popover open={open && !disabled} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-label={label}
                    aria-expanded={open && !disabled}
                    aria-haspopup="dialog"
                    disabled={disabled}
                    className="h-auto min-h-11 w-full justify-between bg-card px-3 py-2 text-left font-normal whitespace-normal"
                >
                    <span className="min-w-0">
                        <span className="block">
                            {selected?.label ?? placeholder}
                        </span>
                        {selected?.description && (
                            <span className="mt-0.5 block text-xs text-muted-foreground">
                                {selected.description}
                            </span>
                        )}
                    </span>
                    <ChevronsUpDown
                        className="ml-3 size-4 shrink-0 opacity-50"
                        aria-hidden
                    />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                aria-label={label}
                className="w-[var(--radix-popover-trigger-width)] max-w-[calc(100vw-2rem)] p-0"
                collisionPadding={12}
            >
                <Command label={searchLabel}>
                    <CommandInput
                        aria-label={searchLabel}
                        placeholder={searchLabel}
                    />
                    <CommandList aria-label={label}>
                        <CommandEmpty>No matching colleagues.</CommandEmpty>
                        <CommandGroup>
                            {options.map((option) => (
                                <CommandItem
                                    key={option.value}
                                    value={option.value}
                                    keywords={[
                                        option.label,
                                        option.description ?? '',
                                    ]}
                                    onSelect={() => {
                                        if (!disabled) {
                                            onChange(option.value);
                                            setOpen(false);
                                        }
                                    }}
                                    className="min-h-11 px-3 py-2"
                                >
                                    <Check
                                        className={
                                            value === option.value
                                                ? 'text-primary'
                                                : 'opacity-0'
                                        }
                                        aria-hidden
                                    />
                                    <span className="min-w-0">
                                        <span className="block">
                                            {option.label}
                                        </span>
                                        {option.description && (
                                            <span className="mt-0.5 block text-xs text-muted-foreground">
                                                {option.description}
                                            </span>
                                        )}
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
