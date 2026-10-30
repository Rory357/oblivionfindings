import { SettingsModal } from '@/components/settings/settings-modal';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDateOnly } from '@/lib/datetime';
import { useEffect, useState } from 'react';
import type { StockItem, SupplyOrder } from './_types';

type Choices = { orders: SupplyOrder[]; medicines: StockItem[]; more: boolean };

/** The row menu's rule for receiving a medicine (StockHub itemActions). */
export function receiveBlockedReason(item: StockItem): string | undefined {
    if (!item.lots_started && item.stock_id !== null)
        return 'Pack tracking has not been set up.';
    if (!(item.receivable ?? item.active))
        return 'This medicine has been stopped.';
    return undefined;
}

/**
 * Approved P06 header "Receive a delivery" (Main, 20 Oct): not a new flow.
 * Pick what arrived — deliveries due from the pharmacy first, then the other
 * medicines at the chosen house — and the existing receive dialog takes over.
 * A scoped server search (DESIGN.md "Searchable record selection in dialogs").
 */
export function ReceivePicker({
    sites,
    siteId,
    onPick,
    onClose,
}: {
    sites: { id: number; name: string }[];
    siteId: number | null;
    onPick: (choice: { medicationId: number; order?: SupplyOrder }) => void;
    onClose: () => void;
}) {
    const [house, setHouse] = useState<number | null>(siteId);
    const [query, setQuery] = useState('');
    const [choices, setChoices] = useState<Choices | null>(null);
    const [busy, setBusy] = useState(true);
    const [error, setError] = useState(false);
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        const abort = new AbortController();
        setBusy(true);
        setError(false);
        const params = new URLSearchParams({
            ...(house ? { site_id: String(house) } : {}),
            ...(query.trim() ? { search: query.trim() } : {}),
        }).toString();
        const timer = setTimeout(
            () =>
                fetch(
                    `/emar/stock/packs/receive-choices${params ? `?${params}` : ''}`,
                    {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                        signal: abort.signal,
                    },
                )
                    .then(async (response) => {
                        if (!response.ok) throw new Error('unavailable');
                        return response.json();
                    })
                    .then((result: Choices) => {
                        if (!abort.signal.aborted) setChoices(result);
                    })
                    .catch(() => {
                        if (!abort.signal.aborted) setError(true);
                    })
                    .finally(() => {
                        if (!abort.signal.aborted) setBusy(false);
                    }),
            query ? 200 : 0,
        );
        return () => {
            clearTimeout(timer);
            abort.abort();
        };
    }, [house, query, retry]);
    const nothing =
        choices && !choices.orders.length && !choices.medicines.length;
    return (
        <SettingsModal
            frontline
            title="Receive a delivery"
            description="Choose what arrived. Deliveries due from the pharmacy come first."
            onClose={onClose}
        >
            <>
                {sites.length > 1 && (
                    <div className="grid gap-1.5">
                        <Label htmlFor="receive-house">House</Label>
                        <Select
                            value={house ? String(house) : 'all'}
                            onValueChange={(value) =>
                                setHouse(value === 'all' ? null : Number(value))
                            }
                        >
                            <SelectTrigger
                                id="receive-house"
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All your houses
                                </SelectItem>
                                {sites.map((site) => (
                                    <SelectItem
                                        key={site.id}
                                        value={String(site.id)}
                                    >
                                        {site.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )}
                <Command shouldFilter={false} className="rounded-lg border">
                    <CommandInput
                        value={query}
                        onValueChange={setQuery}
                        placeholder="Search medicines or people"
                        aria-label="Search medicines or people"
                    />
                    <CommandList className="max-h-[360px]">
                        {busy && (
                            <p className="text-caption px-3 py-2" role="status">
                                Searching…
                            </p>
                        )}
                        {error ? (
                            <div className="grid gap-2 p-3" role="alert">
                                <p className="text-subtle">
                                    What can be received could not be loaded.
                                </p>
                                <Button
                                    variant="outline"
                                    className="w-fit"
                                    onClick={() => setRetry((n) => n + 1)}
                                >
                                    Try again
                                </Button>
                            </div>
                        ) : choices ? (
                            <>
                                {choices.orders.length > 0 && (
                                    <CommandGroup heading="Due from the pharmacy">
                                        {choices.orders.map((order) => (
                                            <CommandItem
                                                key={`order-${order.id}`}
                                                value={`order-${order.id}`}
                                                onSelect={() =>
                                                    onPick({
                                                        medicationId:
                                                            order.client_medication_id,
                                                        order,
                                                    })
                                                }
                                                className="grid justify-items-start gap-0.5"
                                            >
                                                <span className="font-medium">
                                                    {order.medication_name} for{' '}
                                                    {order.client_name}
                                                </span>
                                                <span className="text-caption">
                                                    Order #{order.id} ·{' '}
                                                    {order.status ===
                                                    'part_received'
                                                        ? 'the rest of a part delivery'
                                                        : 'dispensed'}{' '}
                                                    ·{' '}
                                                    {order.expected_delivery
                                                        ? `due ${formatDateOnly(order.expected_delivery)}`
                                                        : 'no date given'}
                                                    {order.controlled
                                                        ? ' · with a witness'
                                                        : ''}
                                                </span>
                                            </CommandItem>
                                        ))}
                                    </CommandGroup>
                                )}
                                {choices.medicines.length > 0 && (
                                    <CommandGroup heading="Other medicines">
                                        {choices.medicines.map((item) => {
                                            const blocked =
                                                receiveBlockedReason(item);
                                            return (
                                                <CommandItem
                                                    key={`medicine-${item.id}`}
                                                    value={`medicine-${item.id}`}
                                                    disabled={!!blocked}
                                                    onSelect={() =>
                                                        onPick({
                                                            medicationId:
                                                                item.id,
                                                        })
                                                    }
                                                    className="grid justify-items-start gap-0.5"
                                                >
                                                    <span className="font-medium">
                                                        {item.name}
                                                    </span>
                                                    <span className="text-caption">
                                                        {blocked ??
                                                            `${item.client_name} · ${item.site_name ?? 'House not available'}${item.controlled ? ' · with a witness' : ''}`}
                                                    </span>
                                                </CommandItem>
                                            );
                                        })}
                                    </CommandGroup>
                                )}
                                {choices.more && (
                                    <p className="text-caption px-3 py-2">
                                        Showing the first 100 medicines. Search
                                        to find another.
                                    </p>
                                )}
                                {nothing && !busy && (
                                    <CommandEmpty>
                                        Nothing here can be received. Change the
                                        house or the search.
                                    </CommandEmpty>
                                )}
                            </>
                        ) : null}
                    </CommandList>
                </Command>
            </>
        </SettingsModal>
    );
}
