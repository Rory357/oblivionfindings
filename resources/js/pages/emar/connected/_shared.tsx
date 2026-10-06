import { ConfirmDialog } from '@/components/confirm-dialog';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import {
    EntityTable,
    type EntityTableColumn,
} from '@/components/lists/entity-table';
import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
} from '@/components/page/page-header';
import { RecordPicker as BaseRecordPicker } from '@/components/people-locations/record-picker';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Field } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { toDatetimeLocal, workerTimeOffsets } from '@/lib/datetime';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Check, ClipboardCheck, Link2 } from 'lucide-react';
import {
    useEffect,
    useRef,
    useState,
    type ComponentProps,
    type ReactNode,
} from 'react';

export function useCommand() {
    const guard = useRef(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [uncertain, setUncertain] = useState(false);
    async function run<T = Record<string, unknown>>(
        url: string,
        data: unknown,
        method: 'post' | 'put' = 'post',
    ): Promise<T | null> {
        if (guard.current || uncertain) return null;
        guard.current = true;
        setBusy(true);
        setError('');
        try {
            return (
                await axios.request<T>({
                    url,
                    method,
                    data,
                    headers: { Accept: 'application/json' },
                })
            ).data;
        } catch (e) {
            if (
                axios.isAxiosError(e) &&
                e.response &&
                e.response.status < 500
            ) {
                const values = Object.values(
                    e.response.data?.errors ?? {},
                ).flat();
                setError(
                    values.length
                        ? values.join(' ')
                        : (e.response.data?.message ??
                              'This action is unavailable. Refresh and check your access.'),
                );
            } else {
                setUncertain(true);
                setError(
                    'The result could not be confirmed. Close and refresh this page to check the saved record before trying again.',
                );
            }
            return null;
        } finally {
            guard.current = false;
            setBusy(false);
        }
    }
    return { run, busy, error, uncertain };
}

export function ConnectedHeader({
    title,
    subline,
    view,
    tabs,
    onView,
    query,
    onQuery,
    actions,
    filters,
    meters,
    parent = { title: 'Connected services', href: '/emar/connections' },
    children,
}: {
    title: string;
    parent?: { title: string; href: string };
    subline: string;
    view: string;
    tabs: { key: string; label: string }[];
    onView: (view: string) => void;
    query: string;
    onQuery: (value: string) => void;
    actions?: ReactNode;
    filters?: ReactNode;
    meters: {
        label: string;
        value: number | string;
        caption: string;
        view: string;
    }[];
    children: ReactNode;
}) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/my-day' },
                { title: 'Medication', href: '/meds/today' },
                parent,
                { title, href: '#' },
            ]}
        >
            <Head title={title} />
            <div className="space-y-5">
                <PageHeader
                    icon={Link2}
                    title={title}
                    subline={subline}
                    actions={
                        <>
                            <PageHeaderSearch
                                value={query}
                                onChange={onQuery}
                                placeholder={'Search ' + title.toLowerCase()}
                            />
                            {actions}
                        </>
                    }
                    filters={filters}
                    meters={meters.map((m) => (
                        <PageHeaderMeterBlock
                            key={m.label}
                            label={m.label}
                            onClick={() => onView(m.view)}
                        >
                            <PageHeaderMeterBig>{m.value}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {m.caption}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ))}
                    rail={
                        <PageHeaderRail
                            value={view}
                            items={tabs.map((t) => ({ ...t, icon: Link2 }))}
                            onSelect={onView}
                            showFind={tabs.length > 3}
                        />
                    }
                />
                {children}
            </div>
        </AppLayout>
    );
}

export function BoundedTable<T extends { id: number | string }>({
    rows,
    identity,
    columns,
    open,
    empty = 'No records in this view.',
}: {
    rows: T[];
    identity: (row: T) => { name: string; subline?: ReactNode };
    columns: EntityTableColumn<T>[];
    open?: (row: T) => void;
    empty?: string;
}) {
    const [page, setPage] = useState(1);
    const last = Math.max(1, Math.ceil(rows.length / 25));
    const current = Math.min(page, last);
    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <p className="text-caption">
                    {rows.length
                        ? (current - 1) * 25 +
                          1 +
                          '–' +
                          Math.min(current * 25, rows.length) +
                          ' of ' +
                          rows.length
                        : '0 records'}
                </p>
                {last > 1 && (
                    <div className="flex items-center gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={current === 1}
                            onClick={() => setPage(current - 1)}
                        >
                            Previous
                        </Button>
                        <span className="text-caption">
                            Page {current} of {last}
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={current === last}
                            onClick={() => setPage(current + 1)}
                        >
                            Next
                        </Button>
                    </div>
                )}
            </div>
            {rows.length ? (
                <EntityTable
                    rows={rows.slice((current - 1) * 25, current * 25)}
                    rowKey={(r) => r.id}
                    identity={identity}
                    wrapIdentity
                    rowHeight="content"
                    columns={columns}
                    onOpen={open}
                    actionsFor={(r) =>
                        open ? [{ label: 'Open', onClick: () => open(r) }] : []
                    }
                />
            ) : (
                <SettingsNotice>{empty}</SettingsNotice>
            )}
        </div>
    );
}

export function ReviewWizard({
    title,
    description,
    steps,
    review,
    onClose,
    onSave,
    busy,
    error,
    disabled = false,
    dirty = true,
    saved,
    saveLabel = 'Save',
    success = 'Saved',
    successDetail = 'The record has been saved.',
}: {
    title: string;
    description: string;
    steps: { label: string; content: ReactNode; valid?: boolean }[];
    review: { label: string; value: ReactNode }[];
    onClose: () => void;
    onSave: () => void;
    busy: boolean;
    error: string;
    disabled?: boolean;
    dirty?: boolean;
    saved: boolean;
    saveLabel?: string;
    success?: string;
    successDetail?: string;
}) {
    const [step, setStep] = useState(0);
    const [discard, setDiscard] = useState(false);
    const close = () => {
        if (!busy) {
            if (dirty && !saved) setDiscard(true);
            else onClose();
        }
    };
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={title}
                description={description}
                railIcon={ClipboardCheck}
                railTitle={title}
                railSub={description}
                maxWidth="min(94vw, 1100px)"
                steps={[
                    ...steps.map((s, i) => ({
                        key: String(i),
                        label: s.label,
                        blurb: 'Enter and check details',
                        icon: ClipboardCheck,
                        disabled:
                            busy ||
                            steps
                                .slice(0, i)
                                .some((previous) => previous.valid === false),
                    })),
                    {
                        key: 'review',
                        disabled:
                            busy ||
                            steps.some((previous) => previous.valid === false),
                        label: 'Review',
                        blurb: 'Check before saving',
                        icon: Check,
                    },
                ]}
                stepIndex={step}
                onStepClick={(next) => {
                    if (
                        !busy &&
                        !steps
                            .slice(0, next)
                            .some((previous) => previous.valid === false)
                    )
                        setStep(next);
                }}
                pct={Math.round(((step + 1) / (steps.length + 1)) * 100)}
                pctLabel="Steps"
                footerStart={
                    <Button variant="outline" onClick={close} disabled={busy}>
                        Cancel
                    </Button>
                }
                footerEnd={
                    <div className="flex gap-2">
                        {step > 0 && (
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => setStep(step - 1)}
                            >
                                Back
                            </Button>
                        )}
                        {step < steps.length ? (
                            <Button
                                disabled={busy || steps[step].valid === false}
                                onClick={() => setStep(step + 1)}
                            >
                                Continue
                            </Button>
                        ) : (
                            <Button
                                disabled={
                                    busy ||
                                    disabled ||
                                    steps.some((s) => s.valid === false)
                                }
                                onClick={onSave}
                            >
                                {busy ? 'Saving…' : saveLabel}
                            </Button>
                        )}
                    </div>
                }
                success={
                    saved ? (
                        <WizardSuccessPane
                            title={success}
                            blurb={successDetail}
                            actions={<Button onClick={onClose}>Done</Button>}
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    {error && (
                        <SettingsNotice role="alert">{error}</SettingsNotice>
                    )}
                    {step < steps.length ? (
                        steps[step].content
                    ) : (
                        <ReviewCard
                            icon={ClipboardCheck}
                            title="Confirm these details"
                        >
                            {review.map((r) => (
                                <ReviewRow
                                    key={r.label}
                                    label={r.label}
                                    value={r.value}
                                />
                            ))}
                        </ReviewCard>
                    )}
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard this draft?"
                description="Your unsaved changes will be lost."
                confirmText="Discard draft"
            />
        </>
    );
}

export function SingleFile({
    file,
    onChange,
    accept = '.pdf,.png,.jpg,.jpeg',
    hint = 'PDF, JPEG or PNG · up to 10 MB',
}: {
    file: File | null;
    onChange: (f: File | null) => void;
    accept?: string;
    hint?: string;
}) {
    return file ? (
        <StagedFileCard file={file} onRemove={() => onChange(null)} />
    ) : (
        <FileDropzone
            multiple={false}
            accept={accept}
            hint={hint}
            onFiles={(files) => onChange(files[0] ?? null)}
        />
    );
}

export function multipart(data: Record<string, unknown>): FormData {
    const form = new FormData();
    const add = (key: string, value: unknown) => {
        if (value === undefined) return;
        if (value === null) {
            form.append(key, '');
            return;
        }
        if (value instanceof File) {
            form.append(key, value);
            return;
        }
        if (typeof value === 'object') {
            Object.entries(value).forEach(([k, v]) =>
                add(key + '[' + k + ']', v),
            );
            return;
        }
        form.append(
            key,
            typeof value === 'boolean' ? (value ? '1' : '0') : String(value),
        );
    };
    Object.entries(data).forEach(([k, v]) => add(k, v));
    return form;
}

export function NzDateTime({
    id,
    label,
    value,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    compact?: boolean;
}) {
    const local = /[+-]\d{2}:\d{2}$|Z$/.test(value)
        ? toDatetimeLocal(value)
        : value;
    const offsets = workerTimeOffsets(local);
    const chosen = offsets.find((o) => value.endsWith(o)) ?? '';
    const change = (next: string) => {
        const choices = workerTimeOffsets(next);
        onChange(choices.length === 1 ? next + ':00' + choices[0] : next);
    };
    return (
        <div className="space-y-2">
            <DateTimeField
                compact
                id={id}
                label={label}
                value={local}
                onChange={change}
            />
            {local && offsets.length === 0 && (
                <SettingsNotice role="alert">
                    Choose a complete New Zealand date and time. This time may
                    fall in the daylight-saving gap.
                </SettingsNotice>
            )}
            {offsets.length > 1 && (
                <RecordPicker
                    label="Which occurrence of this repeated time?"
                    value={chosen}
                    options={offsets.map((o) => ({
                        value: o,
                        label: 'UTC' + o,
                    }))}
                    onChange={(offset) => onChange(local + ':00' + offset)}
                />
            )}
        </div>
    );
}
export function ServerPages({
    meta,
    name,
    path,
}: {
    meta?: { current_page: number; last_page: number; total: number };
    name: string;
    path: string;
}) {
    if (!meta || meta.last_page < 2) return null;
    const url = (page: number) => {
        const target = new URL(window.location.href);
        target.pathname = path;
        target.searchParams.set(name + '_page', String(page));
        return target.pathname + target.search + window.location.hash;
    };
    return (
        <div className="flex items-center justify-end gap-3">
            <p className="text-caption">
                {meta.total} records · batch {meta.current_page} of{' '}
                {meta.last_page}
            </p>
            <Button
                variant="outline"
                size="sm"
                disabled={meta.current_page === 1}
                onClick={() => {
                    window.location.assign(url(meta.current_page - 1));
                }}
            >
                Previous batch
            </Button>
            <Button
                variant="outline"
                size="sm"
                disabled={meta.current_page === meta.last_page}
                onClick={() => {
                    window.location.assign(url(meta.current_page + 1));
                }}
            >
                Next batch
            </Button>
        </div>
    );
}

export function RemotePicker<T>({
    label,
    url,
    value,
    onChange,
    rows,
    meta,
    option,
    queryKey = 'q',
}: {
    label: string;
    url: string;
    value: string;
    onChange: (id: string, row: T) => void;
    rows: (data: Record<string, unknown>) => T[];
    meta: (data: Record<string, unknown>) => {
        current_page: number;
        last_page: number;
        total: number;
    };
    option: (row: T) => { id: string; label: string; description: string };
    queryKey?: string;
}) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const [data, setData] = useState<T[]>([]);
    const [pages, setPages] = useState(1);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [selected, setSelected] = useState('');
    const callbacks = useRef({ rows, meta });
    useEffect(() => {
        callbacks.current = { rows, meta };
    }, [rows, meta]);
    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        const timer = setTimeout(() => {
            setBusy(true);
            setError('');
            void axios
                .get(url, {
                    params: { [queryKey]: q, page },
                    signal: controller.signal,
                    headers: { Accept: 'application/json' },
                })
                .then(({ data: result }) => {
                    if (!controller.signal.aborted) {
                        setData(callbacks.current.rows(result));
                        setPages(callbacks.current.meta(result).last_page);
                    }
                })
                .catch((e) => {
                    if (!axios.isCancel(e)) {
                        setData([]);
                        setError(
                            'Could not load permitted records. Close and reopen to retry.',
                        );
                    }
                })
                .finally(() => {
                    if (!controller.signal.aborted) setBusy(false);
                });
        }, 250);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [open, q, page, url, queryKey]);
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    className="w-full justify-start"
                    role="combobox"
                    aria-label={label}
                    aria-expanded={open}
                >
                    {selected || value || 'Choose ' + label.toLowerCase()}
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-[480px] max-w-[90vw] space-y-3"
                align="start"
            >
                <Input
                    aria-label={'Search ' + label.toLowerCase()}
                    placeholder={'Search ' + label.toLowerCase()}
                    value={q}
                    onChange={(e) => {
                        setQ(e.target.value);
                        setPage(1);
                    }}
                />
                {busy ? (
                    <p role="status" className="text-caption">
                        Searching…
                    </p>
                ) : error ? (
                    <p role="alert">{error}</p>
                ) : (
                    <div className="max-h-64 space-y-1 overflow-y-auto">
                        {data.length ? (
                            data.map((row) => {
                                const item = option(row);
                                return (
                                    <Button
                                        key={item.id}
                                        variant="ghost"
                                        className="h-auto w-full justify-start py-2 text-left"
                                        onClick={() => {
                                            onChange(item.id, row);
                                            setSelected(item.label);
                                            setOpen(false);
                                        }}
                                    >
                                        <span>
                                            <span className="block">
                                                {item.label}
                                            </span>
                                            <span className="text-caption block whitespace-normal">
                                                {item.description}
                                            </span>
                                        </span>
                                    </Button>
                                );
                            })
                        ) : (
                            <p className="text-caption">
                                No permitted records match.
                            </p>
                        )}
                    </div>
                )}
                {pages > 1 && (
                    <div className="flex justify-between">
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={page === 1}
                            onClick={() => setPage(page - 1)}
                        >
                            Previous
                        </Button>
                        <span className="text-caption">
                            {page} / {pages}
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={page === pages}
                            onClick={() => setPage(page + 1)}
                        >
                            Next
                        </Button>
                    </div>
                )}
            </PopoverContent>
        </Popover>
    );
}

export function useWorkspaceView(initial: string, allowed: string[]) {
    const [view, setView] = useState(() => {
        const hash =
            typeof window === 'undefined' ? '' : window.location.hash.slice(1);
        return allowed.includes(hash) ? hash : initial;
    });
    const change = (next: string) => {
        if (!allowed.includes(next)) return;
        setView(next);
        window.history.replaceState(
            window.history.state,
            '',
            window.location.pathname + window.location.search + '#' + next,
        );
    };
    return [view, change] as const;
}

export function RecordPicker(props: ComponentProps<typeof BaseRecordPicker>) {
    return props.variant === 'header' ? (
        <BaseRecordPicker {...props} />
    ) : (
        <Field label={props.label}>
            <BaseRecordPicker {...props} />
        </Field>
    );
}
