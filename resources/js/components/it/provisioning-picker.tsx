import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
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
import axios from 'axios';
import { Check, ChevronsUpDown } from 'lucide-react';
import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';

export interface ProvisioningTemplatePreviewTask {
    task_key: string;
    title: string;
    description?: string | null;
    action: string;
    stage: number;
    dependency_task_keys: string[];
    approval_required: boolean;
    evidence_required: boolean;
    due_offset_days: number;
}
export interface ProvisioningOption {
    id: number;
    label: string;
    lifecycle_type?: string;
    tasks?: ProvisioningTemplatePreviewTask[];
}
const record = (value: unknown): value is Record<string, unknown> =>
    value !== null && typeof value === 'object' && !Array.isArray(value);
const previewTask = (
    value: unknown,
): value is ProvisioningTemplatePreviewTask =>
    record(value) &&
    typeof value.task_key === 'string' &&
    typeof value.title === 'string' &&
    (value.description === undefined ||
        value.description === null ||
        typeof value.description === 'string') &&
    typeof value.action === 'string' &&
    ['grant', 'change', 'revoke', 'recover', 'configure', 'verify'].includes(
        value.action,
    ) &&
    typeof value.stage === 'number' &&
    Number.isSafeInteger(value.stage) &&
    value.stage > 0 &&
    Array.isArray(value.dependency_task_keys) &&
    value.dependency_task_keys.every((key) => typeof key === 'string') &&
    typeof value.approval_required === 'boolean' &&
    typeof value.evidence_required === 'boolean' &&
    typeof value.due_offset_days === 'number' &&
    Number.isSafeInteger(value.due_offset_days);
const option = (value: unknown): value is ProvisioningOption =>
    record(value) &&
    typeof value.id === 'number' &&
    Number.isSafeInteger(value.id) &&
    value.id > 0 &&
    typeof value.label === 'string' &&
    (value.lifecycle_type === undefined ||
        ['joiner', 'mover', 'leaver'].includes(String(value.lifecycle_type))) &&
    (value.tasks === undefined ||
        (Array.isArray(value.tasks) &&
            value.tasks.length > 0 &&
            value.tasks.length <= 50 &&
            value.tasks.every(previewTask)));

export function ProvisioningPicker({
    actorId,
    kind,
    contextKind,
    contextId,
    value,
    label,
    onChange,
    onDenied,
    onValidated,
    disabled = false,
}: {
    actorId: number;
    kind:
        | 'employees'
        | 'agents'
        | 'templates'
        | 'identity'
        | 'asset_assignment'
        | 'device_assignment';
    contextKind?: 'request' | 'workflow' | 'launch' | 'catalogue';
    contextId?: number;
    value: number | null;
    label: string;
    onChange: (value: ProvisioningOption | null) => void;
    onDenied?: () => void;
    onValidated?: (value: ProvisioningOption | null) => void;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(1);
    const [rows, setRows] = useState<ProvisioningOption[]>([]);
    const [selected, setSelected] = useState<ProvisioningOption | null>(null);
    const [more, setMore] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    const callbacks = useRef({ onChange, onDenied, onValidated });
    useLayoutEffect(() => {
        callbacks.current = { onChange, onDenied, onValidated };
    }, [onChange, onDenied, onValidated]);
    const requestSequence = useRef(0);
    const listId = useId();
    useEffect(() => {
        setRows([]);
        setSelected(null);
        setPage(1);
        setQuery('');
    }, [actorId, kind, contextKind, contextId]);
    useEffect(() => {
        if (disabled || (!open && value === null)) {
            if (value === null) setSelected(null);
            return;
        }
        const sequence = ++requestSequence.current;
        const controller = new AbortController();
        setLoading(true);
        callbacks.current.onValidated?.(null);
        setError('');
        setRows([]);
        const timer = setTimeout(
            () => {
                void axios
                    .get('/it/provisioning/options', {
                        params: {
                            actor_user_id: actorId,
                            kind,
                            context_kind: contextKind,
                            context_id: contextId,
                            selected_id: value,
                            q: query,
                            page,
                        },
                        signal: controller.signal,
                        timeout: 20000,
                        headers: { Accept: 'application/json' },
                    })
                    .then(({ data }: { data: unknown }) => {
                        if (
                            sequence !== requestSequence.current ||
                            controller.signal.aborted
                        )
                            return;
                        if (
                            !record(data) ||
                            data.actor_user_id !== actorId ||
                            data.kind !== kind ||
                            data.context_kind !== (contextKind ?? null) ||
                            data.context_id !== (contextId ?? null) ||
                            data.page !== page ||
                            !Array.isArray(data.options) ||
                            !data.options.every(
                                (entry) =>
                                    option(entry) &&
                                    (kind !== 'templates' ||
                                        (!!entry.tasks &&
                                            !!entry.lifecycle_type)),
                            ) ||
                            typeof data.has_more !== 'boolean' ||
                            (data.selected !== null &&
                                (!option(data.selected) ||
                                    (kind === 'templates' &&
                                        (!data.selected.tasks ||
                                            !data.selected.lifecycle_type)) ||
                                    data.selected.id !== value))
                        )
                            throw new Error('Unconfirmed choices');
                        setRows(data.options);
                        setMore(data.has_more);
                        setSelected(data.selected);
                        callbacks.current.onValidated?.(data.selected);
                        if (value !== null && data.selected === null) {
                            setError(
                                'The previous choice is no longer available. Choose again.',
                            );
                        }
                    })
                    .catch((failure: unknown) => {
                        if (
                            sequence !== requestSequence.current ||
                            controller.signal.aborted
                        )
                            return;
                        setRows([]);
                        setSelected(null);
                        setMore(false);
                        if (
                            axios.isAxiosError(failure) &&
                            [401, 403, 404, 419].includes(
                                failure.response?.status ?? 0,
                            )
                        ) {
                            callbacks.current.onDenied?.();
                            setOpen(false);
                        } else
                            setError(
                                'Choices could not be checked. Retry before selecting.',
                            );
                    })
                    .finally(() => {
                        if (
                            sequence === requestSequence.current &&
                            !controller.signal.aborted
                        )
                            setLoading(false);
                    });
            },
            query ? 250 : 0,
        );
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [
        actorId,
        kind,
        contextKind,
        contextId,
        value,
        query,
        page,
        retry,
        open,
        disabled,
    ]);
    return (
        <div className="space-y-2">
            <span className="text-sm font-medium">{label}</span>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        aria-controls={listId}
                        aria-label={label}
                        disabled={disabled}
                        className="w-full justify-between text-left"
                    >
                        <span className="min-w-0 truncate">
                            {loading && value !== null
                                ? 'Checking selected choice…'
                                : (selected?.label ??
                                  (value
                                      ? error
                                          ? 'Previous choice unavailable'
                                          : 'Checking selected choice…'
                                      : 'Choose…'))}
                        </span>
                        <ChevronsUpDown
                            className="ml-2 size-4 shrink-0"
                            aria-hidden
                        />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    align="start"
                    className="w-[min(90vw,420px)] p-2"
                >
                    <Command shouldFilter={false} loop>
                        <CommandInput
                            aria-label={'Search ' + label.toLowerCase()}
                            placeholder="Search…"
                            value={query}
                            onValueChange={(value) => {
                                setQuery(value);
                                setPage(1);
                            }}
                        />
                        <CommandList id={listId} aria-label={label}>
                            {loading ? (
                                <p role="status" className="p-3 text-sm">
                                    Checking current choices…
                                </p>
                            ) : error ? (
                                <div
                                    role="alert"
                                    className="space-y-2 p-3 text-sm"
                                >
                                    <p>{error}</p>
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            setRetry((attempt) => attempt + 1)
                                        }
                                    >
                                        Retry choices
                                    </Button>
                                </div>
                            ) : (
                                <>
                                    <CommandEmpty>
                                        No matching choices
                                    </CommandEmpty>
                                    <CommandGroup>
                                        {rows.map((row) => (
                                            <CommandItem
                                                key={row.id}
                                                value={String(row.id)}
                                                onSelect={() => {
                                                    callbacks.current.onChange(
                                                        row,
                                                    );
                                                    callbacks.current.onValidated?.(
                                                        row,
                                                    );
                                                    setSelected(row);
                                                    setOpen(false);
                                                }}
                                            >
                                                <Check
                                                    className={
                                                        'mr-2 size-4 ' +
                                                        (value === row.id
                                                            ? 'opacity-100'
                                                            : 'opacity-0')
                                                    }
                                                    aria-hidden
                                                />
                                                <span className="whitespace-normal">
                                                    {row.label}
                                                </span>
                                            </CommandItem>
                                        ))}
                                    </CommandGroup>
                                </>
                            )}
                        </CommandList>
                        <div className="mt-2 flex items-center justify-between border-t pt-2">
                            <Button
                                variant="ghost"
                                size="sm"
                                disabled={loading || page === 1}
                                onClick={() =>
                                    setPage((current) => current - 1)
                                }
                            >
                                Previous
                            </Button>
                            <span className="text-xs text-muted-foreground">
                                Page {page}
                            </span>
                            <Button
                                variant="ghost"
                                size="sm"
                                disabled={loading || !more}
                                onClick={() =>
                                    setPage((current) => current + 1)
                                }
                            >
                                Next
                            </Button>
                        </div>
                    </Command>
                </PopoverContent>
            </Popover>
        </div>
    );
}
