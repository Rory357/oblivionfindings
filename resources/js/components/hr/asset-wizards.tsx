/* The six Asset Management lifecycle wizards — New/Edit, Assign, Return, Log
 * repair, Return to service, Retire. Each is built on the shared HR wizard kit
 * (WizardShell + primitives) so it is visually identical to the Add-Client /
 * Leave-request modals. Zero confirm(): every action is a reviewed stepper modal
 * ending in a success pane. Vehicles & keys federate to the canonical Fleet
 * register rather than being hand-typed. */
import { useForm } from '@inertiajs/react';
import {
    Boxes,
    CheckCircle2,
    ClipboardCheck,
    FileText,
    Hash,
    QrCode,
    RotateCcw,
    Search,
    Shield,
    Trash2,
    Truck,
    User,
    UserCheck,
    Wrench,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import {
    Field,
    ReviewCard,
    ReviewRow,
    Segmented,
    StepHead,
    TilePicker,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/hr/wizard';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { fireConfetti } from '@/lib/confetti';

import {
    categoryLabel,
    fdate,
    initials,
    nzd,
    type CategoryOption,
    type StaffOption,
} from './asset-parts';

/* ------------------------------------------------------------------ */
/*  Public types                                                      */
/* ------------------------------------------------------------------ */

export interface EditableAsset {
    id: number;
    tag: string;
    name: string;
    category: string;
    make: string | null;
    model: string | null;
    serial: string | null;
    cost: number | null;
    supplier: string | null;
    warranty: string | null;
    purchase_date?: string | null;
    condition?: string | null;
    depreciation_method?: string | null;
    useful_life_years?: number | null;
    fleet_asset_id?: number | null;
    qr_token?: string | null;
}

export interface AssetRef {
    id: number;
    name: string | null;
    tag: string | null;
    assignee?: string | null;
}

export type AssetModal =
    | { type: 'new'; asset?: EditableAsset | null }
    | { type: 'assign'; asset: AssetRef }
    | { type: 'return'; assignmentId: number; asset: AssetRef }
    | { type: 'maintenance'; asset: AssetRef }
    | { type: 'rfs'; asset: AssetRef }
    | { type: 'retire'; asset: AssetRef };

const COND_OPTS = [
    { value: 'good', label: 'Good' },
    { value: 'fair', label: 'Fair' },
    { value: 'poor', label: 'Poor' },
];

const today = () => new Date().toISOString().slice(0, 10);

/** Flash error carried by an Inertia redirect (validation / logic-guard). Read
 *  from the page passed to onSuccess — `back()->with('error')` fires onSuccess,
 *  not onError (see reference_inertia_flash_error). */
function pageFlashError(page: { props: Record<string, unknown> }): string | null {
    const flash = page.props.flash as { error?: string } | undefined;
    return flash?.error ?? null;
}

/* ================================================================== */
/*  Dispatcher                                                        */
/* ================================================================== */

export function AssetWizard({
    modal,
    staff,
    categories,
    onClose,
}: {
    modal: AssetModal | null;
    staff: StaffOption[];
    categories: CategoryOption[];
    onClose: () => void;
}) {
    if (!modal) return null;
    switch (modal.type) {
        case 'new':
            return (
                <NewAssetWizard
                    asset={modal.asset ?? null}
                    categories={categories}
                    onClose={onClose}
                />
            );
        case 'assign':
            return <AssignWizard asset={modal.asset} staff={staff} onClose={onClose} />;
        case 'return':
            return (
                <ReturnWizard
                    assignmentId={modal.assignmentId}
                    asset={modal.asset}
                    onClose={onClose}
                />
            );
        case 'maintenance':
            return <MaintenanceWizard asset={modal.asset} onClose={onClose} />;
        case 'rfs':
            return <ReturnToServiceWizard asset={modal.asset} onClose={onClose} />;
        case 'retire':
            return <RetireWizard asset={modal.asset} onClose={onClose} />;
    }
}

/* ================================================================== */
/*  New / Edit asset                                                  */
/* ================================================================== */

interface FleetMatch {
    id: number;
    name: string;
    asset_tag: string | null;
    category: string;
    registration_number: string | null;
    serial_number: string | null;
    status: string;
}

const NEW_STEPS: readonly WizardStep[] = [
    { key: 'identity', label: 'Identity', blurb: 'Tag, name & type', icon: Hash },
    { key: 'specs', label: 'Specifications', blurb: 'Make, model, serial', icon: Boxes },
    { key: 'purchase', label: 'Purchase & warranty', blurb: 'Cost, supplier, cover', icon: FileText },
    { key: 'tagging', label: 'Tagging', blurb: 'QR / barcode label', icon: QrCode },
    { key: 'docs', label: 'Documents', blurb: 'Receipt, warranty, photo', icon: FileText },
    { key: 'review', label: 'Review', blurb: 'Confirm & create', icon: CheckCircle2 },
];

function NewAssetWizard({
    asset,
    categories,
    onClose,
}: {
    asset: EditableAsset | null;
    categories: CategoryOption[];
    onClose: () => void;
}) {
    const isEdit = asset !== null;
    const wizard = useWizard(NEW_STEPS.length);
    const [done, setDone] = useState(false);
    const [fleetQuery, setFleetQuery] = useState('');
    const [fleetResults, setFleetResults] = useState<FleetMatch[]>([]);
    const [fleetPick, setFleetPick] = useState<FleetMatch | null>(null);

    const form = useForm({
        asset_tag: asset?.tag ?? '',
        name: asset?.name ?? '',
        category: asset?.category ?? 'laptop',
        make: asset?.make ?? '',
        model: asset?.model ?? '',
        serial_number: asset?.serial ?? '',
        condition: asset?.condition ?? 'new',
        purchase_date: asset?.purchase_date ?? '',
        purchase_cost: asset?.cost != null ? String(asset.cost) : '',
        supplier: asset?.supplier ?? '',
        warranty_expiry: asset?.warranty ?? '',
        depreciation_method: asset?.depreciation_method ?? 'straight',
        useful_life_years: asset?.useful_life_years != null ? String(asset.useful_life_years) : '',
        fleet_asset_id: asset?.fleet_asset_id != null ? String(asset.fleet_asset_id) : '',
        notes: '',
    });

    const isFleetCategory = form.data.category === 'vehicle' || form.data.category === 'key';
    const fleetLinked = form.data.fleet_asset_id !== '';

    // Federation: search the canonical Fleet register (debounced) when a
    // fleet-owned category is chosen — vehicles/keys are linked, never re-typed.
    useEffect(() => {
        if (!isFleetCategory || fleetLinked) {
            setFleetResults([]);
            return;
        }
        let cancelled = false;
        const t = setTimeout(() => {
            fetch(`/hr/assets/fleet-search?q=${encodeURIComponent(fleetQuery)}`, {
                headers: { Accept: 'application/json' },
            })
                .then((r) => (r.ok ? r.json() : { data: [] }))
                .then((d) => {
                    if (!cancelled) setFleetResults(d.data ?? []);
                })
                .catch(() => {
                    if (!cancelled) setFleetResults([]);
                });
        }, 250);
        return () => {
            cancelled = true;
            clearTimeout(t);
        };
    }, [isFleetCategory, fleetLinked, fleetQuery]);

    const pickFleet = (m: FleetMatch) => {
        setFleetPick(m);
        form.setData((d) => ({
            ...d,
            fleet_asset_id: String(m.id),
            name: m.name,
            asset_tag: m.asset_tag ?? d.asset_tag,
            serial_number: m.serial_number ?? d.serial_number,
        }));
    };
    const unlinkFleet = () => {
        setFleetPick(null);
        form.setData('fleet_asset_id', '');
    };

    const submit = () => {
        const onResult = (page: { props: Record<string, unknown> }) => {
            const err = pageFlashError(page);
            if (err) {
                toast.error(err);
                return;
            }
            setDone(true);
            fireConfetti();
        };
        const opts = { preserveScroll: true, onSuccess: onResult } as const;
        if (isEdit) form.put(`/hr/assets/${asset!.id}`, opts);
        else form.post('/hr/assets', opts);
    };

    const reset = () => {
        form.reset();
        setFleetPick(null);
        setDone(false);
        wizard.goTo(0);
    };

    const canContinue = form.data.asset_tag.trim() !== '' && form.data.name.trim() !== '';

    return (
        <WizardShell
            open
            onClose={onClose}
            title={isEdit ? 'Edit asset' : 'New asset'}
            description="Add a piece of staff equipment to the register."
            railIcon={Boxes}
            railTitle={isEdit ? 'Edit asset' : 'New asset'}
            railSub="Equipment register"
            steps={NEW_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title={isEdit ? 'Asset updated' : 'Asset created'}
                        blurb={
                            <>
                                “{form.data.name || 'New asset'}” is{' '}
                                {isEdit ? 'updated' : 'now in the register and ready to assign'}.
                            </>
                        }
                        actions={
                            <div className="flex gap-2.5">
                                {!isEdit ? (
                                    <Button variant="outline" onClick={reset}>
                                        Save &amp; add another
                                    </Button>
                                ) : null}
                                <Button onClick={onClose}>Done</Button>
                            </div>
                        }
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button onClick={submit} disabled={form.processing || !canContinue}>
                            {form.processing
                                ? 'Saving…'
                                : isEdit
                                  ? 'Save changes'
                                  : 'Create asset'}
                        </Button>
                    ) : (
                        <Button
                            onClick={wizard.next}
                            disabled={wizard.index === 0 && !canContinue}
                        >
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={Hash}
                        title="Identify the asset"
                        blurb="Tag, name and what type of equipment this is."
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field label="Asset tag" required>
                            <Input
                                value={form.data.asset_tag}
                                onChange={(e) => form.setData('asset_tag', e.target.value)}
                                placeholder="LT-0436"
                                className="font-mono"
                                readOnly={fleetLinked}
                            />
                        </Field>
                        <Field label="Asset name" required>
                            <Input
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder='e.g. MacBook Pro 14"'
                                readOnly={fleetLinked}
                            />
                        </Field>
                    </div>
                    <div className="mt-4">
                        <Field label="Type" required>
                            <TilePicker
                                value={form.data.category}
                                onChange={(v) => {
                                    form.setData('category', v);
                                    if (v !== 'vehicle' && v !== 'key') unlinkFleet();
                                }}
                                cols={3}
                                options={categories.map((c) => ({
                                    key: c.value,
                                    label: c.label,
                                    meta: c.fleet ? '→ Links to Fleet register' : undefined,
                                }))}
                            />
                        </Field>
                    </div>
                    {isFleetCategory ? (
                        <FleetLinkPanel
                            query={fleetQuery}
                            setQuery={setFleetQuery}
                            results={fleetResults}
                            picked={fleetPick}
                            onPick={pickFleet}
                            onUnlink={unlinkFleet}
                        />
                    ) : null}
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Boxes}
                        title="Specifications"
                        blurb="Manufacturer details so the asset is identifiable on sight."
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field label="Make / manufacturer">
                            <Input
                                value={form.data.make}
                                onChange={(e) => form.setData('make', e.target.value)}
                                placeholder="Apple"
                            />
                        </Field>
                        <Field label="Model">
                            <Input
                                value={form.data.model}
                                onChange={(e) => form.setData('model', e.target.value)}
                                placeholder="M3 Pro"
                            />
                        </Field>
                        <Field label="Serial number">
                            <Input
                                value={form.data.serial_number}
                                onChange={(e) => form.setData('serial_number', e.target.value)}
                                placeholder="C02XR9…"
                                className="font-mono"
                            />
                        </Field>
                        <Field label="Condition at intake">
                            <Segmented
                                value={form.data.condition}
                                onChange={(v) => form.setData('condition', v)}
                                options={[
                                    { value: 'new', label: 'New' },
                                    { value: 'good', label: 'Good' },
                                    { value: 'refurb', label: 'Refurbished' },
                                ]}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={FileText}
                        title="Purchase & warranty"
                        blurb="Cost, supplier and cover — drives book value and reminders."
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field label="Purchase date">
                            <Input
                                type="date"
                                value={form.data.purchase_date}
                                onChange={(e) => form.setData('purchase_date', e.target.value)}
                            />
                        </Field>
                        <Field label="Purchase cost (NZD)">
                            <Input
                                type="number"
                                min="0"
                                step="0.01"
                                value={form.data.purchase_cost}
                                onChange={(e) => form.setData('purchase_cost', e.target.value)}
                                placeholder="3299"
                            />
                        </Field>
                        <Field label="Supplier">
                            <Input
                                value={form.data.supplier}
                                onChange={(e) => form.setData('supplier', e.target.value)}
                                placeholder="Noel Leeming Business"
                            />
                        </Field>
                        <Field label="Warranty expiry">
                            <Input
                                type="date"
                                value={form.data.warranty_expiry}
                                onChange={(e) => form.setData('warranty_expiry', e.target.value)}
                            />
                        </Field>
                        <Field label="Depreciation">
                            <Segmented
                                value={form.data.depreciation_method}
                                onChange={(v) => form.setData('depreciation_method', v)}
                                options={[
                                    { value: 'straight', label: 'Straight-line' },
                                    { value: 'diminishing', label: 'Diminishing' },
                                ]}
                            />
                        </Field>
                        <Field label="Useful life (years)">
                            <Input
                                type="number"
                                min="0"
                                max="50"
                                value={form.data.useful_life_years}
                                onChange={(e) => form.setData('useful_life_years', e.target.value)}
                                placeholder="4"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 3 && (
                <WizardStepPane>
                    <StepHead
                        icon={QrCode}
                        title="Tag the asset"
                        blurb="A QR label staff can scan to open this record and log a scan event."
                    />
                    <div className="flex flex-col items-start gap-4 rounded-xl border border-dashed border-border bg-muted/40 p-5 sm:flex-row sm:items-center">
                        <div className="grid h-[120px] w-[120px] flex-none place-items-center rounded-xl border border-border bg-card">
                            {isEdit && asset?.qr_token ? (
                                <img
                                    src={`/hr/assets/${asset.id}/qr.svg`}
                                    alt="Asset QR label"
                                    className="h-[104px] w-[104px]"
                                />
                            ) : (
                                <QrCode className="h-12 w-12 text-muted-foreground" />
                            )}
                        </div>
                        <div className="min-w-0">
                            <div className="text-sm font-bold">
                                {isEdit ? 'QR token ready' : 'QR token generated on save'}
                            </div>
                            <div className="mt-1 text-[13px] text-muted-foreground">
                                Scanning opens the asset detail and logs a scan event for the
                                audit trail.
                            </div>
                            {isEdit ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="mt-3"
                                    onClick={() =>
                                        window.open(`/hr/assets/${asset!.id}/qr.svg`, '_blank')
                                    }
                                >
                                    <QrCode className="h-4 w-4" /> Print label
                                </Button>
                            ) : null}
                        </div>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 4 && (
                <WizardStepPane>
                    <StepHead
                        icon={FileText}
                        title="Documents"
                        blurb="Receipts, warranties, photos and signed handovers."
                    />
                    <div className="flex items-start gap-3 rounded-xl border border-border bg-muted/40 p-4">
                        <FileText className="mt-0.5 h-5 w-5 flex-none text-primary" />
                        <div className="text-[13px] text-muted-foreground">
                            Once the asset is {isEdit ? 'saved' : 'created'} you can attach the
                            receipt, warranty PDF and photos from its{' '}
                            <strong className="text-foreground">Documents</strong> library on the
                            asset detail page — each upload is stored privately and audit-logged.
                        </div>
                    </div>
                    <div className="mt-3.5">
                        <Field label="Notes">
                            <Textarea
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                placeholder="Charger and case included, asset kept at Auckland Central…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 5 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review the asset"
                        blurb="Check the details, then confirm below."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard icon={Hash} title="Identity" onEdit={() => wizard.goTo(0)}>
                            <ReviewRow label="Tag" value={form.data.asset_tag} />
                            <ReviewRow label="Name" value={form.data.name} />
                            <ReviewRow label="Type" value={categoryLabel(form.data.category)} />
                            {fleetLinked ? (
                                <ReviewRow label="Fleet-linked" value="Yes" />
                            ) : null}
                        </ReviewCard>
                        <ReviewCard icon={Boxes} title="Specifications" onEdit={() => wizard.goTo(1)}>
                            <ReviewRow
                                label="Make / model"
                                value={[form.data.make, form.data.model].filter(Boolean).join(' ') || undefined}
                            />
                            <ReviewRow label="Serial" value={form.data.serial_number || undefined} />
                            <ReviewRow label="Condition" value={form.data.condition} />
                        </ReviewCard>
                        <ReviewCard
                            icon={FileText}
                            title="Purchase & warranty"
                            onEdit={() => wizard.goTo(2)}
                            span
                        >
                            <ReviewRow
                                label="Cost"
                                value={form.data.purchase_cost ? nzd(Number(form.data.purchase_cost)) : undefined}
                            />
                            <ReviewRow label="Supplier" value={form.data.supplier || undefined} />
                            <ReviewRow
                                label="Warranty"
                                value={form.data.warranty_expiry ? fdate(form.data.warranty_expiry) : undefined}
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

function FleetLinkPanel({
    query,
    setQuery,
    results,
    picked,
    onPick,
    onUnlink,
}: {
    query: string;
    setQuery: (v: string) => void;
    results: FleetMatch[];
    picked: FleetMatch | null;
    onPick: (m: FleetMatch) => void;
    onUnlink: () => void;
}) {
    return (
        <div
            className="mt-4 rounded-xl border p-4"
            style={{
                borderColor: 'color-mix(in oklch, var(--category-fleet) 35%, transparent)',
                background: 'color-mix(in oklch, var(--category-fleet) 8%, transparent)',
            }}
        >
            <div className="flex items-start gap-2.5">
                <Truck className="mt-0.5 h-5 w-5 flex-none" style={{ color: 'var(--category-fleet)' }} />
                <div className="min-w-0 flex-1">
                    <div className="text-[13px] font-bold">This type is owned by Fleet &amp; Assets</div>
                    <div className="mt-0.5 text-[12.5px] text-muted-foreground">
                        To avoid duplicates, vehicles and keys link to the canonical Fleet register
                        rather than being re-typed here.
                    </div>

                    {picked ? (
                        <div className="mt-3 flex items-center gap-3 rounded-lg border border-border bg-card p-2.5">
                            <Truck className="h-4 w-4 flex-none" style={{ color: 'var(--category-fleet)' }} />
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-[13px] font-semibold">{picked.name}</div>
                                <div className="truncate font-mono text-[11px] text-muted-foreground">
                                    {[picked.asset_tag, picked.registration_number].filter(Boolean).join(' · ')}
                                </div>
                            </div>
                            <Button variant="ghost" size="sm" onClick={onUnlink}>
                                Change
                            </Button>
                        </div>
                    ) : (
                        <>
                            <div className="relative mt-3">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    placeholder="Search Fleet register by name, plate or tag…"
                                    className="pl-8"
                                />
                            </div>
                            <div className="mt-2 flex max-h-44 flex-col gap-1 overflow-y-auto">
                                {results.length === 0 ? (
                                    <div className="px-1 py-3 text-center text-[12.5px] text-muted-foreground">
                                        {query ? 'No matching Fleet asset.' : 'Start typing to search the Fleet register.'}
                                    </div>
                                ) : (
                                    results.map((m) => (
                                        <button
                                            key={m.id}
                                            type="button"
                                            onClick={() => onPick(m)}
                                            className="flex items-center gap-3 rounded-lg border border-border bg-card px-3 py-2 text-left transition-colors hover:border-primary"
                                        >
                                            <Truck className="h-4 w-4 flex-none text-muted-foreground" />
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate text-[13px] font-semibold">{m.name}</div>
                                                <div className="truncate font-mono text-[11px] text-muted-foreground">
                                                    {[m.asset_tag, m.registration_number].filter(Boolean).join(' · ')}
                                                </div>
                                            </div>
                                            <span className="text-[11px] text-muted-foreground capitalize">{m.status}</span>
                                        </button>
                                    ))
                                )}
                            </div>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}

/* ================================================================== */
/*  Assign                                                            */
/* ================================================================== */

const ASSIGN_STEPS: readonly WizardStep[] = [
    { key: 'who', label: 'Employee', blurb: 'Who receives it', icon: User },
    { key: 'cond', label: 'Condition & dates', blurb: 'State out + return-by', icon: Boxes },
    { key: 'ack', label: 'Acknowledgement', blurb: 'Handover sign-off', icon: Shield },
    { key: 'review', label: 'Review', blurb: 'Confirm & assign', icon: CheckCircle2 },
];

function AssignWizard({
    asset,
    staff,
    onClose,
}: {
    asset: AssetRef;
    staff: StaffOption[];
    onClose: () => void;
}) {
    const wizard = useWizard(ASSIGN_STEPS.length);
    const [done, setDone] = useState(false);
    const [search, setSearch] = useState('');

    const form = useForm({
        employee_profile_id: '',
        assigned_at: today(),
        due_at: '',
        condition_on_assign: 'good',
        acknowledged: false as boolean,
        notes: '',
    });

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return staff;
        return staff.filter((s) =>
            `${s.name} ${s.role ?? ''} ${s.site ?? ''}`.toLowerCase().includes(q),
        );
    }, [search, staff]);

    const picked = staff.find((s) => String(s.id) === form.data.employee_profile_id) ?? null;

    const submit = () => {
        form.post(`/hr/assets/${asset.id}/assign`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
                fireConfetti();
            },
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Assign asset"
            description={`Hand ${asset.name ?? 'this asset'} to a staff member.`}
            railIcon={UserCheck}
            railTitle="Assign asset"
            railSub="Lifecycle action"
            steps={ASSIGN_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title="Asset assigned"
                        blurb={
                            <>
                                “{asset.name}” is now with {picked?.name ?? 'the employee'}.
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button onClick={submit} disabled={form.processing || !picked}>
                            {form.processing ? 'Assigning…' : 'Assign'}
                        </Button>
                    ) : (
                        <Button onClick={wizard.next} disabled={wizard.index === 0 && !picked}>
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={User}
                        title="Who receives it?"
                        blurb={`Pick the staff member taking custody of ${asset.name ?? 'this asset'}.`}
                    />
                    <div className="relative mb-3">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search staff by name or role…"
                            className="pl-8"
                        />
                    </div>
                    <div className="flex max-h-64 flex-col gap-1.5 overflow-y-auto">
                        {filtered.map((s) => {
                            const active = String(s.id) === form.data.employee_profile_id;
                            return (
                                <button
                                    key={s.id}
                                    type="button"
                                    onClick={() => form.setData('employee_profile_id', String(s.id))}
                                    className={`flex items-center gap-3 rounded-xl border bg-card px-3 py-2.5 text-left transition-colors ${active ? 'border-primary bg-primary/[0.06]' : 'border-border hover:border-primary/50'}`}
                                >
                                    <span className="grid h-9 w-9 flex-none place-items-center rounded-full bg-primary/12 text-[12.5px] font-bold text-primary">
                                        {initials(s.name)}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="text-[13.5px] font-bold">{s.name}</div>
                                        <div className="text-[11.5px] text-muted-foreground">
                                            {[s.role, s.site].filter(Boolean).join(' · ') || '—'}
                                        </div>
                                    </div>
                                    {active ? <CheckCircle2 className="h-5 w-5 text-primary" /> : null}
                                </button>
                            );
                        })}
                        {filtered.length === 0 ? (
                            <div className="py-6 text-center text-[13px] text-muted-foreground">
                                No staff match “{search}”.
                            </div>
                        ) : null}
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Boxes}
                        title="Condition & dates"
                        blurb="Record the state going out and when it should come back."
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field label="Assigned date" required>
                            <Input
                                type="date"
                                value={form.data.assigned_at}
                                onChange={(e) => form.setData('assigned_at', e.target.value)}
                            />
                        </Field>
                        <Field label="Return-by date" hint="optional">
                            <Input
                                type="date"
                                value={form.data.due_at}
                                onChange={(e) => form.setData('due_at', e.target.value)}
                            />
                        </Field>
                    </div>
                    <div className="mt-4">
                        <Field label="Condition on assign">
                            <Segmented
                                value={form.data.condition_on_assign}
                                onChange={(v) => form.setData('condition_on_assign', v)}
                                options={COND_OPTS}
                            />
                        </Field>
                    </div>
                    <div className="mt-3.5">
                        <Field label="Notes">
                            <Textarea
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                placeholder="Charger and case included…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={Shield}
                        title="Acknowledgement"
                        blurb="Capture the employee’s sign-off for the handover."
                    />
                    <label className="flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-card p-4">
                        <input
                            type="checkbox"
                            checked={form.data.acknowledged}
                            onChange={(e) => form.setData('acknowledged', e.target.checked)}
                            className="mt-0.5 h-4 w-4 accent-[var(--primary)]"
                        />
                        <span>
                            <span className="block text-[13px] font-semibold">
                                {picked?.name ?? 'The employee'} acknowledged receipt
                            </span>
                            <span className="block text-[12.5px] text-muted-foreground">
                                Records the handover acknowledgement timestamp against this
                                assignment. Leave unchecked to assign now and collect the
                                acknowledgement later.
                            </span>
                        </span>
                    </label>
                </WizardStepPane>
            )}

            {wizard.index === 3 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Confirm the handover"
                        blurb="Check the details, then assign."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard icon={User} title="Employee" onEdit={() => wizard.goTo(0)}>
                            <ReviewRow label="Assignee" value={picked?.name} />
                            <ReviewRow label="Asset" value={`${asset.name} · ${asset.tag}`} />
                        </ReviewCard>
                        <ReviewCard icon={Boxes} title="Condition & dates" onEdit={() => wizard.goTo(1)}>
                            <ReviewRow label="Assigned" value={fdate(form.data.assigned_at)} />
                            <ReviewRow label="Return by" value={form.data.due_at ? fdate(form.data.due_at) : undefined} />
                            <ReviewRow label="Condition" value={form.data.condition_on_assign} />
                        </ReviewCard>
                        <ReviewCard icon={Shield} title="Acknowledgement" onEdit={() => wizard.goTo(2)} span>
                            <ReviewRow label="Status" value={form.data.acknowledged ? 'Acknowledged' : 'Pending'} />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Return                                                            */
/* ================================================================== */

const RETURN_STEPS: readonly WizardStep[] = [
    { key: 'when', label: 'Return', blurb: 'Date & condition', icon: RotateCcw },
    { key: 'review', label: 'Review', blurb: 'Confirm & return', icon: CheckCircle2 },
];

function ReturnWizard({
    assignmentId,
    asset,
    onClose,
}: {
    assignmentId: number;
    asset: AssetRef;
    onClose: () => void;
}) {
    const wizard = useWizard(RETURN_STEPS.length);
    const [done, setDone] = useState(false);
    const form = useForm({
        returned_at: today(),
        condition_on_return: 'good',
        damaged: false as boolean,
        notes: '',
    });

    const submit = () => {
        form.post(`/hr/assets/assignments/${assignmentId}/return`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
            },
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Return asset"
            description={`Record the return of ${asset.name ?? 'this asset'}.`}
            railIcon={RotateCcw}
            railTitle="Return asset"
            railSub="Lifecycle action"
            steps={RETURN_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title="Asset returned"
                        blurb={
                            <>
                                “{asset.name}” is back and marked{' '}
                                {form.data.damaged ? 'for maintenance' : 'available'}.
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button onClick={submit} disabled={form.processing}>
                            {form.processing ? 'Returning…' : 'Confirm return'}
                        </Button>
                    ) : (
                        <Button onClick={wizard.next}>Continue</Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={RotateCcw}
                        title={`Return ${asset.name ?? 'asset'}`}
                        blurb="Record the return and the condition it came back in."
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field label="Return date" required>
                            <Input
                                type="date"
                                value={form.data.returned_at}
                                onChange={(e) => form.setData('returned_at', e.target.value)}
                            />
                        </Field>
                        <Field label="Condition on return">
                            <Segmented
                                value={form.data.condition_on_return}
                                onChange={(v) => form.setData('condition_on_return', v)}
                                options={COND_OPTS}
                            />
                        </Field>
                    </div>
                    <div className="mt-3.5">
                        <Field label="Notes">
                            <Textarea
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                placeholder="Returned with charger, minor scuffs…"
                            />
                        </Field>
                    </div>
                    <label className="mt-3.5 flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-card p-4">
                        <input
                            type="checkbox"
                            checked={form.data.damaged}
                            onChange={(e) => form.setData('damaged', e.target.checked)}
                            className="mt-0.5 h-4 w-4 accent-[var(--status-critical)]"
                        />
                        <span>
                            <span className="block text-[13px] font-semibold">Damaged or lost</span>
                            <span className="block text-[12.5px] text-muted-foreground">
                                Parks the asset in maintenance on return so you can log a repair or
                                retire it as lost/damaged.
                            </span>
                        </span>
                    </label>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Confirm the return"
                        blurb="Check the details, then confirm."
                    />
                    <ReviewCard icon={RotateCcw} title="Return" onEdit={() => wizard.goTo(0)} span>
                        <ReviewRow label="Asset" value={`${asset.name} · ${asset.tag}`} />
                        <ReviewRow label="From" value={asset.assignee ?? undefined} />
                        <ReviewRow label="Date" value={fdate(form.data.returned_at)} />
                        <ReviewRow label="Condition" value={form.data.condition_on_return} />
                        <ReviewRow label="Flag" value={form.data.damaged ? 'Damaged / lost' : 'None'} />
                    </ReviewCard>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Log repair / maintenance                                          */
/* ================================================================== */

const MAINT_STEPS: readonly WizardStep[] = [
    { key: 'job', label: 'Repair job', blurb: 'Vendor, cost, dates', icon: Wrench },
    { key: 'review', label: 'Review', blurb: 'Confirm & log', icon: CheckCircle2 },
];

function MaintenanceWizard({ asset, onClose }: { asset: AssetRef; onClose: () => void }) {
    const wizard = useWizard(MAINT_STEPS.length);
    const [done, setDone] = useState(false);
    const form = useForm({
        type: 'repair',
        vendor: '',
        cost: '',
        sent_at: today(),
        expected_back_at: '',
        next_due_at: '',
        notes: '',
    });

    const submit = () => {
        form.post(`/hr/assets/${asset.id}/maintenance`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
            },
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Log repair"
            description={`Log a repair or service for ${asset.name ?? 'this asset'}.`}
            railIcon={Wrench}
            railTitle="Log repair"
            railSub="Lifecycle action"
            steps={MAINT_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title="Repair logged"
                        blurb="The job is in the maintenance queue and the asset is marked in maintenance."
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button onClick={submit} disabled={form.processing}>
                            {form.processing ? 'Logging…' : 'Log job'}
                        </Button>
                    ) : (
                        <Button onClick={wizard.next}>Continue</Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={Wrench}
                        title="Log repair / service"
                        blurb="Capture vendor, cost and timing — builds the maintenance history."
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field label="Type">
                            <Segmented
                                value={form.data.type}
                                onChange={(v) => form.setData('type', v)}
                                options={[
                                    { value: 'service', label: 'Service' },
                                    { value: 'repair', label: 'Repair' },
                                    { value: 'cleaning', label: 'Cleaning' },
                                ]}
                            />
                        </Field>
                        <Field label="Vendor">
                            <Input
                                value={form.data.vendor}
                                onChange={(e) => form.setData('vendor', e.target.value)}
                                placeholder="iFix Repairs"
                            />
                        </Field>
                        <Field label="Cost (NZD)">
                            <Input
                                type="number"
                                min="0"
                                step="0.01"
                                value={form.data.cost}
                                onChange={(e) => form.setData('cost', e.target.value)}
                                placeholder="240"
                            />
                        </Field>
                        <Field label="Sent date">
                            <Input
                                type="date"
                                value={form.data.sent_at}
                                onChange={(e) => form.setData('sent_at', e.target.value)}
                            />
                        </Field>
                        <Field label="Expected back">
                            <Input
                                type="date"
                                value={form.data.expected_back_at}
                                onChange={(e) => form.setData('expected_back_at', e.target.value)}
                            />
                        </Field>
                        <Field label="Next service due">
                            <Input
                                type="date"
                                value={form.data.next_due_at}
                                onChange={(e) => form.setData('next_due_at', e.target.value)}
                            />
                        </Field>
                    </div>
                    <div className="mt-3.5">
                        <Field label="Notes">
                            <Textarea
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                placeholder="Screen replacement, battery health 78%…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Confirm the job"
                        blurb="Check the details, then log."
                    />
                    <ReviewCard icon={Wrench} title="Repair job" onEdit={() => wizard.goTo(0)} span>
                        <ReviewRow label="Asset" value={`${asset.name} · ${asset.tag}`} />
                        <ReviewRow label="Type" value={form.data.type} />
                        <ReviewRow label="Vendor" value={form.data.vendor || undefined} />
                        <ReviewRow label="Cost" value={form.data.cost ? nzd(Number(form.data.cost)) : undefined} />
                        <ReviewRow
                            label="Expected back"
                            value={form.data.expected_back_at ? fdate(form.data.expected_back_at) : undefined}
                        />
                    </ReviewCard>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Return to service                                                 */
/* ================================================================== */

const RFS_STEPS: readonly WizardStep[] = [
    { key: 'out', label: 'Return to service', blurb: 'Outcome & cost', icon: CheckCircle2 },
];

function ReturnToServiceWizard({ asset, onClose }: { asset: AssetRef; onClose: () => void }) {
    const wizard = useWizard(RFS_STEPS.length);
    const [done, setDone] = useState(false);
    const form = useForm({
        outcome: 'repaired',
        cost: '',
        condition: 'good',
        next_due_at: '',
        notes: '',
    });

    const submit = () => {
        form.post(`/hr/assets/${asset.id}/return-to-service`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
            },
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Return to service"
            description={`Close the repair and return ${asset.name ?? 'the asset'} to the pool.`}
            railIcon={CheckCircle2}
            railTitle="Return to service"
            railSub="Lifecycle action"
            steps={RFS_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            success={
                done ? (
                    <WizardSuccessPane
                        title="Back in service"
                        blurb="The asset is available again and the maintenance log is closed."
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        {form.processing ? 'Saving…' : 'Return to service'}
                    </Button>
                </>
            }
        >
            <WizardStepPane>
                <StepHead
                    icon={CheckCircle2}
                    title="Return to service"
                    blurb={`Close the job and put ${asset.name ?? 'the asset'} back in the pool.`}
                />
                <div className="grid gap-3.5 sm:grid-cols-2">
                    <Field label="Outcome">
                        <Segmented
                            value={form.data.outcome}
                            onChange={(v) => form.setData('outcome', v)}
                            options={[
                                { value: 'repaired', label: 'Repaired' },
                                { value: 'replaced', label: 'Replaced' },
                                { value: 'no-fault', label: 'No fault' },
                            ]}
                        />
                    </Field>
                    <Field label="Final cost (NZD)">
                        <Input
                            type="number"
                            min="0"
                            step="0.01"
                            value={form.data.cost}
                            onChange={(e) => form.setData('cost', e.target.value)}
                            placeholder="240"
                        />
                    </Field>
                    <Field label="Condition">
                        <Segmented
                            value={form.data.condition}
                            onChange={(v) => form.setData('condition', v)}
                            options={COND_OPTS}
                        />
                    </Field>
                    <Field label="Next service due">
                        <Input
                            type="date"
                            value={form.data.next_due_at}
                            onChange={(e) => form.setData('next_due_at', e.target.value)}
                        />
                    </Field>
                </div>
                <div className="mt-4 flex items-start gap-3 rounded-xl border border-status-success/35 bg-status-success-bg p-4">
                    <CheckCircle2 className="mt-0.5 h-5 w-5 flex-none text-status-success" />
                    <div className="text-[12.5px]">
                        Status returns to <strong>Available</strong> and the maintenance log is
                        closed.
                    </div>
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}

/* ================================================================== */
/*  Retire / dispose                                                  */
/* ================================================================== */

const RETIRE_STEPS: readonly WizardStep[] = [
    { key: 'why', label: 'Retire / dispose', blurb: 'Reason & evidence', icon: Trash2 },
    { key: 'review', label: 'Review', blurb: 'Confirm', icon: CheckCircle2 },
];

function RetireWizard({ asset, onClose }: { asset: AssetRef; onClose: () => void }) {
    const wizard = useWizard(RETIRE_STEPS.length);
    const [done, setDone] = useState(false);
    const form = useForm({
        disposal_reason: 'end-of-life',
        disposed_at: today(),
        disposal_value: '',
        notes: '',
    });

    const submit = () => {
        form.post(`/hr/assets/${asset.id}/retire`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
            },
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Retire asset"
            description={`Remove ${asset.name ?? 'this asset'} from the active register.`}
            railIcon={Trash2}
            railTitle="Retire asset"
            railSub="Lifecycle action"
            steps={RETIRE_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title="Asset retired"
                        blurb="The asset has been removed from active inventory and kept on record."
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button variant="destructive" onClick={submit} disabled={form.processing}>
                            {form.processing ? 'Retiring…' : 'Retire'}
                        </Button>
                    ) : (
                        <Button onClick={wizard.next}>Continue</Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={Trash2}
                        title="Retire / dispose"
                        blurb="Record why this asset is leaving the register and keep the evidence."
                    />
                    <Field label="Reason" required>
                        <TilePicker
                            value={form.data.disposal_reason}
                            onChange={(v) => form.setData('disposal_reason', v)}
                            cols={3}
                            options={[
                                { key: 'end-of-life', label: 'End of life' },
                                { key: 'lost', label: 'Lost' },
                                { key: 'stolen', label: 'Stolen' },
                                { key: 'sold', label: 'Sold' },
                                { key: 'damaged', label: 'Damaged' },
                            ]}
                        />
                    </Field>
                    <div className="mt-4 grid gap-3.5 sm:grid-cols-2">
                        <Field label="Date" required>
                            <Input
                                type="date"
                                value={form.data.disposed_at}
                                onChange={(e) => form.setData('disposed_at', e.target.value)}
                            />
                        </Field>
                        <Field label="Disposal value (NZD)">
                            <Input
                                type="number"
                                min="0"
                                step="0.01"
                                value={form.data.disposal_value}
                                onChange={(e) => form.setData('disposal_value', e.target.value)}
                                placeholder="0"
                            />
                        </Field>
                    </div>
                    <div className="mt-3.5">
                        <Field label="Write-off note">
                            <Textarea
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                placeholder="Approved by site manager…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Confirm retirement"
                        blurb="Check the details, then confirm."
                    />
                    <ReviewCard icon={Trash2} title="Retire / dispose" onEdit={() => wizard.goTo(0)} span>
                        <ReviewRow label="Asset" value={`${asset.name} · ${asset.tag}`} />
                        <ReviewRow label="Reason" value={form.data.disposal_reason} />
                        <ReviewRow label="Date" value={fdate(form.data.disposed_at)} />
                        <ReviewRow
                            label="Disposal value"
                            value={form.data.disposal_value ? nzd(Number(form.data.disposal_value)) : undefined}
                        />
                    </ReviewCard>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}
