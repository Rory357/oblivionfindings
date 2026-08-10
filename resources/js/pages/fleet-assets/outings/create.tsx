import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import {
    AlertTriangle,
    ArrowDown,
    ArrowUp,
    Car,
    Check,
    Clock,
    Heart,
    Loader2,
    MapPin,
    Navigation,
    Pill,
    Plus,
    Route,
    Save,
    ShoppingBag,
    Stethoscope,
    Sun,
    Trash2,
    Users,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

type ClientOption = {
    id: number;
    name: string;
    transport_needs?: Record<string, boolean> | null;
    transport_notes?: string | null;
    site?: string | null;
};

type VehicleOption = {
    id: number;
    name: string;
    asset_tag?: string;
    has_wheelchair_ramp?: boolean;
    has_hoist?: boolean;
    has_child_seat_anchors?: boolean;
    has_medical_storage?: boolean;
    seating_capacity?: number | null;
};

type DriverOption = {
    id: number;
    name: string;
};

type RouteStop = {
    id: string;
    location: string;
    estimated_arrival: string;
    resident_ids: number[];
    lat?: number;
    lng?: number;
};

type Props = {
    clients: ClientOption[];
    vehicles: VehicleOption[];
    drivers: DriverOption[];
    auth_user: { id: number; name: string };
    can: {
        manage: boolean;
    };
};

const PURPOSE_TYPES = [
    { value: 'community', label: 'Community', icon: MapPin, color: 'border-teal-500 bg-teal-50 text-teal-800 dark:bg-teal-900/30 dark:text-teal-400 dark:border-teal-700' },
    { value: 'medical', label: 'Medical', icon: Stethoscope, color: 'border-red-500 bg-red-50 text-red-800 dark:bg-red-900/30 dark:text-red-400 dark:border-red-700' },
    { value: 'social', label: 'Social', icon: Users, color: 'border-green-500 bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-400 dark:border-green-700' },
    { value: 'recreational', label: 'Recreational', icon: Sun, color: 'border-orange-500 bg-orange-50 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400 dark:border-orange-700' },
    { value: 'shopping', label: 'Shopping', icon: ShoppingBag, color: 'border-purple-500 bg-purple-50 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400 dark:border-purple-700' },
];

export default function OutingCreate({ clients, vehicles, drivers, auth_user, can }: Props) {
    const safeClients = clients ?? [];
    const safeVehicles = vehicles ?? [];
    const safeDrivers = drivers ?? [];

    const [step, setStep] = useState(1);
    const [selectedResidents, setSelectedResidents] = useState<number[]>([]);

    const [stops, setStops] = useState<RouteStop[]>([]);

    const form = useForm({
        title: '',
        destination: '',
        purpose: '',
        planned_departure: '',
        planned_return: '',
        asset_id: '',
        driver_user_id: '',
        risk_assessment: '',
        notes: '',
        resident_ids: [] as number[],
        stops: [] as Array<{ location: string; estimated_arrival: string; resident_ids: number[] }>,
    });

    const toggleResident = (id: number) => {
        setSelectedResidents((prev) => {
            const next = prev.includes(id) ? prev.filter((r) => r !== id) : [...prev, id];
            form.setData('resident_ids', next);
            return next;
        });
    };

    // Compute combined accessibility needs of all selected residents
    const combinedNeeds = useMemo(() => {
        const needs = { wheelchair_ramp: false, hoist: false, child_seat: false, medical_storage: false };
        for (const rid of selectedResidents) {
            const client = safeClients.find((c) => c.id === rid);
            if (client?.transport_needs) {
                if (client.transport_needs.wheelchair_ramp) needs.wheelchair_ramp = true;
                if (client.transport_needs.hoist) needs.hoist = true;
                if (client.transport_needs.child_seat) needs.child_seat = true;
                if (client.transport_needs.medical_storage) needs.medical_storage = true;
            }
        }
        return needs;
    }, [selectedResidents, safeClients]);

    // Filter vehicles by combined needs
    const filteredVehicles = useMemo(() => {
        const hasNeeds = Object.values(combinedNeeds).some(Boolean);
        if (!hasNeeds) return safeVehicles;

        const compatible: VehicleOption[] = [];
        const incompatible: VehicleOption[] = [];

        for (const v of safeVehicles) {
            let isCompatible = true;
            if (combinedNeeds.wheelchair_ramp && !v.has_wheelchair_ramp) isCompatible = false;
            if (combinedNeeds.hoist && !v.has_hoist) isCompatible = false;
            if (combinedNeeds.child_seat && !v.has_child_seat_anchors) isCompatible = false;
            if (combinedNeeds.medical_storage && !v.has_medical_storage) isCompatible = false;

            if (isCompatible) compatible.push(v);
            else incompatible.push(v);
        }

        return [...compatible, ...incompatible];
    }, [safeVehicles, combinedNeeds]);

    const isVehicleCompatible = useCallback((v: VehicleOption) => {
        if (combinedNeeds.wheelchair_ramp && !v.has_wheelchair_ramp) return false;
        if (combinedNeeds.hoist && !v.has_hoist) return false;
        if (combinedNeeds.child_seat && !v.has_child_seat_anchors) return false;
        if (combinedNeeds.medical_storage && !v.has_medical_storage) return false;
        return true;
    }, [combinedNeeds]);

    // Check if any selected resident may need medication during outing window
    const hasMedicationAlert = useMemo(() => {
        if (!form.data.planned_departure || !form.data.planned_return) return false;
        // Simple heuristic: if outing is longer than 2 hours, show alert
        const start = new Date(form.data.planned_departure).getTime();
        const end = new Date(form.data.planned_return).getTime();
        const durationHours = (end - start) / (1000 * 60 * 60);
        return durationHours >= 2 && selectedResidents.length > 0;
    }, [form.data.planned_departure, form.data.planned_return, selectedResidents]);

    // Route stop helpers
    const addStop = useCallback(() => {
        const newStop: RouteStop = {
            id: `stop-${Date.now()}`,
            location: '',
            estimated_arrival: '',
            resident_ids: [],
        };
        const next = [...stops, newStop];
        setStops(next);
        form.setData('stops', next.map(s => ({ location: s.location, estimated_arrival: s.estimated_arrival, resident_ids: s.resident_ids })));
    }, [stops, form]);

    const updateStop = useCallback((id: string, field: keyof RouteStop, value: unknown) => {
        const next = stops.map(s => s.id === id ? { ...s, [field]: value } : s);
        setStops(next);
        form.setData('stops', next.map(s => ({ location: s.location, estimated_arrival: s.estimated_arrival, resident_ids: s.resident_ids })));
    }, [stops, form]);

    const removeStop = useCallback((id: string) => {
        const next = stops.filter(s => s.id !== id);
        setStops(next);
        form.setData('stops', next.map(s => ({ location: s.location, estimated_arrival: s.estimated_arrival, resident_ids: s.resident_ids })));
    }, [stops, form]);

    const moveStop = useCallback((index: number, direction: 'up' | 'down') => {
        const next = [...stops];
        const targetIndex = direction === 'up' ? index - 1 : index + 1;
        if (targetIndex < 0 || targetIndex >= next.length) return;
        [next[index], next[targetIndex]] = [next[targetIndex], next[index]];
        setStops(next);
        form.setData('stops', next.map(s => ({ location: s.location, estimated_arrival: s.estimated_arrival, resident_ids: s.resident_ids })));
    }, [stops, form]);

    const toggleStopResident = useCallback((stopId: string, residentId: number) => {
        const stop = stops.find(s => s.id === stopId);
        if (!stop) return;
        const rids = stop.resident_ids.includes(residentId)
            ? stop.resident_ids.filter(r => r !== residentId)
            : [...stop.resident_ids, residentId];
        updateStop(stopId, 'resident_ids', rids);
    }, [stops, updateStop]);

    // Straight-line distance estimate between stops
    const routeEstimates = useMemo(() => {
        if (stops.length < 2) return { totalDistanceKm: 0, totalMinutes: 0 };
        let totalDistanceKm = 0;
        for (let i = 0; i < stops.length - 1; i++) {
            // Without real coords, estimate 5km between each stop
            totalDistanceKm += 5;
        }
        // Estimate 3 min/km average urban driving
        const totalMinutes = Math.round(totalDistanceKm * 3);
        return { totalDistanceKm, totalMinutes };
    }, [stops]);

    const handleSubmit = useCallback((e: React.FormEvent) => {
        e.preventDefault();
        form.post('/fleet-assets/outings');
    }, [form]);

    if (!can.manage) {
        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Fleet & Assets', href: '/fleet-assets' },
                    { title: 'Outings', href: '/fleet-assets/outings' },
                    { title: 'Plan Outing', href: '#' },
                ]}
            >
                <Head title="Plan Outing" />
                <PageShell>
                    <FleetHero
                        title="Plan Community Outing"
                        description="Create a new outing for residents."
                        backHref="/fleet-assets/outings"
                        backLabel="Back to Outings"
                    />
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">View-only</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm text-muted-foreground">
                                Planning or changing outings requires fleet outing management access.
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
                { title: 'Outings', href: '/fleet-assets/outings' },
                { title: 'Plan Outing', href: '#' },
            ]}
        >
            <Head title="Plan Outing" />
            <PageShell>
                <FleetHero
                    title="Plan Community Outing"
                    description="Create a new outing for residents."
                    backHref="/fleet-assets/outings"
                    backLabel="Back to Outings"
                />

                {/* Step Indicators */}
                <div className="flex items-center gap-2 mb-6 flex-wrap">
                    {[
                        { num: 1, label: 'Details' },
                        { num: 2, label: 'Residents' },
                        { num: 3, label: 'Route Planner' },
                        { num: 4, label: 'Vehicle & Driver' },
                        { num: 5, label: 'Risk Assessment' },
                    ].map((s) => (
                        <button
                            key={s.num}
                            type="button"
                            onClick={() => setStep(s.num)}
                            className={cn(
                                "flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-all",
                                step === s.num
                                    ? "bg-purple-600 text-white shadow-md"
                                    : step > s.num
                                        ? "bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400"
                                        : "bg-muted text-muted-foreground"
                            )}
                        >
                            <span className={cn(
                                "flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold",
                                step === s.num ? "bg-white text-purple-600" : step > s.num ? "bg-purple-600 text-white" : "bg-muted-foreground/20"
                            )}>
                                {step > s.num ? <Check className="h-3.5 w-3.5" /> : s.num}
                            </span>
                            <span className="hidden sm:inline">{s.label}</span>
                        </button>
                    ))}
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Step 1: Details */}
                    {step === 1 && (
                        <>
                            {/* Purpose Type Cards */}
                            <Card>
                                <CardHeader>
                                    <CardTitle>Outing Purpose</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                                        {PURPOSE_TYPES.map((type) => {
                                            const IconComp = type.icon;
                                            return (
                                                <button
                                                    key={type.value}
                                                    type="button"
                                                    onClick={() => form.setData('purpose', type.value)}
                                                    className={cn(
                                                        "flex flex-col items-center gap-2 rounded-xl border-2 px-4 py-5 text-sm transition-all",
                                                        form.data.purpose === type.value
                                                            ? `${type.color} shadow-md`
                                                            : 'border-transparent bg-muted/30 text-muted-foreground hover:bg-muted/60 hover:border-muted-foreground/20'
                                                    )}
                                                >
                                                    <IconComp className="h-7 w-7" />
                                                    <span className="font-semibold">{type.label}</span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Outing Details</CardTitle>
                                </CardHeader>
                                <CardContent className="grid gap-4 sm:grid-cols-2">
                                    <div className="sm:col-span-2">
                                        <label className="text-sm font-medium">Title *</label>
                                        <Input
                                            value={form.data.title}
                                            onChange={(e) => form.setData('title', e.target.value)}
                                            placeholder="e.g. Beach Trip, Community Garden Visit"
                                        />
                                        {form.errors.title && <p className="mt-1 text-xs text-destructive">{form.errors.title}</p>}
                                    </div>
                                    <div className="sm:col-span-2">
                                        <label className="text-sm font-medium">Destination *</label>
                                        <Input
                                            value={form.data.destination}
                                            onChange={(e) => form.setData('destination', e.target.value)}
                                            placeholder="Where are you going?"
                                        />
                                        {form.errors.destination && <p className="mt-1 text-xs text-destructive">{form.errors.destination}</p>}
                                    </div>
                                    <div>
                                        <label className="text-sm font-medium">Planned Departure *</label>
                                        <Input
                                            type="datetime-local"
                                            value={form.data.planned_departure}
                                            onChange={(e) => form.setData('planned_departure', e.target.value)}
                                        />
                                        {form.errors.planned_departure && <p className="mt-1 text-xs text-destructive">{form.errors.planned_departure}</p>}
                                    </div>
                                    <div>
                                        <label className="text-sm font-medium">Planned Return *</label>
                                        <Input
                                            type="datetime-local"
                                            value={form.data.planned_return}
                                            onChange={(e) => form.setData('planned_return', e.target.value)}
                                        />
                                        {form.errors.planned_return && <p className="mt-1 text-xs text-destructive">{form.errors.planned_return}</p>}
                                    </div>
                                </CardContent>
                            </Card>

                            <div className="flex justify-end">
                                <Button type="button" onClick={() => setStep(2)}>
                                    Next: Select Residents
                                </Button>
                            </div>
                        </>
                    )}

                    {/* Step 2: Residents */}
                    {step === 2 && (
                        <>
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <Users className="h-5 w-5" />
                                        Select Residents
                                        {selectedResidents.length > 0 && (
                                            <Badge variant="default" className="text-xs">{selectedResidents.length} selected</Badge>
                                        )}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {safeClients.length > 0 ? (
                                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                            {safeClients.map((client) => {
                                                const isSelected = selectedResidents.includes(client.id);
                                                const hasNeeds = client.transport_needs && Object.values(client.transport_needs).some(Boolean);
                                                return (
                                                    <button
                                                        key={client.id}
                                                        type="button"
                                                        onClick={() => toggleResident(client.id)}
                                                        className={cn(
                                                            "flex items-start gap-3 rounded-xl border-2 p-4 text-left transition-all",
                                                            isSelected
                                                                ? "border-purple-500 bg-purple-50 shadow-md dark:bg-purple-900/20 dark:border-purple-600"
                                                                : "border-transparent bg-muted/30 hover:bg-muted/60 hover:border-muted-foreground/20"
                                                        )}
                                                    >
                                                        <div className={cn(
                                                            "mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded border-2 transition-all",
                                                            isSelected
                                                                ? "border-purple-600 bg-purple-600 text-white"
                                                                : "border-muted-foreground/30"
                                                        )}>
                                                            {isSelected && <Check className="h-3.5 w-3.5" />}
                                                        </div>
                                                        <div className="min-w-0 flex-1">
                                                            <p className="text-sm font-medium">{client.name}</p>
                                                            {client.site && (
                                                                <p className="text-[10px] text-muted-foreground">{client.site}</p>
                                                            )}
                                                            {hasNeeds && (
                                                                <div className="mt-1.5 flex flex-wrap gap-1">
                                                                    {client.transport_needs?.wheelchair_ramp && (
                                                                        <Badge variant="outline" className="text-[9px] h-4">Wheelchair</Badge>
                                                                    )}
                                                                    {client.transport_needs?.hoist && (
                                                                        <Badge variant="outline" className="text-[9px] h-4">Hoist</Badge>
                                                                    )}
                                                                    {client.transport_needs?.child_seat && (
                                                                        <Badge variant="outline" className="text-[9px] h-4">Child Seat</Badge>
                                                                    )}
                                                                    {client.transport_needs?.medical_storage && (
                                                                        <Badge variant="outline" className="text-[9px] h-4">Medical</Badge>
                                                                    )}
                                                                </div>
                                                            )}
                                                        </div>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground py-6 text-center">No active clients found.</p>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Care needs summary */}
                            {selectedResidents.length > 0 && Object.values(combinedNeeds).some(Boolean) && (
                                <Card className="border-purple-200 dark:border-purple-800">
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-sm text-purple-700 dark:text-purple-400">Care Needs Summary</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="flex flex-wrap gap-2">
                                            {combinedNeeds.wheelchair_ramp && <Badge className="bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400">Wheelchair Ramp Required</Badge>}
                                            {combinedNeeds.hoist && <Badge className="bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400">Hoist Required</Badge>}
                                            {combinedNeeds.child_seat && <Badge className="bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400">Child Seat Required</Badge>}
                                            {combinedNeeds.medical_storage && <Badge className="bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400">Medical Storage Required</Badge>}
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Medication alert */}
                            {hasMedicationAlert && (
                                <div className="flex items-center gap-3 rounded-lg border border-amber-300 bg-amber-50/50 px-4 py-3 dark:border-amber-800 dark:bg-amber-950/20">
                                    <Pill className="h-5 w-5 text-amber-600 shrink-0" />
                                    <div>
                                        <p className="text-sm font-medium text-amber-700 dark:text-amber-400">Medication Reminder</p>
                                        <p className="text-xs text-amber-600 dark:text-amber-500">
                                            This outing is 2+ hours. Ensure all required medications are packed for selected residents.
                                        </p>
                                    </div>
                                </div>
                            )}

                            <div className="flex justify-between">
                                <Button type="button" variant="outline" onClick={() => setStep(1)}>Back</Button>
                                <Button type="button" onClick={() => setStep(3)}>
                                    Next: Route Planner
                                </Button>
                            </div>
                        </>
                    )}

                    {/* Step 3: Route Planner */}
                    {step === 3 && (
                        <>
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <Route className="h-5 w-5" />
                                        Route Planner
                                        {stops.length > 0 && (
                                            <Badge variant="default" className="text-xs">{stops.length} stops</Badge>
                                        )}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <p className="text-sm text-muted-foreground">
                                        Add multiple stops to plan the route. Drag to reorder stops and assign residents to each stop.
                                    </p>

                                    {/* Stop List */}
                                    {stops.length > 0 ? (
                                        <div className="space-y-3">
                                            {stops.map((stop, index) => (
                                                <div
                                                    key={stop.id}
                                                    className="rounded-xl border-2 border-purple-200 bg-purple-50/30 p-4 dark:border-purple-800 dark:bg-purple-950/20"
                                                >
                                                    <div className="flex items-start gap-3">
                                                        {/* Stop Number */}
                                                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-purple-600 text-sm font-bold text-white">
                                                            {index + 1}
                                                        </div>

                                                        <div className="min-w-0 flex-1 space-y-3">
                                                            <div className="grid gap-3 sm:grid-cols-2">
                                                                <div>
                                                                    <label className="text-xs font-medium text-muted-foreground">Location</label>
                                                                    <Input
                                                                        value={stop.location}
                                                                        onChange={(e) => updateStop(stop.id, 'location', e.target.value)}
                                                                        placeholder="Stop name or address"
                                                                        className="mt-1"
                                                                    />
                                                                </div>
                                                                <div>
                                                                    <label className="text-xs font-medium text-muted-foreground">Estimated Arrival</label>
                                                                    <Input
                                                                        type="time"
                                                                        value={stop.estimated_arrival}
                                                                        onChange={(e) => updateStop(stop.id, 'estimated_arrival', e.target.value)}
                                                                        className="mt-1"
                                                                    />
                                                                </div>
                                                            </div>

                                                            {/* Residents getting off at this stop */}
                                                            {selectedResidents.length > 0 && (
                                                                <div>
                                                                    <label className="text-xs font-medium text-muted-foreground">Residents at this stop</label>
                                                                    <div className="mt-1 flex flex-wrap gap-1.5">
                                                                        {selectedResidents.map((rid) => {
                                                                            const client = safeClients.find(c => c.id === rid);
                                                                            if (!client) return null;
                                                                            const isAtStop = stop.resident_ids.includes(rid);
                                                                            return (
                                                                                <button
                                                                                    key={rid}
                                                                                    type="button"
                                                                                    onClick={() => toggleStopResident(stop.id, rid)}
                                                                                    className={cn(
                                                                                        "rounded-full px-2.5 py-1 text-xs font-medium transition-all",
                                                                                        isAtStop
                                                                                            ? "bg-purple-600 text-white"
                                                                                            : "bg-muted text-muted-foreground hover:bg-muted/80"
                                                                                    )}
                                                                                >
                                                                                    {client.name}
                                                                                </button>
                                                                            );
                                                                        })}
                                                                    </div>
                                                                </div>
                                                            )}
                                                        </div>

                                                        {/* Reorder + Delete */}
                                                        <div className="flex flex-col gap-1">
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                className="h-7 w-7 p-0"
                                                                disabled={index === 0}
                                                                onClick={() => moveStop(index, 'up')}
                                                            >
                                                                <ArrowUp className="h-3.5 w-3.5" />
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                className="h-7 w-7 p-0"
                                                                disabled={index === stops.length - 1}
                                                                onClick={() => moveStop(index, 'down')}
                                                            >
                                                                <ArrowDown className="h-3.5 w-3.5" />
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="sm"
                                                                className="h-7 w-7 p-0 text-red-500 hover:text-red-700"
                                                                onClick={() => removeStop(stop.id)}
                                                            >
                                                                <Trash2 className="h-3.5 w-3.5" />
                                                            </Button>
                                                        </div>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="rounded-lg border-2 border-dashed border-muted-foreground/20 py-8 text-center">
                                            <Navigation className="mx-auto h-8 w-8 text-muted-foreground/40 mb-2" />
                                            <p className="text-sm text-muted-foreground">No stops added yet.</p>
                                            <p className="text-xs text-muted-foreground mt-1">Add stops to plan the route for this outing.</p>
                                        </div>
                                    )}

                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={addStop}
                                        className="w-full border-dashed"
                                    >
                                        <Plus className="mr-2 h-4 w-4" />
                                        Add Stop
                                    </Button>
                                </CardContent>
                            </Card>

                            {/* Route Estimates */}
                            {stops.length >= 2 && (
                                <Card className="border-purple-200 dark:border-purple-800">
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-sm text-purple-700 dark:text-purple-400">Route Estimate</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="grid grid-cols-2 gap-4">
                                            <div className="flex items-center gap-2">
                                                <Navigation className="h-4 w-4 text-purple-600" />
                                                <div>
                                                    <p className="text-lg font-bold">{routeEstimates.totalDistanceKm} km</p>
                                                    <p className="text-xs text-muted-foreground">Estimated Distance</p>
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Clock className="h-4 w-4 text-purple-600" />
                                                <div>
                                                    <p className="text-lg font-bold">{routeEstimates.totalMinutes} min</p>
                                                    <p className="text-xs text-muted-foreground">Estimated Travel Time</p>
                                                </div>
                                            </div>
                                        </div>
                                        <p className="mt-2 text-[10px] text-muted-foreground">
                                            Estimates use straight-line distance calculations. Actual travel times may vary.
                                        </p>
                                    </CardContent>
                                </Card>
                            )}

                            <div className="flex justify-between">
                                <Button type="button" variant="outline" onClick={() => setStep(2)}>Back</Button>
                                <Button type="button" onClick={() => setStep(4)}>
                                    Next: Vehicle & Driver
                                </Button>
                            </div>
                        </>
                    )}

                    {/* Step 4: Vehicle & Driver */}
                    {step === 4 && (
                        <>
                            <div className="grid gap-6 lg:grid-cols-2">
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2">
                                            <Car className="h-5 w-5" />
                                            Select Vehicle
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-3">
                                        <Select value={form.data.asset_id} onValueChange={(v) => form.setData('asset_id', v)}>
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select vehicle" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {filteredVehicles.map((v) => {
                                                    const compatible = isVehicleCompatible(v);
                                                    return (
                                                        <SelectItem key={v.id} value={String(v.id)}>
                                                            <span className="flex items-center gap-2">
                                                                {v.name}{v.asset_tag ? ` (${v.asset_tag})` : ''}
                                                                {v.seating_capacity && <span className="text-[10px] text-muted-foreground">{v.seating_capacity} seats</span>}
                                                                {!compatible && <Badge variant="destructive" className="text-[9px] h-4 ml-1">Incompatible</Badge>}
                                                            </span>
                                                        </SelectItem>
                                                    );
                                                })}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.asset_id && <p className="mt-1 text-xs text-destructive">{form.errors.asset_id}</p>}

                                        {/* Vehicle compatibility warning */}
                                        {form.data.asset_id && !isVehicleCompatible(filteredVehicles.find((v) => String(v.id) === form.data.asset_id) ?? {} as VehicleOption) && (
                                            <div className="flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50/50 px-3 py-2 dark:border-amber-800 dark:bg-amber-950/20">
                                                <AlertTriangle className="h-4 w-4 text-amber-600 shrink-0" />
                                                <p className="text-xs text-amber-700 dark:text-amber-400">
                                                    This vehicle does not meet all accessibility needs of selected residents.
                                                </p>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader>
                                        <CardTitle>Driver</CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-3">
                                        <Select value={form.data.driver_user_id} onValueChange={(v) => form.setData('driver_user_id', v)}>
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select driver" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {safeDrivers.map((d) => (
                                                    <SelectItem key={d.id} value={String(d.id)}>
                                                        {d.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.driver_user_id && <p className="mt-1 text-xs text-destructive">{form.errors.driver_user_id}</p>}

                                        <div className="rounded-lg border bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
                                            Created by: <span className="font-medium text-foreground">{auth_user?.name ?? 'Current user'}</span>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>

                            <div className="flex justify-between">
                                <Button type="button" variant="outline" onClick={() => setStep(3)}>Back</Button>
                                <Button type="button" onClick={() => setStep(5)}>
                                    Next: Risk Assessment
                                </Button>
                            </div>
                        </>
                    )}

                    {/* Step 5: Risk Assessment */}
                    {step === 5 && (
                        <>
                            <Card>
                                <CardHeader>
                                    <CardTitle>Risk Assessment & Notes</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div>
                                        <label className="text-sm font-medium">Risk Assessment</label>
                                        <textarea
                                            className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            rows={4}
                                            value={form.data.risk_assessment}
                                            onChange={(e) => form.setData('risk_assessment', e.target.value)}
                                            placeholder="Identify potential risks and mitigations for this outing..."
                                        />
                                    </div>
                                    <div>
                                        <label className="text-sm font-medium">Additional Notes</label>
                                        <textarea
                                            className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            rows={3}
                                            value={form.data.notes}
                                            onChange={(e) => form.setData('notes', e.target.value)}
                                            placeholder="Any additional notes about this outing..."
                                        />
                                    </div>
                                </CardContent>
                            </Card>

                            {/* Summary card */}
                            <Card className="border-purple-200 dark:border-purple-800">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm">Outing Summary</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Title</span>
                                        <span className="font-medium">{form.data.title || '---'}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Destination</span>
                                        <span className="font-medium">{form.data.destination || '---'}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Purpose</span>
                                        <span className="font-medium capitalize">{form.data.purpose || '---'}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Residents</span>
                                        <span className="font-medium">{selectedResidents.length}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Route Stops</span>
                                        <span className="font-medium">{stops.length}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Vehicle</span>
                                        <span className="font-medium">
                                            {form.data.asset_id ? (filteredVehicles.find((v) => String(v.id) === form.data.asset_id)?.name ?? '---') : '---'}
                                        </span>
                                    </div>
                                </CardContent>
                            </Card>

                            <div className="flex items-center justify-between">
                                <Button type="button" variant="outline" onClick={() => setStep(4)}>Back</Button>
                                <div className="flex items-center gap-2">
                                    <Button variant="outline" asChild>
                                        <Link href="/fleet-assets/outings">Cancel</Link>
                                    </Button>
                                    <Button type="submit" disabled={form.processing}>
                                        {form.processing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                                        Create Outing
                                    </Button>
                                </div>
                            </div>
                        </>
                    )}
                </form>
            </PageShell>
        </AppLayout>
    );
}
