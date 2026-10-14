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
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    sourceBase,
    sourceSetupReply,
    type SourceChoice,
    type SourceChoicePage,
    type SourceOption,
} from './_source-contract';
import { cctvSourceJson } from './live-transport';

export function CctvSourcePicker({
    actor,
    camera,
    revision,
    kind,
    label,
    collectorId,
    value,
    disabled,
    onChange,
}: {
    actor: number;
    camera: number;
    revision: number;
    kind: SourceChoice;
    label: string;
    collectorId?: number;
    value: SourceOption | null;
    disabled: boolean;
    onChange: (option: SourceOption) => void;
}) {
    const [open, setOpen] = useState(false),
        [query, setQuery] = useState(''),
        [page, setPage] = useState<SourceChoicePage | null>(null),
        [notice, setNotice] = useState(''),
        [busy, setBusy] = useState(false);
    const call = useRef<AbortController | null>(null);
    const href = useCallback(
        () =>
            sourceBase(camera) +
            '?' +
            new URLSearchParams({
                choice: kind,
                ...(query.trim() ? { q: query.trim() } : {}),
                ...(kind === 'scopes' && collectorId
                    ? { collector_id: String(collectorId) }
                    : {}),
            }),
        [camera, kind, query, collectorId],
    );
    const load = useCallback(
        async (url: string) => {
            call.current?.abort();
            const controller = new AbortController();
            call.current = controller;
            setBusy(true);
            setPage(null);
            setNotice('');
            try {
                const data = sourceSetupReply(
                    await cctvSourceJson(camera, url, controller.signal),
                    actor,
                    camera,
                );
                if (
                    data.revision !== revision ||
                    data.choice !== kind ||
                    data.q !== query.trim() ||
                    data.collector_id !==
                        (kind === 'scopes' ? (collectorId ?? null) : null)
                )
                    throw Error('Choice context changed');
                if (
                    kind === 'scopes' &&
                    data.choices.scopes.items.some(
                        (option) => option.collector_id !== collectorId,
                    )
                )
                    throw Error('Collector context changed');
                if (!controller.signal.aborted) setPage(data.choices[kind]);
            } catch {
                if (!controller.signal.aborted)
                    setNotice(
                        'Choices could not be checked. Retry, or close setup and refresh the camera.',
                    );
            } finally {
                if (!controller.signal.aborted) setBusy(false);
            }
        },
        [actor, camera, collectorId, kind, query, revision],
    );
    useEffect(() => {
        if (!open || disabled) return;
        setPage(null);
        setBusy(true);
        const timer = setTimeout(() => void load(href()), 180);
        return () => {
            clearTimeout(timer);
            call.current?.abort();
        };
    }, [open, disabled, href, load]);
    return (
        <Popover open={open && !disabled} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open && !disabled}
                    aria-label={label}
                    disabled={disabled || (kind === 'scopes' && !collectorId)}
                    className="h-auto min-h-9 w-full justify-between gap-3 text-left"
                >
                    <span className="min-w-0 [overflow-wrap:anywhere] whitespace-normal">
                        {value?.name ?? 'Choose ' + label.toLowerCase()}
                    </span>
                    <ChevronsUpDown
                        className="size-4 shrink-0"
                        aria-hidden="true"
                    />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-[var(--radix-popover-trigger-width)] max-w-[calc(100vw-2rem)] p-0"
            >
                <Command shouldFilter={false}>
                    <CommandInput
                        aria-label={'Search ' + label.toLowerCase()}
                        placeholder="Search by name…"
                        value={query}
                        onValueChange={setQuery}
                        maxLength={200}
                    />
                    <CommandList>
                        {busy ? (
                            <p className="text-subtle p-4" role="status">
                                Checking available choices…
                            </p>
                        ) : notice ? (
                            <div className="space-y-3 p-4">
                                <p className="text-subtle" role="status">
                                    {notice}
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => void load(href())}
                                >
                                    Retry choices
                                </Button>
                            </div>
                        ) : (
                            page && (
                                <>
                                    <CommandEmpty>
                                        No available matches.
                                    </CommandEmpty>
                                    {page.items.map((option) => (
                                        <CommandItem
                                            key={option.id}
                                            value={String(option.id)}
                                            onSelect={() => {
                                                onChange(option);
                                                setOpen(false);
                                            }}
                                        >
                                            <Check
                                                aria-hidden="true"
                                                className={
                                                    value?.id === option.id
                                                        ? 'size-4 shrink-0'
                                                        : 'size-4 shrink-0 opacity-0'
                                                }
                                            />
                                            <span className="min-w-0 [overflow-wrap:anywhere]">
                                                {option.name}
                                            </span>
                                        </CommandItem>
                                    ))}
                                </>
                            )
                        )}
                    </CommandList>
                </Command>
                {page && !busy && (
                    <div className="flex flex-wrap items-center justify-between gap-2 border-t p-3">
                        <span className="text-caption text-muted-foreground">
                            {page.items.length} on this page
                        </span>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={!page.previous_href}
                                onClick={() =>
                                    page.previous_href &&
                                    void load(page.previous_href)
                                }
                            >
                                Previous
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={!page.next_href}
                                onClick={() =>
                                    page.next_href && void load(page.next_href)
                                }
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                )}
            </PopoverContent>
        </Popover>
    );
}
