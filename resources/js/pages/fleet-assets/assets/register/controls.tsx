import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Check, ChevronsUpDown, Search } from 'lucide-react';
import { useId, useState } from 'react';
import type { Option } from './api';

export function SearchPicker({
    label,
    options,
    value,
    onChange,
    empty = 'All',
    disabled = false,
    hideLabel = false,
}: {
    label: string;
    options: Option[];
    value: string;
    onChange: (value: string) => void;
    empty?: string;
    disabled?: boolean;
    hideLabel?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const id = useId();
    const matches = options.filter((o) =>
        `${o.name} ${o.id}`
            .toLocaleLowerCase()
            .includes(search.toLocaleLowerCase()),
    );
    return (
        <div className="min-w-0 space-y-1.5">
            <label
                id={id}
                className={hideLabel ? 'sr-only' : 'text-xs font-semibold'}
            >
                {label}
            </label>
            <Popover
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);
                    if (next) setSearch('');
                }}
            >
                <PopoverTrigger asChild>
                    <Button
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        aria-labelledby={id}
                        disabled={disabled}
                        className="h-11 w-full justify-between font-normal"
                    >
                        <span className="truncate">
                            {options.find((o) => String(o.id) === value)
                                ?.name || empty}
                        </span>
                        <ChevronsUpDown className="size-4 shrink-0" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    align="start"
                    className="w-[min(90vw,360px)] p-2"
                >
                    <div className="flex items-center gap-2 px-2">
                        <Search className="size-4" />
                        <Input
                            autoFocus
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder={`Search ${label.toLowerCase()}…`}
                            aria-label={`Search ${label.toLowerCase()}`}
                            className="h-11 border-0 shadow-none"
                        />
                    </div>
                    <div
                        role="listbox"
                        aria-label={label}
                        className="max-h-64 overflow-y-auto"
                    >
                        <Button
                            variant="ghost"
                            role="option"
                            aria-selected={!value}
                            className="h-11 w-full justify-start"
                            onClick={() => {
                                onChange('');
                                setOpen(false);
                            }}
                        >
                            {empty}
                        </Button>
                        {matches.map((option) => (
                            <Button
                                key={option.id}
                                variant="ghost"
                                role="option"
                                aria-selected={value === String(option.id)}
                                className="h-11 w-full justify-between text-left font-normal"
                                onClick={() => {
                                    onChange(String(option.id));
                                    setOpen(false);
                                }}
                            >
                                <span className="truncate">{option.name}</span>
                                {value === String(option.id) && (
                                    <Check className="size-4" />
                                )}
                            </Button>
                        ))}
                        {!matches.length && (
                            <p className="p-3 text-sm text-muted-foreground">
                                No matches. Try another name.
                            </p>
                        )}
                    </div>
                    <p className="border-t px-2 pt-2 text-xs text-muted-foreground">
                        {matches.length} of {options.length} available
                    </p>
                </PopoverContent>
            </Popover>
        </div>
    );
}

export function ErrorNotice({
    message,
    retry,
}: {
    message: string;
    retry?: () => void;
}) {
    if (!message) return null;
    return (
        <div
            role="alert"
            className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical"
        >
            <span>{message}</span>
            {retry && (
                <Button variant="outline" onClick={retry}>
                    Try again
                </Button>
            )}
        </div>
    );
}

export function Paging({
    page,
    last,
    total,
    onChange,
}: {
    page: number;
    last: number;
    total: number;
    onChange: (page: number) => void;
}) {
    return (
        <div className="flex items-center justify-between gap-3 py-3 text-sm">
            <span className="text-muted-foreground">
                {total} records · Page {page} of {Math.max(1, last)}
            </span>
            <div className="flex gap-2">
                <Button
                    variant="outline"
                    className="min-h-11"
                    disabled={page <= 1}
                    onClick={() => onChange(page - 1)}
                >
                    Previous
                </Button>
                <Button
                    variant="outline"
                    className="min-h-11"
                    disabled={page >= last}
                    onClick={() => onChange(page + 1)}
                >
                    Next
                </Button>
            </div>
        </div>
    );
}
