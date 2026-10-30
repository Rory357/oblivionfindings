import { Button } from '@/components/ui/button';
import {
    Command,
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
import { useEffect, useRef, useState } from 'react';
import { stageLabel } from '../stage-badge';
import type { ReferralSummary } from '../types';

type State = 'idle' | 'loading' | 'ready' | 'error' | 'access';

/**
 * Searchable referral selector (POPUP_STYLE_GUIDE "Searchable record
 * selectors"): shared Popover + Command, scoped server search by name or
 * reference, bounded pages with Load more, and distinct loading / empty /
 * failure / access states. Never auto-selects; cancelling keeps the choice.
 */
export function ReferralPicker({
    id,
    value,
    onChange,
    stages,
    invalid,
    describedBy,
}: {
    id: string;
    value: ReferralSummary | null;
    onChange: (value: ReferralSummary) => void;
    stages?: { id: string; label: string }[];
    invalid?: boolean;
    describedBy?: string;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [items, setItems] = useState<ReferralSummary[]>([]);
    const [page, setPage] = useState(1);
    const [more, setMore] = useState(false);
    const [state, setState] = useState<State>('idle');
    const request = useRef<AbortController | null>(null);
    const timer = useRef<number | null>(null);
    const cancel = () => {
        request.current?.abort();
        request.current = null;
        if (timer.current) window.clearTimeout(timer.current);
    };
    useEffect(() => cancel, []);

    const load = async (search: string, nextPage = 1) => {
        cancel();
        const controller = new AbortController();
        request.current = controller;
        setState('loading');
        if (nextPage === 1) setItems([]);
        try {
            const response = await fetch(
                `/operations/referrals/calendar/referral-options?${new URLSearchParams({ q: search, page: String(nextPage) })}`,
                {
                    signal: controller.signal,
                    cache: 'no-store',
                    headers: { Accept: 'application/json' },
                },
            );
            if (response.status === 401 || response.status === 403) {
                setState('access');
                return;
            }
            if (!response.ok) throw new Error('Unavailable');
            const data = (await response.json()) as {
                referrals: ReferralSummary[];
                has_more: boolean;
            };
            if (controller.signal.aborted || !Array.isArray(data.referrals))
                return;
            setItems((previous) =>
                nextPage === 1
                    ? data.referrals
                    : [
                          ...new Map(
                              [...previous, ...data.referrals].map((item) => [
                                  item.reference,
                                  item,
                              ]),
                          ).values(),
                      ],
            );
            setPage(nextPage);
            setMore(data.has_more);
            setState('ready');
        } catch (failure) {
            if (
                (failure as Error).name !== 'AbortError' &&
                !controller.signal.aborted
            )
                setState('error');
        }
    };
    const search = (text: string) => {
        setQuery(text);
        cancel();
        timer.current = window.setTimeout(() => void load(text.trim()), 250);
    };
    const changeOpen = (next: boolean) => {
        setOpen(next);
        if (next) {
            setQuery('');
            void load('');
        } else {
            cancel();
            setState('idle');
        }
    };

    return (
        <Popover open={open} onOpenChange={changeOpen}>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    aria-invalid={invalid}
                    aria-describedby={describedBy}
                    aria-required
                    className="h-auto min-h-10 w-full justify-between text-left whitespace-normal"
                >
                    <span className="min-w-0">
                        {value ? (
                            <>
                                <span className="block break-words">
                                    {value.name}
                                </span>
                                <span className="text-caption block break-all text-muted-foreground">
                                    {value.reference} ·{' '}
                                    {value.site_name ?? 'Central intake'}
                                </span>
                            </>
                        ) : (
                            'Choose a referral'
                        )}
                    </span>
                    <ChevronsUpDown
                        aria-hidden="true"
                        className="ml-2 size-4 shrink-0"
                    />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-[var(--radix-popover-trigger-width)] p-0"
                aria-label="Referral choices"
            >
                <Command shouldFilter={false} label="Search referrals">
                    <CommandInput
                        aria-label="Search referrals by name or reference"
                        placeholder="Name or reference…"
                        value={query}
                        maxLength={80}
                        onValueChange={search}
                    />
                    <CommandList label="Referral choices">
                        {state === 'ready' && items.length === 0 && (
                            <p role="status" className="text-subtle p-3">
                                No open referrals you can plan for match this
                                search.
                            </p>
                        )}
                        <CommandGroup>
                            {items.map((item) => (
                                <CommandItem
                                    key={item.reference}
                                    value={item.reference}
                                    className="min-h-11"
                                    onSelect={() => {
                                        onChange(item);
                                        changeOpen(false);
                                    }}
                                >
                                    <Check
                                        aria-hidden="true"
                                        className={
                                            value?.reference === item.reference
                                                ? 'opacity-100'
                                                : 'opacity-0'
                                        }
                                    />
                                    <span className="min-w-0">
                                        <span className="block truncate">
                                            {item.name}
                                        </span>
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {item.reference} ·{' '}
                                            {item.site_name ?? 'Central intake'}{' '}
                                            · {stageLabel(item.stage, stages)}
                                        </span>
                                    </span>
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                    <div
                        className="grid gap-2 border-t border-border p-3"
                        onKeyDown={(event) => {
                            // Footer buttons must not trigger cmdk's highlighted option.
                            if (event.key === 'Enter' || event.key === ' ')
                                event.stopPropagation();
                        }}
                    >
                        {state === 'loading' && (
                            <p role="status" className="text-subtle">
                                Searching referrals…
                            </p>
                        )}
                        {state === 'error' && (
                            <p role="alert" className="text-subtle">
                                Referrals could not be loaded. Retry; your
                                selection has not changed.
                            </p>
                        )}
                        {state === 'access' && (
                            <p role="alert" className="text-subtle">
                                Your current access does not allow planning
                                referral work. Reload the calendar.
                            </p>
                        )}
                        <div className="flex flex-wrap gap-2">
                            {state === 'error' && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => void load(query.trim())}
                                >
                                    Retry search
                                </Button>
                            )}
                            {state === 'ready' && more && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        void load(query.trim(), page + 1)
                                    }
                                >
                                    Load more referrals
                                </Button>
                            )}
                        </div>
                        {state === 'ready' && items.length > 0 && (
                            <p className="text-caption text-muted-foreground">
                                {more
                                    ? `Showing ${items.length} most recent matches. Load more or type more of the name.`
                                    : `${items.length} ${items.length === 1 ? 'match' : 'matches'}.`}
                            </p>
                        )}
                    </div>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
