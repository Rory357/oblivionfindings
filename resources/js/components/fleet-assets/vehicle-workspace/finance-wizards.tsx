import { Button } from '@/components/ui/button';
import {
    Command,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    type WizardStep,
} from '@/components/wizard/shell';
import { formatCurrency } from '@/lib/fleet-utils';
import {
    Check,
    ChevronsUpDown,
    FileCheck2,
    Landmark,
    Lock,
    ReceiptText,
    Search,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { uploadSummary } from './evidence-upload';
import {
    FinanceNotice,
    requestSourceValue,
    useFinanceEvidenceUpload,
    vehicleContext,
} from './finance-shared';
import type {
    FinanceRecordType,
    FinanceRequestType,
    LinkableFinancePage,
    LinkableFinanceRecord,
    VehicleFinanceWorkspace,
} from './finance-types';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import { VehicleSearchSelect } from './search-select';
import type { VehicleWorkspace } from './types';
import {
    fieldProps,
    StagedFilesField,
    WizardField,
    WizardSuccess,
    WorkspaceWizard,
} from './wizard-kit';

/** A Finance record chosen in a picker; the canonical id is what is saved. */
export type PickedFinanceRecord = {
    type: FinanceRecordType;
    id: number;
    label: string;
    detail: string;
};

const GROUP_LABELS: Record<FinanceRecordType, string> = {
    fixed_asset: 'Fixed assets',
    purchase_order: 'Purchase orders',
    bill: 'Supplier invoices',
};

const SCOPES = ['recent', 'search', 'site_cost_centre', 'none'] as const;

class SearchAccessError extends Error {}

function recordLabel(record: LinkableFinanceRecord): string {
    return [record.reference, record.name].filter(Boolean).join(' · ');
}

function readPage(value: unknown): LinkableFinancePage | null {
    if (!isJsonObject(value) || !Array.isArray(value.results)) return null;
    const results = value.results.filter(
        (item): item is LinkableFinanceRecord =>
            isJsonObject(item) &&
            typeof item.id === 'number' &&
            typeof item.name === 'string' &&
            typeof item.type === 'string' &&
            typeof item.detail === 'string',
    );
    const scope = SCOPES.find((known) => known === value.scope) ?? 'recent';

    return {
        results,
        next_before:
            typeof value.next_before === 'number' ? value.next_before : null,
        scope,
    };
}

type TypePage = {
    results: LinkableFinanceRecord[];
    next: number | null;
    scope: LinkableFinancePage['scope'];
};

/**
 * Searchable Finance record selector with scoped, bounded server search
 * (POPUP_STYLE_GUIDE § Searchable record selectors). Results come only from
 * records this vehicle may link; a failed search keeps the current choice.
 */
export function FinanceRecordPicker({
    id,
    label,
    vehicleId,
    types,
    value,
    onChange,
    noneLabel,
    noneDetail,
    invalid,
    describedBy,
}: {
    id: string;
    label: string;
    vehicleId: number;
    types: FinanceRecordType[];
    value: PickedFinanceRecord | null;
    onChange: (value: PickedFinanceRecord | null) => void;
    noneLabel: string;
    noneDetail: string;
    invalid?: boolean;
    describedBy?: string;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState<
        'idle' | 'loading' | 'ready' | 'error' | 'denied'
    >('idle');
    const [pages, setPages] = useState<
        Partial<Record<FinanceRecordType, TypePage>>
    >({});
    const request = useRef<AbortController | null>(null);
    const epoch = useRef(0);
    const typeKey = types.join(',');

    const load = useCallback(
        async (
            search: string,
            more?: { type: FinanceRecordType; before: number },
        ) => {
            request.current?.abort();
            const controller = new AbortController();
            request.current = controller;
            const token = ++epoch.current;
            setStatus('loading');
            if (!more) setPages({});
            try {
                const targets = more
                    ? [more.type]
                    : (typeKey.split(',') as FinanceRecordType[]);
                const loaded = await Promise.all(
                    targets.map(async (type) => {
                        const params = new URLSearchParams({ type, q: search });
                        if (more) params.set('before', String(more.before));
                        const response = await fetch(
                            `/fleet-assets/vehicles/${vehicleId}/finance/linkable?${params.toString()}`,
                            {
                                credentials: 'same-origin',
                                cache: 'no-store',
                                headers: {
                                    Accept: 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                                signal: controller.signal,
                            },
                        );
                        if ([401, 403, 404, 419].includes(response.status))
                            throw new SearchAccessError();
                        const page = response.ok
                            ? readPage(await response.json())
                            : null;
                        if (!page) throw new Error('Unconfirmed results');
                        return [type, page] as const;
                    }),
                );
                if (token !== epoch.current) return;
                setPages((current) => {
                    const next: Partial<Record<FinanceRecordType, TypePage>> =
                        more ? { ...current } : {};
                    loaded.forEach(([type, page]) => {
                        const earlier = more ? (next[type]?.results ?? []) : [];
                        const seen = new Set(earlier.map((item) => item.id));
                        next[type] = {
                            results: [
                                ...earlier,
                                ...page.results.filter(
                                    (item) => !seen.has(item.id),
                                ),
                            ],
                            next: page.next_before,
                            scope: page.scope,
                        };
                    });
                    return next;
                });
                setStatus('ready');
            } catch (error) {
                if (controller.signal.aborted || token !== epoch.current)
                    return;
                setStatus(
                    error instanceof SearchAccessError ? 'denied' : 'error',
                );
            }
        },
        [typeKey, vehicleId],
    );

    useEffect(() => {
        if (!open) return;
        const timer = window.setTimeout(
            () => void load(query.trim()),
            query ? 250 : 0,
        );
        return () => window.clearTimeout(timer);
    }, [open, query, load]);
    useEffect(
        () => () => {
            ++epoch.current;
            request.current?.abort();
        },
        [],
    );

    const choose = (next: PickedFinanceRecord | null) => {
        onChange(next);
        setOpen(false);
        setQuery('');
    };
    const empty =
        status === 'ready' &&
        types.every((type) => !pages[type]?.results.length);
    const orders = pages.purchase_order;

    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (!next) setQuery('');
            }}
        >
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-label={label}
                    aria-expanded={open}
                    aria-invalid={invalid}
                    aria-describedby={describedBy}
                    className="w-full justify-between font-normal"
                >
                    <Search className="size-4 shrink-0 text-muted-foreground" />
                    <span className="min-w-0 flex-1 truncate text-left">
                        {value ? value.label : noneLabel}
                    </span>
                    <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-[var(--radix-popover-trigger-width)] min-w-72 p-0"
                align="start"
            >
                <Command shouldFilter={false}>
                    <CommandInput
                        aria-label={`Search ${label.toLowerCase()}`}
                        placeholder="Search name or reference…"
                        value={query}
                        onValueChange={setQuery}
                    />
                    <CommandList>
                        <CommandGroup>
                            <CommandItem
                                value="none"
                                onSelect={() => choose(null)}
                            >
                                <div className="min-w-0 flex-1">
                                    <span>{noneLabel}</span>
                                    <p className="text-xs text-muted-foreground">
                                        {noneDetail}
                                    </p>
                                </div>
                                {!value && (
                                    <Check className="size-4 text-primary" />
                                )}
                            </CommandItem>
                        </CommandGroup>
                        {types.map((type) => {
                            const page = pages[type];
                            if (!page || !page.results.length) return null;
                            return (
                                <CommandGroup
                                    key={type}
                                    heading={GROUP_LABELS[type]}
                                >
                                    {page.results.map((record) => (
                                        <CommandItem
                                            key={`${type}-${record.id}`}
                                            value={`${type}-${record.id}`}
                                            onSelect={() =>
                                                choose({
                                                    type,
                                                    id: record.id,
                                                    label: recordLabel(record),
                                                    detail: record.detail,
                                                })
                                            }
                                        >
                                            <div className="min-w-0 flex-1">
                                                <span className="block truncate">
                                                    {recordLabel(record)}
                                                </span>
                                                <p className="text-xs text-muted-foreground">
                                                    {record.detail}
                                                </p>
                                            </div>
                                            {value?.type === type &&
                                                value.id === record.id && (
                                                    <Check className="size-4 text-primary" />
                                                )}
                                        </CommandItem>
                                    ))}
                                    {page.next !== null && (
                                        <CommandItem
                                            value={`${type}-more`}
                                            onSelect={() =>
                                                void load(query.trim(), {
                                                    type,
                                                    before: page.next as number,
                                                })
                                            }
                                        >
                                            <span className="text-primary">
                                                Show more{' '}
                                                {GROUP_LABELS[
                                                    type
                                                ].toLowerCase()}
                                            </span>
                                        </CommandItem>
                                    )}
                                </CommandGroup>
                            );
                        })}
                        {status === 'loading' && (
                            <div className="flex items-center gap-2 px-3 py-2 text-xs text-muted-foreground">
                                <Spinner className="size-3.5" />
                                Searching Finance records…
                            </div>
                        )}
                        {status === 'ready' && orders?.scope === 'none' && (
                            <p className="px-3 py-2 text-xs text-muted-foreground">
                                This site has no cost centre in Finance. Search
                                by purchase order number or supplier.
                            </p>
                        )}
                        {status === 'ready' &&
                            orders?.scope === 'site_cost_centre' && (
                                <p className="px-3 py-2 text-xs text-muted-foreground">
                                    Showing purchase orders for the site cost
                                    centre. Search to find others.
                                </p>
                            )}
                        {empty && (
                            <p className="px-3 py-2 text-xs text-muted-foreground">
                                No matching records. Try a different name or
                                reference.
                            </p>
                        )}
                        {status === 'error' && (
                            <div
                                role="alert"
                                className="grid gap-2 px-3 py-2 text-xs text-status-critical"
                            >
                                Search unavailable. Your selection has been
                                kept.
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onKeyDown={(event) =>
                                        event.stopPropagation()
                                    }
                                    onClick={() => void load(query.trim())}
                                >
                                    Retry search
                                </Button>
                            </div>
                        )}
                        {status === 'denied' && (
                            <p
                                role="alert"
                                className="px-3 py-2 text-xs text-status-warning"
                            >
                                You no longer have access to these Finance
                                records. Reload this vehicle to check what is
                                available.
                            </p>
                        )}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

/** A read-only field value that this vehicle cannot change. */
function LockedChoice({
    id,
    label,
    name,
    detail,
}: {
    id: string;
    label: string;
    name: string;
    detail: string;
}) {
    return (
        <div
            id={id}
            role="group"
            aria-label={`${label}: ${name}`}
            className="flex w-full items-center gap-2.5 rounded-md border bg-muted/40 px-3 py-2 text-sm"
        >
            <Lock
                className="size-4 shrink-0 text-muted-foreground"
                aria-hidden
            />
            <span className="min-w-0 flex-1">
                <span className="block truncate font-medium">{name}</span>
                <span className="block text-xs text-muted-foreground">
                    {detail}
                </span>
            </span>
        </div>
    );
}

const LINK_STEPS: WizardStep[] = [
    {
        key: 'ownership',
        label: 'Finance ownership',
        blurb: 'Select existing records',
        icon: Landmark,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the resulting record',
        icon: FileCheck2,
    },
];

/** Link existing Finance records to the vehicle (approved "Link Finance records" flow). */
export function LinkFinanceWizard({
    workspace,
    finance,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    finance: VehicleFinanceWorkspace;
    onClose: () => void;
    onSaved: () => void;
}) {
    const vehicle = workspace.vehicle;
    const locked = finance.link_state.finance_fixed_asset;
    const [initialFixed] = useState<PickedFinanceRecord | null>(() =>
        finance.link_state.vehicle_fixed_asset
            ? {
                  type: 'fixed_asset',
                  id: finance.link_state.vehicle_fixed_asset.id,
                  label: finance.link_state.vehicle_fixed_asset.label,
                  detail: 'Linked from this vehicle',
              }
            : null,
    );
    const [fixedAsset, setFixedAsset] = useState(initialFixed);
    const [spend, setSpend] = useState<PickedFinanceRecord | null>(null);
    const [reason, setReason] = useState('');
    const [step, setStep] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [saved, setSaved] = useState(false);
    const command = useVehicleRecordCommand(isJsonObject);
    const errors = { ...command.errors, ...localErrors };
    const fixedChanged =
        !locked && (fixedAsset?.id ?? null) !== (initialFixed?.id ?? null);
    const centre = finance.cost_centre;
    const context = vehicleContext(vehicle);
    const clear = (...keys: string[]) => {
        setLocalErrors((old) => {
            const next = { ...old };
            keys.forEach((key) => delete next[key]);
            return next;
        });
        keys.forEach((key) => command.clearError(key));
    };

    // Every field is on the first step, so server errors return there.
    useEffect(() => {
        if (Object.keys(command.errors).length) setStep(0);
    }, [command.errors]);

    const validateStep = (at: number): boolean => {
        if (at !== 0) return true;
        const found: Record<string, string> = {};
        if (!fixedChanged && !spend)
            found.record_id =
                'Choose a record to link, or change the fixed asset.';
        if (!reason.trim()) found.reason = 'Record the reason for this change.';
        setLocalErrors(found);
        return Object.keys(found).length === 0;
    };

    const submit = async () => {
        if (!command.uncertain && !validateStep(0)) {
            setStep(0);
            return;
        }
        const body: Record<string, unknown> = { reason: reason.trim() };
        if (fixedChanged) body.fixed_asset_id = fixedAsset?.id ?? null;
        if (spend) {
            body.record_type = spend.type;
            body.record_id = spend.id;
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}/finance/links`,
            body,
        );
        if (result) {
            setSaved(true);
            onSaved();
        }
    };
    const fixedAssetLabel = locked
        ? locked.label
        : (fixedAsset?.label ?? 'Not linked');

    return (
        <WorkspaceWizard
            title="Link Finance records"
            description={`${vehicle.name}: select existing Finance records. Fleet cannot create approvals or post accounting entries.`}
            railIcon={Landmark}
            railSub={[vehicle.registration_number, vehicle.site?.name]
                .filter(Boolean)
                .join(' · ')}
            steps={LINK_STEPS}
            step={step}
            setStep={setStep}
            pct={reason.trim() ? 100 : 75}
            context={context}
            command={command}
            dirty={fixedChanged || !!spend || !!reason.trim()}
            saved={saved}
            submitLabel="Save Finance links"
            onValidateStep={validateStep}
            onSubmit={submit}
            onClose={onClose}
            onReload={() => {
                onSaved();
                onClose();
            }}
            errorKey={JSON.stringify(errors)}
            success={
                <WizardSuccess
                    title="Finance links saved"
                    blurb="Finance approval and payment statuses are unchanged."
                    onClose={onClose}
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Select existing records. Fleet cannot create approvals
                        or post accounting entries.
                    </p>
                    <WizardField
                        id="finance-fixed-asset"
                        label="Fixed asset record"
                        error={errors.fixed_asset_id}
                        hint={
                            locked
                                ? 'Linked by Finance. Ask Finance to change it.'
                                : fixedAsset
                                  ? fixedAsset.detail
                                  : 'Requires Finance review'
                        }
                    >
                        {locked ? (
                            <LockedChoice
                                id="finance-fixed-asset"
                                label="Fixed asset record"
                                name={locked.label}
                                detail="Linked in Finance"
                            />
                        ) : (
                            <FinanceRecordPicker
                                id="finance-fixed-asset"
                                label="Fixed asset record"
                                vehicleId={vehicle.id}
                                types={['fixed_asset']}
                                value={fixedAsset}
                                onChange={(next) => {
                                    setFixedAsset(next);
                                    clear('fixed_asset_id', 'record_id');
                                }}
                                noneLabel="Not linked"
                                noneDetail="Requires Finance review"
                                invalid={!!errors.fixed_asset_id}
                                describedBy={
                                    errors.fixed_asset_id
                                        ? 'finance-fixed-asset-error'
                                        : undefined
                                }
                            />
                        )}
                    </WizardField>
                    <WizardField
                        id="finance-cost-centre"
                        label="Cost centre"
                        hint="Follows the vehicle’s site. Finance maintains cost centres."
                    >
                        <LockedChoice
                            id="finance-cost-centre"
                            label="Cost centre"
                            name={centre?.name ?? 'Not assigned'}
                            detail={
                                centre
                                    ? `${centre.code} · site cost centre${centre.active ? '' : ' · inactive'}`
                                    : 'No site cost centre in Finance'
                            }
                        />
                    </WizardField>
                    <WizardField
                        id="finance-spend-record"
                        label="Purchase order or supplier invoice"
                        error={errors.record_id ?? errors.record_type}
                        hint={
                            finance.can.link_spend
                                ? (spend?.detail ?? 'Keep existing links')
                                : undefined
                        }
                    >
                        {finance.can.link_spend ? (
                            <FinanceRecordPicker
                                id="finance-spend-record"
                                label="Purchase order or supplier invoice"
                                vehicleId={vehicle.id}
                                types={['purchase_order', 'bill']}
                                value={spend}
                                onChange={(next) => {
                                    setSpend(next);
                                    clear('record_id', 'record_type');
                                }}
                                noneLabel="No additional record"
                                noneDetail="Keep existing links"
                                invalid={!!errors.record_id}
                                describedBy={
                                    errors.record_id
                                        ? 'finance-spend-record-error'
                                        : undefined
                                }
                            />
                        ) : (
                            <LockedChoice
                                id="finance-spend-record"
                                label="Purchase order or supplier invoice"
                                name="No additional record"
                                detail="Linking purchase orders and supplier invoices needs accounts payable access."
                            />
                        )}
                    </WizardField>
                    <WizardField
                        id="finance-link-reason"
                        label="Reason / supporting details"
                        error={errors.reason}
                    >
                        <Textarea
                            {...fieldProps(
                                'finance-link-reason',
                                errors.reason,
                            )}
                            rows={3}
                            maxLength={2000}
                            value={reason}
                            onChange={(event) => {
                                setReason(event.target.value);
                                clear('reason');
                            }}
                        />
                    </WizardField>
                </div>
            )}
            {step === 1 && (
                <div className="grid gap-4">
                    <ReviewCard
                        icon={Landmark}
                        title="Finance ownership"
                        onEdit={() => setStep(0)}
                    >
                        <ReviewRow
                            label="Fixed asset record"
                            value={fixedAssetLabel}
                        />
                        <ReviewRow
                            label="Cost centre"
                            value={centre?.name ?? 'Not assigned'}
                        />
                        <ReviewRow
                            label="Purchase order or supplier invoice"
                            value={spend?.label ?? 'No additional record'}
                        />
                        <ReviewRow
                            label="Reason / supporting details"
                            value={reason.trim() || undefined}
                        />
                    </ReviewCard>
                    {fixedChanged && initialFixed && (
                        <FinanceNotice title="What this changes">
                            {fixedAsset
                                ? `${initialFixed.label} is replaced by ${fixedAsset.label}.`
                                : `${initialFixed.label} is no longer linked.`}{' '}
                            The earlier link and this reason stay in the vehicle
                            history. Finance records are not changed.
                        </FinanceNotice>
                    )}
                </div>
            )}
        </WorkspaceWizard>
    );
}

const REQUEST_STEPS: WizardStep[] = [
    {
        key: 'request',
        label: 'Request & source',
        blurb: 'Route supporting evidence to Finance',
        icon: ReceiptText,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the resulting record',
        icon: FileCheck2,
    },
];

const AMOUNT = /^\d{1,8}(\.\d{1,2})?$/;

/**
 * Route evidence to Finance for a decision (approved "Request Finance
 * review" flow). The request is saved first; its supporting files are then
 * stored privately as documents owned by the request.
 */
export function FinanceReviewWizard({
    workspace,
    finance,
    presetSource,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    finance: VehicleFinanceWorkspace;
    /** e.g. "work_order:12" when started from a work order. */
    presetSource?: string;
    onClose: () => void;
    onSaved: () => void;
}) {
    const vehicle = workspace.vehicle;
    const canAttach = finance.can.attach_files;
    const [initial] = useState(() => ({
        existing: 'none',
        request_type: 'supplier_invoice_review' as FinanceRequestType,
        source:
            presetSource &&
            finance.sources.some((source) => source.value === presetSource)
                ? presetSource
                : 'vehicle',
        amount: '',
        note: '',
    }));
    const [form, setForm] = useState(initial);
    const [files, setFiles] = useState<File[]>([]);
    const [step, setStep] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState('');
    const [saved, setSaved] = useState<string | null>(null);
    const command = useVehicleRecordCommand(isJsonObject);
    const uploads = useFinanceEvidenceUpload(vehicle.id);
    const errors = { ...command.errors, ...localErrors };
    const hasEvidence = files.length > 0 || form.existing !== 'none';
    const typeLabel =
        finance.request_types.find((type) => type.value === form.request_type)
            ?.label ?? 'Finance review';
    const source = finance.sources.find(
        (option) => option.value === form.source,
    );
    const documentOptions = [
        canAttach
            ? {
                  value: 'none',
                  label: 'Upload new evidence instead',
                  description: 'Choose a file below',
              }
            : {
                  value: 'none',
                  label: 'No existing document',
                  description: 'Choose a current vehicle document',
              },
        ...finance.documents.map((document) => ({
            value: String(document.id),
            label: document.name,
            description: document.detail,
        })),
    ];
    const existing = documentOptions.find(
        (option) => option.value === form.existing,
    );
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((old) => ({ ...old, [key]: value }));
        setLocalErrors((old) => {
            const next = { ...old };
            delete next[key as string];
            return next;
        });
        setFormError('');
        command.clearError(key as string);
        if (key === 'existing') command.clearError('existing_document_id');
    };

    useEffect(() => {
        if (Object.keys(command.errors).length) setStep(0);
    }, [command.errors]);

    const validateStep = (at: number): boolean => {
        if (at !== 0) return true;
        const found: Record<string, string> = {};
        if (!form.request_type)
            found.request_type = 'Choose the Finance request type.';
        if (!form.source) found.source = 'Choose the source record.';
        if (form.amount.trim() && !AMOUNT.test(form.amount.trim()))
            found.amount =
                'Enter the amount in dollars and cents, for example 408.25.';
        if (!form.note.trim())
            found.note = 'Record what Finance needs to review.';
        let message = '';
        if (!Object.keys(found).length) {
            if (!hasEvidence)
                message =
                    'Upload supporting files or select an existing document.';
            else if (
                finance.requests.some(
                    (request) =>
                        request.status === 'submitted' &&
                        request.type === form.request_type &&
                        requestSourceValue(request) === form.source,
                )
            )
                message =
                    'An open request already exists for this source and request type. Open the existing request in Finance.';
        }
        setLocalErrors(found);
        setFormError(message);
        return !Object.keys(found).length && !message;
    };

    const submit = async () => {
        if (!command.uncertain && !validateStep(0)) {
            setStep(0);
            return;
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicle.id}/finance/review-requests`,
            {
                request_type: form.request_type,
                source: form.source,
                amount: form.amount.trim() || null,
                note: form.note.trim(),
                existing_document_id:
                    form.existing === 'none' ? null : Number(form.existing),
            },
        );
        const created =
            result && isJsonObject(result.request) ? result.request : null;
        if (!created) return;
        const reference =
            typeof created.reference === 'string'
                ? created.reference
                : 'The request';
        const outcome = files.length
            ? await uploads.upload(files, {
                  category: typeLabel,
                  reason: `Supporting files for ${reference}`,
                  requestId: Number(created.id),
              })
            : null;
        setSaved(
            `${reference} is now in the Finance queue. Finance users see it in All Tasks; no email or external notification is sent.${uploadSummary(outcome, files.length)}`,
        );
        onSaved();
    };
    const state = {
        ...command,
        processing: command.processing || uploads.command.processing,
        locked: command.locked || uploads.command.processing,
    };
    const pct = Math.round(
        ([
            !!form.request_type,
            !!form.source,
            !!form.note.trim(),
            hasEvidence,
        ].filter(Boolean).length /
            4) *
            100,
    );

    return (
        <WorkspaceWizard
            title="Request Finance review"
            description={`${vehicle.name}: route supporting evidence to Finance. An estimate is not an approved cost.`}
            railIcon={ReceiptText}
            railSub={[vehicle.registration_number, vehicle.site?.name]
                .filter(Boolean)
                .join(' · ')}
            steps={REQUEST_STEPS}
            step={step}
            setStep={setStep}
            pct={pct}
            context={vehicleContext(vehicle)}
            command={state}
            dirty={
                JSON.stringify(form) !== JSON.stringify(initial) ||
                files.length > 0
            }
            saved={saved !== null}
            submitLabel="Create Finance review request"
            onValidateStep={validateStep}
            onSubmit={submit}
            onClose={onClose}
            onReload={() => {
                onSaved();
                onClose();
            }}
            errorKey={JSON.stringify(errors)}
            success={
                <WizardSuccess
                    title="Finance review requested"
                    blurb={saved}
                    onClose={onClose}
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Route supporting evidence to Finance. An estimate is not
                        an approved cost.
                    </p>
                    {formError && (
                        <FinanceNotice
                            title="Review before continuing"
                            tone="critical"
                        >
                            {formError}
                        </FinanceNotice>
                    )}
                    <WizardField
                        id="finance-existing-document"
                        label="Use an existing document"
                        error={errors.existing_document_id}
                        hint={existing?.description}
                    >
                        <VehicleSearchSelect
                            id="finance-existing-document"
                            label="Use an existing document"
                            value={form.existing}
                            options={documentOptions}
                            onChange={(value) =>
                                update('existing', value || 'none')
                            }
                            invalid={!!errors.existing_document_id}
                        />
                    </WizardField>
                    <WizardField
                        id="finance-request-type"
                        label="Finance request type"
                        error={errors.request_type}
                    >
                        <Select
                            value={form.request_type}
                            onValueChange={(value) =>
                                update(
                                    'request_type',
                                    value as FinanceRequestType,
                                )
                            }
                        >
                            <SelectTrigger
                                {...fieldProps(
                                    'finance-request-type',
                                    errors.request_type,
                                )}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {finance.request_types.map((type) => (
                                    <SelectItem
                                        key={type.value}
                                        value={type.value}
                                    >
                                        {type.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </WizardField>
                    <WizardField
                        id="finance-request-source"
                        label="Source record"
                        error={errors.source}
                        hint={source?.detail}
                    >
                        <VehicleSearchSelect
                            id="finance-request-source"
                            label="Source record"
                            value={form.source}
                            options={finance.sources.map((option) => ({
                                value: option.value,
                                label: option.label,
                                description: option.detail,
                            }))}
                            onChange={(value) =>
                                update('source', value || 'vehicle')
                            }
                            invalid={!!errors.source}
                        />
                    </WizardField>
                    <WizardField
                        id="finance-request-amount"
                        label="Amount including GST · NZD"
                        optional
                        error={errors.amount}
                    >
                        <Input
                            {...fieldProps(
                                'finance-request-amount',
                                errors.amount,
                            )}
                            type="number"
                            inputMode="decimal"
                            min={0}
                            step="0.01"
                            value={form.amount}
                            onChange={(event) =>
                                update('amount', event.target.value)
                            }
                        />
                    </WizardField>
                    <WizardField
                        id="finance-request-note"
                        label="What Finance needs to review"
                        error={errors.note}
                    >
                        <Textarea
                            {...fieldProps('finance-request-note', errors.note)}
                            rows={4}
                            maxLength={2000}
                            value={form.note}
                            onChange={(event) =>
                                update('note', event.target.value)
                            }
                        />
                    </WizardField>
                    {canAttach ? (
                        <StagedFilesField
                            label="Quote / invoice / supporting files"
                            files={files}
                            onChange={(next) => {
                                setFiles(next);
                                setFormError('');
                            }}
                            error={
                                command.errors['files.0'] ??
                                command.errors.files
                            }
                        />
                    ) : (
                        <p className="text-caption">
                            Uploading files needs vehicle document access.
                            Choose an existing document above instead.
                        </p>
                    )}
                </div>
            )}
            {step === 1 && (
                <div className="grid gap-4">
                    <ReviewCard
                        icon={ReceiptText}
                        title="Request & source"
                        onEdit={() => setStep(0)}
                    >
                        <ReviewRow
                            label="Use an existing document"
                            value={existing?.label}
                        />
                        <ReviewRow
                            label="Finance request type"
                            value={typeLabel}
                        />
                        <ReviewRow
                            label="Source record"
                            value={source?.label}
                        />
                        <ReviewRow
                            label="Amount including GST · NZD"
                            value={
                                form.amount.trim()
                                    ? formatCurrency(Number(form.amount))
                                    : 'Not provided'
                            }
                        />
                        <ReviewRow
                            label="What Finance needs to review"
                            value={form.note.trim() || undefined}
                        />
                        {canAttach && (
                            <ReviewRow
                                label="Quote / invoice / supporting files"
                                value={
                                    files.map((file) => file.name).join(', ') ||
                                    'No files attached'
                                }
                            />
                        )}
                    </ReviewCard>
                </div>
            )}
        </WorkspaceWizard>
    );
}
