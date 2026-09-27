import type { GeocodeResult } from '@/components/address-autocomplete';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { ChevronsUpDown, Loader2, MapPin, Search } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

export type TransportLocation = { id: number; name: string; address: string };

/** Location text is a trip snapshot; saved Site records remain owned by Sites. */
export function TransportLocationField({
    label,
    value,
    onChange,
    locations,
    clientId,
    invalid,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    locations: TransportLocation[];
    clientId: string;
    invalid?: boolean;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<GeocodeResult[]>([]);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const active = useRef<AbortController | null>(null);
    useEffect(() => () => active.current?.abort(), [clientId]);
    const clearSearch = () => {
        active.current?.abort();
        active.current = null;
        setBusy(false);
        setResults([]);
        setMessage('');
    };
    const pick = (text: string) => {
        onChange(text);
        clearSearch();
        setOpen(false);
    };
    const search = async () => {
        if (!clientId || query.trim().length < 3 || busy) return;
        clearSearch();
        const controller = new AbortController();
        active.current = controller;
        setBusy(true);
        const csrf = document.querySelector<HTMLMetaElement>(
            'meta[name="csrf-token"]',
        )?.content;
        const xsrf = document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.slice(11);
        try {
            const response = await fetch(
                '/fleet-assets/transports/workspace/address-search',
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        ...(csrf
                            ? { 'X-CSRF-TOKEN': csrf }
                            : xsrf
                              ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
                              : {}),
                    },
                    body: JSON.stringify({
                        q: query.trim(),
                        client_id: Number(clientId),
                    }),
                },
            );
            if (controller.signal.aborted) return;
            if (!response.ok)
                throw new Error(
                    [401, 403, 404, 419].includes(response.status)
                        ? 'Address search access has changed. Refresh the page and check the passenger.'
                        : 'Address search is unavailable. Retry Search or enter the location manually.',
                );
            const data = await response.json();
            if (controller.signal.aborted) return;
            const found: GeocodeResult[] = Array.isArray(data.results)
                ? data.results.filter(
                      (r: GeocodeResult) =>
                          typeof r.display_name === 'string' &&
                          r.display_name.trim(),
                  )
                : [];
            setResults(found);
            setMessage(
                found.length
                    ? `${found.length} ${found.length === 1 ? 'address' : 'addresses'} found. Choose one below.`
                    : 'No addresses found. Add a suburb or town, or use your entered location.',
            );
        } catch (error) {
            if (!controller.signal.aborted)
                setMessage(
                    error instanceof Error
                        ? error.message
                        : 'Address search failed. Try again.',
                );
        } finally {
            if (!controller.signal.aborted) {
                setBusy(false);
                active.current = null;
            }
        }
    };
    const matches = locations.filter((place) =>
        `${place.name} ${place.address}`
            .toLocaleLowerCase()
            .includes(query.trim().toLocaleLowerCase()),
    );
    return (
        <div className="min-w-0 space-y-2">
            <Label htmlFor={id}>{label}</Label>
            <Popover
                open={open}
                onOpenChange={(next) => {
                    clearSearch();
                    setQuery('');
                    setOpen(next);
                }}
            >
                <PopoverTrigger asChild>
                    <Button
                        id={id}
                        type="button"
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        aria-invalid={invalid}
                        aria-label={label}
                        className="h-auto min-h-11 w-full justify-between gap-2 py-2 text-left font-normal"
                    >
                        <MapPin className="size-4 shrink-0 text-primary" />
                        <span className="min-w-0 flex-1 break-words whitespace-normal">
                            {value || 'Choose a site or find an address'}
                        </span>
                        <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    align="start"
                    className="flex flex-col overflow-hidden p-0"
                    style={{
                        width: 'min(420px, 88vw)',
                        maxHeight:
                            'min(480px, var(--radix-popover-content-available-height))',
                    }}
                >
                    <Command
                        shouldFilter={false}
                        label={`Search ${label.toLowerCase()}`}
                        className="min-h-0"
                    >
                        <CommandInput
                            aria-label={`Search ${label.toLowerCase()}`}
                            placeholder="Search a site, street or place…"
                            value={query}
                            maxLength={255}
                            onValueChange={(text) => {
                                clearSearch();
                                setQuery(text);
                            }}
                        />
                        <div className="space-y-2 border-b p-3">
                            <Button
                                type="button"
                                variant="outline"
                                className="w-full"
                                disabled={
                                    !clientId ||
                                    query.trim().length < 3 ||
                                    query.trim().length > 200 ||
                                    busy
                                }
                                onClick={() => void search()}
                            >
                                {busy ? (
                                    <Loader2 className="size-4 animate-spin" />
                                ) : (
                                    <Search className="size-4" />
                                )}{' '}
                                {busy ? 'Searching…' : 'Search OpenStreetMap'}
                            </Button>
                            <p className="text-xs text-muted-foreground">
                                {clientId
                                    ? 'Search a public address or place. Keep passenger details out of the search.'
                                    : 'Choose a passenger to search addresses.'}
                            </p>
                            {message && (
                                <p role="status" className="text-xs">
                                    {message}
                                </p>
                            )}
                        </div>
                        <CommandList className="min-h-0 flex-1 overscroll-contain">
                            {matches.length > 0 && (
                                <CommandGroup heading="Saved sites">
                                    {matches.map((place) => (
                                        <CommandItem
                                            key={place.id}
                                            value={`site-${place.id}`}
                                            onSelect={() =>
                                                pick(
                                                    [place.name, place.address]
                                                        .filter(Boolean)
                                                        .join(' · '),
                                                )
                                            }
                                            disabled={
                                                [place.name, place.address]
                                                    .filter(Boolean)
                                                    .join(' · ').length > 255
                                            }
                                            className="min-h-11 items-start"
                                        >
                                            <MapPin className="mt-1 size-4 shrink-0" />
                                            <span className="min-w-0 break-words whitespace-normal">
                                                <strong className="block">
                                                    {place.name}
                                                </strong>
                                                <span className="text-xs text-muted-foreground">
                                                    {place.address ||
                                                        'Site address not recorded'}
                                                </span>
                                            </span>
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            )}
                            {results.length > 0 && (
                                <CommandGroup heading="OpenStreetMap results">
                                    {results.map((result, index) => (
                                        <CommandItem
                                            key={index}
                                            value={`address-${index}`}
                                            disabled={
                                                result.display_name.length > 255
                                            }
                                            onSelect={() =>
                                                pick(result.display_name)
                                            }
                                            className="min-h-11 break-words whitespace-normal"
                                        >
                                            {result.display_name}
                                            {result.display_name.length > 255 &&
                                                ' — shorten this address manually'}
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            )}
                            {query.trim() && (
                                <CommandGroup heading="Manual location">
                                    <CommandItem
                                        value="manual"
                                        className="min-h-11 break-words whitespace-normal"
                                        onSelect={() => pick(query.trim())}
                                    >
                                        Use entered location: {query.trim()}
                                    </CommandItem>
                                </CommandGroup>
                            )}
                            {!matches.length && !query.trim() && (
                                <p className="p-3 text-sm text-muted-foreground">
                                    No saved sites available. Enter an address
                                    or place above.
                                </p>
                            )}
                        </CommandList>
                    </Command>
                    <p className="shrink-0 border-t px-3 py-2 text-xs text-muted-foreground">
                        Address results ©{' '}
                        <a
                            className="underline"
                            href="https://www.openstreetmap.org/copyright"
                            target="_blank"
                            rel="noreferrer"
                        >
                            OpenStreetMap contributors
                        </a>
                    </p>
                </PopoverContent>
            </Popover>
        </div>
    );
}
