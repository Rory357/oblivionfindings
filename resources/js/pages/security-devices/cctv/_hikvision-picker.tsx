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
    hikBase,
    hikSetupReply,
    type HikChoice,
    type HikOption,
    type HikPage,
    type HikSetup,
} from './_hikvision-contract';
import {
    recorderSetupReply,
    type RecorderSetup,
} from './_hikvision-recorder-contract';
import { hikvisionJson } from './live-transport';

export function HikvisionPicker({
    setup,
    kind,
    label,
    collector,
    value,
    disabled,
    onChange,
}: {
    setup: HikSetup | RecorderSetup;
    kind: HikChoice;
    label: string;
    collector?: number;
    value: HikOption | null;
    disabled: boolean;
    onChange: (v: HikOption) => void;
}) {
    const device = 'recorder_id' in setup ? setup.recorder_id : setup.camera_id;
    const recorder = 'recorder_id' in setup;
    const [open, setOpen] = useState(false),
        [query, setQuery] = useState(''),
        [page, setPage] = useState<HikPage | null>(null),
        [busy, setBusy] = useState(false),
        [failed, setFailed] = useState(false);
    const call = useRef<AbortController | null>(null);
    const href = useCallback(
        () =>
            hikBase(device) +
            '/setup?' +
            new URLSearchParams({
                choice: kind,
                ...(query.trim() ? { q: query.trim() } : {}),
                ...(kind === 'scopes' && collector
                    ? { collector_id: String(collector) }
                    : {}),
            }),
        [device, kind, query, collector],
    );
    const load = useCallback(
        async (url: string) => {
            call.current?.abort();
            const controller = new AbortController();
            call.current = controller;
            setBusy(true);
            setFailed(false);
            setPage(null);
            try {
                const next = (recorder ? recorderSetupReply : hikSetupReply)(
                    await hikvisionJson(
                        device,
                        setup.actor_id,
                        url,
                        controller.signal,
                    ),
                    setup.actor_id,
                    device,
                );
                if (
                    next.revision !== setup.revision ||
                    next.choice !== kind ||
                    next.q !== query.trim() ||
                    next.collector_id !==
                        (kind === 'scopes' ? (collector ?? null) : null)
                )
                    throw Error('Choices changed');
                if (!controller.signal.aborted) setPage(next.choices[kind]);
            } catch {
                if (!controller.signal.aborted) setFailed(true);
            } finally {
                if (!controller.signal.aborted) setBusy(false);
            }
        },
        [
            setup.actor_id,
            device,
            recorder,
            setup.revision,
            kind,
            query,
            collector,
        ],
    );
    useEffect(() => {
        if (!open || disabled) return;
        setBusy(true);
        setPage(null);
        const timer = setTimeout(() => void load(href()), 180);
        return () => {
            clearTimeout(timer);
            call.current?.abort();
        };
    }, [open, disabled, load, href]);
    return (
        <Popover open={open && !disabled} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open && !disabled}
                    aria-label={label}
                    disabled={disabled || (kind === 'scopes' && !collector)}
                    className="frontline-tap h-auto w-full justify-between gap-3 text-left"
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
                            <p role="status" className="text-subtle p-4">
                                Checking available choices…
                            </p>
                        ) : failed ? (
                            <div className="space-y-3 p-4">
                                <p role="alert" className="text-subtle">
                                    Choices could not be checked. Your selection
                                    has been kept.
                                </p>
                                <Button
                                    variant="outline"
                                    type="button"
                                    onClick={() => void load(href())}
                                >
                                    Retry choices
                                </Button>
                            </div>
                        ) : (
                            page && (
                                <>
                                    <CommandEmpty>
                                        No matching records for this camera’s
                                        site.
                                    </CommandEmpty>
                                    {page.items.map((item) => (
                                        <CommandItem
                                            className="frontline-tap"
                                            key={item.id}
                                            value={String(item.id)}
                                            onSelect={() => {
                                                onChange(item);
                                                setOpen(false);
                                            }}
                                        >
                                            <Check
                                                aria-hidden="true"
                                                className={
                                                    value?.id === item.id
                                                        ? 'size-4'
                                                        : 'size-4 opacity-0'
                                                }
                                            />
                                            <span className="min-w-0 [overflow-wrap:anywhere] whitespace-normal">
                                                {item.name}
                                            </span>
                                        </CommandItem>
                                    ))}
                                    <div className="flex flex-wrap gap-3 p-3">
                                        {page.previous_href && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    void load(
                                                        page.previous_href!,
                                                    )
                                                }
                                            >
                                                Previous results
                                            </Button>
                                        )}
                                        {page.next_href && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    void load(page.next_href!)
                                                }
                                            >
                                                More results
                                            </Button>
                                        )}
                                    </div>
                                </>
                            )
                        )}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
