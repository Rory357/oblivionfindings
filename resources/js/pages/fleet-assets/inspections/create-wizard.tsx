/* Vehicle-inspection create wizard — Add-Client-modal pattern (WizardShell)
 * replacing the old full-page create form. Three steps: Vehicle & type →
 * Checklist → Details & review. Preserves every field/behaviour of the old
 * page (pre/post-trip types, 3-section checklist, post-trip extras and the
 * pre-trip comparison panel when arriving from a booking). The old page's
 * photo picker was dropped: the store endpoint never accepted photos, so the
 * control silently discarded files. POSTs to the existing store route; the
 * controller redirects to the new inspection's detail page. */
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Car,
    CheckCircle,
    ClipboardCheck,
    FileCheck,
    Loader2,
    MinusCircle,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

export type WizardVehicle = {
    id: number;
    name: string;
    registration_number?: string | null;
};

export type WizardPreTripResult = {
    id: number;
    passed: boolean;
    odometer: number | null;
    overall_condition: string | null;
    completed_at: string | null;
    checklist: Record<string, { result: string; notes: string }>;
};

const STEPS: readonly WizardStep[] = [
    { key: 'vehicle', label: 'Vehicle & type', blurb: 'Which vehicle, which check', icon: Car },
    { key: 'checklist', label: 'Checklist', blurb: 'Exterior, interior, under bonnet', icon: ClipboardCheck },
    { key: 'review', label: 'Details & review', blurb: 'Notes, extras and confirm', icon: FileCheck },
];

// Standard vehicle inspection checklist (identical to the retired create page)
const CHECKLIST_SECTIONS = [
    {
        section: 'Exterior',
        color: 'bg-status-info',
        items: [
            { key: 'tyres_condition', label: 'Tyres - Condition & Pressure' },
            { key: 'lights_front', label: 'Lights - Front (headlights, indicators)' },
            { key: 'lights_rear', label: 'Lights - Rear (tail, brake, indicators)' },
            { key: 'body_damage', label: 'Body Damage' },
            { key: 'windscreen', label: 'Windscreen (chips, cracks)' },
            { key: 'mirrors', label: 'Mirrors (side & rear-view)' },
            { key: 'number_plates', label: 'Number Plates (visible & legible)' },
        ],
    },
    {
        section: 'Interior',
        color: 'bg-primary',
        items: [
            { key: 'seatbelts', label: 'Seatbelts (functional)' },
            { key: 'horn', label: 'Horn' },
            { key: 'wipers', label: 'Wipers (blades & washers)' },
            { key: 'dashboard_warnings', label: 'Dashboard Warning Lights' },
            { key: 'cleanliness', label: 'Cleanliness' },
            { key: 'first_aid_kit', label: 'First Aid Kit' },
        ],
    },
    {
        section: 'Under Bonnet',
        color: 'bg-status-warning',
        items: [
            { key: 'oil_level', label: 'Oil Level' },
            { key: 'coolant', label: 'Coolant Level' },
            { key: 'brake_fluid', label: 'Brake Fluid' },
            { key: 'battery', label: 'Battery Condition' },
        ],
    },
];

type ChecklistResult = 'pass' | 'fail' | 'na';

type FormData = {
    asset_id: string;
    inspection_type: string;
    odometer: string;
    overall_condition: string;
    notes: string;
    checklist: Record<string, { result: ChecklistResult; notes: string }>;
    booking_id: string;
    fuel_level_return: string;
    items_left: string;
    new_damage: string;
};

function allItemKeys(): string[] {
    return CHECKLIST_SECTIONS.flatMap((s) => s.items.map((i) => i.key));
}

function buildInitialChecklist(): Record<string, { result: ChecklistResult; notes: string }> {
    const obj: Record<string, { result: ChecklistResult; notes: string }> = {};
    for (const key of allItemKeys()) {
        obj[key] = { result: 'pass', notes: '' };
    }
    return obj;
}

const NONE = '__none__'; // Radix Select crashes on value="" — sentinel for "no selection".

export function InspectionCreateWizard({
    open,
    onClose,
    vehicles,
    preselectedAssetId,
    preselectedType,
    bookingId,
    preTripResults,
}: {
    open: boolean;
    onClose: () => void;
    vehicles: WizardVehicle[];
    preselectedAssetId?: number | string | null;
    preselectedType?: string;
    bookingId?: number | string | null;
    preTripResults?: WizardPreTripResult | null;
}) {
    const [stepIndex, setStepIndex] = useState(0);

    const form = useForm<FormData>({
        asset_id: preselectedAssetId ? String(preselectedAssetId) : '',
        inspection_type: preselectedType ?? 'pre-trip',
        odometer: '',
        overall_condition: 'good',
        notes: '',
        checklist: buildInitialChecklist(),
        booking_id: bookingId ? String(bookingId) : '',
        fuel_level_return: '',
        items_left: '',
        new_damage: '',
    });

    const isPostTrip = form.data.inspection_type === 'post-trip';
    const hasAnyFail = Object.values(form.data.checklist).some((v) => v.result === 'fail');
    const selectedVehicle = vehicles.find((v) => String(v.id) === form.data.asset_id) ?? null;
    const stepOneValid = form.data.asset_id !== '';

    const setChecklistItem = (key: string, field: 'result' | 'notes', value: string) => {
        const updated = { ...form.data.checklist };
        updated[key] = { ...updated[key], [field]: value };
        form.setData('checklist', updated);
    };

    const close = () => {
        setStepIndex(0);
        onClose();
    };

    const submit = () => {
        form.post('/fleet-assets/inspections', {
            // Store redirects to the new inspection's show page on success; on
            // validation failure, jump back to the step that owns the first error.
            onError: (errors) => {
                if (errors.asset_id || errors.inspection_type || errors.odometer || errors.overall_condition) {
                    setStepIndex(0);
                } else if (Object.keys(errors).some((k) => k.startsWith('checklist'))) {
                    setStepIndex(1);
                } else {
                    setStepIndex(2);
                }
            },
        });
    };

    const resultBadge = hasAnyFail ? (
        <Badge variant="destructive" className="text-xs">
            <XCircle className="mr-1 h-3.5 w-3.5" /> Issues found
        </Badge>
    ) : (
        <Badge variant="default" className="bg-status-success text-xs">
            <CheckCircle className="mr-1 h-3.5 w-3.5" /> All clear
        </Badge>
    );

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="New vehicle inspection"
            description="Record a pre-trip or post-trip vehicle inspection."
            railIcon={ClipboardCheck}
            railTitle="New inspection"
            railSub="Vehicle checks"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={(i) => {
                if (i < stepIndex || stepOneValid) setStepIndex(i);
            }}
            footerStart={
                stepIndex > 0 ? (
                    <Button variant="ghost" onClick={() => setStepIndex(stepIndex - 1)}>
                        <ArrowLeft className="mr-1.5 h-4 w-4" /> Back
                    </Button>
                ) : (
                    <Button variant="ghost" onClick={close}>
                        Cancel
                    </Button>
                )
            }
            footerEnd={
                <>
                    {stepIndex > 0 ? resultBadge : null}
                    {stepIndex < STEPS.length - 1 ? (
                        <Button onClick={() => setStepIndex(stepIndex + 1)} disabled={!stepOneValid}>
                            Continue <ArrowRight className="ml-1.5 h-4 w-4" />
                        </Button>
                    ) : (
                        <Button onClick={submit} disabled={form.processing || !stepOneValid}>
                            {form.processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                            Submit inspection
                        </Button>
                    )}
                </>
            }
        >
            {stepIndex === 0 && (
                <WizardStepPane>
                    <div className="space-y-5">
                        <div>
                            <Label className="mb-1.5 block">Vehicle *</Label>
                            <Select
                                value={form.data.asset_id === '' ? NONE : form.data.asset_id}
                                onValueChange={(v) => form.setData('asset_id', v === NONE ? '' : v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select vehicle" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>Select vehicle</SelectItem>
                                    {vehicles.map((v) => (
                                        <SelectItem key={v.id} value={String(v.id)}>
                                            {v.name}
                                            {v.registration_number ? ` (${v.registration_number})` : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {form.errors.asset_id && <p className="mt-1 text-xs text-destructive">{form.errors.asset_id}</p>}
                        </div>

                        <div>
                            <Label className="mb-1.5 block">Inspection type *</Label>
                            <div className="grid grid-cols-2 gap-3">
                                {[
                                    { value: 'pre-trip', label: 'Pre-Trip', blurb: 'Before heading out' },
                                    { value: 'post-trip', label: 'Post-Trip', blurb: 'On return' },
                                ].map((opt) => {
                                    const active = form.data.inspection_type === opt.value;
                                    return (
                                        // eslint-disable-next-line no-restricted-syntax -- Send-Kudos-style type tile picker, not a shadcn Button.
                                        <button
                                            key={opt.value}
                                            type="button"
                                            onClick={() => form.setData('inspection_type', opt.value)}
                                            className={cn(
                                                'rounded-xl border-2 px-4 py-4 text-left transition-all',
                                                active
                                                    ? 'border-primary bg-primary/10 shadow-md'
                                                    : 'border-transparent bg-muted hover:bg-muted/80',
                                            )}
                                        >
                                            <span className={cn('block text-sm font-bold', active ? 'text-primary' : 'text-foreground')}>
                                                {opt.label}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">{opt.blurb}</span>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label className="mb-1.5 block">Odometer reading (km)</Label>
                                <Input
                                    type="number"
                                    min="0"
                                    value={form.data.odometer}
                                    onChange={(e) => form.setData('odometer', e.target.value)}
                                    placeholder="Current km"
                                />
                                {form.errors.odometer && <p className="mt-1 text-xs text-destructive">{form.errors.odometer}</p>}
                            </div>
                            <div>
                                <Label className="mb-1.5 block">Overall condition *</Label>
                                <Select
                                    value={form.data.overall_condition}
                                    onValueChange={(v) => form.setData('overall_condition', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="good">Good</SelectItem>
                                        <SelectItem value="fair">Fair</SelectItem>
                                        <SelectItem value="poor">Poor</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </div>
                </WizardStepPane>
            )}

            {stepIndex === 1 && (
                <WizardStepPane>
                    <div className="space-y-5">
                        {CHECKLIST_SECTIONS.map((section) => {
                            const sectionPassCount = section.items.filter(
                                (item) => form.data.checklist[item.key]?.result === 'pass',
                            ).length;
                            return (
                                <div key={section.section} className="overflow-hidden rounded-xl border border-border">
                                    <div className={cn('flex items-center justify-between px-4 py-2.5 text-primary-foreground', section.color)}>
                                        <span className="text-sm font-semibold">{section.section}</span>
                                        <span className="text-xs opacity-90">
                                            {sectionPassCount}/{section.items.length} passed
                                        </span>
                                    </div>
                                    <div className="space-y-3 p-3">
                                        {section.items.map((item) => {
                                            const val = form.data.checklist[item.key];
                                            return (
                                                <div key={item.key} className="rounded-lg border border-border p-3">
                                                    <span className="text-sm font-medium">{item.label}</span>
                                                    <div className="mt-2 grid grid-cols-3 gap-2">
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => setChecklistItem(item.key, 'result', 'pass')}
                                                            className={cn(
                                                                'rounded-lg border-2 transition-all',
                                                                val?.result === 'pass'
                                                                    ? 'border-primary bg-primary/10 text-primary'
                                                                    : 'border-transparent bg-muted hover:border-primary',
                                                            )}
                                                        >
                                                            <CheckCircle className="mr-1 h-3.5 w-3.5" /> Pass
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => setChecklistItem(item.key, 'result', 'fail')}
                                                            className={cn(
                                                                'rounded-lg border-2 transition-all',
                                                                val?.result === 'fail'
                                                                    ? 'border-status-critical/30 bg-status-critical-bg text-status-critical'
                                                                    : 'border-transparent bg-muted hover:border-status-critical/30',
                                                            )}
                                                        >
                                                            <XCircle className="mr-1 h-3.5 w-3.5" /> Fail
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => setChecklistItem(item.key, 'result', 'na')}
                                                            className={cn(
                                                                'rounded-lg border-2 transition-all',
                                                                val?.result === 'na'
                                                                    ? 'border-border bg-muted text-foreground'
                                                                    : 'border-transparent bg-muted hover:border-border',
                                                            )}
                                                        >
                                                            <MinusCircle className="mr-1 h-3.5 w-3.5" /> N/A
                                                        </Button>
                                                    </div>
                                                    <Input
                                                        value={val?.notes ?? ''}
                                                        onChange={(e) => setChecklistItem(item.key, 'notes', e.target.value)}
                                                        placeholder="Notes (optional)"
                                                        className="mt-2 h-8 text-xs"
                                                    />
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </WizardStepPane>
            )}

            {stepIndex === 2 && (
                <WizardStepPane>
                    <div className="space-y-5">
                        {isPostTrip && preTripResults && (
                            <div className="rounded-md border border-status-info/30 bg-status-info-bg p-3">
                                <div className="mb-1 text-sm font-medium text-status-info">Pre-trip comparison</div>
                                <div className="grid gap-2 text-xs sm:grid-cols-3">
                                    <div>
                                        <span className="text-muted-foreground">Pre-trip odometer:</span>{' '}
                                        <span className="font-medium">{preTripResults.odometer ?? '—'} km</span>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Pre-trip condition:</span>{' '}
                                        <span className="font-medium capitalize">{preTripResults.overall_condition ?? '—'}</span>
                                    </div>
                                    <div>
                                        <span className="text-muted-foreground">Pre-trip result:</span>{' '}
                                        <span
                                            className={cn(
                                                'font-medium',
                                                preTripResults.passed ? 'text-status-success' : 'text-status-critical',
                                            )}
                                        >
                                            {preTripResults.passed ? 'All clear' : 'Issues found'}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        )}

                        {isPostTrip && (
                            <div className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label className="mb-1.5 block">Fuel level on return</Label>
                                        <Select
                                            value={form.data.fuel_level_return === '' ? NONE : form.data.fuel_level_return}
                                            onValueChange={(v) => form.setData('fuel_level_return', v === NONE ? '' : v)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select fuel level" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={NONE}>Not recorded</SelectItem>
                                                <SelectItem value="full">Full</SelectItem>
                                                <SelectItem value="3/4">3/4</SelectItem>
                                                <SelectItem value="1/2">1/2</SelectItem>
                                                <SelectItem value="1/4">1/4</SelectItem>
                                                <SelectItem value="empty">Empty</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                                <div>
                                    <Label className="mb-1.5 block">Any new damage?</Label>
                                    <textarea
                                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        rows={2}
                                        value={form.data.new_damage}
                                        onChange={(e) => form.setData('new_damage', e.target.value)}
                                        placeholder="Describe any new damage noticed during/after the trip..."
                                    />
                                </div>
                                <div>
                                    <Label className="mb-1.5 block">Items left in vehicle</Label>
                                    <textarea
                                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        rows={2}
                                        value={form.data.items_left}
                                        onChange={(e) => form.setData('items_left', e.target.value)}
                                        placeholder="List any personal items or equipment left in the vehicle..."
                                    />
                                </div>
                            </div>
                        )}

                        <div>
                            <Label className="mb-1.5 block">Notes / comments</Label>
                            <textarea
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                placeholder="Any additional observations, concerns, or comments..."
                            />
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewCard icon={Car} title="Vehicle & type" onEdit={() => setStepIndex(0)}>
                                <ReviewRow
                                    label="Vehicle"
                                    value={
                                        selectedVehicle
                                            ? `${selectedVehicle.name}${selectedVehicle.registration_number ? ` (${selectedVehicle.registration_number})` : ''}`
                                            : undefined
                                    }
                                />
                                <ReviewRow label="Type" value={isPostTrip ? 'Post-Trip' : 'Pre-Trip'} />
                                <ReviewRow label="Odometer" value={form.data.odometer ? `${form.data.odometer} km` : undefined} />
                                <ReviewRow label="Condition" value={form.data.overall_condition} />
                            </ReviewCard>
                            <ReviewCard icon={ClipboardCheck} title="Checklist" onEdit={() => setStepIndex(1)}>
                                <ReviewRow
                                    label="Result"
                                    value={
                                        <span className="inline-flex items-center gap-1.5">
                                            {hasAnyFail ? (
                                                <XCircle className="h-3.5 w-3.5 text-status-critical" />
                                            ) : (
                                                <CheckCircle className="h-3.5 w-3.5 text-status-success" />
                                            )}
                                            {hasAnyFail ? 'Issues found' : 'All clear'}
                                        </span>
                                    }
                                />
                                <ReviewRow
                                    label="Failed items"
                                    value={String(Object.values(form.data.checklist).filter((v) => v.result === 'fail').length)}
                                />
                                <ReviewRow
                                    label="N/A items"
                                    value={String(Object.values(form.data.checklist).filter((v) => v.result === 'na').length)}
                                />
                            </ReviewCard>
                        </div>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}
