import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
// Switch replaced with button toggle
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Check,
    FileText,
    Flame,
    Fuel,
    Key,
    Plus,
    Save,
    ShieldPlus,
    Trash2,
    X,
} from 'lucide-react';

type Vehicle = {
    id: number;
    name: string;
    registration_number?: string | null;
};

type UserOption = {
    id: number;
    name: string;
};

type DamageNote = {
    area: string;
    description: string;
};

type Props = {
    vehicles: Vehicle[];
    users: UserOption[];
    current_user_id: number;
    can: {
        manage: boolean;
    };
};

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

export default function HandoverCreate({ vehicles, users, current_user_id, can }: Props) {
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

    const addDamageNote = () => {
        form.setData('damage_notes', [...form.data.damage_notes, { area: '', description: '' }]);
    };

    const updateDamageNote = (index: number, field: keyof DamageNote, value: string) => {
        const updated = [...form.data.damage_notes];
        updated[index] = { ...updated[index], [field]: value };
        form.setData('damage_notes', updated);
    };

    const removeDamageNote = (index: number) => {
        form.setData('damage_notes', form.data.damage_notes.filter((_, i) => i !== index));
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/fleet-assets/handovers', { preserveScroll: true });
    };

    const currentUserName = (users ?? []).find((u) => u.id === current_user_id)?.name ?? 'Current User';

    if (!can.manage) {
        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Fleet & Assets', href: '/fleet-assets' },
                    { title: 'Shift Handovers', href: '/fleet-assets/handovers' },
                    { title: 'New Handover', href: '#' },
                ]}
            >
                <Head title="New Shift Handover" />
                <PageShell>
                    <FleetHero
                        title="New Shift Handover"
                        backHref="/fleet-assets/handovers"
                        backLabel="Back to Handovers"
                    />
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">View-only</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm text-muted-foreground">
                                Creating handovers requires fleet manager access.
                            </p>
                        </CardContent>
                    </Card>
                </PageShell>
            </AppLayout>
        );
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Shift Handovers', href: '/fleet-assets/handovers' },
                { title: 'New Handover', href: '#' },
            ]}
        >
            <Head title="New Shift Handover" />
            <PageShell>
                <FleetHero
                    title="New Shift Handover"
                    backHref="/fleet-assets/handovers"
                    backLabel="Back to Handovers"
                />

                <form onSubmit={submit} className="space-y-6">
                    {/* Vehicle & Staff */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Handover Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <div>
                                    <Label>Vehicle *</Label>
                                    <Select
                                        value={form.data.asset_id}
                                        onValueChange={(v) => form.setData('asset_id', v)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select vehicle" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(vehicles ?? []).map((v) => (
                                                <SelectItem key={v.id} value={String(v.id)}>
                                                    {v.name}{v.registration_number ? ` (${v.registration_number})` : ''}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.asset_id && (
                                        <p className="mt-1 text-xs text-destructive">{form.errors.asset_id}</p>
                                    )}
                                </div>
                                <div>
                                    <Label>Outgoing Staff</Label>
                                    <Input value={currentUserName} disabled className="bg-muted" />
                                    <p className="mt-1 text-xs text-muted-foreground">Auto-filled with current user</p>
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
                                            {(users ?? [])
                                                .filter((u) => u.id !== current_user_id)
                                                .map((u) => (
                                                    <SelectItem key={u.id} value={String(u.id)}>
                                                        {u.name}
                                                    </SelectItem>
                                                ))}
                                        </SelectContent>
                                    </Select>
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
                                        <p className="mt-1 text-xs text-destructive">{form.errors.odometer_km}</p>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Fuel Level - Large visual cards with fuel gauge */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base flex items-center gap-2">
                                <Fuel className="h-4 w-4" />
                                Fuel Level
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-5 gap-3">
                                {FUEL_LEVELS.map((level) => (
                                    <button
                                        key={level.value}
                                        type="button"
                                        onClick={() => form.setData('fuel_level', level.value)}
                                        className={cn(
                                            "flex flex-col items-center rounded-xl border-2 px-3 py-5 transition-all",
                                            form.data.fuel_level === level.value
                                                ? 'border-primary bg-primary/10 shadow-md dark:bg-primary/20 dark:border-primary'
                                                : 'border-transparent bg-muted hover:bg-muted/80 hover:border-muted-foreground/20'
                                        )}
                                    >
                                        {/* Fuel gauge icon */}
                                        <div className="relative mb-3 h-16 w-10 rounded-lg border-2 border-current overflow-hidden">
                                            <div
                                                className={cn(
                                                    "absolute bottom-0 w-full transition-all duration-300",
                                                    level.pct > 50 ? 'bg-status-success' : level.pct > 25 ? 'bg-status-warning' : 'bg-status-critical'
                                                )}
                                                style={{ height: `${level.pct}%` }}
                                            />
                                            {/* Cap on top */}
                                            <div className="absolute -top-1.5 left-1/2 -translate-x-1/2 h-2 w-4 rounded-t border-2 border-current bg-background" />
                                        </div>
                                        <span className="text-sm font-bold">{level.label}</span>
                                        <span className="text-[10px] text-muted-foreground">{level.pct}%</span>
                                    </button>
                                ))}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Vehicle Condition - Visual cards with colored borders */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Vehicle Condition</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {/* Exterior */}
                            <div>
                                <Label className="mb-3 block">Exterior Condition *</Label>
                                <div className="grid grid-cols-3 gap-3">
                                    {[
                                        { value: 'good', label: 'Good', borderColor: 'border-primary', bgColor: 'bg-primary/10 dark:bg-primary/20', textColor: 'text-primary dark:text-primary' },
                                        { value: 'minor_damage', label: 'Minor Damage', borderColor: 'border-status-warning/30', bgColor: 'bg-status-warning-bg dark:bg-status-warning', textColor: 'text-status-warning dark:text-status-warning' },
                                        { value: 'significant_damage', label: 'Significant Damage', borderColor: 'border-status-critical/30', bgColor: 'bg-status-critical-bg dark:bg-status-critical', textColor: 'text-status-critical dark:text-status-critical' },
                                    ].map((opt) => (
                                        <button
                                            key={opt.value}
                                            type="button"
                                            onClick={() => form.setData('exterior_condition', opt.value)}
                                            className={cn(
                                                "flex flex-col items-center gap-2 rounded-xl border-2 px-4 py-5 text-sm font-semibold transition-all",
                                                form.data.exterior_condition === opt.value
                                                    ? `${opt.borderColor} ${opt.bgColor} ${opt.textColor} shadow-md`
                                                    : 'border-transparent bg-muted text-muted-foreground hover:bg-muted/80'
                                            )}
                                        >
                                            {form.data.exterior_condition === opt.value && (
                                                <Check className="h-5 w-5" />
                                            )}
                                            {opt.label}
                                        </button>
                                    ))}
                                </div>
                                {form.errors.exterior_condition && (
                                    <p className="mt-1 text-xs text-destructive">{form.errors.exterior_condition}</p>
                                )}
                            </div>

                            {/* Interior */}
                            <div>
                                <Label className="mb-3 block">Interior Condition *</Label>
                                <div className="grid grid-cols-3 gap-3">
                                    {[
                                        { value: 'clean', label: 'Clean', borderColor: 'border-primary', bgColor: 'bg-primary/10 dark:bg-primary/20', textColor: 'text-primary dark:text-primary' },
                                        { value: 'acceptable', label: 'Acceptable', borderColor: 'border-status-warning/30', bgColor: 'bg-status-warning-bg dark:bg-status-warning', textColor: 'text-status-warning dark:text-status-warning' },
                                        { value: 'needs_cleaning', label: 'Needs Cleaning', borderColor: 'border-status-critical/30', bgColor: 'bg-status-critical-bg dark:bg-status-critical', textColor: 'text-status-critical dark:text-status-critical' },
                                    ].map((opt) => (
                                        <button
                                            key={opt.value}
                                            type="button"
                                            onClick={() => form.setData('interior_condition', opt.value)}
                                            className={cn(
                                                "flex flex-col items-center gap-2 rounded-xl border-2 px-4 py-5 text-sm font-semibold transition-all",
                                                form.data.interior_condition === opt.value
                                                    ? `${opt.borderColor} ${opt.bgColor} ${opt.textColor} shadow-md`
                                                    : 'border-transparent bg-muted text-muted-foreground hover:bg-muted/80'
                                            )}
                                        >
                                            {form.data.interior_condition === opt.value && (
                                                <Check className="h-5 w-5" />
                                            )}
                                            {opt.label}
                                        </button>
                                    ))}
                                </div>
                                {form.errors.interior_condition && (
                                    <p className="mt-1 text-xs text-destructive">{form.errors.interior_condition}</p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Checklist Items - Toggle switches with icons in 2-column grid */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Checklist Items</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {[
                                    { key: 'keys_present' as const, label: 'Keys Present', icon: Key },
                                    { key: 'documents_present' as const, label: 'Documents Present', icon: FileText },
                                    { key: 'first_aid_kit' as const, label: 'First Aid Kit', icon: ShieldPlus },
                                    { key: 'fire_extinguisher' as const, label: 'Fire Extinguisher', icon: Flame },
                                ].map((item) => {
                                    const IconComp = item.icon;
                                    return (
                                        <div key={item.key} className={cn(
                                            "flex items-center justify-between rounded-xl border-2 p-4 transition-all",
                                            form.data[item.key]
                                                ? "border-primary bg-primary/10/50 dark:border-primary/30 dark:bg-primary/10"
                                                : "border-status-critical/30 bg-status-critical-bg dark:border-status-critical/30 dark:bg-status-critical"
                                        )}>
                                            <div className="flex items-center gap-3">
                                                <div className={cn(
                                                    "flex h-9 w-9 items-center justify-center rounded-lg",
                                                    form.data[item.key]
                                                        ? "bg-primary/10 text-primary dark:bg-primary/30 dark:text-primary"
                                                        : "bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical"
                                                )}>
                                                    <IconComp className="h-4 w-4" />
                                                </div>
                                                <Label htmlFor={item.key} className="cursor-pointer font-medium">{item.label}</Label>
                                            </div>
                                            <button type="button" onClick={() => form.setData(item.key, !form.data[item.key])} className={cn("h-7 w-12 rounded-full transition-colors", form.data[item.key] ? "bg-primary" : "bg-muted")}><span className={cn("block h-5 w-5 rounded-full bg-white shadow transition-transform", form.data[item.key] ? "translate-x-6" : "translate-x-1")} /></button>
                                        </div>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Damage Notes */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base flex items-center justify-between">
                                Damage Notes
                                <Button type="button" variant="outline" size="sm" onClick={addDamageNote}>
                                    <Plus className="mr-1 h-3.5 w-3.5" />
                                    Add Damage
                                </Button>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {form.data.damage_notes.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No damage noted. Click "Add Damage" to report any damage.</p>
                            ) : (
                                <div className="space-y-3">
                                    {form.data.damage_notes.map((note, index) => (
                                        <div key={index} className="flex items-start gap-3 rounded-lg border p-3">
                                            <div className="flex-1 space-y-2">
                                                <Select
                                                    value={note.area}
                                                    onValueChange={(v) => updateDamageNote(index, 'area', v)}
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Select area" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {DAMAGE_AREAS.map((area) => (
                                                            <SelectItem key={area} value={area}>{area}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                <Input
                                                    value={note.description}
                                                    onChange={(e) => updateDamageNote(index, 'description', e.target.value)}
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
                        </CardContent>
                    </Card>

                    {/* General Notes */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">General Notes</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <textarea
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                rows={4}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                placeholder="Any additional observations or comments about the vehicle..."
                            />
                        </CardContent>
                    </Card>

                    {/* Submit */}
                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            disabled={form.processing || !form.data.asset_id}
                            size="lg"
                        >
                            <Save className="mr-2 h-4 w-4" />
                            Submit Handover
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
