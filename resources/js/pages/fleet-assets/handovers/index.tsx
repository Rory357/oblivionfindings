import PageShell from '@/components/page-shell';
import { FleetResponsiveTable } from '@/pages/fleet-assets/components/fleet-responsive-list';
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
import { Switch } from '@/components/ui/switch';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/fleet-utils';
import { cn } from '@/lib/utils';
import {
    FleetHeroAction,
    fmt,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Car,
    Check,
    ClipboardCheck,
    Eye,
    FileText,
    Flame,
    Fuel,
    Key,
    Plus,
    Save,
    ShieldPlus,
    Trash2,
    XCircle,
} from 'lucide-react';
import { useEffect, useState } from 'react';

type Handover = {
    id: number;
    asset: { id: number; name: string; registration_number?: string | null } | null;
    outgoing_user: { id: number; name: string } | null;
    incoming_user: { id: number; name: string } | null;
    odometer_km: number | null;
    fuel_level: string | null;
    exterior_condition: string;
    interior_condition: string;
    status: string;
    handed_over_at: string | null;
    accepted_at: string | null;
};

type Vehicle = { id: number; name: string };

type WizardVehicle = {
    id: number;
    name: string;
    registration_number?: string | null;
};

type UserOption = { id: number; name: string };

type WizardPayload = {
    vehicles: WizardVehicle[];
    users: UserOption[];
    current_user_id: number;
};

type PaginatedHandovers = {
    data: Handover[];
    links?: Array<{ url: string | null; label: string; active: boolean }>;
    meta?: { current_page: number; last_page: number; total: number };
};

type Props = {
    handovers: Handover[] | PaginatedHandovers;
    vehicles: Vehicle[];
    filters: {
        vehicle_id?: string;
        status?: string;
        date_from?: string;
        date_to?: string;
    };
    stats?: {
        total: number;
        pending: number;
        disputed: number;
        completed_7d: number;
    };
    wizard?: WizardPayload | null;
    can: {
        manage: boolean;
    };
};

function conditionBadge(condition: string) {
    switch (condition) {
        case 'good':
        case 'clean':
            return (
                <Badge variant="default" className="bg-status-success">
                    {condition.replace(/_/g, ' ')}
                </Badge>
            );
        case 'minor_damage':
        case 'acceptable':
            return (
                <Badge variant="default" className="bg-status-warning">
                    {condition.replace(/_/g, ' ')}
                </Badge>
            );
        case 'significant_damage':
        case 'needs_cleaning':
            return <Badge variant="destructive">{condition.replace(/_/g, ' ')}</Badge>;
        default:
            return <Badge variant="outline">{condition.replace(/_/g, ' ')}</Badge>;
    }
}

function statusBadge(status: string) {
    switch (status) {
        case 'accepted':
            return (
                <Badge variant="default" className="bg-status-success">
                    <Check className="mr-1 h-3 w-3" />
                    Accepted
                </Badge>
            );
        case 'disputed':
            return (
                <Badge variant="destructive">
                    <XCircle className="mr-1 h-3 w-3" />
                    Disputed
                </Badge>
            );
        case 'pending_acceptance':
            return <Badge variant="outline">Pending</Badge>;
        default:
            return <Badge variant="outline">{status.replace(/_/g, ' ')}</Badge>;
    }
}

function fuelLabel(level: string | null) {
    if (!level) return '---';
    const labels: Record<string, string> = {
        full: 'Full',
        '3/4': '3/4',
        '1/2': '1/2',
        '1/4': '1/4',
        empty: 'Empty',
    };
    return labels[level] ?? level;
}

/* ------------------------------------------------------------------ */
/*  New-handover wizard (retired /handovers/create page)               */
/* ------------------------------------------------------------------ */

const FUEL_LEVELS = [
    { value: 'full', label: 'Full', pct: 100 },
    { value: '3/4', label: '3/4', pct: 75 },
    { value: '1/2', label: '1/2', pct: 50 },
    { value: '1/4', label: '1/4', pct: 25 },
    { value: 'empty', label: 'Empty', pct: 0 },
];

const DAMAGE_AREAS = [
    'Front bumper',
    'Rear bumper',
    'Left side',
    'Right side',
    'Roof',
    'Bonnet',
    'Boot/Tailgate',
    'Windscreen',
    'Left mirror',
    'Right mirror',
    'Wheels/Tyres',
    'Interior dashboard',
    'Interior seats',
    'Other',
];

const WIZARD_STEPS: readonly WizardStep[] = [
    { key: 'details', label: 'Details', blurb: 'Vehicle, staff & odometer', icon: Car },
    { key: 'condition', label: 'Fuel & condition', blurb: 'Fuel, exterior, interior', icon: Fuel },
    { key: 'checklist', label: 'Checklist & damage', blurb: 'Items present, damage notes', icon: ClipboardCheck },
    { key: 'review', label: 'Notes & review', blurb: 'Final check before submit', icon: FileText },
];

type DamageNote = {
    area: string;
    description: string;
};

type FormData = {
    asset_id: string;
    incoming_user_id: string;
    odometer_km: string;
    fuel_level: string;
    exterior_condition: string;
    interior_condition: string;
    keys_present: boolean;
    documents_present: boolean;
    first_aid_kit: boolean;
    fire_extinguisher: boolean;
    damage_notes: DamageNote[];
    notes: string;
};

const ERROR_STEP: Record<string, number> = {
    asset_id: 0,
    incoming_user_id: 0,
    odometer_km: 0,
    fuel_level: 1,
    exterior_condition: 1,
    interior_condition: 1,
    keys_present: 2,
    documents_present: 2,
    first_aid_kit: 2,
    fire_extinguisher: 2,
    damage_notes: 2,
    notes: 3,
};

function HandoverWizard({
    payload,
    open,
    onClose,
}: {
    payload: WizardPayload;
    open: boolean;
    onClose: () => void;
}) {
    const [stepIndex, setStepIndex] = useState(0);

    const form = useForm<FormData>({
        asset_id: '',
        incoming_user_id: '',
        odometer_km: '',
        fuel_level: 'full',
        exterior_condition: 'good',
        interior_condition: 'clean',
        keys_present: true,
        documents_present: true,
        first_aid_kit: true,
        fire_extinguisher: true,
        damage_notes: [],
        notes: '',
    });

    const vehicles = payload.vehicles ?? [];
    const users = payload.users ?? [];
    const currentUserName =
        users.find((u) => u.id === payload.current_user_id)?.name ?? 'Current User';
    const selectedVehicle = vehicles.find((v) => String(v.id) === form.data.asset_id);
    const incomingUser = users.find((u) => String(u.id) === form.data.incoming_user_id);

    const addDamageNote = () => {
        form.setData('damage_notes', [
            ...form.data.damage_notes,
            { area: '', description: '' },
        ]);
    };

    const updateDamageNote = (index: number, field: keyof DamageNote, value: string) => {
        const updated = [...form.data.damage_notes];
        updated[index] = { ...updated[index], [field]: value };
        form.setData('damage_notes', updated);
    };

    const removeDamageNote = (index: number) => {
        form.setData(
            'damage_notes',
            form.data.damage_notes.filter((_, i) => i !== index),
        );
    };

    const submit = () => {
        form.post('/fleet-assets/handovers', {
            preserveScroll: true,
            onError: (errors) => {
                const firstKey = Object.keys(errors)[0]?.split('.')[0];
                if (firstKey != null && ERROR_STEP[firstKey] != null) {
                    setStepIndex(ERROR_STEP[firstKey]);
                }
            },
        });
    };

    const canContinue = stepIndex === 0 ? !!form.data.asset_id : true;

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title="New shift handover"
            description="Record a vehicle shift handover with condition, checklist and damage notes."
            railIcon={ArrowLeftRight}
            railTitle="Shift handover"
            railSub="Vehicle changeover"
            steps={WIZARD_STEPS}
            stepIndex={stepIndex}
            onStepClick={(i) => setStepIndex(i)}
            footerStart={
                stepIndex > 0 ? (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => setStepIndex((i) => Math.max(0, i - 1))}
                    >
                        Back
                    </Button>
                ) : undefined
            }
            footerEnd={
                stepIndex < WIZARD_STEPS.length - 1 ? (
                    <Button
                        type="button"
                        onClick={() =>
                            setStepIndex((i) => Math.min(WIZARD_STEPS.length - 1, i + 1))
                        }
                        disabled={!canContinue}
                    >
                        Continue
                    </Button>
                ) : (
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={form.processing || !form.data.asset_id}
                    >
                        <Save className="mr-2 h-4 w-4" />
                        Submit Handover
                    </Button>
                )
            }
        >
            {stepIndex === 0 && (
                <WizardStepPane>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <Label>Vehicle *</Label>
                            <Select
                                value={form.data.asset_id}
                                onValueChange={(v) => form.setData('asset_id', v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select vehicle" />
                                </SelectTrigger>
                                <SelectContent>
                                    {vehicles.map((v) => (
                                        <SelectItem key={v.id} value={String(v.id)}>
                                            {v.name}
                                            {v.registration_number
                                                ? ` (${v.registration_number})`
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {form.errors.asset_id && (
                                <p className="mt-1 text-xs text-destructive">
                                    {form.errors.asset_id}
                                </p>
                            )}
                        </div>
                        <div>
                            <Label>Outgoing Staff</Label>
                            <Input value={currentUserName} disabled className="bg-muted" />
                            <p className="mt-1 text-xs text-muted-foreground">
                                Auto-filled with current user
                            </p>
                        </div>
                        <div>
                            <Label>Incoming Staff</Label>
                            <Select
                                value={form.data.incoming_user_id}
                                onValueChange={(v) => form.setData('incoming_user_id', v)}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select incoming staff" />
                                </SelectTrigger>
                                <SelectContent>
                                    {users
                                        .filter((u) => u.id !== payload.current_user_id)
                                        .map((u) => (
                                            <SelectItem key={u.id} value={String(u.id)}>
                                                {u.name}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>
                            {form.errors.incoming_user_id && (
                                <p className="mt-1 text-xs text-destructive">
                                    {form.errors.incoming_user_id}
                                </p>
                            )}
                        </div>
                        <div>
                            <Label>Odometer Reading (km)</Label>
                            <Input
                                type="number"
                                value={form.data.odometer_km}
                                onChange={(e) => form.setData('odometer_km', e.target.value)}
                                placeholder="Current km"
                            />
                            {form.errors.odometer_km && (
                                <p className="mt-1 text-xs text-destructive">
                                    {form.errors.odometer_km}
                                </p>
                            )}
                        </div>
                    </div>
                </WizardStepPane>
            )}

            {stepIndex === 1 && (
                <WizardStepPane>
                    <div className="space-y-6">
                        {/* Fuel level */}
                        <div>
                            <Label className="mb-3 flex items-center gap-2">
                                <Fuel className="h-4 w-4" />
                                Fuel Level
                            </Label>
                            <div className="grid grid-cols-5 gap-3">
                                {FUEL_LEVELS.map((level) => (
                                    <Button
                                        key={level.value}
                                        type="button"
                                        variant="outline"
                                        onClick={() => form.setData('fuel_level', level.value)}
                                        className={cn(
                                            'h-auto flex-col rounded-xl border-2 px-3 py-4 whitespace-normal transition-all',
                                            form.data.fuel_level === level.value
                                                ? 'border-primary bg-primary/10 shadow-md dark:border-primary dark:bg-primary/20'
                                                : 'border-transparent bg-muted hover:border-muted-foreground/20 hover:bg-muted/80',
                                        )}
                                    >
                                        <div className="relative mb-2 h-12 w-8 overflow-hidden rounded-lg border-2 border-current">
                                            <div
                                                className={cn(
                                                    'absolute bottom-0 w-full transition-all duration-300',
                                                    level.pct > 50
                                                        ? 'bg-status-success'
                                                        : level.pct > 25
                                                          ? 'bg-status-warning'
                                                          : 'bg-status-critical',
                                                )}
                                                style={{ height: `${level.pct}%` }}
                                            />
                                            <div className="absolute -top-1.5 left-1/2 h-2 w-4 -translate-x-1/2 rounded-t border-2 border-current bg-background" />
                                        </div>
                                        <span className="text-sm font-bold">{level.label}</span>
                                        <span className="text-[10px] text-muted-foreground">
                                            {level.pct}%
                                        </span>
                                    </Button>
                                ))}
                            </div>
                            {form.errors.fuel_level && (
                                <p className="mt-1 text-xs text-destructive">
                                    {form.errors.fuel_level}
                                </p>
                            )}
                        </div>

                        {/* Exterior */}
                        <div>
                            <Label className="mb-3 block">Exterior Condition *</Label>
                            <div className="grid grid-cols-3 gap-3">
                                {[
                                    { value: 'good', label: 'Good', borderColor: 'border-primary', bgColor: 'bg-primary/10 dark:bg-primary/20', textColor: 'text-primary dark:text-primary' },
                                    { value: 'minor_damage', label: 'Minor Damage', borderColor: 'border-status-warning/30', bgColor: 'bg-status-warning-bg', textColor: 'text-status-warning dark:text-status-warning' },
                                    { value: 'significant_damage', label: 'Significant Damage', borderColor: 'border-status-critical/30', bgColor: 'bg-status-critical-bg', textColor: 'text-status-critical dark:text-status-critical' },
                                ].map((opt) => (
                                    <Button
                                        key={opt.value}
                                        type="button"
                                        variant="outline"
                                        onClick={() => form.setData('exterior_condition', opt.value)}
                                        className={cn(
                                            'h-auto flex-col gap-2 rounded-xl border-2 px-4 py-4 whitespace-normal transition-all',
                                            form.data.exterior_condition === opt.value
                                                ? `${opt.borderColor} ${opt.bgColor} ${opt.textColor} shadow-md`
                                                : 'border-transparent bg-muted text-muted-foreground hover:bg-muted/80',
                                        )}
                                    >
                                        {form.data.exterior_condition === opt.value && (
                                            <Check className="h-5 w-5" />
                                        )}
                                        {opt.label}
                                    </Button>
                                ))}
                            </div>
                            {form.errors.exterior_condition && (
                                <p className="mt-1 text-xs text-destructive">
                                    {form.errors.exterior_condition}
                                </p>
                            )}
                        </div>

                        {/* Interior */}
                        <div>
                            <Label className="mb-3 block">Interior Condition *</Label>
                            <div className="grid grid-cols-3 gap-3">
                                {[
                                    { value: 'clean', label: 'Clean', borderColor: 'border-primary', bgColor: 'bg-primary/10 dark:bg-primary/20', textColor: 'text-primary dark:text-primary' },
                                    { value: 'acceptable', label: 'Acceptable', borderColor: 'border-status-warning/30', bgColor: 'bg-status-warning-bg', textColor: 'text-status-warning dark:text-status-warning' },
                                    { value: 'needs_cleaning', label: 'Needs Cleaning', borderColor: 'border-status-critical/30', bgColor: 'bg-status-critical-bg', textColor: 'text-status-critical dark:text-status-critical' },
                                ].map((opt) => (
                                    <Button
                                        key={opt.value}
                                        type="button"
                                        variant="outline"
                                        onClick={() => form.setData('interior_condition', opt.value)}
                                        className={cn(
                                            'h-auto flex-col gap-2 rounded-xl border-2 px-4 py-4 whitespace-normal transition-all',
                                            form.data.interior_condition === opt.value
                                                ? `${opt.borderColor} ${opt.bgColor} ${opt.textColor} shadow-md`
                                                : 'border-transparent bg-muted text-muted-foreground hover:bg-muted/80',
                                        )}
                                    >
                                        {form.data.interior_condition === opt.value && (
                                            <Check className="h-5 w-5" />
                                        )}
                                        {opt.label}
                                    </Button>
                                ))}
                            </div>
                            {form.errors.interior_condition && (
                                <p className="mt-1 text-xs text-destructive">
                                    {form.errors.interior_condition}
                                </p>
                            )}
                        </div>
                    </div>
                </WizardStepPane>
            )}

            {stepIndex === 2 && (
                <WizardStepPane>
                    <div className="space-y-6">
                        {/* Checklist */}
                        <div>
                            <Label className="mb-3 block">Checklist Items</Label>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {[
                                    { key: 'keys_present' as const, label: 'Keys Present', icon: Key },
                                    { key: 'documents_present' as const, label: 'Documents Present', icon: FileText },
                                    { key: 'first_aid_kit' as const, label: 'First Aid Kit', icon: ShieldPlus },
                                    { key: 'fire_extinguisher' as const, label: 'Fire Extinguisher', icon: Flame },
                                ].map((item) => {
                                    const IconComp = item.icon;
                                    return (
                                        <div
                                            key={item.key}
                                            className={cn(
                                                'flex items-center justify-between rounded-xl border-2 p-4 transition-all',
                                                form.data[item.key]
                                                    ? 'border-primary/40 bg-primary/5 dark:border-primary/30 dark:bg-primary/10'
                                                    : 'border-status-critical/30 bg-status-critical-bg dark:border-status-critical/30',
                                            )}
                                        >
                                            <div className="flex items-center gap-3">
                                                <div
                                                    className={cn(
                                                        'flex h-9 w-9 items-center justify-center rounded-lg',
                                                        form.data[item.key]
                                                            ? 'bg-primary/10 text-primary dark:bg-primary/30 dark:text-primary'
                                                            : 'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
                                                    )}
                                                >
                                                    <IconComp className="h-4 w-4" />
                                                </div>
                                                <Label
                                                    htmlFor={item.key}
                                                    className="cursor-pointer font-medium"
                                                >
                                                    {item.label}
                                                </Label>
                                            </div>
                                            <Switch
                                                checked={form.data[item.key]}
                                                onCheckedChange={() =>
                                                    form.setData(item.key, !form.data[item.key])
                                                }
                                            />
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        {/* Damage notes */}
                        <div>
                            <div className="mb-3 flex items-center justify-between">
                                <Label>Damage Notes</Label>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={addDamageNote}
                                >
                                    <Plus className="mr-1 h-3.5 w-3.5" />
                                    Add Damage
                                </Button>
                            </div>
                            {form.data.damage_notes.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No damage noted. Click "Add Damage" to report any damage.
                                </p>
                            ) : (
                                <div className="space-y-3">
                                    {form.data.damage_notes.map((note, index) => (
                                        <div
                                            key={index}
                                            className="flex items-start gap-3 rounded-lg border p-3"
                                        >
                                            <div className="flex-1 space-y-2">
                                                <Select
                                                    value={note.area}
                                                    onValueChange={(v) =>
                                                        updateDamageNote(index, 'area', v)
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Select area" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {DAMAGE_AREAS.map((area) => (
                                                            <SelectItem key={area} value={area}>
                                                                {area}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                <Input
                                                    value={note.description}
                                                    onChange={(e) =>
                                                        updateDamageNote(
                                                            index,
                                                            'description',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="Describe the damage..."
                                                />
                                            </div>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => removeDamageNote(index)}
                                                className="text-destructive hover:text-destructive"
                                            >
                                                <Trash2 className="h-4 w-4" />
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                            )}
                            {form.errors.damage_notes && (
                                <p className="mt-1 text-xs text-destructive">
                                    {form.errors.damage_notes}
                                </p>
                            )}
                        </div>
                    </div>
                </WizardStepPane>
            )}

            {stepIndex === 3 && (
                <WizardStepPane>
                    <div className="space-y-6">
                        <div>
                            <Label className="mb-2 block">General Notes</Label>
                            <textarea
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                rows={4}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                placeholder="Any additional observations or comments about the vehicle..."
                            />
                            {form.errors.notes && (
                                <p className="mt-1 text-xs text-destructive">
                                    {form.errors.notes}
                                </p>
                            )}
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <ReviewCard icon={Car} title="Details" onEdit={() => setStepIndex(0)}>
                                <ReviewRow
                                    label="Vehicle"
                                    value={
                                        selectedVehicle
                                            ? `${selectedVehicle.name}${selectedVehicle.registration_number ? ` (${selectedVehicle.registration_number})` : ''}`
                                            : undefined
                                    }
                                />
                                <ReviewRow label="Outgoing" value={currentUserName} />
                                <ReviewRow label="Incoming" value={incomingUser?.name} />
                                <ReviewRow
                                    label="Odometer"
                                    value={
                                        form.data.odometer_km
                                            ? `${form.data.odometer_km} km`
                                            : undefined
                                    }
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={Fuel}
                                title="Fuel & condition"
                                onEdit={() => setStepIndex(1)}
                            >
                                <ReviewRow label="Fuel" value={fuelLabel(form.data.fuel_level)} />
                                <ReviewRow
                                    label="Exterior"
                                    value={form.data.exterior_condition.replace(/_/g, ' ')}
                                />
                                <ReviewRow
                                    label="Interior"
                                    value={form.data.interior_condition.replace(/_/g, ' ')}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={ClipboardCheck}
                                title="Checklist & damage"
                                onEdit={() => setStepIndex(2)}
                                span
                            >
                                <ReviewRow
                                    label="Keys"
                                    value={form.data.keys_present ? 'Present' : 'Missing'}
                                />
                                <ReviewRow
                                    label="Documents"
                                    value={form.data.documents_present ? 'Present' : 'Missing'}
                                />
                                <ReviewRow
                                    label="First aid kit"
                                    value={form.data.first_aid_kit ? 'Present' : 'Missing'}
                                />
                                <ReviewRow
                                    label="Fire extinguisher"
                                    value={form.data.fire_extinguisher ? 'Present' : 'Missing'}
                                />
                                <ReviewRow
                                    label="Damage notes"
                                    value={
                                        form.data.damage_notes.length > 0
                                            ? `${form.data.damage_notes.length} noted`
                                            : 'None'
                                    }
                                />
                            </ReviewCard>
                        </div>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function HandoverIndex({
    handovers: rawHandovers,
    vehicles,
    filters,
    stats,
    wizard,
    can,
}: Props) {
    const allHandovers = Array.isArray(rawHandovers)
        ? rawHandovers
        : (rawHandovers?.data ?? []);
    const paginationLinks = !Array.isArray(rawHandovers) ? (rawHandovers?.links ?? []) : [];
    const paginationMeta = !Array.isArray(rawHandovers)
        ? (rawHandovers?.meta ?? { current_page: 1, last_page: 1, total: 0 })
        : { current_page: 1, last_page: 1, total: 0 };

    const totalCount = stats?.total ?? allHandovers.length;
    const pendingCount =
        stats?.pending ?? allHandovers.filter((h) => h.status === 'pending_acceptance').length;
    const disputedCount =
        stats?.disputed ?? allHandovers.filter((h) => h.status === 'disputed').length;
    const completed7d = stats?.completed_7d ?? 0;

    const applyFilter = (key: string, value: string) => {
        router.get(
            '/fleet-assets/handovers',
            { ...filters, [key]: value || undefined },
            { preserveState: true },
        );
    };

    /* ── New-handover wizard (?new=1 shim) ── */
    const [wizardOpen, setWizardOpen] = useState(!!wizard);
    useEffect(() => setWizardOpen(!!wizard), [wizard]);
    const closeWizard = () => {
        setWizardOpen(false);
        const params = new URLSearchParams(window.location.search);
        params.delete('new');
        const qs = params.toString();
        router.get(
            `${window.location.pathname}${qs ? `?${qs}` : ''}`,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Shift Handovers', href: '#' },
            ]}
        >
            <Head title="Shift Handovers" />
            <PageShell>
                {/* ── Hero ── */}
                <HeroShell>
                    <div className="flex flex-wrap items-center gap-4">
                        <HeroMedallion icon={ArrowLeftRight} />
                        <div className="min-w-0">
                            <HeroStatusPill>Vehicle changeovers · accountability</HeroStatusPill>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight">
                                Shift Handovers
                            </h1>
                            <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                                Record and accept vehicle condition at every shift change.
                            </p>
                        </div>
                        <div className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-4 lg:ml-auto lg:max-w-2xl">
                            <HeroClusterTile
                                label="Pending acceptance"
                                value={fmt(pendingCount)}
                                caption="awaiting sign-off"
                                tone={pendingCount > 0 ? 'warning' : 'success'}
                            />
                            <HeroClusterTile
                                label="Disputed"
                                value={fmt(disputedCount)}
                                caption="needs review"
                                tone={disputedCount > 0 ? 'critical' : 'success'}
                            />
                            <HeroClusterTile
                                label="Completed 7d"
                                value={fmt(completed7d)}
                                caption="accepted this week"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                label="Total"
                                value={fmt(totalCount)}
                                caption="all records"
                                tone="neutral"
                            />
                        </div>
                    </div>
                    {can.manage && (
                        <div className="flex flex-wrap items-center gap-2">
                            <FleetHeroAction
                                href="/fleet-assets/handovers?new=1"
                                icon={Plus}
                                emphasis
                            >
                                New handover
                            </FleetHeroAction>
                        </div>
                    )}
                </HeroShell>

                {/* Filters */}
                <div className="flex flex-wrap items-end gap-3">
                    <div className="min-w-[160px]">
                        <label className="mb-1 block text-xs font-medium text-muted-foreground">
                            Vehicle
                        </label>
                        <Select
                            value={filters.vehicle_id || '__all__'}
                            onValueChange={(v) =>
                                applyFilter('vehicle_id', v === '__all__' ? '' : v)
                            }
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="All vehicles" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">All vehicles</SelectItem>
                                {(vehicles ?? []).map((v) => (
                                    <SelectItem key={v.id} value={String(v.id)}>
                                        {v.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="min-w-[140px]">
                        <label className="mb-1 block text-xs font-medium text-muted-foreground">
                            Status
                        </label>
                        <Select
                            value={filters.status || '__all__'}
                            onValueChange={(v) =>
                                applyFilter('status', v === '__all__' ? '' : v)
                            }
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">All statuses</SelectItem>
                                <SelectItem value="pending_acceptance">Pending</SelectItem>
                                <SelectItem value="accepted">Accepted</SelectItem>
                                <SelectItem value="disputed">Disputed</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-muted-foreground">
                            From
                        </label>
                        <Input
                            type="date"
                            value={filters.date_from ?? ''}
                            onChange={(e) => applyFilter('date_from', e.target.value)}
                            className="w-[150px]"
                        />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-muted-foreground">
                            To
                        </label>
                        <Input
                            type="date"
                            value={filters.date_to ?? ''}
                            onChange={(e) => applyFilter('date_to', e.target.value)}
                            className="w-[150px]"
                        />
                    </div>
                </div>

                {/* Table with status color coding */}
                <div className="overflow-hidden rounded-lg border">
                    <FleetResponsiveTable>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-xs tracking-wider text-muted-foreground uppercase">
                                <th className="px-3 py-2 text-left font-medium">Date</th>
                                <th className="px-3 py-2 text-left font-medium">Vehicle</th>
                                <th className="px-3 py-2 text-left font-medium">Outgoing Staff</th>
                                <th className="px-3 py-2 text-left font-medium">Incoming Staff</th>
                                <th className="px-3 py-2 text-left font-medium">Fuel</th>
                                <th className="px-3 py-2 text-left font-medium">Condition</th>
                                <th className="px-3 py-2 text-left font-medium">Status</th>
                                <th className="px-3 py-2 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {allHandovers.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={8}
                                        className="px-3 py-8 text-center text-muted-foreground"
                                    >
                                        No handovers found.
                                    </td>
                                </tr>
                            )}
                            {allHandovers.map((h) => (
                                <tr
                                    key={h.id}
                                    className="border-b transition-colors hover:bg-muted/30"
                                >
                                    <td data-fleet-row-time className="px-3 py-2 whitespace-nowrap">
                                        {h.handed_over_at ? formatDate(h.handed_over_at) : '---'}
                                    </td>
                                    <td data-fleet-row-identity className="px-3 py-2">
                                        <div className="font-medium">{h.asset?.name ?? '---'}</div>
                                        {h.asset?.registration_number && (
                                            <div className="text-xs text-muted-foreground">
                                                {h.asset.registration_number}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">{h.outgoing_user?.name ?? '---'}</td>
                                    <td className="px-3 py-2">{h.incoming_user?.name ?? '---'}</td>
                                    <td className="px-3 py-2">{fuelLabel(h.fuel_level)}</td>
                                    <td className="px-3 py-2">
                                        <div className="flex gap-1">
                                            {conditionBadge(h.exterior_condition)}
                                        </div>
                                    </td>
                                    <td data-fleet-row-status className="px-3 py-2">{statusBadge(h.status)}</td>
                                    <td data-fleet-row-action className="px-3 py-2 text-right">
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={`/fleet-assets/handovers/${h.id}`}>
                                                <Eye className="mr-1 h-3.5 w-3.5" />
                                                View
                                            </Link>
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    </FleetResponsiveTable>
                </div>

                {/* Pagination */}
                {(paginationMeta.last_page ?? 1) > 1 && paginationLinks.length > 0 && (
                    <div className="flex items-center justify-center gap-1 pt-4">
                        {paginationLinks.map((link, i) => (
                            <Button
                                key={i}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url)}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}

                {/* ── New handover wizard (retired /handovers/create page) ── */}
                {wizard && can.manage && (
                    <HandoverWizard payload={wizard} open={wizardOpen} onClose={closeWizard} />
                )}
            </PageShell>
        </AppLayout>
    );
}
