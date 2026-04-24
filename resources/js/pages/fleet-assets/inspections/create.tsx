import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
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
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    Camera,
    CheckCircle,
    ClipboardCheck,
    MinusCircle,
    Save,
    XCircle,
} from 'lucide-react';
import { useMemo } from 'react';

type Vehicle = {
    id: number;
    name: string;
    registration_number?: string | null;
};

type PreTripResult = {
    id: number;
    passed: boolean;
    odometer: number | null;
    overall_condition: string | null;
    completed_at: string | null;
    checklist: Record<string, { result: string; notes: string }>;
};

type BookingInfo = {
    id: number;
    asset_id: number;
    purpose: string;
};

type Props = {
    vehicles: Vehicle[];
    preselected_asset_id?: number | null;
    preselected_type?: string;
    booking_id?: number | null;
    booking?: BookingInfo | null;
    pre_trip_results?: PreTripResult | null;
    can: {
        manage: boolean;
    };
};

// Standard vehicle inspection checklist
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
    photos: File[];
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

export default function InspectionCreate({ vehicles, preselected_asset_id, preselected_type, booking_id, booking, pre_trip_results, can }: Props) {
    const { url } = usePage();
    const urlParams = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : null;
    const defaultAssetId = preselected_asset_id
        ? String(preselected_asset_id)
        : urlParams?.get('asset_id') ?? '';

    const form = useForm<FormData>({
        asset_id: defaultAssetId,
        inspection_type: preselected_type ?? urlParams?.get('type') ?? 'pre-trip',
        odometer: '',
        overall_condition: 'good',
        notes: '',
        checklist: buildInitialChecklist(),
        photos: [],
        booking_id: booking_id ? String(booking_id) : urlParams?.get('booking_id') ?? '',
        fuel_level_return: '',
        items_left: '',
        new_damage: '',
    });

    const isPostTrip = form.data.inspection_type === 'post-trip';

    const setChecklistItem = (key: string, field: 'result' | 'notes', value: string) => {
        const updated = { ...form.data.checklist };
        updated[key] = { ...updated[key], [field]: value };
        form.setData('checklist', updated);
    };

    const hasAnyFail = Object.values(form.data.checklist).some((v) => v.result === 'fail');

    const totalItems = allItemKeys().length;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/fleet-assets/inspections', {
            preserveScroll: true,
        });
    };

    if (!can.manage) {
        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Fleet & Assets', href: '/fleet-assets' },
                    { title: 'Inspections', href: '/fleet-assets/inspections' },
                    { title: 'New Inspection', href: '#' },
                ]}
            >
                <Head title="New Vehicle Inspection" />
                <PageShell>
                    <FleetHero
                        title="New Vehicle Inspection"
                        backHref="/fleet-assets/inspections"
                        backLabel="Back to Inspections"
                    />
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">View-only</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm text-muted-foreground">
                                Creating inspections requires fleet maintenance manager access.
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
                { title: 'Inspections', href: '/fleet-assets/inspections' },
                { title: 'New Inspection', href: '#' },
            ]}
        >
            <Head title="New Vehicle Inspection" />
            <PageShell>
                <FleetHero
                    title="New Vehicle Inspection"
                    backHref="/fleet-assets/inspections"
                    backLabel="Back to Inspections"
                />

                <form onSubmit={submit} className="space-y-6">
                    {/* Completion Progress Bar */}
                    <div className="rounded-lg border bg-muted/30 p-4">
                        <div className="flex items-center justify-between mb-2">
                            <span className="text-sm font-medium">Inspection Progress</span>
                            <span className="text-sm text-muted-foreground">{totalItems} of {totalItems} items checked</span>
                        </div>
                        <div className="h-2 w-full rounded-full bg-muted">
                            <div
                                className="h-full rounded-full bg-primary transition-all duration-300"
                                style={{ width: '100%' }}
                            />
                        </div>
                    </div>

                    {/* Vehicle & Type */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Inspection Details</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
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
                                    <Label>Inspection Type *</Label>
                                    <Select
                                        value={form.data.inspection_type}
                                        onValueChange={(v) => form.setData('inspection_type', v)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="pre-trip">Pre-Trip</SelectItem>
                                            <SelectItem value="post-trip">Post-Trip</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Odometer Reading (km)</Label>
                                    <Input
                                        type="number"
                                        value={form.data.odometer}
                                        onChange={(e) => form.setData('odometer', e.target.value)}
                                        placeholder="Current km"
                                    />
                                    {form.errors.odometer && (
                                        <p className="mt-1 text-xs text-destructive">{form.errors.odometer}</p>
                                    )}
                                </div>
                                <div>
                                    <Label>Overall Condition *</Label>
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
                        </CardContent>
                    </Card>

                    {/* Checklist Sections with colored banners */}
                    {CHECKLIST_SECTIONS.map((section) => {
                        const sectionPassCount = section.items.filter(
                            (item) => form.data.checklist[item.key]?.result === 'pass'
                        ).length;
                        const sectionTotal = section.items.length;

                        return (
                            <Card key={section.section}>
                                <div className={cn("rounded-t-lg px-6 py-3 text-white", section.color)}>
                                    <div className="flex items-center justify-between">
                                        <span className="text-base font-semibold">{section.section}</span>
                                        <span className="text-sm opacity-90">{sectionPassCount}/{sectionTotal} passed</span>
                                    </div>
                                </div>
                                <CardContent className="pt-4">
                                    <div className="space-y-4">
                                        {section.items.map((item) => {
                                            const val = form.data.checklist[item.key];
                                            return (
                                                <div
                                                    key={item.key}
                                                    className="flex flex-col gap-3 rounded-lg border p-4"
                                                >
                                                    <div className="min-w-[200px]">
                                                        <span className="text-sm font-medium">{item.label}</span>
                                                    </div>
                                                    <div className="grid grid-cols-3 gap-2">
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            onClick={() => setChecklistItem(item.key, 'result', 'pass')}
                                                            className={cn(
                                                                "h-auto flex-col rounded-lg border-2 py-3 transition-all",
                                                                val?.result === 'pass'
                                                                    ? "border-primary bg-primary/10 text-primary dark:bg-primary/30 dark:text-primary dark:border-primary"
                                                                    : "border-transparent bg-muted hover:border-primary"
                                                            )}
                                                        >
                                                            <CheckCircle className="mx-auto mb-1 h-4 w-4" />
                                                            Pass
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            onClick={() => setChecklistItem(item.key, 'result', 'fail')}
                                                            className={cn(
                                                                "h-auto flex-col rounded-lg border-2 py-3 transition-all",
                                                                val?.result === 'fail'
                                                                    ? "border-status-critical/30 bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical dark:border-status-critical/30"
                                                                    : "border-transparent bg-muted hover:border-status-critical/30"
                                                            )}
                                                        >
                                                            <XCircle className="mx-auto mb-1 h-4 w-4" />
                                                            Fail
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            onClick={() => setChecklistItem(item.key, 'result', 'na')}
                                                            className={cn(
                                                                "h-auto flex-col rounded-lg border-2 py-3 transition-all",
                                                                val?.result === 'na'
                                                                    ? "border-border bg-muted text-foreground dark:bg-muted/30 dark:text-muted-foreground dark:border-border"
                                                                    : "border-transparent bg-muted hover:border-border"
                                                            )}
                                                        >
                                                            <MinusCircle className="mx-auto mb-1 h-4 w-4" />
                                                            N/A
                                                        </Button>
                                                    </div>
                                                    <Input
                                                        value={val?.notes ?? ''}
                                                        onChange={(e) => setChecklistItem(item.key, 'notes', e.target.value)}
                                                        placeholder="Notes (optional)"
                                                        className="h-8 text-xs"
                                                    />
                                                </div>
                                            );
                                        })}
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}

                    {/* Post-Trip Specific Fields */}
                    {isPostTrip && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Post-Trip Details</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {pre_trip_results && (
                                    <div className="rounded-md border border-status-info/30 bg-status-info-bg p-3 dark:border-status-info/30 dark:bg-status-info">
                                        <div className="mb-1 text-sm font-medium text-status-info dark:text-status-info">Pre-Trip Comparison</div>
                                        <div className="grid gap-2 text-xs sm:grid-cols-3">
                                            <div>
                                                <span className="text-muted-foreground">Pre-trip odometer:</span>{' '}
                                                <span className="font-medium">{pre_trip_results.odometer ?? '---'} km</span>
                                            </div>
                                            <div>
                                                <span className="text-muted-foreground">Pre-trip condition:</span>{' '}
                                                <span className="font-medium capitalize">{pre_trip_results.overall_condition ?? '---'}</span>
                                            </div>
                                            <div>
                                                <span className="text-muted-foreground">Pre-trip result:</span>{' '}
                                                <span className={`font-medium ${pre_trip_results.passed ? 'text-status-success' : 'text-status-critical'}`}>
                                                    {pre_trip_results.passed ? 'All Clear' : 'Issues Found'}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                )}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label>Fuel Level on Return</Label>
                                        <Select
                                            value={form.data.fuel_level_return}
                                            onValueChange={(v) => form.setData('fuel_level_return', v)}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select fuel level" />
                                            </SelectTrigger>
                                            <SelectContent>
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
                                    <Label>Any New Damage?</Label>
                                    <textarea
                                        className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        rows={2}
                                        value={form.data.new_damage}
                                        onChange={(e) => form.setData('new_damage', e.target.value)}
                                        placeholder="Describe any new damage noticed during/after the trip..."
                                    />
                                </div>
                                <div>
                                    <Label>Items Left in Vehicle</Label>
                                    <textarea
                                        className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                        rows={2}
                                        value={form.data.items_left}
                                        onChange={(e) => form.setData('items_left', e.target.value)}
                                        placeholder="List any personal items or equipment left in the vehicle..."
                                    />
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Notes & Photos */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Additional Information</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div>
                                <Label>Notes / Comments</Label>
                                <textarea
                                    className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    rows={4}
                                    value={form.data.notes}
                                    onChange={(e) => form.setData('notes', e.target.value)}
                                    placeholder="Any additional observations, concerns, or comments..."
                                />
                            </div>
                            <div>
                                <Label>Photos (optional)</Label>
                                <div className="mt-1">
                                    <label className="flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-input px-4 py-3 text-sm text-muted-foreground hover:bg-muted/50">
                                        <Camera className="h-4 w-4" />
                                        <span>Upload inspection photos</span>
                                        <input
                                            type="file"
                                            accept="image/*"
                                            multiple
                                            className="hidden"
                                            onChange={(e) => {
                                                const files = Array.from(e.target.files ?? []);
                                                form.setData('photos', files);
                                            }}
                                        />
                                    </label>
                                    {form.data.photos.length > 0 && (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {form.data.photos.length} file(s) selected
                                        </p>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Sticky Submit */}
                    <div className="sticky bottom-0 z-10 -mx-4 border-t bg-background/95 px-4 py-4 backdrop-blur supports-[backdrop-filter]:bg-background/60 sm:-mx-6 sm:px-6">
                        <div className="flex flex-col items-start gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-center gap-3">
                                <span className="text-sm font-medium">Inspection Result:</span>
                                {hasAnyFail ? (
                                    <Badge variant="destructive" className="text-sm">
                                        <XCircle className="mr-1 h-4 w-4" /> Issues Found
                                    </Badge>
                                ) : (
                                    <Badge variant="default" className="bg-status-success text-sm">
                                        <CheckCircle className="mr-1 h-4 w-4" /> All Clear
                                    </Badge>
                                )}
                            </div>
                            <Button
                                type="submit"
                                disabled={form.processing || !form.data.asset_id}
                                size="lg"
                            >
                                <Save className="mr-2 h-4 w-4" />
                                Submit Inspection
                            </Button>
                        </div>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
