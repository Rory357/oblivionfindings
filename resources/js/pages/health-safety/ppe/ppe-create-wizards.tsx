/**
 * PPE create/edit wizards — Add/Edit inventory, Allocate PPE, Add/Edit type.
 * All compose the shared `PpeWizard` engine (Add-Client style) + wizard primitives.
 * Add-inventory and Record-inspection capture documents at source (Pattern B);
 * the detail modal carries the primary AttachmentUploader (Pattern A).
 */
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    Ring,
    Segmented,
    SelectInput,
    StepHead,
    TilePicker,
} from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { cn } from '@/lib/utils';
import {
    Calendar,
    ClipboardCheck,
    Hexagon,
    IdCard,
    Info,
    Package,
    ShieldCheck,
    User,
    Wind,
} from 'lucide-react';
import {
    CATEGORY_TILES,
    INSPECTION_FREQUENCY_OPTIONS,
    catLabel,
    fmtDateNZ,
    type Allocatable,
    type Ref,
    type TypeRow,
} from './ppe-shared';
import { PpeWizard, type WizardCtx } from './ppe-wizard';

/** Subset of an inventory item the edit wizard prefills — both a list row and the
 *  full ItemDetail structurally satisfy it. */
export type EditableInventory = {
    id: number;
    ppe_type: { id: number } | null;
    site: { id: number } | null;
    location: string | null;
    brand: string | null;
    model: string | null;
    serial_number: string | null;
    quantity: number;
    condition: string;
    purchase_date: string | null;
    expiry_date: string | null;
    next_inspection_due: string | null;
};

function ToggleRow({
    checked,
    onChange,
    label,
    hint,
}: {
    checked: boolean;
    onChange: (v: boolean) => void;
    label: string;
    hint?: string;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- custom toggle/switch row, not a shadcn Button
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            onClick={() => onChange(!checked)}
            className="flex w-full items-center justify-between gap-3 rounded-lg border border-border bg-card px-3 py-2.5 text-left transition-colors hover:border-primary/40"
        >
            <span className="min-w-0">
                <span className="block text-[13px] font-semibold">{label}</span>
                {hint ? (
                    <span className="block text-[11px] text-muted-foreground">
                        {hint}
                    </span>
                ) : null}
            </span>
            <span
                className={cn(
                    'relative h-[22px] w-[40px] shrink-0 rounded-full transition-colors',
                    checked ? 'bg-primary' : 'bg-muted',
                )}
            >
                <span
                    className={cn(
                        'absolute top-[3px] h-4 w-4 rounded-full bg-card shadow transition-all',
                        checked ? 'left-[21px]' : 'left-[3px]',
                    )}
                />
            </span>
        </button>
    );
}

type DocDraft = { file: File; kind: string; note: string };

function DocumentsField({
    value,
    onChange,
    hint,
}: {
    value: DocDraft[];
    onChange: (v: DocDraft[]) => void;
    hint: string;
}) {
    return (
        <div className="col-span-full flex flex-col gap-2">
            <FileDropzone
                onFiles={(files) =>
                    onChange([
                        ...value,
                        ...files.map((file) => ({
                            file,
                            kind: file.type.startsWith('image/')
                                ? 'photo'
                                : 'document',
                            note: '',
                        })),
                    ])
                }
                accept="image/*,.pdf,.doc,.docx"
                hint={hint}
            />
            {value.map((d, i) => (
                <StagedFileCard
                    key={i}
                    file={d.file}
                    onRemove={() =>
                        onChange(value.filter((_, idx) => idx !== i))
                    }
                >
                    <Input
                        value={d.note}
                        onChange={(e) =>
                            onChange(
                                value.map((x, idx) =>
                                    idx === i
                                        ? { ...x, note: e.target.value }
                                        : x,
                                ),
                            )
                        }
                        placeholder="Note (optional)"
                        className="h-8"
                    />
                </StagedFileCard>
            ))}
        </div>
    );
}

function gridCols(children: React.ReactNode) {
    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">{children}</div>
    );
}

const filled = (...vals: unknown[]) =>
    vals.filter((v) => v !== '' && v !== null && v !== undefined).length;

// ───────────────────────── Add / Edit inventory ─────────────────────────

type InventoryForm = {
    ppe_type_id: string;
    site_id: string;
    location: string;
    brand: string;
    model: string;
    serial_number: string;
    quantity: string;
    condition: string;
    purchase_date: string;
    expiry_date: string;
    next_inspection_due: string;
    documents: DocDraft[];
};

export function InventoryDialog({
    open,
    onClose,
    types,
    sites,
    lockedTypeId,
    edit,
}: {
    open: boolean;
    onClose: () => void;
    types: TypeRow[];
    sites: Ref[];
    lockedTypeId?: number | null;
    edit?: EditableInventory | null;
}) {
    const activeTypes = types.filter(
        (t) =>
            t.is_active || (edit && String(t.id) === String(edit.ppe_type?.id)),
    );
    const typeOptions = activeTypes.map((t) => ({
        value: String(t.id),
        label: t.name,
    }));
    const siteOptions = sites.map((s) => ({
        value: String(s.id),
        label: s.name,
    }));
    const isEdit = !!edit;

    const initial: InventoryForm = {
        ppe_type_id: edit?.ppe_type
            ? String(edit.ppe_type.id)
            : lockedTypeId
              ? String(lockedTypeId)
              : '',
        site_id: edit?.site ? String(edit.site.id) : '',
        location: edit?.location ?? '',
        brand: edit?.brand ?? '',
        model: edit?.model ?? '',
        serial_number: edit?.serial_number ?? '',
        quantity: edit ? String(edit.quantity) : '1',
        condition: edit?.condition ?? 'new',
        purchase_date: edit?.purchase_date ?? '',
        expiry_date: edit?.expiry_date ?? '',
        next_inspection_due: edit?.next_inspection_due ?? '',
        documents: [],
    };

    const typeName = (d: InventoryForm) =>
        types.find((t) => String(t.id) === d.ppe_type_id)?.name ?? '—';

    return (
        <PpeWizard<InventoryForm>
            open={open}
            onClose={onClose}
            icon={Package}
            title={isEdit ? 'Edit inventory item' : 'Add inventory item'}
            subtitle="Register a physical PPE item at a site."
            edit={isEdit}
            addAnother={!isEdit}
            submitLabel="Create stock"
            savedTitle={(d) => `${typeName(d)} (${d.serial_number || '—'})`}
            endpoint={
                isEdit
                    ? `/health-safety/ppe/inventory/${edit.id}`
                    : '/health-safety/ppe/inventory'
            }
            method={isEdit ? 'put' : 'post'}
            transform={
                isEdit ? ({ documents: _drop, ...rest }) => rest : undefined
            }
            initial={initial}
            steps={[
                {
                    key: 'type_site',
                    label: 'Type & site',
                    blurb: 'What and where',
                    icon: Hexagon,
                    fields: ['ppe_type_id', 'site_id', 'location'],
                    validate: (d) => {
                        const e: Record<string, string> = {};
                        if (!d.ppe_type_id) e.ppe_type_id = 'Choose a PPE type';
                        if (!d.site_id) e.site_id = 'Choose a site';
                        return e;
                    },
                    render: ({
                        data,
                        set,
                        errors,
                    }: WizardCtx<InventoryForm>) => {
                        const chosen = types.find(
                            (t) => String(t.id) === data.ppe_type_id,
                        );
                        return (
                            <>
                                <StepHead
                                    icon={Hexagon}
                                    title="Type & site"
                                    blurb="Pick the catalogue type and where this item is stored."
                                />
                                {gridCols(
                                    <>
                                        <Field
                                            label="PPE type"
                                            required
                                            error={errors.ppe_type_id}
                                            span
                                        >
                                            <SelectInput
                                                value={data.ppe_type_id}
                                                onChange={(v) =>
                                                    set('ppe_type_id', v)
                                                }
                                                placeholder="Choose a PPE type"
                                                options={typeOptions}
                                            />
                                        </Field>
                                        <Field
                                            label="Site / home"
                                            required
                                            error={errors.site_id}
                                        >
                                            <SelectInput
                                                value={data.site_id}
                                                onChange={(v) =>
                                                    set('site_id', v)
                                                }
                                                placeholder="Choose a site"
                                                options={siteOptions}
                                            />
                                        </Field>
                                        <Field label="Location" hint="Optional">
                                            <Input
                                                value={data.location}
                                                onChange={(e) =>
                                                    set(
                                                        'location',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="e.g. PPE store A"
                                            />
                                        </Field>
                                        {chosen?.standards_reference ? (
                                            <InfoCard icon={ShieldCheck}>
                                                <span className="font-semibold">
                                                    {chosen.name}
                                                </span>{' '}
                                                is governed by{' '}
                                                <span className="font-semibold">
                                                    {chosen.standards_reference}
                                                </span>
                                                .
                                                {chosen.inspection_frequency
                                                    ? ` Default inspection cadence: ${chosen.inspection_frequency}.`
                                                    : ''}
                                            </InfoCard>
                                        ) : null}
                                    </>,
                                )}
                            </>
                        );
                    },
                },
                {
                    key: 'identification',
                    label: 'Identification',
                    blurb: 'Brand, model, serial',
                    icon: IdCard,
                    fields: ['brand', 'model', 'serial_number', 'quantity'],
                    validate: (d): Record<string, string> =>
                        d.serial_number.trim()
                            ? {}
                            : {
                                  serial_number:
                                      'Serial / asset ID is required',
                              },
                    render: ({
                        data,
                        set,
                        errors,
                    }: WizardCtx<InventoryForm>) => (
                        <>
                            <StepHead
                                icon={IdCard}
                                title="Identification"
                                blurb="Brand, model and the unique asset identifier."
                            />
                            {gridCols(
                                <>
                                    <Field label="Brand">
                                        <Input
                                            value={data.brand}
                                            onChange={(e) =>
                                                set('brand', e.target.value)
                                            }
                                            placeholder="e.g. 3M"
                                        />
                                    </Field>
                                    <Field label="Model">
                                        <Input
                                            value={data.model}
                                            onChange={(e) =>
                                                set('model', e.target.value)
                                            }
                                            placeholder="e.g. 6200"
                                        />
                                    </Field>
                                    <Field
                                        label="Serial / asset ID"
                                        required
                                        error={errors.serial_number}
                                    >
                                        <Input
                                            value={data.serial_number}
                                            onChange={(e) =>
                                                set(
                                                    'serial_number',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g. RSP-6200-014"
                                        />
                                    </Field>
                                    <Field label="Quantity">
                                        <Input
                                            type="number"
                                            min={1}
                                            value={data.quantity}
                                            onChange={(e) =>
                                                set('quantity', e.target.value)
                                            }
                                            placeholder="1"
                                        />
                                    </Field>
                                </>,
                            )}
                        </>
                    ),
                },
                {
                    key: 'condition',
                    label: 'Condition & dates',
                    blurb: 'Grade, expiry, evidence',
                    icon: Calendar,
                    fields: [
                        'condition',
                        'purchase_date',
                        'expiry_date',
                        'next_inspection_due',
                        'documents',
                    ],
                    render: ({ data, set }: WizardCtx<InventoryForm>) => (
                        <>
                            <StepHead
                                icon={Calendar}
                                title="Condition & dates"
                                blurb="Current state, expiry and the next inspection due date."
                            />
                            {gridCols(
                                <>
                                    <Field label="Condition" span>
                                        <Segmented
                                            value={data.condition}
                                            onChange={(v) =>
                                                set('condition', v)
                                            }
                                            options={[
                                                { value: 'new', label: 'New' },
                                                {
                                                    value: 'good',
                                                    label: 'Good',
                                                },
                                                {
                                                    value: 'fair',
                                                    label: 'Fair',
                                                },
                                                {
                                                    value: 'poor',
                                                    label: 'Poor',
                                                },
                                            ]}
                                        />
                                    </Field>
                                    <Field label="Purchase date">
                                        <Input
                                            type="date"
                                            value={data.purchase_date}
                                            onChange={(e) =>
                                                set(
                                                    'purchase_date',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Expiry date">
                                        <Input
                                            type="date"
                                            value={data.expiry_date}
                                            onChange={(e) =>
                                                set(
                                                    'expiry_date',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    <Field label="Next inspection due" span>
                                        <Input
                                            type="date"
                                            value={data.next_inspection_due}
                                            onChange={(e) =>
                                                set(
                                                    'next_inspection_due',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                    {!isEdit ? (
                                        <Field
                                            label="Documents"
                                            hint="Certificate, declaration of conformity — optional"
                                            span
                                        >
                                            <DocumentsField
                                                value={data.documents}
                                                onChange={(v) =>
                                                    set('documents', v)
                                                }
                                                hint="PDF, Word, images — up to 20 MB each"
                                            />
                                        </Field>
                                    ) : null}
                                </>,
                            )}
                        </>
                    ),
                },
                {
                    key: 'review',
                    label: 'Review',
                    blurb: 'Confirm & create',
                    icon: ClipboardCheck,
                    render: ({ data }: WizardCtx<InventoryForm>) => {
                        const chosen = types.find(
                            (t) => String(t.id) === data.ppe_type_id,
                        );
                        const pct = Math.round(
                            (filled(
                                data.ppe_type_id,
                                data.site_id,
                                data.serial_number,
                                data.brand,
                                data.condition,
                                data.expiry_date,
                            ) /
                                6) *
                                100,
                        );
                        return (
                            <>
                                <StepHead
                                    icon={ClipboardCheck}
                                    title="Review & create"
                                    blurb="Confirm the details before adding this item to the register."
                                />
                                <div className="mb-4 flex items-center gap-3 rounded-xl border border-border bg-muted/30 p-3">
                                    <Ring pct={pct} />
                                    <div>
                                        <div className="text-sm font-bold">
                                            {chosen?.name ?? 'PPE item'}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {data.serial_number || 'No serial'}
                                        </div>
                                    </div>
                                </div>
                                <ReviewCard icon={Package} title="Item details">
                                    <ReviewRow
                                        label="Type"
                                        value={chosen?.name}
                                    />
                                    <ReviewRow
                                        label="Category"
                                        value={
                                            chosen
                                                ? catLabel(chosen.category)
                                                : undefined
                                        }
                                    />
                                    <ReviewRow
                                        label="Site"
                                        value={
                                            sites.find(
                                                (s) =>
                                                    String(s.id) ===
                                                    data.site_id,
                                            )?.name
                                        }
                                    />
                                    <ReviewRow
                                        label="Location"
                                        value={data.location}
                                    />
                                    <ReviewRow
                                        label="Brand / model"
                                        value={
                                            [data.brand, data.model]
                                                .filter(Boolean)
                                                .join(' ') || undefined
                                        }
                                    />
                                    <ReviewRow
                                        label="Serial"
                                        value={data.serial_number}
                                    />
                                    <ReviewRow
                                        label="Quantity"
                                        value={data.quantity}
                                    />
                                    <ReviewRow
                                        label="Condition"
                                        value={catTitle(data.condition)}
                                    />
                                    <ReviewRow
                                        label="Expiry"
                                        value={
                                            data.expiry_date
                                                ? fmtDateNZ(data.expiry_date)
                                                : undefined
                                        }
                                    />
                                    <ReviewRow
                                        label="Next inspection"
                                        value={
                                            data.next_inspection_due
                                                ? fmtDateNZ(
                                                      data.next_inspection_due,
                                                  )
                                                : undefined
                                        }
                                    />
                                    {data.documents.length ? (
                                        <ReviewRow
                                            label="Documents"
                                            value={`${data.documents.length} attached`}
                                        />
                                    ) : null}
                                </ReviewCard>
                            </>
                        );
                    },
                },
            ]}
        />
    );
}

function catTitle(s: string): string {
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : '—';
}

// ───────────────────────── Allocate PPE ─────────────────────────

type AllocateForm = {
    user_id: string;
    inventory: string;
    fit_test_completed: boolean;
    fit_test_date: string;
    fit_test_result: string;
    training_completed: boolean;
    training_date: string;
    acknowledged: boolean;
    notes: string;
};

export function AllocateDialog({
    open,
    onClose,
    staff,
    allocatable,
    lockedItem,
}: {
    open: boolean;
    onClose: () => void;
    staff: Ref[];
    allocatable: Allocatable[];
    lockedItem?: { id: number; label: string; category: string | null } | null;
}) {
    const staffOptions = staff.map((s) => ({
        value: String(s.id),
        label: s.name,
    }));
    const itemOptions = allocatable.map((a) => ({
        value: String(a.id),
        label: a.label,
    }));

    const initial: AllocateForm = {
        user_id: '',
        inventory: lockedItem ? String(lockedItem.id) : '',
        fit_test_completed: false,
        fit_test_date: '',
        fit_test_result: 'pass',
        training_completed: false,
        training_date: '',
        acknowledged: false,
        notes: '',
    };

    const itemCategory = (d: AllocateForm) =>
        lockedItem
            ? lockedItem.category
            : (allocatable.find((a) => String(a.id) === d.inventory)
                  ?.category ?? null);
    const isRpe = (d: AllocateForm) => itemCategory(d) === 'respiratory';
    const workerName = (d: AllocateForm) =>
        staff.find((s) => String(s.id) === d.user_id)?.name ?? 'Worker';
    const itemLabel = (d: AllocateForm) =>
        lockedItem?.label ??
        allocatable.find((a) => String(a.id) === d.inventory)?.label ??
        '—';

    return (
        <PpeWizard<AllocateForm>
            open={open}
            onClose={onClose}
            icon={User}
            title="Allocate PPE"
            subtitle="Issue a physical item to a worker."
            submitLabel="Issue PPE"
            savedTitle={(d) => itemLabel(d)}
            endpoint={(d) =>
                `/health-safety/ppe/inventory/${lockedItem?.id ?? d.inventory}/allocate`
            }
            initial={initial}
            steps={[
                {
                    key: 'worker_item',
                    label: 'Worker & item',
                    blurb: 'Who and which unit',
                    icon: User,
                    fields: ['user_id', 'inventory'],
                    validate: (d) => {
                        const e: Record<string, string> = {};
                        if (!d.user_id) e.user_id = 'Choose a worker';
                        if (!lockedItem && !d.inventory)
                            e.inventory = 'Choose an item';
                        return e;
                    },
                    render: ({
                        data,
                        set,
                        errors,
                    }: WizardCtx<AllocateForm>) => (
                        <>
                            <StepHead
                                icon={User}
                                title="Worker & item"
                                blurb="Who receives this item, and which physical unit."
                            />
                            {gridCols(
                                <>
                                    <Field
                                        label="Worker"
                                        required
                                        error={errors.user_id}
                                    >
                                        <SelectInput
                                            value={data.user_id}
                                            onChange={(v) => set('user_id', v)}
                                            placeholder="Choose a worker"
                                            options={staffOptions}
                                        />
                                    </Field>
                                    {lockedItem ? (
                                        <Field label="Inventory item">
                                            <div className="flex h-9 items-center rounded-lg border border-border bg-muted/40 px-3 text-[13px] font-medium">
                                                {lockedItem.label}
                                            </div>
                                        </Field>
                                    ) : (
                                        <Field
                                            label="Inventory item"
                                            required
                                            error={errors.inventory}
                                            span
                                        >
                                            <SelectInput
                                                value={data.inventory}
                                                onChange={(v) =>
                                                    set('inventory', v)
                                                }
                                                placeholder="Choose an available item"
                                                options={itemOptions}
                                            />
                                        </Field>
                                    )}
                                    {isRpe(data) ? (
                                        <InfoCard icon={Wind} tone="warn">
                                            This is respiratory protective
                                            equipment. Under{' '}
                                            <span className="font-semibold">
                                                AS/NZS 1715
                                            </span>{' '}
                                            a current quantitative fit-test is
                                            required before issue.
                                        </InfoCard>
                                    ) : null}
                                </>,
                            )}
                        </>
                    ),
                },
                {
                    key: 'fit',
                    label: 'Fit-test',
                    blurb: 'AS/NZS 1715',
                    icon: Wind,
                    fields: [
                        'fit_test_completed',
                        'fit_test_date',
                        'fit_test_result',
                    ],
                    validate: (d) => {
                        if (!isRpe(d)) return {};
                        const e: Record<string, string> = {};
                        if (!d.fit_test_completed)
                            e.fit_test_completed =
                                'RPE requires a current fit-test (AS/NZS 1715)';
                        else if (!d.fit_test_date)
                            e.fit_test_date = 'Record the fit-test date';
                        return e;
                    },
                    render: ({
                        data,
                        set,
                        errors,
                    }: WizardCtx<AllocateForm>) => (
                        <>
                            <StepHead
                                icon={Wind}
                                title="Fit-test"
                                blurb={
                                    isRpe(data)
                                        ? 'Quantitative fit-test per AS/NZS 1715.'
                                        : 'Not required for this equipment type.'
                                }
                            />
                            {!isRpe(data) ? (
                                <InfoCard icon={Info}>
                                    Fit-testing applies to tight-fitting
                                    respiratory protection only. You can
                                    continue.
                                </InfoCard>
                            ) : (
                                <div className="flex flex-col gap-4">
                                    <Field error={errors.fit_test_completed}>
                                        <ToggleRow
                                            checked={data.fit_test_completed}
                                            onChange={(v) =>
                                                set('fit_test_completed', v)
                                            }
                                            label="Fit-test completed"
                                            hint="Worker passed a quantitative fit-test for this make/model"
                                        />
                                    </Field>
                                    {data.fit_test_completed ? (
                                        <>
                                            <Field
                                                label="Fit-test date"
                                                required
                                                error={errors.fit_test_date}
                                            >
                                                <Input
                                                    type="date"
                                                    value={data.fit_test_date}
                                                    onChange={(e) =>
                                                        set(
                                                            'fit_test_date',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </Field>
                                            <Field label="Result">
                                                <Segmented
                                                    value={data.fit_test_result}
                                                    onChange={(v) =>
                                                        set(
                                                            'fit_test_result',
                                                            v,
                                                        )
                                                    }
                                                    options={[
                                                        {
                                                            value: 'pass',
                                                            label: 'Pass',
                                                        },
                                                        {
                                                            value: 'fail',
                                                            label: 'Fail',
                                                        },
                                                    ]}
                                                />
                                            </Field>
                                        </>
                                    ) : null}
                                </div>
                            )}
                        </>
                    ),
                },
                {
                    key: 'training',
                    label: 'Training & sign-off',
                    blurb: 'Use & acknowledgement',
                    icon: ClipboardCheck,
                    fields: [
                        'training_completed',
                        'training_date',
                        'acknowledged',
                    ],
                    render: ({ data, set }: WizardCtx<AllocateForm>) => (
                        <>
                            <StepHead
                                icon={ClipboardCheck}
                                title="Training & acknowledgement"
                                blurb="Donning/doffing training and the worker sign-off."
                            />
                            <div className="flex flex-col gap-4">
                                <ToggleRow
                                    checked={data.training_completed}
                                    onChange={(v) =>
                                        set('training_completed', v)
                                    }
                                    label="Training completed"
                                    hint="Worker trained on correct use, storage & limits"
                                />
                                {data.training_completed ? (
                                    <Field label="Training date">
                                        <Input
                                            type="date"
                                            value={data.training_date}
                                            onChange={(e) =>
                                                set(
                                                    'training_date',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                ) : null}
                                <ToggleRow
                                    checked={data.acknowledged}
                                    onChange={(v) => set('acknowledged', v)}
                                    label="Worker acknowledgement"
                                    hint="Worker confirms they received and understand the PPE"
                                />
                            </div>
                        </>
                    ),
                },
                {
                    key: 'review',
                    label: 'Review',
                    blurb: 'Confirm & issue',
                    icon: ClipboardCheck,
                    render: ({ data }: WizardCtx<AllocateForm>) => (
                        <>
                            <StepHead
                                icon={ClipboardCheck}
                                title="Review & issue"
                                blurb="Confirm before issuing this item to the worker."
                            />
                            <ReviewCard icon={User} title="Allocation">
                                <ReviewRow
                                    label="Worker"
                                    value={workerName(data)}
                                />
                                <ReviewRow
                                    label="Item"
                                    value={itemLabel(data)}
                                />
                                <ReviewRow
                                    label="Fit-test"
                                    value={
                                        isRpe(data)
                                            ? data.fit_test_completed
                                                ? `Pass · ${data.fit_test_date ? fmtDateNZ(data.fit_test_date) : '—'}`
                                                : 'Required'
                                            : 'N/A'
                                    }
                                />
                                <ReviewRow
                                    label="Training"
                                    value={
                                        data.training_completed
                                            ? `Done${data.training_date ? ` · ${fmtDateNZ(data.training_date)}` : ''}`
                                            : 'Outstanding'
                                    }
                                />
                                <ReviewRow
                                    label="Acknowledged"
                                    value={
                                        data.acknowledged ? 'Yes' : 'Pending'
                                    }
                                />
                            </ReviewCard>
                        </>
                    ),
                },
            ]}
        />
    );
}

// ───────────────────────── Add / Edit type ─────────────────────────

type TypeForm = {
    name: string;
    category: string;
    standards_reference: string;
    inspection_frequency: string;
    typical_lifespan_months: string;
    hazards_addressed: string;
    description: string;
};

export function TypeDialog({
    open,
    onClose,
    edit,
}: {
    open: boolean;
    onClose: () => void;
    edit?: TypeRow | null;
}) {
    const isEdit = !!edit;
    const initial: TypeForm = {
        name: edit?.name ?? '',
        category: edit?.category ?? '',
        standards_reference: edit?.standards_reference ?? '',
        inspection_frequency: edit?.inspection_frequency ?? 'monthly',
        typical_lifespan_months:
            edit?.typical_lifespan_months != null
                ? String(edit.typical_lifespan_months)
                : '',
        hazards_addressed: edit?.hazards_addressed ?? '',
        description: edit?.description ?? '',
    };

    return (
        <PpeWizard<TypeForm>
            open={open}
            onClose={onClose}
            icon={Hexagon}
            title={isEdit ? 'Edit PPE type' : 'Add PPE type'}
            subtitle="A catalogue entry describing a kind of PPE."
            edit={isEdit}
            addAnother={!isEdit}
            submitLabel="Create catalogue entry"
            savedTitle={(d) => d.name || 'PPE type'}
            endpoint={
                isEdit
                    ? `/health-safety/ppe/types/${edit.id}`
                    : '/health-safety/ppe/types'
            }
            method={isEdit ? 'put' : 'post'}
            initial={initial}
            steps={[
                {
                    key: 'identity',
                    label: 'Identity',
                    blurb: 'Name & category',
                    icon: Hexagon,
                    fields: ['name', 'category'],
                    validate: (d) => {
                        const e: Record<string, string> = {};
                        if (!d.name.trim()) e.name = 'A name is required';
                        if (!d.category) e.category = 'Choose a category';
                        return e;
                    },
                    render: ({ data, set, errors }: WizardCtx<TypeForm>) => (
                        <>
                            <StepHead
                                icon={Hexagon}
                                title="Identity"
                                blurb="Name the type and pick its protection category."
                            />
                            <div className="flex flex-col gap-4">
                                <Field
                                    label="Name"
                                    required
                                    error={errors.name}
                                >
                                    <Input
                                        value={data.name}
                                        onChange={(e) =>
                                            set('name', e.target.value)
                                        }
                                        placeholder="e.g. Half-face respirator (P2)"
                                    />
                                </Field>
                                <Field
                                    label="Category"
                                    required
                                    error={errors.category}
                                >
                                    <TilePicker
                                        value={data.category}
                                        onChange={(v) => set('category', v)}
                                        options={CATEGORY_TILES}
                                    />
                                </Field>
                            </div>
                        </>
                    ),
                },
                {
                    key: 'standards',
                    label: 'Standards & lifecycle',
                    blurb: 'AS/NZS, cadence, life',
                    icon: ShieldCheck,
                    fields: [
                        'standards_reference',
                        'inspection_frequency',
                        'typical_lifespan_months',
                    ],
                    validate: (d): Record<string, string> =>
                        d.standards_reference.trim()
                            ? {}
                            : {
                                  standards_reference:
                                      'Standard reference is required',
                              },
                    render: ({ data, set, errors }: WizardCtx<TypeForm>) => (
                        <>
                            <StepHead
                                icon={ShieldCheck}
                                title="Standards & lifecycle"
                                blurb="The AS/NZS reference, inspection cadence and expected life."
                            />
                            <div className="flex flex-col gap-4">
                                <Field
                                    label="Standard reference"
                                    required
                                    error={errors.standards_reference}
                                >
                                    <Input
                                        value={data.standards_reference}
                                        onChange={(e) =>
                                            set(
                                                'standards_reference',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="e.g. AS/NZS 1715 & 1716"
                                    />
                                </Field>
                                <Field label="Inspection frequency">
                                    <Segmented
                                        value={data.inspection_frequency}
                                        onChange={(v) =>
                                            set('inspection_frequency', v)
                                        }
                                        options={INSPECTION_FREQUENCY_OPTIONS}
                                    />
                                </Field>
                                <Field label="Typical lifespan (months)">
                                    <Input
                                        type="number"
                                        min={1}
                                        value={data.typical_lifespan_months}
                                        onChange={(e) =>
                                            set(
                                                'typical_lifespan_months',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="e.g. 60"
                                    />
                                </Field>
                            </div>
                        </>
                    ),
                },
                {
                    key: 'guidance',
                    label: 'Guidance',
                    blurb: 'Hazards & notes',
                    icon: Info,
                    fields: ['hazards_addressed', 'description'],
                    render: ({ data, set }: WizardCtx<TypeForm>) => (
                        <>
                            <StepHead
                                icon={Info}
                                title="Guidance"
                                blurb="Hazards this protects against and any handling notes."
                            />
                            <div className="flex flex-col gap-4">
                                <Field label="Hazards addressed">
                                    <Input
                                        value={data.hazards_addressed}
                                        onChange={(e) =>
                                            set(
                                                'hazards_addressed',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="e.g. Airborne particulates, chemical vapours"
                                    />
                                </Field>
                                <Field label="Description">
                                    <Textarea
                                        rows={3}
                                        value={data.description}
                                        onChange={(e) =>
                                            set('description', e.target.value)
                                        }
                                        placeholder="Notes on correct use, fit-testing or storage…"
                                    />
                                </Field>
                            </div>
                        </>
                    ),
                },
                {
                    key: 'review',
                    label: 'Review',
                    blurb: 'Confirm & create',
                    icon: ClipboardCheck,
                    render: ({ data }: WizardCtx<TypeForm>) => (
                        <>
                            <StepHead
                                icon={ClipboardCheck}
                                title="Review & create"
                                blurb="Confirm the catalogue entry."
                            />
                            <ReviewCard icon={Hexagon} title="Catalogue entry">
                                <ReviewRow label="Name" value={data.name} />
                                <ReviewRow
                                    label="Category"
                                    value={
                                        data.category
                                            ? catLabel(data.category)
                                            : undefined
                                    }
                                />
                                <ReviewRow
                                    label="Standard"
                                    value={data.standards_reference}
                                />
                                <ReviewRow
                                    label="Inspection"
                                    value={catTitle(data.inspection_frequency)}
                                />
                                <ReviewRow
                                    label="Lifespan"
                                    value={
                                        data.typical_lifespan_months
                                            ? `${data.typical_lifespan_months} months`
                                            : undefined
                                    }
                                />
                                <ReviewRow
                                    label="Hazards"
                                    value={data.hazards_addressed}
                                />
                            </ReviewCard>
                        </>
                    ),
                },
            ]}
        />
    );
}
