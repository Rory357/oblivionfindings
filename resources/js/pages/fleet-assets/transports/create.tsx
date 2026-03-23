import PageHeader from '@/components/page-header';
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
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Calendar,
    Heart,
    Loader2,
    MapPin,
    Pill,
    Save,
    ShoppingBag,
    Stethoscope,
    Sun,
    Users,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { useCallback, useMemo, useState } from 'react';

type ClientMedication = {
    id: number;
    name: string;
    dosage: string | null;
    frequency: string | null;
    is_prn: boolean;
    controlled_drug: boolean;
    dose_times: string[] | null;
    route: string | null;
    instructions: string | null;
};

type ClientOption = {
    id: number;
    name: string;
};

type Props = {
    vehicles: Array<{ id: number; name: string; asset_tag?: string }>;
    recent_residents?: string[];
    clients?: ClientOption[];
    client_medications?: ClientMedication[];
    auth_user: { id: number; name: string };
};

const TRANSPORT_TYPES = [
    { value: 'medical', label: 'Medical', icon: Stethoscope, color: 'border-red-500 bg-red-50 text-red-800 dark:bg-red-900/30 dark:text-red-400 dark:border-red-700' },
    { value: 'appointment', label: 'Appointment', icon: Calendar, color: 'border-blue-500 bg-blue-50 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400 dark:border-blue-700' },
    { value: 'social', label: 'Social', icon: Users, color: 'border-green-500 bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-400 dark:border-green-700' },
    { value: 'shopping', label: 'Shopping', icon: ShoppingBag, color: 'border-purple-500 bg-purple-50 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400 dark:border-purple-700' },
    { value: 'community', label: 'Community', icon: MapPin, color: 'border-teal-500 bg-teal-50 text-teal-800 dark:bg-teal-900/30 dark:text-teal-400 dark:border-teal-700' },
    { value: 'respite', label: 'Respite', icon: Sun, color: 'border-orange-500 bg-orange-50 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400 dark:border-orange-700' },
    { value: 'activity', label: 'Activity', icon: Activity, color: 'border-cyan-500 bg-cyan-50 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-400 dark:border-cyan-700' },
    { value: 'other', label: 'Other', icon: Heart, color: 'border-gray-500 bg-gray-50 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400 dark:border-gray-700' },
];

export default function TransportCreate({ vehicles, recent_residents, clients, client_medications, auth_user }: Props) {
    const safeVehicles = vehicles ?? [];
    const safeRecentResidents = recent_residents ?? [];
    const safeClients = clients ?? [];
    const safeMedications = client_medications ?? [];

    const form = useForm({
        asset_id: '',
        resident_name: '',
        client_id: '',
        transport_type: '',
        pickup_location: '',
        dropoff_location: '',
        departed_at: new Date().toISOString().slice(0, 16),
        passengers_count: '1',
        supervisor_name: '',
        notes: '',
        medications: [] as Array<{ medication_id: number; medication_name: string; is_controlled_drug: boolean; witness_name: string }>,
    });

    const [showSuggestions, setShowSuggestions] = useState(false);
    const [selectedMedIds, setSelectedMedIds] = useState<Set<number>>(new Set());
    const [witnessNames, setWitnessNames] = useState<Record<number, string>>({});

    const filteredResidents = useMemo(() => {
        if (!form.data.resident_name || form.data.resident_name.length < 1) return [];
        const query = form.data.resident_name.toLowerCase();
        return safeRecentResidents.filter((r) => r.toLowerCase().includes(query)).slice(0, 8);
    }, [form.data.resident_name, safeRecentResidents]);

    const handleClientChange = useCallback((clientId: string) => {
        form.setData('client_id', clientId);
        // Reload page with client_id to fetch medications
        if (clientId) {
            router.visit('/fleet-assets/transports/create', {
                data: { client_id: clientId },
                preserveState: false,
                only: ['client_medications', 'clients', 'vehicles', 'recent_residents', 'auth_user'],
            });
        }
    }, []);

    const handleMedToggle = useCallback((med: ClientMedication) => {
        setSelectedMedIds((prev) => {
            const next = new Set(prev);
            if (next.has(med.id)) {
                next.delete(med.id);
            } else {
                next.add(med.id);
            }
            return next;
        });
    }, []);

    const handleSubmit = useCallback((e: React.FormEvent) => {
        e.preventDefault();
        // Build medications array from selected meds
        const medications = safeMedications
            .filter((m) => selectedMedIds.has(m.id))
            .map((m) => ({
                medication_id: m.id,
                medication_name: m.name + (m.dosage ? ` ${m.dosage}` : ''),
                is_controlled_drug: m.controlled_drug,
                witness_name: witnessNames[m.id] ?? '',
            }));
        form.setData('medications', medications);
        // Need to post after setData - use transform
        form.transform((data) => ({
            ...data,
            medications,
        })).post('/fleet-assets/transports');
    }, [form, selectedMedIds, witnessNames, safeMedications]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Transport Logs', href: '/fleet-assets/transports' },
                { title: 'Log Transport', href: '#' },
            ]}
        >
            <Head title="Log Transport" />
            <PageShell>
                <PageHeader
                    title="Log Resident Transport"
                    description="Record a resident transport trip."
                    backHref="/fleet-assets/transports"
                    backLabel="Back to Transport Logs"
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Transport Type */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Transport Type *</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                {TRANSPORT_TYPES.map((type) => {
                                    const IconComp = type.icon;
                                    return (
                                        <button
                                            key={type.value}
                                            type="button"
                                            onClick={() => form.setData('transport_type', type.value)}
                                            className={cn(
                                                "flex flex-col items-center gap-2 rounded-xl border-2 px-4 py-5 text-sm transition-all",
                                                form.data.transport_type === type.value
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
                            {form.errors.transport_type && <p className="mt-2 text-xs text-destructive">{form.errors.transport_type}</p>}
                        </CardContent>
                    </Card>

                    {/* Trip Details - 2 column */}
                    <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Vehicle & Resident</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            <div>
                                <label className="text-sm font-medium">Vehicle *</label>
                                <Select value={form.data.asset_id} onValueChange={(v) => form.setData('asset_id', v)}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select vehicle" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {safeVehicles.map((v) => (
                                            <SelectItem key={v.id} value={String(v.id)}>
                                                {v.name}{v.asset_tag ? ` (${v.asset_tag})` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.asset_id && <p className="mt-1 text-xs text-destructive">{form.errors.asset_id}</p>}
                            </div>

                            {/* Linked Client (for medication lookup) */}
                            {safeClients.length > 0 && (
                                <div>
                                    <label className="text-sm font-medium">Link to Resident (for medications)</label>
                                    <Select value={form.data.client_id} onValueChange={handleClientChange}>
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select resident (optional)" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {safeClients.map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>
                                                    {c.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            )}

                            <div className="relative">
                                <label className="text-sm font-medium">Resident Name *</label>
                                <Input
                                    value={form.data.resident_name}
                                    onChange={(e) => {
                                        form.setData('resident_name', e.target.value);
                                        setShowSuggestions(true);
                                    }}
                                    onFocus={() => setShowSuggestions(true)}
                                    onBlur={() => setTimeout(() => setShowSuggestions(false), 200)}
                                    placeholder="Enter resident name"
                                    autoComplete="off"
                                />
                                {showSuggestions && filteredResidents.length > 0 && (
                                    <div className="absolute z-10 mt-1 w-full rounded-md border bg-background shadow-lg">
                                        {filteredResidents.map((name) => (
                                            <button
                                                key={name}
                                                type="button"
                                                className="w-full px-3 py-2 text-left text-sm hover:bg-muted/50 first:rounded-t-md last:rounded-b-md"
                                                onMouseDown={(e) => {
                                                    e.preventDefault();
                                                    form.setData('resident_name', name);
                                                    setShowSuggestions(false);
                                                }}
                                            >
                                                {name}
                                            </button>
                                        ))}
                                    </div>
                                )}
                                {form.errors.resident_name && <p className="mt-1 text-xs text-destructive">{form.errors.resident_name}</p>}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Timing & Location</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            <div>
                                <label className="text-sm font-medium">Departure Time *</label>
                                <Input
                                    type="datetime-local"
                                    value={form.data.departed_at}
                                    onChange={(e) => form.setData('departed_at', e.target.value)}
                                />
                                {form.errors.departed_at && <p className="mt-1 text-xs text-destructive">{form.errors.departed_at}</p>}
                            </div>

                            <div>
                                <label className="text-sm font-medium">Passengers</label>
                                <Input
                                    type="number"
                                    min="1"
                                    max="20"
                                    value={form.data.passengers_count}
                                    onChange={(e) => form.setData('passengers_count', e.target.value)}
                                />
                                {form.errors.passengers_count && <p className="mt-1 text-xs text-destructive">{form.errors.passengers_count}</p>}
                            </div>

                            <div>
                                <label className="text-sm font-medium">Pickup Location</label>
                                <Input
                                    value={form.data.pickup_location}
                                    onChange={(e) => form.setData('pickup_location', e.target.value)}
                                    placeholder="Where are you picking up?"
                                />
                                {form.errors.pickup_location && <p className="mt-1 text-xs text-destructive">{form.errors.pickup_location}</p>}
                            </div>

                            <div>
                                <label className="text-sm font-medium">Dropoff Location</label>
                                <Input
                                    value={form.data.dropoff_location}
                                    onChange={(e) => form.setData('dropoff_location', e.target.value)}
                                    placeholder="Where are you dropping off?"
                                />
                                {form.errors.dropoff_location && <p className="mt-1 text-xs text-destructive">{form.errors.dropoff_location}</p>}
                            </div>

                            <div>
                                <label className="text-sm font-medium">Supervisor</label>
                                <Input
                                    value={form.data.supervisor_name}
                                    onChange={(e) => form.setData('supervisor_name', e.target.value)}
                                    placeholder="Supervising staff member"
                                />
                                {form.errors.supervisor_name && <p className="mt-1 text-xs text-destructive">{form.errors.supervisor_name}</p>}
                            </div>
                        </CardContent>
                    </Card>
                    </div>{/* end 2-col grid */}

                    {/* Medications Section */}
                    {safeMedications.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Pill className="h-5 w-5" />
                                    Medications for Transit
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="mb-4 text-sm text-muted-foreground">
                                    Select medications that need to be packed for this transport. PRN and scheduled medications for this resident are shown below.
                                </p>
                                <div className="space-y-3">
                                    {safeMedications.map((med) => {
                                        const isSelected = selectedMedIds.has(med.id);
                                        return (
                                            <div
                                                key={med.id}
                                                className={cn(
                                                    "rounded-lg border p-4 transition-all",
                                                    isSelected
                                                        ? "border-purple-300 bg-purple-50/50 dark:border-purple-700 dark:bg-purple-950/20"
                                                        : "border-border hover:border-muted-foreground/30"
                                                )}
                                            >
                                                <div className="flex items-start gap-3">
                                                    <input
                                                        type="checkbox"
                                                        checked={isSelected}
                                                        onChange={() => handleMedToggle(med)}
                                                        className="mt-1 h-4 w-4 rounded border-gray-300"
                                                    />
                                                    <div className="flex-1 min-w-0">
                                                        <div className="flex items-center gap-2 flex-wrap">
                                                            <span className="font-medium text-sm">{med.name}</span>
                                                            {med.dosage && (
                                                                <span className="text-xs text-muted-foreground">{med.dosage}</span>
                                                            )}
                                                            {med.is_prn ? (
                                                                <Badge variant="secondary" className="text-[10px]">PRN</Badge>
                                                            ) : (
                                                                <Badge variant="outline" className="text-[10px]">Scheduled</Badge>
                                                            )}
                                                            {med.controlled_drug && (
                                                                <Badge variant="destructive" className="text-[10px] flex items-center gap-1">
                                                                    <AlertTriangle className="h-3 w-3" />
                                                                    Controlled Drug
                                                                </Badge>
                                                            )}
                                                        </div>
                                                        {med.route && (
                                                            <p className="text-xs text-muted-foreground mt-0.5">Route: {med.route}</p>
                                                        )}
                                                        {med.instructions && (
                                                            <p className="text-xs text-muted-foreground mt-0.5">{med.instructions}</p>
                                                        )}
                                                    </div>
                                                </div>

                                                {/* Witness field for controlled drugs */}
                                                {isSelected && med.controlled_drug && (
                                                    <div className="mt-3 ml-7">
                                                        <label className="text-xs font-medium text-red-700 dark:text-red-400">
                                                            Witness Required for Controlled Drug *
                                                        </label>
                                                        <Input
                                                            value={witnessNames[med.id] ?? ''}
                                                            onChange={(e) =>
                                                                setWitnessNames((prev) => ({
                                                                    ...prev,
                                                                    [med.id]: e.target.value,
                                                                }))
                                                            }
                                                            placeholder="Name of witness"
                                                            className="mt-1"
                                                        />
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                                {selectedMedIds.size > 0 && (
                                    <div className="mt-3 rounded-md bg-purple-50 px-3 py-2 text-sm text-purple-800 dark:bg-purple-950/30 dark:text-purple-300">
                                        {selectedMedIds.size} medication{selectedMedIds.size !== 1 ? 's' : ''} selected for packing
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle>Notes</CardTitle>
                        </CardHeader>
                        <CardContent>
                                <textarea
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    rows={3}
                                    value={form.data.notes}
                                    onChange={(e) => form.setData('notes', e.target.value)}
                                    placeholder="Any additional notes about this transport..."
                                />
                                {form.errors.notes && <p className="mt-1 text-xs text-destructive">{form.errors.notes}</p>}
                        </CardContent>
                    </Card>

                    {/* Driver Info */}
                    <div className="flex items-center gap-2 rounded-lg border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
                        Driver will be set to: <span className="font-medium text-foreground">{auth_user?.name ?? 'Current user'}</span>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                            Log Transport
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/fleet-assets/transports">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
