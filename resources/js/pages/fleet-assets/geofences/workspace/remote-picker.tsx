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
import { useDebounced, useRemote } from './api';
export function RemotePicker<T>({
    label,
    value,
    url,
    describe,
    onSelect,
    disabled = false,
}: {
    label: string;
    value?: string;
    url: (q: string) => string;
    describe: (v: T) => { id: number | string; name: string; detail?: string };
    onSelect: (v: T) => void;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false),
        [q, setQ] = useState('');
    const debounced = useDebounced(q),
        result = useRemote<{ data: T[] }>(open ? url(debounced) : null);
    return (
        <div className="field">
            <span>{label}</span>
            <Popover
                open={open}
                onOpenChange={(o) => {
                    setOpen(o);
                    setQ('');
                }}
            >
                <PopoverTrigger asChild>
                    <Button
                        disabled={disabled}
                        variant="outline"
                        role="combobox"
                        aria-label={label}
                        aria-expanded={open}
                        className="w-full justify-between"
                    >
                        <span className="truncate">
                            {value || 'Search and select…'}
                        </span>
                        <ChevronsUpDown />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    className="w-[360px] max-w-[90vw] p-0"
                    align="start"
                >
                    <Command shouldFilter={false}>
                        <CommandInput
                            value={q}
                            onValueChange={setQ}
                            placeholder={'Search ' + label.toLowerCase()}
                        />
                        <CommandList
                            aria-busy={result.loading || q !== debounced}
                        >
                            {result.loading || q !== debounced ? (
                                <div role="status" className="p-3 text-sm">
                                    Searching permitted records…
                                </div>
                            ) : result.error ? (
                                <div role="alert" className="p-3 text-sm">
                                    {result.error}
                                    <Button
                                        variant="outline"
                                        onClick={result.reload}
                                    >
                                        Retry
                                    </Button>
                                </div>
                            ) : (
                                <>
                                    <CommandEmpty>
                                        No matching permitted records.
                                    </CommandEmpty>
                                    {result.data?.data.slice(0, 20).map((v) => {
                                        const d = describe(v);
                                        return (
                                            <CommandItem
                                                key={d.id}
                                                value={String(d.id)}
                                                onSelect={() => {
                                                    onSelect(v);
                                                    setOpen(false);
                                                }}
                                            >
                                                <span>
                                                    <strong>{d.name}</strong>
                                                    {d.detail && (
                                                        <small className="block text-muted-foreground">
                                                            {d.detail}
                                                        </small>
                                                    )}
                                                </span>
                                                {value === d.name && (
                                                    <Check className="ml-auto" />
                                                )}
                                            </CommandItem>
                                        );
                                    })}
                                </>
                            )}
                        </CommandList>
                        <p className="border-t p-2 text-xs text-muted-foreground">
                            Up to 20 matches. Type a name, ID or address to
                            narrow the search.
                        </p>
                    </Command>
                </PopoverContent>
            </Popover>
        </div>
    );
}
