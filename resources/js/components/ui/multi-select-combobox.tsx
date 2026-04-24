import * as React from 'react';
import { Check, ChevronsUpDown, X } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export interface MultiSelectOption {
    value: string;
    label: string;
    group?: string;
}

interface MultiSelectComboboxProps {
    options: MultiSelectOption[];
    selected: string[];
    onChange: (selected: string[]) => void;
    placeholder?: string;
    allowCustom?: boolean;
    disabled?: boolean;
    className?: string;
}

export function MultiSelectCombobox({
    options,
    selected,
    onChange,
    placeholder = 'Select items...',
    allowCustom = false,
    disabled = false,
    className,
}: MultiSelectComboboxProps) {
    const [open, setOpen] = React.useState(false);
    const [search, setSearch] = React.useState('');

    const grouped = React.useMemo(() => {
        const groups: Record<string, MultiSelectOption[]> = {};
        for (const opt of options) {
            const group = opt.group || 'Options';
            if (!groups[group]) groups[group] = [];
            groups[group].push(opt);
        }
        return groups;
    }, [options]);

    const handleSelect = (value: string) => {
        if (selected.includes(value)) {
            onChange(selected.filter((v) => v !== value));
        } else {
            onChange([...selected, value]);
        }
    };

    const handleRemove = (value: string, e: React.SyntheticEvent) => {
        e.stopPropagation();
        onChange(selected.filter((v) => v !== value));
    };

    const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (!allowCustom) return;
        if (e.key !== 'Enter') return;

        const trimmed = search.trim();
        if (!trimmed) return;

        const exists = options.some((o) => o.value === trimmed || o.label.toLowerCase() === trimmed.toLowerCase());
        if (!exists && !selected.includes(trimmed)) {
            onChange([...selected, trimmed]);
            setSearch('');
        }
    };

    const getLabel = (value: string) => {
        const opt = options.find((o) => o.value === value);
        return opt ? opt.label : value;
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    disabled={disabled}
                    className={cn('h-auto min-h-10 w-full justify-between', className)}
                >
                    <div className="flex flex-1 flex-wrap gap-1">
                        {selected.length > 0 ? (
                            selected.map((value) => (
                                <Badge key={value} variant="secondary" className="text-xs">
                                    {getLabel(value)}
                                    {!disabled && (
                                        <span
                                            role="button"
                                            tabIndex={disabled ? -1 : 0}
                                            className="ml-1 rounded-full outline-none ring-offset-background focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                            onClick={(e) => handleRemove(value, e)}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter' || e.key === ' ') {
                                                    e.preventDefault();
                                                    handleRemove(value, e);
                                                }
                                            }}
                                        >
                                            <X className="h-3 w-3" />
                                        </span>
                                    )}
                                </Badge>
                            ))
                        ) : (
                            <span className="text-muted-foreground">{placeholder}</span>
                        )}
                    </div>
                    <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-[var(--radix-popover-trigger-width)] p-0" align="start">
                <Command>
                    <CommandInput
                        placeholder={`Search${allowCustom ? ' or type custom...' : '...'}`}
                        value={search}
                        onValueChange={setSearch}
                        onKeyDown={handleKeyDown}
                    />
                    <CommandList>
                        <CommandEmpty>
                            {allowCustom && search.trim() ? (
                                <span className="text-muted-foreground">Press Enter to add &quot;{search.trim()}&quot;</span>
                            ) : (
                                'No results found.'
                            )}
                        </CommandEmpty>
                        {Object.entries(grouped).map(([group, opts]) => (
                            <CommandGroup key={group} heading={group}>
                                {opts.map((opt) => (
                                    <CommandItem key={opt.value} value={opt.label} onSelect={() => handleSelect(opt.value)}>
                                        <Check className={cn('mr-2 h-4 w-4', selected.includes(opt.value) ? 'opacity-100' : 'opacity-0')} />
                                        {opt.label}
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
