/* Asset create/edit wizard for the Fleet & Assets register.
 *
 * Mirrors the Add-Client wizard contract (resources/js/components/clients/
 * add-client-dialog.tsx) via the shared WizardShell chrome. One component
 * serves BOTH modes: pass `asset` for edit, omit it for create. Four steps —
 * Basic info → Details → Location → Compliance — matching the retired
 * full-page create/edit forms field-for-field (vehicle-only fields stay
 * conditional on category === 'vehicle').
 *
 * Submit: POST /fleet-assets/assets (create, `_modal: true` so the controller
 * bounces back to the index with `created_asset_id` for the success pane) or
 * PUT /fleet-assets/assets/{id} (edit). Success pane is gated on !flash.error
 * (Inertia fires onSuccess for back()->with('error') redirects too).
 *
 * NZ English, semantic design tokens only. */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    Ring,
    Segmented,
    SelectInput,
    StepHead,
    SubHead,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { Link, router } from '@inertiajs/react';
import {
    Car,
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    Home,
    IdCard,
    Info,
    Loader2,
    MapPin,
    Package,
    Pencil,
    Plus,
    ShieldCheck,
    Wrench,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Props + local shapes                                               */
/* ------------------------------------------------------------------ */

type SiteOption = { id: number; name: string };

type ClientOption = {
    id: number;
    first_name: string;
    last_name: string;
    site_id?: number | null;
};

/** Editable slice of an asset — what the show/edit payloads expose. */
export type AssetWizardAsset = {
    id: number;
    name: string;
    asset_tag: string | null;
    category: string | null;
    status: string;
    risk_level: string | null;
    site_id: number | null;
    home_site_id: number | null;
    client_id?: number | null;
    location: string | null;
    manufacturer: string | null;
    model: string | null;
    serial_number: string | null;
    description: string | null;
    registration_number: string | null;
    registration_expires_at: string | null;
    wof_expires_at: string | null;
    cof_expires_at: string | null;
    fuel_type: string | null;
    odometer_km: number | null;
    purchase_date: string | null;
    warranty_expires_at: string | null;
    requires_inspection: boolean;
    inspection_due_at: string | null;
    requires_maintenance: boolean;
    maintenance_due_at: string | null;
    notes: string | null;
};

export type AssetWizardPrefill = {
    site_id?: number | null;
    client_id?: number | null;
    category?: string | null;
};

type Props = {
    open: boolean;
    onClose: () => void;
    sites: SiteOption[];
    clients?: ClientOption[];
    /** Present = edit mode; absent = create mode. */
    asset?: AssetWizardAsset | null;
    /** Create-mode seeds (e.g. from a site profile "Add asset" link). */
    prefill?: AssetWizardPrefill | null;
};

type StepKey = 'basic' | 'details' | 'location' | 'compliance';

/** Flat wizard state — each field maps 1:1 to a controller field. */
type WizardState = {
    name: string;
    asset_tag: string;
    category: string;
    status: string;
    risk_level: string;
    site_id: string;
    home_site_id: string;
    client_id: string;
    location: string;
    manufacturer: string;
    model: string;
    serial_number: string;
    description: string;
    registration_number: string;
    registration_expires_at: string;
    wof_expires_at: string;
    cof_expires_at: string;
    fuel_type: string;
    odometer_km: string;
    purchase_date: string;
    warranty_expires_at: string;
    requires_inspection: boolean;
    inspection_due_at: string;
    requires_maintenance: boolean;
    maintenance_due_at: string;
    notes: string;
};

const STEPS: WizardStep[] = [
    {
        key: 'basic',
        label: 'Basic info',
        blurb: 'Name, category, status & risk',
        icon: IdCard,
    },
    {
        key: 'details',
        label: 'Details',
        blurb: 'Make, model & purchase',
        icon: Wrench,
    },
    {
        key: 'location',
        label: 'Location',
        blurb: 'Site, client & placement',
        icon: MapPin,
    },
    {
        key: 'compliance',
        label: 'Compliance',
        blurb: 'Dates, checks & notes',
        icon: ShieldCheck,
    },
];

const CATEGORY_OPTIONS = [
    { key: 'vehicle', label: 'Vehicle', icon: Car, description: 'Cars, vans & fleet vehicles' },
    { key: 'equipment', label: 'Equipment', icon: Wrench, description: 'Tools, devices & appliances' },
    { key: 'property', label: 'Property', icon: Home, description: 'Buildings & fixed property' },
    { key: 'other', label: 'Other', icon: Package, description: 'Everything else' },
];

const FUEL_TYPES = [
    { value: 'none', label: '—' },
    { value: 'petrol', label: 'Petrol' },
    { value: 'diesel', label: 'Diesel' },
    { value: 'electric', label: 'Electric' },
    { value: 'hybrid', label: 'Hybrid' },
    { value: 'lpg', label: 'LPG' },
];

function initialState(
    asset?: AssetWizardAsset | null,
    prefill?: AssetWizardPrefill | null,
): WizardState {
    if (asset) {
        return {
            name: asset.name ?? '',
            asset_tag: asset.asset_tag ?? '',
            category: asset.category ?? 'vehicle',
            status: asset.status ?? 'active',
            risk_level: asset.risk_level ?? 'low',
            site_id: asset.site_id ? String(asset.site_id) : '',
            home_site_id: asset.home_site_id ? String(asset.home_site_id) : '',
            client_id: asset.client_id ? String(asset.client_id) : '',
            location: asset.location ?? '',
            manufacturer: asset.manufacturer ?? '',
            model: asset.model ?? '',
            serial_number: asset.serial_number ?? '',
            description: asset.description ?? '',
            registration_number: asset.registration_number ?? '',
            registration_expires_at: asset.registration_expires_at ?? '',
            wof_expires_at: asset.wof_expires_at ?? '',
            cof_expires_at: asset.cof_expires_at ?? '',
            fuel_type: asset.fuel_type ?? '',
            odometer_km: asset.odometer_km != null ? String(asset.odometer_km) : '',
            purchase_date: asset.purchase_date ?? '',
            warranty_expires_at: asset.warranty_expires_at ?? '',
            requires_inspection: asset.requires_inspection ?? false,
            inspection_due_at: asset.inspection_due_at ?? '',
            requires_maintenance: asset.requires_maintenance ?? false,
            maintenance_due_at: asset.maintenance_due_at ?? '',
            notes: asset.notes ?? '',
        };
    }
    return {
        name: '',
        asset_tag: '',
        category: prefill?.category ?? 'vehicle',
        status: 'active',
        risk_level: 'low',
        site_id: prefill?.site_id ? String(prefill.site_id) : '',
        home_site_id: '',
        client_id: prefill?.client_id ? String(prefill.client_id) : '',
        location: '',
        manufacturer: '',
        model: '',
        serial_number: '',
        description: '',
        registration_number: '',
        registration_expires_at: '',
        wof_expires_at: '',
        cof_expires_at: '',
        fuel_type: '',
        odometer_km: '',
        purchase_date: '',
        warranty_expires_at: '',
        requires_inspection: false,
        inspection_due_at: '',
        requires_maintenance: false,
        maintenance_due_at: '',
        notes: '',
    };
}

/* ------------------------------------------------------------------ */
/*  Client-side validation (mirrors AssetController@store rules)       */
/* ------------------------------------------------------------------ */

function validateStep(
    key: StepKey,
    d: WizardState,
): Record<string, string> {
    const e: Record<string, string> = {};
    if (key === 'basic') {
        if (!d.name.trim()) e.name = 'Name is required';
    }
    if (key === 'location') {
        // store() rejects assets that belong to neither a site nor a client —
        // hold edit mode to the same invariant so an asset can't be orphaned.
        if (!d.site_id && !d.client_id)
            e.site_id = 'Select a site or a client.';
    }
    return e;
}

const STEP_FOR_FIELD: Record<string, StepKey> = {
    name: 'basic',
    asset_tag: 'basic',
    category: 'basic',
    status: 'basic',
    risk_level: 'basic',
    manufacturer: 'details',
    model: 'details',
    serial_number: 'details',
    description: 'details',
    registration_number: 'details',
    fuel_type: 'details',
    odometer_km: 'details',
    purchase_date: 'details',
    warranty_expires_at: 'details',
    site_id: 'location',
    home_site_id: 'location',
    client_id: 'location',
    location: 'location',
    registration_expires_at: 'compliance',
    wof_expires_at: 'compliance',
    cof_expires_at: 'compliance',
    requires_inspection: 'compliance',
    inspection_due_at: 'compliance',
    requires_maintenance: 'compliance',
    maintenance_due_at: 'compliance',
    notes: 'compliance',
};

function stepForField(field: string): StepKey {
    return STEP_FOR_FIELD[field.split('.')[0]] ?? 'basic';
}

/** '' → null so ConvertEmptyStringsToNull never bites and nullable rules apply. */
function nn(v: string): string | null {
    return v === '' ? null : v;
}

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export function AssetWizardDialog({
    open,
    onClose,
    sites,
    clients,
    asset,
    prefill,
}: Props) {
    const isEdit = asset != null;

    const [data, setData] = useState<WizardState>(() =>
        initialState(asset, prefill),
    );
    const [stepIndex, setStepIndex] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [done, setDone] = useState(false);
    const [createdId, setCreatedId] = useState<number | null>(null);

    const cur = STEPS[stepIndex];
    const stepKey = cur.key as StepKey;
    const lastIndex = STEPS.length - 1;
    const isLast = stepIndex === lastIndex;

    const set = <K extends keyof WizardState>(k: K, v: WizardState[K]) =>
        setData((prev) => ({ ...prev, [k]: v }));

    const clientList = clients ?? [];
    const selectedSiteId = data.site_id ? Number(data.site_id) : null;
    const filteredClients = useMemo(() => {
        if (!selectedSiteId) return clientList;
        return clientList.filter((c) => c.site_id === selectedSiteId);
    }, [clientList, selectedSiteId]);

    const goToStep = (key: StepKey) => {
        const idx = STEPS.findIndex((s) => s.key === key);
        if (idx >= 0) setStepIndex(idx);
    };

    const next = () => {
        const e = validateStep(stepKey, data);
        setErrors(e);
        if (Object.keys(e).length) return;
        setStepIndex((i) => Math.min(i + 1, lastIndex));
    };
    const back = () => setStepIndex((i) => Math.max(i - 1, 0));

    const resetAll = () => {
        setData(initialState(asset, prefill));
        setErrors({});
        setStepIndex(0);
        setDone(false);
        setProcessing(false);
        setCreatedId(null);
    };

    // The dialog stays mounted while closed, so reset on the closed→open
    // transition: always after a completed save (stale success pane), and in
    // edit mode reseed from the (possibly refreshed) asset prop.
    const wasOpen = useRef(open);
    useEffect(() => {
        const justOpened = open && !wasOpen.current;
        wasOpen.current = open;
        if (justOpened && (done || isEdit)) resetAll();
    });

    /** Completeness % across the fields worth filling in. */
    const pct = useMemo(() => {
        const checks: boolean[] = [
            !!data.name.trim(),
            !!data.asset_tag.trim(),
            !!data.category,
            !!data.manufacturer.trim(),
            !!data.model.trim(),
            !!data.serial_number.trim(),
            !!data.description.trim(),
            !!(data.site_id || data.client_id),
            !!data.location.trim(),
            !!data.purchase_date,
            data.category !== 'vehicle' || !!data.registration_number.trim(),
            data.category !== 'vehicle' || !!data.wof_expires_at,
        ];
        return Math.round(
            (checks.filter(Boolean).length / checks.length) * 100,
        );
    }, [data]);

    /** Picking a client auto-sets the owning site (create-page behaviour). */
    const handleClientChange = (value: string) => {
        if (value === 'none' || !value) {
            set('client_id', '');
            return;
        }
        const client = clientList.find((c) => String(c.id) === value);
        setData((prev) => ({
            ...prev,
            client_id: value,
            site_id:
                client?.site_id && !prev.site_id
                    ? String(client.site_id)
                    : prev.site_id,
        }));
    };

    const submit = () => {
        // Re-validate every gating step; jump to the first that fails.
        const all: Record<string, string> = {};
        for (const s of STEPS)
            Object.assign(all, validateStep(s.key as StepKey, data));
        if (Object.keys(all).length) {
            setErrors(all);
            goToStep(stepForField(Object.keys(all)[0]));
            return;
        }
        setErrors({});
        setProcessing(true);

        const payload = {
            name: data.name,
            asset_tag: nn(data.asset_tag),
            category: nn(data.category),
            status: data.status,
            risk_level: data.risk_level,
            site_id: data.site_id ? Number(data.site_id) : null,
            home_site_id: data.home_site_id ? Number(data.home_site_id) : null,
            client_id: data.client_id ? Number(data.client_id) : null,
            location: nn(data.location),
            manufacturer: nn(data.manufacturer),
            model: nn(data.model),
            serial_number: nn(data.serial_number),
            description: nn(data.description),
            registration_number: nn(data.registration_number),
            registration_expires_at: nn(data.registration_expires_at),
            wof_expires_at: nn(data.wof_expires_at),
            cof_expires_at: nn(data.cof_expires_at),
            fuel_type: nn(data.fuel_type),
            odometer_km: nn(data.odometer_km),
            purchase_date: nn(data.purchase_date),
            warranty_expires_at: nn(data.warranty_expires_at),
            requires_inspection: data.requires_inspection,
            inspection_due_at: nn(data.inspection_due_at),
            requires_maintenance: data.requires_maintenance,
            maintenance_due_at: nn(data.maintenance_due_at),
            notes: nn(data.notes),
        };

        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (pg: { props: Record<string, unknown> }) => {
                const flash = pg.props.flash as
                    | { error?: string | null }
                    | undefined;
                setProcessing(false);
                if (flash?.error) return;
                if (!isEdit) {
                    const id = pg.props.created_asset_id;
                    setCreatedId(typeof id === 'number' ? id : null);
                }
                setDone(true);
            },
            onError: (errs: Record<string, string>) => {
                setErrors(errs);
                setProcessing(false);
                const first = Object.keys(errs)[0];
                if (first) goToStep(stepForField(first));
            },
        };

        if (isEdit && asset) {
            router.put(`/fleet-assets/assets/${asset.id}`, payload, options);
        } else {
            // _modal: the controller redirects back to the index (instead of the
            // new show page) with created_asset_id so this success pane can link.
            router.post(
                '/fleet-assets/assets',
                { ...payload, _modal: true },
                options,
            );
        }
    };

    /* ---- success pane ---- */
    const success = done ? (
        <WizardSuccessPane
            title={isEdit ? 'Asset updated' : 'Asset created'}
            blurb={
                isEdit ? (
                    <>
                        <span className="font-semibold">{data.name}</span> has
                        been updated on the asset register.
                    </>
                ) : (
                    <>
                        <span className="font-semibold">{data.name}</span> is now
                        on the asset register. Add documents, trackers,
                        inspections and assignments from its detail page.
                    </>
                )
            }
            actions={
                isEdit ? (
                    <Button onClick={onClose}>Done</Button>
                ) : (
                    <>
                        <Button variant="outline" onClick={resetAll}>
                            <Plus className="h-4 w-4" /> Add another
                        </Button>
                        {createdId != null ? (
                            <Button asChild>
                                <Link href={`/fleet-assets/assets/${createdId}`}>
                                    View asset
                                </Link>
                            </Button>
                        ) : (
                            <Button onClick={onClose}>Done</Button>
                        )}
                    </>
                )
            }
        />
    ) : undefined;

    /* ---- footer ---- */
    const footerStart = !done ? <Ring pct={pct} size={40} /> : undefined;
    const footerEnd = done ? undefined : (
        <>
            {stepIndex > 0 ? (
                <Button variant="ghost" onClick={back} disabled={processing}>
                    <ChevronLeft className="h-4 w-4" /> Back
                </Button>
            ) : null}
            <Button variant="outline" onClick={onClose} disabled={processing}>
                Cancel
            </Button>
            {isLast ? (
                <Button onClick={submit} disabled={processing}>
                    {processing ? (
                        <>
                            <Loader2 className="h-4 w-4 animate-spin" />
                            {isEdit ? 'Saving…' : 'Creating…'}
                        </>
                    ) : (
                        <>
                            <Check className="h-4 w-4" />
                            {isEdit ? 'Save changes' : 'Create asset'}
                        </>
                    )}
                </Button>
            ) : (
                <Button onClick={next}>
                    Continue <ChevronRight className="h-4 w-4" />
                </Button>
            )}
        </>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={isEdit ? `Edit ${asset?.name ?? 'asset'}` : 'Add asset'}
            description="A guided wizard to record an asset on the Fleet & Assets register."
            railIcon={isEdit ? Pencil : Package}
            railTitle={isEdit ? 'Edit asset' : 'New asset'}
            railSub={isEdit ? (asset?.name ?? 'Update details') : 'Asset register'}
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={(i) => {
                setErrors({});
                setStepIndex(i);
            }}
            pct={pct}
            footerStart={footerStart}
            footerEnd={footerEnd}
            success={success}
        >
            <WizardStepPane>
                {stepKey === 'basic' ? (
                    <StepBasic data={data} set={set} errors={errors} />
                ) : null}
                {stepKey === 'details' ? (
                    <StepDetails data={data} set={set} errors={errors} />
                ) : null}
                {stepKey === 'location' ? (
                    <StepLocation
                        data={data}
                        set={set}
                        errors={errors}
                        sites={sites}
                        clients={filteredClients}
                        onClientChange={handleClientChange}
                    />
                ) : null}
                {stepKey === 'compliance' ? (
                    <StepCompliance data={data} set={set} errors={errors} />
                ) : null}
            </WizardStepPane>
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Step contracts                                                     */
/* ------------------------------------------------------------------ */

type StepProps = {
    data: WizardState;
    set: <K extends keyof WizardState>(k: K, v: WizardState[K]) => void;
    errors: Record<string, string>;
};

/* ---- Step 1 — Basic info ---- */

function StepBasic({ data, set, errors }: StepProps) {
    return (
        <div>
            <StepHead
                icon={IdCard}
                title="What are we adding?"
                blurb="Name and tag the asset, then classify it so the right compliance fields follow."
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Name" required error={errors.name}>
                    <Input
                        value={data.name}
                        onChange={(e) => set('name', e.target.value)}
                        placeholder="Asset name"
                        aria-invalid={!!errors.name}
                    />
                </Field>
                <Field
                    label="Asset tag"
                    hint="internal reference"
                    error={errors.asset_tag}
                >
                    <Input
                        value={data.asset_tag}
                        onChange={(e) => set('asset_tag', e.target.value)}
                        placeholder="e.g. VEH-001"
                    />
                </Field>
                <Field label="Category" span error={errors.category}>
                    <TilePicker
                        value={data.category}
                        onChange={(v) => set('category', v)}
                        cols={2}
                        options={CATEGORY_OPTIONS}
                    />
                </Field>
                <Field label="Status" error={errors.status}>
                    <Segmented
                        value={data.status}
                        onChange={(v) => set('status', v)}
                        options={[
                            { value: 'active', label: 'Active' },
                            { value: 'out_of_service', label: 'Out of service' },
                            { value: 'retired', label: 'Retired' },
                        ]}
                    />
                </Field>
                <Field label="Risk level" error={errors.risk_level}>
                    <Segmented
                        value={data.risk_level}
                        onChange={(v) => set('risk_level', v)}
                        options={[
                            { value: 'low', label: 'Low' },
                            { value: 'medium', label: 'Medium' },
                            { value: 'high', label: 'High' },
                        ]}
                    />
                </Field>
                {data.category === 'vehicle' ? (
                    <InfoCard icon={Car}>
                        Vehicles get registration, fuel, odometer and WOF / CoF
                        compliance fields on the next steps.
                    </InfoCard>
                ) : null}
            </div>
        </div>
    );
}

/* ---- Step 2 — Details ---- */

function StepDetails({ data, set, errors }: StepProps) {
    return (
        <div>
            <StepHead
                icon={Wrench}
                title="Identify the asset"
                blurb="Make, model and serial details help match invoices, warranties and recalls."
            />
            <div className="grid gap-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <SubHead icon={Package}>Identification</SubHead>
                    <Field label="Manufacturer" error={errors.manufacturer}>
                        <Input
                            value={data.manufacturer}
                            onChange={(e) => set('manufacturer', e.target.value)}
                            placeholder="e.g. Toyota"
                        />
                    </Field>
                    <Field label="Model" error={errors.model}>
                        <Input
                            value={data.model}
                            onChange={(e) => set('model', e.target.value)}
                            placeholder="e.g. Hiace"
                        />
                    </Field>
                    <Field label="Serial number" error={errors.serial_number}>
                        <Input
                            value={data.serial_number}
                            onChange={(e) => set('serial_number', e.target.value)}
                            placeholder="Serial / VIN"
                        />
                    </Field>
                    <Field label="Description" span error={errors.description}>
                        <Textarea
                            rows={3}
                            value={data.description}
                            onChange={(e) => set('description', e.target.value)}
                            placeholder="What is it, and what is it used for?"
                        />
                    </Field>
                </div>

                {data.category === 'vehicle' ? (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <SubHead icon={Car}>Vehicle details</SubHead>
                        <Field
                            label="Registration number"
                            error={errors.registration_number}
                        >
                            <Input
                                value={data.registration_number}
                                onChange={(e) =>
                                    set('registration_number', e.target.value)
                                }
                                placeholder="e.g. ABC123"
                            />
                        </Field>
                        <Field label="Fuel type" error={errors.fuel_type}>
                            <SelectInput
                                value={data.fuel_type}
                                onChange={(v) =>
                                    set('fuel_type', v === 'none' ? '' : v)
                                }
                                placeholder="Select fuel type"
                                options={FUEL_TYPES}
                            />
                        </Field>
                        <Field
                            label="Odometer"
                            hint="km"
                            error={errors.odometer_km}
                        >
                            <Input
                                type="number"
                                min={0}
                                value={data.odometer_km}
                                onChange={(e) =>
                                    set('odometer_km', e.target.value)
                                }
                                placeholder="e.g. 84500"
                            />
                        </Field>
                    </div>
                ) : null}

                <div className="grid gap-4 sm:grid-cols-2">
                    <SubHead icon={ClipboardCheck}>
                        Purchase &amp; warranty
                    </SubHead>
                    <Field label="Purchase date" error={errors.purchase_date}>
                        <Input
                            type="date"
                            value={data.purchase_date}
                            onChange={(e) =>
                                set('purchase_date', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="Warranty expires"
                        error={errors.warranty_expires_at}
                    >
                        <Input
                            type="date"
                            value={data.warranty_expires_at}
                            onChange={(e) =>
                                set('warranty_expires_at', e.target.value)
                            }
                        />
                    </Field>
                </div>
            </div>
        </div>
    );
}

/* ---- Step 3 — Location ---- */

function StepLocation({
    data,
    set,
    errors,
    sites,
    clients,
    onClientChange,
}: StepProps & {
    sites: SiteOption[];
    clients: ClientOption[];
    onClientChange: (v: string) => void;
}) {
    return (
        <div>
            <StepHead
                icon={MapPin}
                title="Where does it live?"
                blurb="Every asset belongs to a site or a client — that drives who sees it and where it turns up."
            />
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Site" error={errors.site_id}>
                    <SelectInput
                        value={data.site_id}
                        onChange={(v) => set('site_id', v === 'none' ? '' : v)}
                        placeholder="Select site"
                        options={[
                            { value: 'none', label: '—' },
                            ...sites.map((s) => ({
                                value: String(s.id),
                                label: s.name,
                            })),
                        ]}
                    />
                </Field>
                <Field
                    label="Client"
                    hint="picking a client auto-sets the site"
                    error={errors.client_id}
                >
                    <SelectInput
                        value={data.client_id}
                        onChange={onClientChange}
                        placeholder="Select client (optional)"
                        options={[
                            { value: 'none', label: '—' },
                            ...clients.map((c) => ({
                                value: String(c.id),
                                label: `${c.first_name} ${c.last_name}`,
                            })),
                        ]}
                    />
                </Field>
                <Field label="Home site" error={errors.home_site_id}>
                    <SelectInput
                        value={data.home_site_id}
                        onChange={(v) =>
                            set('home_site_id', v === 'none' ? '' : v)
                        }
                        placeholder="Select home site"
                        options={[
                            { value: 'none', label: '—' },
                            ...sites.map((s) => ({
                                value: String(s.id),
                                label: s.name,
                            })),
                        ]}
                    />
                </Field>
                <Field
                    label="Location description"
                    error={errors.location}
                >
                    <Input
                        value={data.location}
                        onChange={(e) => set('location', e.target.value)}
                        placeholder="e.g. Bay 3, Warehouse A"
                    />
                </Field>
                <InfoCard icon={Info}>
                    An asset must belong to at least a site or a client. If you
                    pick a client, the owning site is derived from their
                    placement on save.
                </InfoCard>
            </div>
        </div>
    );
}

/* ---- Step 4 — Compliance ---- */

function StepCompliance({ data, set, errors }: StepProps) {
    return (
        <div>
            <StepHead
                icon={ShieldCheck}
                title="Keep it compliant"
                blurb="Expiry dates and recurring checks feed the compliance chips, calendar and reminders."
            />
            <div className="grid gap-4">
                {data.category === 'vehicle' ? (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <SubHead icon={Car}>Compliance dates</SubHead>
                        <Field
                            label="Registration expires"
                            error={errors.registration_expires_at}
                        >
                            <Input
                                type="date"
                                value={data.registration_expires_at}
                                onChange={(e) =>
                                    set(
                                        'registration_expires_at',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="WOF expires"
                            error={errors.wof_expires_at}
                        >
                            <Input
                                type="date"
                                value={data.wof_expires_at}
                                onChange={(e) =>
                                    set('wof_expires_at', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="COF expires"
                            error={errors.cof_expires_at}
                        >
                            <Input
                                type="date"
                                value={data.cof_expires_at}
                                onChange={(e) =>
                                    set('cof_expires_at', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                ) : null}

                <div className="grid gap-4 sm:grid-cols-2">
                    <SubHead icon={ClipboardCheck}>
                        Inspection &amp; maintenance
                    </SubHead>
                    <div className="flex items-start gap-3 rounded-lg border border-border bg-muted/40 p-3 sm:col-span-2">
                        <Switch
                            checked={data.requires_inspection}
                            onCheckedChange={(v) =>
                                set('requires_inspection', v)
                            }
                            aria-label="Requires inspection"
                        />
                        <div className="min-w-0 flex-1">
                            <div className="text-sm font-semibold">
                                Requires inspection
                            </div>
                            <div className="mt-0.5 text-[13px] text-muted-foreground">
                                Recurring safety or condition checks.
                            </div>
                            {data.requires_inspection ? (
                                <div className="mt-2 max-w-xs">
                                    <Field
                                        label="Next inspection due"
                                        error={errors.inspection_due_at}
                                    >
                                        <Input
                                            type="date"
                                            value={data.inspection_due_at}
                                            onChange={(e) =>
                                                set(
                                                    'inspection_due_at',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                            ) : null}
                        </div>
                    </div>
                    <div className="flex items-start gap-3 rounded-lg border border-border bg-muted/40 p-3 sm:col-span-2">
                        <Switch
                            checked={data.requires_maintenance}
                            onCheckedChange={(v) =>
                                set('requires_maintenance', v)
                            }
                            aria-label="Requires maintenance"
                        />
                        <div className="min-w-0 flex-1">
                            <div className="text-sm font-semibold">
                                Requires maintenance
                            </div>
                            <div className="mt-0.5 text-[13px] text-muted-foreground">
                                Scheduled servicing or upkeep.
                            </div>
                            {data.requires_maintenance ? (
                                <div className="mt-2 max-w-xs">
                                    <Field
                                        label="Next maintenance due"
                                        error={errors.maintenance_due_at}
                                    >
                                        <Input
                                            type="date"
                                            value={data.maintenance_due_at}
                                            onChange={(e) =>
                                                set(
                                                    'maintenance_due_at',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                </div>
                            ) : null}
                        </div>
                    </div>
                </div>

                <div className="grid gap-4">
                    <SubHead icon={Pencil}>Notes</SubHead>
                    <Field error={errors.notes}>
                        <Textarea
                            rows={4}
                            value={data.notes}
                            onChange={(e) => set('notes', e.target.value)}
                            placeholder="Additional notes…"
                            aria-label="Notes"
                        />
                    </Field>
                </div>
            </div>
        </div>
    );
}
