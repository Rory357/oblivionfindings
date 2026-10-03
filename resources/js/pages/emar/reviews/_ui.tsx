import ConfirmDialog from '@/components/confirm-dialog';
import {
    FilePreviewDialog,
    type PreviewFile,
} from '@/components/files/file-preview-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    ChevronsUpDown,
    FileText,
    LockKeyhole,
    Plus,
    RefreshCw,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import {
    reviewRequest,
    ReviewRequestError,
    type ReviewRequestData,
} from './_request';
import { OUTCOME_LABELS, recommendationOutcome } from './model';
import type {
    OutcomeDraft,
    PickerOption,
    ReviewOutcome,
    SourceFile,
} from './types';

export function Required() {
    return (
        <>
            <span className="text-status-critical" aria-hidden="true">
                *
            </span>
            <span className="sr-only"> (required)</span>
        </>
    );
}
export function Field({
    id,
    label,
    required,
    error,
    hint,
    children,
}: {
    id: string;
    label: ReactNode;
    required?: boolean;
    error?: string;
    hint?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="space-y-1.5">
            <Label htmlFor={id}>
                {label} {required && <Required />}
            </Label>
            {children}
            {hint && (
                <p id={`${id}-hint`} className="text-caption">
                    {hint}
                </p>
            )}
            <InputError id={`${id}-error`} message={error} />
        </div>
    );
}
export function Notice({
    title,
    children,
    warning = false,
}: {
    title: string;
    children: ReactNode;
    warning?: boolean;
}) {
    return (
        <Card
            className={`gap-1 px-4 py-3 ${warning ? 'border-status-warning/30 bg-status-warning-bg' : 'bg-muted/30'}`}
        >
            <div className="flex items-start gap-2">
                <AlertTriangle
                    className={`mt-0.5 size-4 shrink-0 ${warning ? 'text-status-warning' : 'text-muted-foreground'}`}
                    aria-hidden="true"
                />
                <div className="min-w-0">
                    <p className="text-sm font-semibold">{title}</p>
                    <div className="text-muted-foreground mt-1 text-sm">
                        {children}
                    </div>
                </div>
            </div>
        </Card>
    );
}
export function Facts({ rows }: { rows: [string, ReactNode][] }) {
    return (
        <dl className="grid gap-3 sm:grid-cols-2">
            {rows.map(([label, value]) => (
                <div key={label} className="min-w-0">
                    <dt className="text-caption">{label}</dt>
                    <dd className="mt-1 break-words text-sm font-medium">
                        {value || '—'}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
export function ConcealedMedicine({
    name = 'Controlled medicine',
}: {
    name?: string;
}) {
    return (
        <div className="flex min-w-0 items-start gap-2">
            <LockKeyhole
                className="text-muted-foreground mt-0.5 size-4 shrink-0"
                aria-hidden="true"
            />
            <div>
                <p className="text-sm font-semibold">{name}</p>
                <p className="text-caption">
                    Details need the required medicine access.
                </p>
            </div>
        </div>
    );
}

export function ChoiceTiles<K extends string>({
    id,
    label,
    value,
    onChange,
    choices,
    error,
}: {
    id: string;
    label: string;
    value: K | '';
    onChange: (value: K) => void;
    choices: {
        value: K;
        label: string;
        description: string;
        icon: LucideIcon;
    }[];
    error?: string;
}) {
    return (
        <fieldset
            id={id}
            tabIndex={-1}
            aria-invalid={!!error}
            aria-describedby={error ? `${id}-error` : undefined}
            className="space-y-2"
        >
            <legend className="text-sm font-medium">
                {label} <Required />
            </legend>
            <div
                role="radiogroup"
                aria-label={label}
                className="grid gap-2 sm:grid-cols-2"
            >
                {choices.map(
                    (
                        { value: option, label: text, description, icon: Icon },
                        index,
                    ) => (
                        <Button
                            key={option}
                            type="button"
                            variant="outline"
                            role="radio"
                            aria-checked={value === option}
                            tabIndex={
                                value === option || (!value && index === 0)
                                    ? 0
                                    : -1
                            }
                            onKeyDown={(event) => {
                                if (
                                    ![
                                        'ArrowLeft',
                                        'ArrowRight',
                                        'ArrowUp',
                                        'ArrowDown',
                                        'Home',
                                        'End',
                                    ].includes(event.key)
                                )
                                    return;
                                event.preventDefault();
                                const target =
                                    event.key === 'Home'
                                        ? 0
                                        : event.key === 'End'
                                          ? choices.length - 1
                                          : (index +
                                                (event.key === 'ArrowLeft' ||
                                                event.key === 'ArrowUp'
                                                    ? -1
                                                    : 1) +
                                                choices.length) %
                                            choices.length;
                                onChange(choices[target].value);
                                event.currentTarget.parentElement
                                    ?.querySelectorAll<HTMLButtonElement>(
                                        '[role="radio"]',
                                    )
                                    [target]?.focus();
                            }}
                            onClick={() => onChange(option)}
                            className={`h-auto min-h-16 justify-start gap-3 whitespace-normal p-3 text-left ${value === option ? 'border-primary bg-primary/10' : ''}`}
                        >
                            <Icon
                                className="text-primary size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <span className="min-w-0">
                                <span className="block text-sm font-semibold">
                                    {text}
                                </span>
                                <span className="text-caption block font-normal">
                                    {description}
                                </span>
                            </span>
                            {value === option && (
                                <Check
                                    className="text-primary ml-auto size-4 shrink-0"
                                    aria-hidden="true"
                                />
                            )}
                        </Button>
                    ),
                )}
            </div>
            <InputError id={`${id}-error`} message={error} />
        </fieldset>
    );
}

/** A bounded server directory. A typed name never becomes a person or owner ID. */
export function ReviewPicker({
    id,
    kind,
    value,
    onChange,
    clientId,
    placeholder,
    error,
    optional,
    allowCustom,
}: {
    id: string;
    kind: 'person' | 'owner' | 'clinician' | 'prescriber';
    value: PickerOption | null;
    onChange: (option: PickerOption | null) => void;
    clientId?: number;
    placeholder: string;
    error?: string;
    optional?: boolean;
    allowCustom?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [options, setOptions] = useState<PickerOption[]>([]);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState<string | null>(null);
    const [retry, setRetry] = useState(0);
    const [custom, setCustom] = useState(false);
    const [customName, setCustomName] = useState('');
    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        setLoading(true);
        setFailed(null);
        const timer = window.setTimeout(async () => {
            try {
                const params = new URLSearchParams({
                    kind,
                    q: query,
                    ...(clientId ? { client_id: String(clientId) } : {}),
                });
                const response = await fetch(
                    `/emar/reviews/pickers?${params}`,
                    {
                        signal: controller.signal,
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    },
                );
                if (!response.ok)
                    throw new Error(
                        response.status === 403
                            ? 'You no longer have access to this directory.'
                            : 'We couldn’t load the choices. Try again.',
                    );
                const result: { options: PickerOption[] } =
                    await response.json();
                if (!controller.signal.aborted) setOptions(result.options);
            } catch (problem) {
                if (!controller.signal.aborted)
                    setFailed(
                        problem instanceof Error
                            ? problem.message
                            : 'We couldn’t load the choices. Try again.',
                    );
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }, 200);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [open, query, kind, clientId, retry]);
    return (
        <div className="space-y-2">
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        id={id}
                        type="button"
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        aria-invalid={!!error}
                        aria-describedby={error ? `${id}-error` : undefined}
                        className="frontline-tap h-auto min-h-11 w-full justify-between gap-2 text-left"
                    >
                        <span className="min-w-0">
                            <span className="block truncate">
                                {value?.label ?? placeholder}
                            </span>
                            {value?.description && (
                                <span className="text-caption block truncate font-normal">
                                    {value.description}
                                </span>
                            )}
                        </span>
                        <ChevronsUpDown
                            className="size-4 shrink-0"
                            aria-hidden="true"
                        />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    className="w-[min(88vw,440px)] p-0"
                    align="start"
                >
                    <Command shouldFilter={false}>
                        <CommandInput
                            value={query}
                            onValueChange={setQuery}
                            placeholder={`Search ${kind === 'person' ? 'people' : `${kind}s`}…`}
                            aria-label={`Search ${kind === 'person' ? 'people' : `${kind}s`}`}
                        />
                        <CommandList>
                            {loading ? (
                                <div
                                    role="status"
                                    className="text-muted-foreground p-4 text-sm"
                                >
                                    Loading choices…
                                </div>
                            ) : failed ? (
                                <div
                                    role="alert"
                                    className="space-y-2 p-4 text-sm"
                                >
                                    <p>{failed}</p>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setRetry(retry + 1)}
                                    >
                                        <RefreshCw className="size-4" /> Retry
                                    </Button>
                                </div>
                            ) : (
                                <>
                                    <CommandEmpty>
                                        No matching choices.
                                    </CommandEmpty>
                                    <CommandGroup>
                                        {options.map((option) => (
                                            <CommandItem
                                                key={option.value}
                                                value={option.value}
                                                onSelect={() => {
                                                    onChange(option);
                                                    setOpen(false);
                                                    setCustom(false);
                                                }}
                                                className="frontline-tap"
                                            >
                                                <span className="min-w-0">
                                                    <span className="block font-medium">
                                                        {option.label}
                                                    </span>
                                                    {option.description && (
                                                        <span className="text-caption block">
                                                            {option.description}
                                                        </span>
                                                    )}
                                                </span>
                                                {value?.value ===
                                                    option.value && (
                                                    <Check
                                                        className="ml-auto size-4"
                                                        aria-hidden="true"
                                                    />
                                                )}
                                            </CommandItem>
                                        ))}
                                    </CommandGroup>
                                </>
                            )}
                        </CommandList>
                    </Command>
                    <div className="flex flex-wrap gap-2 border-t p-2">
                        {optional && (
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => {
                                    onChange(null);
                                    setOpen(false);
                                }}
                            >
                                Clear selection
                            </Button>
                        )}
                        {allowCustom && (
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => {
                                    setCustom(true);
                                    setOpen(false);
                                }}
                            >
                                <Plus className="size-4" /> Add someone else
                            </Button>
                        )}
                    </div>
                    <p className="text-caption px-3 pb-2">
                        Searches your permitted directory. Results are limited.
                    </p>
                </PopoverContent>
            </Popover>
            {custom && allowCustom && (
                <div className="flex flex-wrap gap-2">
                    <Input
                        aria-label="Name of someone else"
                        value={customName}
                        onChange={(event) => setCustomName(event.target.value)}
                        className="min-w-0 flex-1"
                        placeholder="Their full name"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        disabled={!customName.trim()}
                        onClick={() => {
                            onChange({
                                value: `custom:${customName.trim()}`,
                                label: customName.trim(),
                            });
                            setCustom(false);
                        }}
                    >
                        Use name
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => setCustom(false)}
                    >
                        Cancel
                    </Button>
                </div>
            )}
        </div>
    );
}

export function OutcomeFields({
    prefix,
    value,
    onChange,
    errors,
    includeStart = false,
}: {
    prefix: string;
    value: Omit<OutcomeDraft, 'client_medication_id'>;
    onChange: (patch: Partial<OutcomeDraft>) => void;
    errors: Record<string, string>;
    includeStart?: boolean;
}) {
    const choices: ReviewOutcome[] = [
        'continue',
        'change',
        'stop',
        'swap',
        'watch',
        ...(includeStart ? ['start' as const] : []),
    ];
    return (
        <div className="space-y-3">
            <Field
                id={`${prefix}-outcome`}
                label="Outcome"
                required
                error={errors[`${prefix}-outcome`]}
            >
                <Select
                    value={value.outcome || undefined}
                    onValueChange={(outcome) =>
                        onChange({ outcome: outcome as ReviewOutcome })
                    }
                >
                    <SelectTrigger
                        id={`${prefix}-outcome`}
                        aria-invalid={!!errors[`${prefix}-outcome`]}
                        className="w-full"
                    >
                        <SelectValue placeholder="Choose the outcome" />
                    </SelectTrigger>
                    <SelectContent>
                        {choices.map((outcome) => (
                            <SelectItem key={outcome} value={outcome}>
                                {OUTCOME_LABELS[outcome]}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>
            {recommendationOutcome(value.outcome) && (
                <Field
                    id={`${prefix}-recommendation`}
                    label="What the clinician recommends"
                    required
                    error={errors[`${prefix}-recommendation`]}
                    hint="This is a recommendation. It does not change the prescription."
                >
                    <Textarea
                        id={`${prefix}-recommendation`}
                        value={value.recommendation}
                        onChange={(event) =>
                            onChange({ recommendation: event.target.value })
                        }
                        aria-invalid={!!errors[`${prefix}-recommendation`]}
                        rows={3}
                    />
                </Field>
            )}
            {value.outcome === 'watch' && (
                <>
                    <Field
                        id={`${prefix}-watch_text`}
                        label="What to watch for"
                        required
                        error={errors[`${prefix}-watch_text`]}
                    >
                        <Textarea
                            id={`${prefix}-watch_text`}
                            value={value.watch_text}
                            onChange={(event) =>
                                onChange({ watch_text: event.target.value })
                            }
                            rows={2}
                            aria-invalid={!!errors[`${prefix}-watch_text`]}
                        />
                    </Field>
                    <Field
                        id={`${prefix}-watch_until`}
                        label="Watch until"
                        required
                        error={errors[`${prefix}-watch_until`]}
                    >
                        <DatePicker
                            id={`${prefix}-watch_until`}
                            label="Watch until"
                            value={value.watch_until}
                            onChange={(watch_until) =>
                                onChange({ watch_until })
                            }
                            invalid={!!errors[`${prefix}-watch_until`]}
                        />
                    </Field>
                </>
            )}
        </div>
    );
}
export function validateOutcome(
    prefix: string,
    value: Omit<OutcomeDraft, 'client_medication_id'>,
): Record<string, string> {
    if (!value.outcome || value.outcome === 'pending_controlled')
        return { [`${prefix}-outcome`]: 'Choose the outcome.' };
    if (recommendationOutcome(value.outcome) && !value.recommendation.trim())
        return {
            [`${prefix}-recommendation`]: 'Say what the clinician recommends.',
        };
    if (value.outcome === 'watch' && !value.watch_text.trim())
        return { [`${prefix}-watch_text`]: 'Say what to watch for.' };
    if (value.outcome === 'watch' && !value.watch_until)
        return { [`${prefix}-watch_until`]: 'Choose until when.' };
    return {};
}

export function SourceUpload({
    id,
    file,
    onChange,
    error,
    processing = false,
}: {
    id: string;
    file: File | null;
    onChange: (file: File | null) => void;
    error?: string;
    processing?: boolean;
}) {
    const [localError, setLocalError] = useState<string | undefined>();
    const choose = (files: File[]) => {
        const next = files[0];
        if (!next) return;
        if (
            ![
                'application/pdf',
                'image/jpeg',
                'image/png',
                'image/webp',
            ].includes(next.type)
        ) {
            setLocalError('Choose a PDF, JPEG, PNG or WebP file.');
            return;
        }
        if (next.size > 10 * 1024 * 1024) {
            setLocalError('Choose a file smaller than 10 MB.');
            return;
        }
        setLocalError(undefined);
        onChange(next);
    };
    return (
        <div className="space-y-2">
            {file ? (
                <StagedFileCard
                    file={file}
                    onRemove={() => !processing && onChange(null)}
                >
                    <StatusBadge variant={processing ? 'info' : 'neutral'}>
                        {processing
                            ? 'Uploading with the review…'
                            : 'Selected — uploads when you save'}
                    </StatusBadge>
                </StagedFileCard>
            ) : (
                <FileDropzone
                    id={id}
                    aria-labelledby={`${id}-label`}
                    aria-invalid={!!(error || localError)}
                    multiple={false}
                    disabled={processing}
                    accept="application/pdf,image/jpeg,image/png,image/webp"
                    hint="One PDF, JPEG, PNG or WebP · up to 10 MB · private storage"
                    onFiles={choose}
                />
            )}
            <InputError id={`${id}-error`} message={error || localError} />
        </div>
    );
}
export function StoredSource({
    source,
    id,
}: {
    source: SourceFile;
    id: string;
}) {
    const [open, setOpen] = useState(false);
    const file: PreviewFile = {
        id,
        name: source.name,
        mime: source.mime,
        bytes: source.size,
        previewUrl: source.view_url,
        downloadUrl: source.download_url,
        source: 'Medication review · private source',
    };
    return (
        <>
            <Button
                type="button"
                variant="outline"
                onClick={() => setOpen(true)}
                className="h-auto w-full justify-start gap-3 whitespace-normal py-3 text-left"
            >
                <FileText
                    className="text-primary size-5 shrink-0"
                    aria-hidden="true"
                />
                <span className="min-w-0">
                    <span className="block break-words font-medium">
                        {source.name}
                    </span>
                    <span className="text-caption block">
                        Stored privately · preview and download
                    </span>
                </span>
            </Button>
            <FilePreviewDialog
                file={open ? file : null}
                onClose={() => setOpen(false)}
            />
        </>
    );
}

export function useReviewCommand() {
    const [processing, setProcessing] = useState(false);
    const [saved, setSaved] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const pending = useRef(false);
    const submit = async (
        url: string,
        data: ReviewRequestData,
        method: 'post' | 'put' | 'delete' = 'post',
    ) => {
        if (pending.current) return;
        if (!navigator.onLine) {
            setErrors({
                _request:
                    'You’re offline. Keep this form open and try again when you’re connected. Nothing has been confirmed as saved.',
            });
            return;
        }
        pending.current = true;
        setProcessing(true);
        setErrors({});
        try {
            await reviewRequest(url, data, method);
            setSaved(true);
            router.reload({ preserveScroll: true });
        } catch (problem) {
            setErrors(
                problem instanceof ReviewRequestError
                    ? problem.errors
                    : {
                          _request:
                              'We couldn’t confirm the save. Keep this form open and try again.',
                      },
            );
        } finally {
            pending.current = false;
            setProcessing(false);
        }
    };
    return { submit, processing, saved, errors, setErrors };
}
export function DiscardDialog({
    open,
    onClose,
    onDiscard,
}: {
    open: boolean;
    onClose: () => void;
    onDiscard: () => void;
}) {
    return (
        <ConfirmDialog
            open={open}
            onClose={onClose}
            onConfirm={onDiscard}
            title="Discard these changes?"
            description="The form entries and selected files will be lost. Nothing has been confirmed as saved."
            confirmText="Discard"
            cancelText="Keep going"
        />
    );
}
export function RequestErrors({ errors }: { errors: Record<string, string> }) {
    const entries = Object.entries(errors);
    return entries.length ? (
        <div role="alert" className="text-status-critical space-y-1 text-sm">
            <p className="font-semibold">We couldn’t save this yet.</p>
            {entries.map(([key, value]) => (
                <p key={key}>{value}</p>
            ))}
            {errors._request && (
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => router.reload({ preserveScroll: true })}
                >
                    <RefreshCw className="size-4" /> Refresh review data
                </Button>
            )}
        </div>
    ) : null;
}
