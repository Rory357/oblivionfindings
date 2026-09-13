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
import { newItCommentUuid } from '@/hooks/it-ticket-comment-contract';
import { draftRecord } from '@/hooks/it-ticket-draft-contract';
import axios from 'axios';
import { Check, ChevronsUpDown } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { CatalogFieldOption } from './it-service-catalogue';

interface Props {
    actorId: number;
    itemId: number;
    schemaVersion: number;
    fieldKey: string;
    label: string;
    id: string;
    value: number | null;
    initialSelected?: CatalogFieldOption;
    disabled?: boolean;
    required?: boolean;
    invalid?: boolean;
    describedBy?: string;
    onChange: (value: number | null) => void;
}
interface Page {
    options: CatalogFieldOption[];
    next_cursor: number | null;
    selected: CatalogFieldOption | null;
}
const positiveId = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) > 0;
const option = (value: unknown): value is CatalogFieldOption =>
    draftRecord(value) &&
    positiveId(value.id) &&
    typeof value.name === 'string' &&
    (value.detail === null || typeof value.detail === 'string');

export function readCatalogueOptionPage(
    data: unknown,
    props: Pick<
        Props,
        'actorId' | 'itemId' | 'schemaVersion' | 'fieldKey' | 'value'
    >,
    uuid: string,
): Page | null {
    if (
        !draftRecord(data) ||
        data.viewer_user_id !== props.actorId ||
        data.query_uuid !== uuid ||
        data.catalog_item_id !== props.itemId ||
        data.schema_version !== props.schemaVersion ||
        data.field_key !== props.fieldKey ||
        data.selected_id !== props.value ||
        !Array.isArray(data.options) ||
        data.options.length > 50 ||
        !data.options.every(option) ||
        new Set(data.options.map((item) => item.id)).size !==
            data.options.length ||
        (data.next_cursor !== null && !positiveId(data.next_cursor)) ||
        (data.selected !== null &&
            (!option(data.selected) || data.selected.id !== props.value))
    )
        return null;
    return {
        options: data.options,
        next_cursor: data.next_cursor,
        selected: data.selected as CatalogFieldOption | null,
    };
}

/** Shared popover/command pattern with server-scoped, cancellable directory reads. */
export function CatalogueEntityPicker(props: Props) {
    return (
        <PickerBody
            key={JSON.stringify([
                props.actorId,
                props.itemId,
                props.schemaVersion,
                props.fieldKey,
            ])}
            {...props}
        />
    );
}
function PickerBody(props: Props) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [items, setItems] = useState<CatalogFieldOption[]>([]);
    const [selected, setSelected] = useState<CatalogFieldOption | null>(
        props.initialSelected ?? null,
    );
    const [next, setNext] = useState<number | null>(null);
    const [hasPaged, setHasPaged] = useState(false);
    const searchInput = useRef<HTMLInputElement>(null);
    const [state, setState] = useState<
        'idle' | 'loading' | 'ready' | 'error' | 'access' | 'session' | 'stale'
    >('idle');
    const [message, setMessage] = useState<string | null>(null);
    const request = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const latest = useRef(props);
    latest.current = props;
    const cancel = () => {
        ++epoch.current;
        request.current?.abort();
        request.current = null;
    };
    useEffect(
        () => () => {
            ++epoch.current;
            request.current?.abort();
        },
        [],
    );

    const load = async (search: string, after: number | null = null) => {
        cancel();
        const controller = new AbortController();
        request.current = controller;
        const token = ++epoch.current;
        const context = latest.current;
        const uuid = newItCommentUuid();
        setState('loading');
        setMessage(null);
        setHasPaged(after !== null);
        if (after === null) setItems([]);
        try {
            const response = await axios.post(
                `/it/catalog/${context.itemId}/fields/${encodeURIComponent(context.fieldKey)}/options`,
                {
                    actor_user_id: context.actorId,
                    query_uuid: uuid,
                    schema_version: context.schemaVersion,
                    query: search,
                    after,
                    selected_id: context.value,
                },
                {
                    signal: controller.signal,
                    timeout: 15000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (epoch.current !== token) return;
            const page = readCatalogueOptionPage(response.data, context, uuid);
            if (
                !page ||
                (after !== null &&
                    page.next_cursor !== null &&
                    page.next_cursor <= after)
            )
                throw new Error('Unconfirmed choices');
            setItems((previous) =>
                after === null
                    ? page.options
                    : [
                          ...new Map(
                              [...previous, ...page.options].map((item) => [
                                  item.id,
                                  item,
                              ]),
                          ).values(),
                      ],
            );
            setNext(page.next_cursor);
            setSelected(page.selected);
            setState('ready');
            if (context.value !== null && page.selected === null)
                setMessage(
                    'Your selected choice is no longer available. Choose another permitted record.',
                );
        } catch (error) {
            if (epoch.current !== token) return;
            const status = axios.isAxiosError(error)
                ? error.response?.status
                : undefined;
            setItems([]);
            setNext(null);
            if (status === 401 || status === 419) {
                setSelected(null);
                setState('session');
                setMessage(
                    'Sign in with the original account, then retry the search.',
                );
            } else if (status === 403 || status === 404) {
                setSelected(null);
                setState('access');
                setMessage(
                    'These choices are no longer available for this account or form. Reopen the request with your current access.',
                );
            } else if (status === 409) {
                setSelected(null);
                setState('stale');
                setMessage(
                    'The published form has changed. Reopen the request to review the current version.',
                );
            } else {
                setState('error');
                setMessage(
                    'Choices could not be loaded. Retry the search; your selection has not changed.',
                );
            }
        } finally {
            if (epoch.current === token) request.current = null;
        }
    };
    const changeOpen = (value: boolean) => {
        setOpen(value);
        if (value) {
            setQuery('');
            void load('');
        } else {
            cancel();
            setItems([]);
            setNext(null);
            setState('idle');
        }
    };
    const selectedLabel =
        selected?.id === props.value
            ? selected.name
            : props.value === null
              ? `Choose ${props.label.toLocaleLowerCase()}`
              : 'Selected choice — check availability';

    return (
        <Popover open={open && !props.disabled} onOpenChange={changeOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    id={props.id}
                    variant="outline"
                    role="combobox"
                    aria-label={props.label}
                    aria-expanded={open}
                    aria-required={props.required}
                    aria-invalid={props.invalid}
                    aria-describedby={props.describedBy}
                    disabled={props.disabled}
                    className="h-auto min-h-10 w-full justify-between"
                >
                    <span className="truncate">{selectedLabel}</span>
                    <ChevronsUpDown
                        className="ml-2 h-4 w-4 shrink-0"
                        aria-hidden="true"
                    />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-[var(--radix-popover-trigger-width)] p-0"
                align="start"
                aria-label={`${props.label} choices`}
            >
                <Command
                    shouldFilter={false}
                    label={`Search ${props.label.toLocaleLowerCase()}`}
                >
                    <CommandInput
                        ref={searchInput}
                        aria-label={`Search ${props.label.toLocaleLowerCase()}`}
                        placeholder="Search by name…"
                        value={query}
                        maxLength={100}
                        onValueChange={(value) => {
                            setQuery(value);
                            void load(value);
                        }}
                    />
                    <CommandList label={`${props.label} choices`}>
                        {state === 'ready' && items.length === 0 && (
                            <p role="status" className="text-subtle p-3">
                                No matching permitted choices.
                            </p>
                        )}
                        <CommandGroup>
                            {items.map((item) => (
                                <CommandItem
                                    key={item.id}
                                    className="group min-h-11 data-[selected=true]:text-accent-foreground"
                                    value={String(item.id)}
                                    disabled={state !== 'ready'}
                                    onSelect={() => {
                                        setSelected(item);
                                        props.onChange(item.id);
                                        changeOpen(false);
                                    }}
                                >
                                    <Check
                                        className={
                                            props.value === item.id
                                                ? 'opacity-100'
                                                : 'opacity-0'
                                        }
                                        aria-hidden="true"
                                    />
                                    <span className="min-w-0">
                                        <span className="block truncate">
                                            {item.name}
                                        </span>
                                        {item.detail && (
                                            <span className="block truncate text-xs text-muted-foreground group-data-[selected=true]:text-accent-foreground">
                                                {item.detail}
                                            </span>
                                        )}
                                    </span>
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                    <div
                        className="space-y-2 border-t border-border p-3"
                        onKeyDown={(event) => {
                            // Footer actions must not trigger cmdk's highlighted option.
                            if (event.key === 'Enter' || event.key === ' ')
                                event.stopPropagation();
                        }}
                    >
                        {state === 'loading' && (
                            <p role="status" className="text-subtle">
                                Loading permitted choices…
                            </p>
                        )}
                        {message && (
                            <p role="alert" className="text-subtle">
                                {message}
                            </p>
                        )}
                        <div className="flex flex-wrap gap-2">
                            {state === 'loading' && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        cancel();
                                        searchInput.current?.focus();
                                        setItems([]);
                                        setState('idle');
                                        setMessage(
                                            'Search stopped. Your selection has not changed.',
                                        );
                                    }}
                                >
                                    Stop searching
                                </Button>
                            )}
                            {['error', 'idle', 'session'].includes(state) && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        searchInput.current?.focus();
                                        void load(query);
                                    }}
                                >
                                    Retry search
                                </Button>
                            )}
                            {(next !== null || hasPaged) && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    aria-disabled={
                                        state !== 'ready' || next === null
                                    }
                                    onClick={() => {
                                        if (state === 'ready' && next !== null)
                                            void load(query, next);
                                    }}
                                >
                                    {state === 'ready' && next === null
                                        ? 'No more choices'
                                        : 'Load more choices'}
                                </Button>
                            )}
                            {!props.required && props.value !== null && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => {
                                        props.onChange(null);
                                        setSelected(null);
                                        changeOpen(false);
                                    }}
                                >
                                    Clear selection
                                </Button>
                            )}
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => changeOpen(false)}
                            >
                                Close choices
                            </Button>
                        </div>
                    </div>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
