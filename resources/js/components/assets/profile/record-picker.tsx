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
import { Check, ChevronsUpDown, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { Option } from './types';

export function AssetRecordPicker({
    assetId,
    kind,
    siteId,
    value,
    onChange,
    label,
    invalid,
}: {
    assetId: number;
    kind: 'staff' | 'rooms' | 'components' | 'owners' | 'assignees';
    siteId?: string;
    value: string;
    onChange: (value: string, name: string) => void;
    label: string;
    invalid?: boolean;
}) {
    const [open, setOpen] = useState(false),
        [query, setQuery] = useState(''),
        [options, setOptions] = useState<Option[]>([]),
        [busy, setBusy] = useState(false),
        [failed, setFailed] = useState(false),
        [retry, setRetry] = useState(0),
        [selected, setSelected] = useState('');
    useEffect(() => {
        if (!open) return;
        const abort = new AbortController();
        setBusy(true);
        setFailed(false);
        const timer = setTimeout(() => {
            const params = new URLSearchParams({
                kind,
                search: query,
                site_id: siteId || '',
            });
            fetch(`/assets/${assetId}/profile-options?${params}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: abort.signal,
            })
                .then(async (response) => {
                    if (!response.ok) throw new Error();
                    return response.json();
                })
                .then((result) => {
                    if (!abort.signal.aborted) setOptions(result.options);
                })
                .catch(() => {
                    if (!abort.signal.aborted) setFailed(true);
                })
                .finally(() => {
                    if (!abort.signal.aborted) setBusy(false);
                });
        }, 180);
        return () => {
            clearTimeout(timer);
            abort.abort();
        };
    }, [assetId, kind, siteId, query, open, retry]);
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-label={label}
                    aria-expanded={open}
                    aria-invalid={invalid}
                    className="w-full justify-between"
                >
                    {value
                        ? selected || `Selected record ${value}`
                        : `Choose ${label.toLowerCase()}`}
                    <ChevronsUpDown className="size-4" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-80 p-0" align="start">
                <Command shouldFilter={false}>
                    <CommandInput
                        aria-label={`Search ${label.toLowerCase()}`}
                        placeholder={`Search ${label.toLowerCase()}…`}
                        value={query}
                        onValueChange={setQuery}
                    />
                    <CommandList>
                        {busy ? (
                            <div role="status" className="flex gap-2 p-4">
                                <Loader2 className="size-4 animate-spin" />
                                Searching…
                            </div>
                        ) : failed ? (
                            <div className="p-4" role="alert">
                                Choices could not load.
                                <Button
                                    variant="ghost"
                                    onClick={() => setRetry(retry + 1)}
                                >
                                    Retry
                                </Button>
                            </div>
                        ) : (
                            <>
                                <CommandEmpty>
                                    No permitted matches.
                                </CommandEmpty>
                                {options.map((option) => (
                                    <CommandItem
                                        key={option.id}
                                        value={String(option.id)}
                                        onSelect={() => {
                                            setSelected(option.name);
                                            onChange(
                                                String(option.id),
                                                option.name,
                                            );
                                            setOpen(false);
                                        }}
                                    >
                                        <Check
                                            className={
                                                value === String(option.id)
                                                    ? 'size-4'
                                                    : 'size-4 opacity-0'
                                            }
                                        />
                                        {option.name}
                                    </CommandItem>
                                ))}
                            </>
                        )}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
