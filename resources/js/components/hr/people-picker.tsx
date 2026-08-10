import { Check, ChevronsUpDown } from 'lucide-react';
import * as React from 'react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { MultiSelectCombobox } from '@/components/ui/multi-select-combobox';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export interface PersonOption {
    value: string;
    label: string;
    /** Secondary line — role, department, or email. */
    sub?: string;
    avatarUrl?: string | null;
}

function initials(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((p) => p[0]?.toUpperCase() ?? '')
            .join('') || '?'
    );
}

/**
 * Single-person searchable combobox over a provided people list. Replaces the
 * raw "Employee Profile ID" number inputs found across the HR module.
 */
export function PeoplePicker({
    value,
    onChange,
    people,
    placeholder = 'Select a person…',
    disabled = false,
    className,
}: {
    value: string;
    onChange: (value: string) => void;
    people: PersonOption[];
    placeholder?: string;
    disabled?: boolean;
    className?: string;
}) {
    const [open, setOpen] = React.useState(false);
    const selected = people.find((p) => p.value === value);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    disabled={disabled}
                    className={cn(
                        'h-auto min-h-10 w-full justify-between',
                        className,
                    )}
                >
                    {selected ? (
                        <span className="flex min-w-0 items-center gap-2">
                            <Avatar className="h-6 w-6">
                                {selected.avatarUrl ? (
                                    <AvatarImage
                                        src={selected.avatarUrl}
                                        alt={selected.label}
                                    />
                                ) : null}
                                <AvatarFallback className="text-[10px]">
                                    {initials(selected.label)}
                                </AvatarFallback>
                            </Avatar>
                            <span className="truncate">{selected.label}</span>
                        </span>
                    ) : (
                        <span className="text-muted-foreground">
                            {placeholder}
                        </span>
                    )}
                    <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-[var(--radix-popover-trigger-width)] p-0"
                align="start"
            >
                <Command>
                    <CommandInput placeholder="Search people…" />
                    <CommandList>
                        <CommandEmpty>No people found.</CommandEmpty>
                        <CommandGroup>
                            {people.map((p) => (
                                <CommandItem
                                    key={p.value}
                                    value={`${p.label} ${p.sub ?? ''}`}
                                    onSelect={() => {
                                        onChange(
                                            p.value === value ? '' : p.value,
                                        );
                                        setOpen(false);
                                    }}
                                >
                                    <Check
                                        className={cn(
                                            'mr-2 h-4 w-4 shrink-0',
                                            value === p.value
                                                ? 'opacity-100'
                                                : 'opacity-0',
                                        )}
                                    />
                                    <Avatar className="mr-2 h-6 w-6">
                                        {p.avatarUrl ? (
                                            <AvatarImage
                                                src={p.avatarUrl}
                                                alt={p.label}
                                            />
                                        ) : null}
                                        <AvatarFallback className="text-[10px]">
                                            {initials(p.label)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <span className="min-w-0">
                                        <span className="block truncate text-sm">
                                            {p.label}
                                        </span>
                                        {p.sub ? (
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {p.sub}
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

/** Multi-person picker — delegates to the shared MultiSelectCombobox. */
export function PeopleMultiPicker({
    value,
    onChange,
    people,
    placeholder = 'Select people…',
    disabled = false,
    className,
}: {
    value: string[];
    onChange: (value: string[]) => void;
    people: PersonOption[];
    placeholder?: string;
    disabled?: boolean;
    className?: string;
}) {
    return (
        <MultiSelectCombobox
            options={people.map((p) => ({ value: p.value, label: p.label }))}
            selected={value}
            onChange={onChange}
            placeholder={placeholder}
            disabled={disabled}
            className={className}
        />
    );
}

export default PeoplePicker;
