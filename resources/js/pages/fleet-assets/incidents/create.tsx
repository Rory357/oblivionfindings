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
// Switch replaced with button toggle
import { cn } from '@/lib/utils';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import {
    AlertOctagon,
    AlertTriangle,
    Ban,
    Car,
    Hammer,
    Save,
    Shield,
    Skull,
    Wrench,
    Zap,
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

type Props = {
    vehicles: Vehicle[];
    users: UserOption[];
    preselected_asset_id?: number | null;
};

const INCIDENT_TYPES = [
    { value: 'collision', label: 'Collision', icon: Car },
    { value: 'damage', label: 'Damage', icon: Hammer },
    { value: 'theft', label: 'Theft', icon: Ban },
    { value: 'vandalism', label: 'Vandalism', icon: Shield },
    { value: 'breakdown', label: 'Breakdown', icon: Wrench },
    { value: 'near_miss', label: 'Near Miss', icon: Zap },
    { value: 'other', label: 'Other', icon: AlertOctagon },
];

const SEVERITY_LEVELS = [
    { value: 'minor', label: 'Minor', color: 'border-status-warning/30 bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning dark:border-status-warning/30' },
    { value: 'moderate', label: 'Moderate', color: 'border-status-warning/30 bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning dark:border-status-warning/30' },
    { value: 'major', label: 'Major', color: 'border-status-critical/30 bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical dark:border-status-critical/30' },
    { value: 'critical', label: 'Critical', color: 'border-status-critical/30 bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical dark:border-status-critical/30' },
];

const DAMAGE_AREAS = [
    'Front',
    'Rear',
    'Left side',
    'Right side',
    'Roof',
    'Undercarriage',
];

type FormData = {
    asset_id: string;
    driver_user_id: string;
    incident_type: string;
    severity: string;
    occurred_at: string;
    location: string;
    description: string;
    damage_areas: string[];
    estimated_cost: string;
    police_notified: boolean;
    police_reference: string;
    insurance_claimed: boolean;
    insurance_reference: string;
};

export default function IncidentCreate({ vehicles, users, preselected_asset_id }: Props) {
    const now = new Date();
    const defaultDateTime = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}T${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;

    const form = useForm<FormData>({
        asset_id: preselected_asset_id ? String(preselected_asset_id) : '',
        driver_user_id: '',
        incident_type: '',
        severity: '',
        occurred_at: defaultDateTime,
        location: '',
        description: '',
        damage_areas: [],
        estimated_cost: '',
        police_notified: false,
        police_reference: '',
        insurance_claimed: false,
        insurance_reference: '',
    });

    const toggleDamageArea = (area: string) => {
        const current = form.data.damage_areas;
        if (current.includes(area)) {
            form.setData('damage_areas', current.filter((a) => a !== area));
        } else {
            form.setData('damage_areas', [...current, area]);
        }
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        const payload: Record<string, unknown> = {
            asset_id: form.data.asset_id,
            driver_user_id: form.data.driver_user_id || null,
            incident_type: form.data.incident_type,
            severity: form.data.severity,
            occurred_at: form.data.occurred_at,
            location: form.data.location || null,
            description: form.data.description,
            police_notified: form.data.police_notified,
            police_reference: form.data.police_reference || null,
            insurance_claimed: form.data.insurance_claimed,
            insurance_reference: form.data.insurance_reference || null,
            damage_details: {
                areas: form.data.damage_areas,
                estimated_cost: form.data.estimated_cost ? parseFloat(form.data.estimated_cost) : null,
            },
        };

        form.transform(() => payload);
        form.post('/fleet-assets/incidents', {
            preserveScroll: true,
            onFinish: () => form.transform((data) => data),
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Incidents', href: '/fleet-assets/incidents' },
                { title: 'Report Incident', href: '#' },
            ]}
        >
            <Head title="Report Incident" />
            <PageShell>
                <FleetHero
                    title="Report Incident"
                    backHref="/fleet-assets/incidents"
                    backLabel="Back to Incidents"
                />

                <form onSubmit={submit} className="space-y-6">
                  <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                    {/* Left column: Main form (60%) */}
                    <div className="space-y-6">
                    {/* Vehicle & Driver */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Incident Details</CardTitle>
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
                                    <Label>Driver at Time of Incident</Label>
                                    <Select
                                        value={form.data.driver_user_id}
                                        onValueChange={(v) => form.setData('driver_user_id', v)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select driver" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(users ?? []).map((u) => (
                                                <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Date/Time of Incident *</Label>
                                    <Input
                                        type="datetime-local"
                                        value={form.data.occurred_at}
                                        onChange={(e) => form.setData('occurred_at', e.target.value)}
                                    />
                                    {form.errors.occurred_at && (
                                        <p className="mt-1 text-xs text-destructive">{form.errors.occurred_at}</p>
                                    )}
                                </div>
                                <div className="sm:col-span-2 lg:col-span-3">
                                    <Label>Location</Label>
                                    <Input
                                        value={form.data.location}
                                        onChange={(e) => form.setData('location', e.target.value)}
                                        placeholder="Address or description of location"
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    </div>{/* end left column */}

                    {/* Right column: Type & Severity (40%) */}
                    <div className="space-y-6">
                    {/* Incident Type - 2x4 grid with icons */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Incident Type *</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-2 gap-3">
                                {INCIDENT_TYPES.map((type) => {
                                    const IconComp = type.icon;
                                    return (
                                        <button
                                            key={type.value}
                                            type="button"
                                            onClick={() => form.setData('incident_type', type.value)}
                                            className={cn(
                                                "flex flex-col items-center gap-2 rounded-xl border-2 px-4 py-5 text-sm transition-all",
                                                form.data.incident_type === type.value
                                                    ? 'border-primary bg-primary/10 shadow-md dark:bg-primary/20 dark:border-primary'
                                                    : 'border-transparent bg-muted hover:bg-muted/80 hover:border-muted-foreground/20'
                                            )}
                                        >
                                            <IconComp className="h-7 w-7" />
                                            <span className="font-semibold">{type.label}</span>
                                        </button>
                                    );
                                })}
                            </div>
                            {form.errors.incident_type && (
                                <p className="mt-2 text-xs text-destructive">{form.errors.incident_type}</p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Severity - Horizontal bar of 4 large colored buttons */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Severity *</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex gap-2">
                                {SEVERITY_LEVELS.map((level) => (
                                    <button
                                        key={level.value}
                                        type="button"
                                        onClick={() => form.setData('severity', level.value)}
                                        className={cn(
                                            "flex-1 rounded-xl border-2 px-3 py-4 text-sm font-bold transition-all text-center",
                                            form.data.severity === level.value
                                                ? `${level.color} shadow-md`
                                                : 'border-transparent bg-muted text-muted-foreground hover:bg-muted/80'
                                        )}
                                    >
                                        {level.label}
                                    </button>
                                ))}
                            </div>
                            {form.errors.severity && (
                                <p className="mt-2 text-xs text-destructive">{form.errors.severity}</p>
                            )}
                        </CardContent>
                    </Card>

                    </div>{/* end right column */}
                  </div>{/* end grid */}

                    {/* Description */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Description *</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <textarea
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                rows={5}
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                placeholder="Describe what happened in detail..."
                            />
                            {form.errors.description && (
                                <p className="mt-1 text-xs text-destructive">{form.errors.description}</p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Damage Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Damage Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label className="mb-2 block">Body Areas Affected</Label>
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                                    {DAMAGE_AREAS.map((area) => (
                                        <label
                                            key={area}
                                            className={cn(
                                                "flex cursor-pointer items-center gap-2 rounded-md border p-2.5 text-sm transition-colors",
                                                form.data.damage_areas.includes(area)
                                                    ? 'border-primary bg-primary/10'
                                                    : 'hover:bg-muted'
                                            )}
                                        >
                                            <input
                                                type="checkbox"
                                                checked={form.data.damage_areas.includes(area)}
                                                onChange={() => toggleDamageArea(area)}
                                                className="rounded border-border"
                                            />
                                            {area}
                                        </label>
                                    ))}
                                </div>
                            </div>
                            <div className="max-w-xs">
                                <Label>Estimated Repair Cost ($)</Label>
                                <Input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={form.data.estimated_cost}
                                    onChange={(e) => form.setData('estimated_cost', e.target.value)}
                                    placeholder="0.00"
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Police & Insurance */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Police & Insurance</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex items-center justify-between rounded-lg border p-3">
                                <Label htmlFor="police_notified" className="cursor-pointer">
                                    <div className="font-medium">Police Notified</div>
                                    <div className="text-xs text-muted-foreground">Has the police been notified about this incident?</div>
                                </Label>
                                <button type="button" onClick={() => form.setData('police_notified', !form.data.police_notified)} className={cn("h-7 w-12 rounded-full transition-colors", form.data.police_notified ? "bg-primary" : "bg-muted")}><span className={cn("block h-5 w-5 rounded-full bg-white shadow transition-transform", form.data.police_notified ? "translate-x-6" : "translate-x-1")} /></button>
                            </div>
                            {form.data.police_notified && (
                                <div className="ml-4">
                                    <Label>Police Reference Number</Label>
                                    <Input
                                        value={form.data.police_reference}
                                        onChange={(e) => form.setData('police_reference', e.target.value)}
                                        placeholder="e.g. NZ123456"
                                    />
                                </div>
                            )}

                            <div className="flex items-center justify-between rounded-lg border p-3">
                                <Label htmlFor="insurance_claimed" className="cursor-pointer">
                                    <div className="font-medium">Insurance Claim</div>
                                    <div className="text-xs text-muted-foreground">Has an insurance claim been lodged?</div>
                                </Label>
                                <button type="button" onClick={() => form.setData('insurance_claimed', !form.data.insurance_claimed)} className={cn("h-7 w-12 rounded-full transition-colors", form.data.insurance_claimed ? "bg-primary" : "bg-muted")}><span className={cn("block h-5 w-5 rounded-full bg-white shadow transition-transform", form.data.insurance_claimed ? "translate-x-6" : "translate-x-1")} /></button>
                            </div>
                            {form.data.insurance_claimed && (
                                <div className="ml-4">
                                    <Label>Insurance Reference Number</Label>
                                    <Input
                                        value={form.data.insurance_reference}
                                        onChange={(e) => form.setData('insurance_reference', e.target.value)}
                                        placeholder="Claim reference"
                                    />
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Submit */}
                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            disabled={form.processing || !form.data.asset_id || !form.data.incident_type || !form.data.severity || !form.data.description}
                            size="lg"
                        >
                            <Save className="mr-2 h-4 w-4" />
                            Submit Incident Report
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
