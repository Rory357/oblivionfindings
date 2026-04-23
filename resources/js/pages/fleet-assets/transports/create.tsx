import FleetHero from '@/components/fleet-hero';
import MedicationScanVerificationPanel from '@/components/medications/MedicationScanVerificationPanel';
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
import {
    emptyMedicationScanCapture,
    hasVerifiedMedicationScan,
    type MedicationScanCapture,
    type MedicationScanVerification,
} from '@/lib/medication-scan';
import { cn } from '@/lib/utils';
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
import { useCallback, useEffect, useMemo, useState } from 'react';

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
    scan_verification?: MedicationScanVerification | null;
};

type ClientOption = {
    id: number;
    name: string;
};

type ShiftOption = {
    id: number;
    client_id?: number | null;
    client_name?: string | null;
    staff_name?: string | null;
    starts_at?: string | null;
    ends_at?: string | null;
    status: string;
    shift_type?: string | null;
    location?: string | null;
    service_context?: string | null;
};

type Props = {
    vehicles: Array<{ id: number; name: string; asset_tag?: string }>;
    recent_residents?: string[];
    clients?: ClientOption[];
    client_medications?: ClientMedication[];
    shifts?: ShiftOption[];
    selected_shift_id?: number | null;
    auth_user: { id: number; name: string };
};

const TRANSPORT_TYPES = [
    {
        value: 'medical',
        label: 'Medical',
        icon: Stethoscope,
        color: 'border-red-500 bg-red-50 text-red-800 dark:bg-red-900/30 dark:text-red-400 dark:border-red-700',
    },
    {
        value: 'appointment',
        label: 'Appointment',
        icon: Calendar,
        color: 'border-blue-500 bg-blue-50 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400 dark:border-blue-700',
    },
    {
        value: 'social',
        label: 'Social',
        icon: Users,
        color: 'border-green-500 bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-400 dark:border-green-700',
    },
    {
        value: 'shopping',
        label: 'Shopping',
        icon: ShoppingBag,
        color: 'border-primary bg-primary/10 text-primary dark:bg-primary/30 dark:text-primary dark:border-primary',
    },
    {
        value: 'community',
        label: 'Community',
        icon: MapPin,
        color: 'border-teal-500 bg-teal-50 text-teal-800 dark:bg-teal-900/30 dark:text-teal-400 dark:border-teal-700',
    },
    {
        value: 'respite',
        label: 'Respite',
        icon: Sun,
        color: 'border-orange-500 bg-orange-50 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400 dark:border-orange-700',
    },
    {
        value: 'activity',
        label: 'Activity',
        icon: Activity,
        color: 'border-cyan-500 bg-cyan-50 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-400 dark:border-cyan-700',
    },
    {
        value: 'other',
        label: 'Other',
        icon: Heart,
        color: 'border-gray-500 bg-muted text-foreground dark:bg-muted/30 dark:text-muted-foreground dark:border-border',
    },
];

export default function TransportCreate({
    vehicles,
    recent_residents,
    clients,
    client_medications,
    shifts,
    selected_shift_id,
    auth_user,
}: Props) {
    const safeVehicles = vehicles ?? [];
    const safeRecentResidents = recent_residents ?? [];
    const safeClients = clients ?? [];
    const safeMedications = client_medications ?? [];
    const safeShifts = shifts ?? [];

    const form = useForm({
        asset_id: '',
        shift_id: selected_shift_id ? String(selected_shift_id) : '',
        resident_name: '',
        client_id: '',
        transport_type: '',
        pickup_location: '',
        dropoff_location: '',
        departed_at: new Date().toISOString().slice(0, 16),
        passengers_count: '1',
        supervisor_name: '',
        notes: '',
        medications: [] as Array<{
            medication_id: number;
            medication_name: string;
            is_controlled_drug: boolean;
            witness_name: string;
            scan_code: string | null;
            scan_source: 'manual' | 'scanner' | null;
            scan_verified: boolean;
            scan_match_source: string | null;
        }>,
    });

    const [showSuggestions, setShowSuggestions] = useState(false);
    const [selectedMedIds, setSelectedMedIds] = useState<Set<number>>(
        new Set(),
    );
    const [witnessNames, setWitnessNames] = useState<Record<number, string>>(
        {},
    );
    const [scanCaptures, setScanCaptures] = useState<
        Record<number, MedicationScanCapture>
    >({});
    const [submitMode, setSubmitMode] = useState<'transport' | 'pack'>(
        'transport',
    );

    const selectedShift = useMemo(
        () =>
            safeShifts.find(
                (shift) => String(shift.id) === String(form.data.shift_id),
            ) ?? null,
        [safeShifts, form.data.shift_id],
    );

    useEffect(() => {
        if (!selectedShift) return;

        if (!form.data.client_id && selectedShift.client_id) {
            form.setData('client_id', String(selectedShift.client_id));
        }
        if (!form.data.resident_name && selectedShift.client_name) {
            form.setData('resident_name', selectedShift.client_name);
        }
        if (!form.data.pickup_location && selectedShift.location) {
            form.setData('pickup_location', selectedShift.location);
        }
    }, [selectedShift]);

    useEffect(() => {
        const availableMedicationIds = new Set(
            safeMedications.map((medication) => medication.id),
        );

        setSelectedMedIds((current) => {
            const next = new Set(
                [...current].filter((id) => availableMedicationIds.has(id)),
            );

            return next.size === current.size ? current : next;
        });

        setScanCaptures((current) =>
            Object.fromEntries(
                Object.entries(current).filter(([medicationId]) =>
                    availableMedicationIds.has(Number(medicationId)),
                ),
            ),
        );
        setWitnessNames((current) =>
            Object.fromEntries(
                Object.entries(current).filter(([medicationId]) =>
                    availableMedicationIds.has(Number(medicationId)),
                ),
            ),
        );
    }, [safeMedications]);

    const filteredResidents = useMemo(() => {
        if (!form.data.resident_name || form.data.resident_name.length < 1)
            return [];
        const query = form.data.resident_name.toLowerCase();
        return safeRecentResidents
            .filter((r) => r.toLowerCase().includes(query))
            .slice(0, 8);
    }, [form.data.resident_name, safeRecentResidents]);

    const handleClientChange = useCallback((clientId: string) => {
        form.setData('client_id', clientId);
        form.setData('shift_id', '');
        // Reload page with client_id to fetch medications
        router.visit('/fleet-assets/transports/create', {
            data: { client_id: clientId || null },
            preserveState: true,
            preserveScroll: true,
            only: [
                'client_medications',
                'clients',
                'vehicles',
                'recent_residents',
                'auth_user',
                'shifts',
                'selected_shift_id',
            ],
        });
    }, [form]);

    const handleShiftChange = useCallback(
        (shiftId: string) => {
            form.setData('shift_id', shiftId);

            const nextShift = safeShifts.find(
                (shift) => String(shift.id) === String(shiftId),
            );

            if (nextShift) {
                form.setData(
                    'client_id',
                    nextShift.client_id ? String(nextShift.client_id) : '',
                );
                form.setData(
                    'resident_name',
                    nextShift.client_name ?? form.data.resident_name,
                );
                form.setData(
                    'pickup_location',
                    nextShift.location ?? form.data.pickup_location,
                );
            }

            router.visit('/fleet-assets/transports/create', {
                data: {
                    shift_id: shiftId || null,
                    client_id: nextShift?.client_id ?? null,
                },
                preserveState: true,
                preserveScroll: true,
                only: [
                    'client_medications',
                    'clients',
                    'vehicles',
                    'recent_residents',
                    'auth_user',
                    'shifts',
                    'selected_shift_id',
                ],
            });
        },
        [safeShifts, form.data.resident_name, form.data.pickup_location],
    );

    const handleMedToggle = useCallback((med: ClientMedication) => {
        setSelectedMedIds((prev) => {
            const next = new Set(prev);
            if (next.has(med.id)) {
                next.delete(med.id);
                form.clearErrors('medications');
                setScanCaptures((current) => {
                    const updated = { ...current };
                    delete updated[med.id];

                    return updated;
                });
            } else {
                next.add(med.id);
            }
            return next;
        });
    }, []);

    const submitTransport = useCallback(
        (mode: 'transport' | 'pack') => {
            const medications =
                mode === 'pack'
                    ? safeMedications
                          .filter((m) => selectedMedIds.has(m.id))
                          .map((m) => ({
                              medication_id: m.id,
                              medication_name:
                                  m.name + (m.dosage ? ` ${m.dosage}` : ''),
                              is_controlled_drug: m.controlled_drug,
                              witness_name: witnessNames[m.id] ?? '',
                              scan_code:
                                  scanCaptures[m.id]?.code?.trim() || null,
                              scan_source: hasVerifiedMedicationScan(
                                  scanCaptures[m.id] ??
                                      emptyMedicationScanCapture(),
                              )
                                  ? scanCaptures[m.id]?.scanSource ?? 'manual'
                                  : null,
                              scan_verified: hasVerifiedMedicationScan(
                                  scanCaptures[m.id] ??
                                      emptyMedicationScanCapture(),
                              ),
                              scan_match_source: hasVerifiedMedicationScan(
                                  scanCaptures[m.id] ??
                                      emptyMedicationScanCapture(),
                              )
                                  ? scanCaptures[m.id]?.matchSource ?? null
                                  : null,
                          }))
                    : [];

            if (mode === 'pack') {
                const missingWitness = medications.find(
                    (m) => m.is_controlled_drug && !m.witness_name.trim(),
                );
                if (missingWitness) {
                    form.setError(
                        'medications',
                        'All controlled drugs require a witness name.',
                    );
                    return;
                }

                const missingScan = safeMedications
                    .filter((m) => selectedMedIds.has(m.id))
                    .find(
                        (medication) =>
                            medication.scan_verification &&
                            !hasVerifiedMedicationScan(
                                scanCaptures[medication.id] ??
                                    emptyMedicationScanCapture(),
                            ),
                    );

                if (missingScan) {
                    form.setError(
                        'medications',
                        `Verify the medication code for ${missingScan.name} before logging this transport.`,
                    );
                    return;
                }
            } else {
                form.clearErrors('medications');
            }

            setSubmitMode(mode);
            form.setData('medications', medications);
            form.transform((data) => ({
                ...data,
                medications,
            }));
            form.post('/fleet-assets/transports', {
                preserveScroll: true,
                onFinish: () => {
                    form.transform((data) => data);
                    setSubmitMode('transport');
                },
            });
        },
        [form, safeMedications, scanCaptures, selectedMedIds, witnessNames],
    );

    const handleSubmit = useCallback(
        (e: React.FormEvent) => {
            e.preventDefault();
            submitTransport('transport');
        },
        [submitTransport],
    );

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
                <FleetHero
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
                                            onClick={() =>
                                                form.setData(
                                                    'transport_type',
                                                    type.value,
                                                )
                                            }
                                            className={cn(
                                                'flex flex-col items-center gap-2 rounded-xl border-2 px-4 py-5 text-sm transition-all',
                                                form.data.transport_type ===
                                                    type.value
                                                    ? `${type.color} shadow-md`
                                                    : 'border-transparent bg-muted/30 text-muted-foreground hover:border-muted-foreground/20 hover:bg-muted/60',
                                            )}
                                        >
                                            <IconComp className="h-7 w-7" />
                                            <span className="font-semibold">
                                                {type.label}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                            {form.errors.transport_type && (
                                <p className="mt-2 text-xs text-destructive">
                                    {form.errors.transport_type}
                                </p>
                            )}
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
                                    <label className="text-sm font-medium">
                                        Vehicle *
                                    </label>
                                    <Select
                                        value={form.data.asset_id}
                                        onValueChange={(v) =>
                                            form.setData('asset_id', v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select vehicle" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {safeVehicles.map((v) => (
                                                <SelectItem
                                                    key={v.id}
                                                    value={String(v.id)}
                                                >
                                                    {v.name}
                                                    {v.asset_tag
                                                        ? ` (${v.asset_tag})`
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

                                {/* Linked Client (for medication lookup) */}
                                {safeShifts.length > 0 && (
                                    <div>
                                        <label className="text-sm font-medium">
                                            Link to Shift
                                        </label>
                                        <Select
                                            value={form.data.shift_id || 'none'}
                                            onValueChange={(value) =>
                                                handleShiftChange(
                                                    value === 'none'
                                                        ? ''
                                                        : value,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select linked shift (optional)" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="none">
                                                    No linked shift
                                                </SelectItem>
                                                {safeShifts.map((shift) => (
                                                    <SelectItem
                                                        key={shift.id}
                                                        value={String(shift.id)}
                                                    >
                                                        #{shift.id} ·{' '}
                                                        {shift.client_name ??
                                                            'No resident'}{' '}
                                                        ·{' '}
                                                        {shift.starts_at
                                                            ? new Date(
                                                                  shift.starts_at,
                                                              ).toLocaleString(
                                                                  'en-NZ',
                                                                  {
                                                                      weekday:
                                                                          'short',
                                                                      day: 'numeric',
                                                                      month: 'short',
                                                                      hour: '2-digit',
                                                                      minute: '2-digit',
                                                                  },
                                                              )
                                                            : 'No time'}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}

                                {safeClients.length > 0 && (
                                    <div>
                                        <label className="text-sm font-medium">
                                            Link to Resident (for medications)
                                        </label>
                                        <Select
                                            value={form.data.client_id}
                                            onValueChange={handleClientChange}
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select resident (optional)" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {safeClients.map((c) => (
                                                    <SelectItem
                                                        key={c.id}
                                                        value={String(c.id)}
                                                    >
                                                        {c.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}

                                {selectedShift && (
                                    <div className="rounded-lg border border-blue-200 bg-blue-50/60 p-3 text-sm dark:border-blue-900 dark:bg-blue-950/20">
                                        <p className="font-medium text-blue-900 dark:text-blue-200">
                                            Linked shift #{selectedShift.id}
                                        </p>
                                        <p className="mt-1 text-xs text-blue-800 dark:text-blue-300">
                                            {(
                                                selectedShift.shift_type ??
                                                'standard'
                                            ).replace(/_/g, ' ')}
                                            {selectedShift.service_context
                                                ? ` · ${selectedShift.service_context}`
                                                : ''}
                                            {selectedShift.location
                                                ? ` · ${selectedShift.location}`
                                                : ''}
                                        </p>
                                        <p className="text-xs text-blue-700 dark:text-blue-400">
                                            {selectedShift.client_name ??
                                                'No resident'}
                                            {selectedShift.staff_name
                                                ? ` · ${selectedShift.staff_name}`
                                                : ''}
                                        </p>
                                    </div>
                                )}

                                <div className="relative">
                                    <label className="text-sm font-medium">
                                        Resident Name *
                                    </label>
                                    <Input
                                        value={form.data.resident_name}
                                        onChange={(e) => {
                                            form.setData(
                                                'resident_name',
                                                e.target.value,
                                            );
                                            setShowSuggestions(true);
                                        }}
                                        onFocus={() => setShowSuggestions(true)}
                                        onBlur={() =>
                                            setTimeout(
                                                () => setShowSuggestions(false),
                                                200,
                                            )
                                        }
                                        placeholder="Enter resident name"
                                        autoComplete="off"
                                    />
                                    {showSuggestions &&
                                        filteredResidents.length > 0 && (
                                            <div className="absolute z-10 mt-1 w-full rounded-md border bg-background shadow-lg">
                                                {filteredResidents.map(
                                                    (name) => (
                                                        <button
                                                            key={name}
                                                            type="button"
                                                            className="w-full px-3 py-2 text-left text-sm first:rounded-t-md last:rounded-b-md hover:bg-muted/50"
                                                            onMouseDown={(
                                                                e,
                                                            ) => {
                                                                e.preventDefault();
                                                                form.setData(
                                                                    'resident_name',
                                                                    name,
                                                                );
                                                                setShowSuggestions(
                                                                    false,
                                                                );
                                                            }}
                                                        >
                                                            {name}
                                                        </button>
                                                    ),
                                                )}
                                            </div>
                                        )}
                                    {form.errors.resident_name && (
                                        <p className="mt-1 text-xs text-destructive">
                                            {form.errors.resident_name}
                                        </p>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Timing & Location</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4">
                                <div>
                                    <label className="text-sm font-medium">
                                        Departure Time *
                                    </label>
                                    <Input
                                        type="datetime-local"
                                        value={form.data.departed_at}
                                        onChange={(e) =>
                                            form.setData(
                                                'departed_at',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {form.errors.departed_at && (
                                        <p className="mt-1 text-xs text-destructive">
                                            {form.errors.departed_at}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label className="text-sm font-medium">
                                        Passengers
                                    </label>
                                    <Input
                                        type="number"
                                        min="1"
                                        max="20"
                                        value={form.data.passengers_count}
                                        onChange={(e) =>
                                            form.setData(
                                                'passengers_count',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {form.errors.passengers_count && (
                                        <p className="mt-1 text-xs text-destructive">
                                            {form.errors.passengers_count}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label className="text-sm font-medium">
                                        Pickup Location
                                    </label>
                                    <Input
                                        value={form.data.pickup_location}
                                        onChange={(e) =>
                                            form.setData(
                                                'pickup_location',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Where are you picking up?"
                                    />
                                    {form.errors.pickup_location && (
                                        <p className="mt-1 text-xs text-destructive">
                                            {form.errors.pickup_location}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label className="text-sm font-medium">
                                        Dropoff Location
                                    </label>
                                    <Input
                                        value={form.data.dropoff_location}
                                        onChange={(e) =>
                                            form.setData(
                                                'dropoff_location',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Where are you dropping off?"
                                    />
                                    {form.errors.dropoff_location && (
                                        <p className="mt-1 text-xs text-destructive">
                                            {form.errors.dropoff_location}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label className="text-sm font-medium">
                                        Supervisor
                                    </label>
                                    <Input
                                        value={form.data.supervisor_name}
                                        onChange={(e) =>
                                            form.setData(
                                                'supervisor_name',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Supervising staff member"
                                    />
                                    {form.errors.supervisor_name && (
                                        <p className="mt-1 text-xs text-destructive">
                                            {form.errors.supervisor_name}
                                        </p>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                    {/* end 2-col grid */}

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
                                    Select medications that need to be packed
                                    for this transport. PRN and scheduled
                                    medications for this resident are shown
                                    below.
                                </p>
                                <div className="space-y-3">
                                    {safeMedications.map((med) => {
                                        const isSelected = selectedMedIds.has(
                                            med.id,
                                        );
                                        return (
                                            <div
                                                key={med.id}
                                                className={cn(
                                                    'rounded-lg border p-4 transition-all',
                                                    isSelected
                                                        ? 'border-primary bg-primary/10/50 dark:border-primary dark:bg-primary/20'
                                                        : 'border-border hover:border-muted-foreground/30',
                                                )}
                                            >
                                                <div className="flex items-start gap-3">
                                                    <input
                                                        type="checkbox"
                                                        checked={isSelected}
                                                        onChange={() =>
                                                            handleMedToggle(med)
                                                        }
                                                        className="mt-1 h-4 w-4 rounded border-border"
                                                    />
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="text-sm font-medium">
                                                                {med.name}
                                                            </span>
                                                            {med.dosage && (
                                                                <span className="text-xs text-muted-foreground">
                                                                    {med.dosage}
                                                                </span>
                                                            )}
                                                            {med.is_prn ? (
                                                                <Badge
                                                                    variant="secondary"
                                                                    className="text-[10px]"
                                                                >
                                                                    PRN
                                                                </Badge>
                                                            ) : (
                                                                <Badge
                                                                    variant="outline"
                                                                    className="text-[10px]"
                                                                >
                                                                    Scheduled
                                                                </Badge>
                                                            )}
                                                            {med.controlled_drug && (
                                                                <Badge
                                                                    variant="destructive"
                                                                    className="flex items-center gap-1 text-[10px]"
                                                                >
                                                                    <AlertTriangle className="h-3 w-3" />
                                                                    Controlled
                                                                    Drug
                                                                </Badge>
                                                            )}
                                                        </div>
                                                        {med.route && (
                                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                                Route:{' '}
                                                                {med.route}
                                                            </p>
                                                        )}
                                                        {med.instructions && (
                                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                                {
                                                                    med.instructions
                                                                }
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>

                                                {/* Witness field for controlled drugs */}
                                                {isSelected &&
                                                    med.controlled_drug && (
                                                        <div className="mt-3 ml-7">
                                                            <label className="text-xs font-medium text-red-700 dark:text-red-400">
                                                                Witness Required
                                                                for Controlled
                                                                Drug *
                                                            </label>
                                                            <Input
                                                                value={
                                                                    witnessNames[
                                                                        med.id
                                                                    ] ?? ''
                                                                }
                                                                onChange={(e) => {
                                                                    form.clearErrors(
                                                                        'medications',
                                                                    );
                                                                    setWitnessNames(
                                                                        (
                                                                            prev,
                                                                        ) => ({
                                                                            ...prev,
                                                                            [med.id]:
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        }),
                                                                    );
                                                                }}
                                                                placeholder="Name of witness"
                                                                className="mt-1"
                                                            />
                                                        </div>
                                                    )}

                                                {isSelected && (
                                                    <div className="mt-3 ml-7">
                                                        <MedicationScanVerificationPanel
                                                            clientId={
                                                                form.data.client_id
                                                                    ? Number(
                                                                          form
                                                                              .data
                                                                              .client_id,
                                                                      )
                                                                    : null
                                                            }
                                                            medicationId={
                                                                med.id
                                                            }
                                                            scanVerification={
                                                                med.scan_verification
                                                            }
                                                            resetKey={`${form.data.client_id}-${med.id}-${isSelected}`}
                                                            requirementText="Verification is required before packing this medication for transit."
                                                            onChange={(
                                                                capture,
                                                            ) => {
                                                                form.clearErrors(
                                                                    'medications',
                                                                );
                                                                setScanCaptures(
                                                                    (
                                                                        current,
                                                                    ) => ({
                                                                        ...current,
                                                                        [med.id]:
                                                                            capture,
                                                                    }),
                                                                );
                                                            }}
                                                        />
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                                {selectedMedIds.size > 0 && (
                                    <div className="mt-3 rounded-md bg-primary/10 px-3 py-2 text-sm text-primary dark:bg-primary/30 dark:text-primary/70">
                                        {selectedMedIds.size} medication
                                        {selectedMedIds.size !== 1
                                            ? 's'
                                            : ''}{' '}
                                        selected for packing
                                    </div>
                                )}
                                {selectedMedIds.size > 0 && (
                                    <p className="mt-3 text-sm text-muted-foreground">
                                        You can create the transport first and
                                        continue medication packing from the
                                        transport detail screen, or pack the
                                        selected medications immediately as
                                        part of this submit.
                                    </p>
                                )}
                                {form.errors.medications && (
                                    <p className="mt-3 text-sm text-destructive">
                                        {form.errors.medications}
                                    </p>
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
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                                placeholder="Any additional notes about this transport..."
                            />
                            {form.errors.notes && (
                                <p className="mt-1 text-xs text-destructive">
                                    {form.errors.notes}
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Driver Info */}
                    <div className="flex items-center gap-2 rounded-lg border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
                        Driver will be set to:{' '}
                        <span className="font-medium text-foreground">
                            {auth_user?.name ?? 'Current user'}
                        </span>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <Save className="mr-2 h-4 w-4" />
                            )}
                            {submitMode === 'pack'
                                ? 'Creating Transport...'
                                : selectedMedIds.size > 0
                                  ? 'Create Transport Only'
                                  : 'Log Transport'}
                        </Button>
                        {selectedMedIds.size > 0 && (
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={form.processing}
                                onClick={() => submitTransport('pack')}
                            >
                                {form.processing && submitMode === 'pack' ? (
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                ) : (
                                    <Pill className="mr-2 h-4 w-4" />
                                )}
                                Create and Pack Selected Medications
                            </Button>
                        )}
                        <Button variant="outline" asChild>
                            <Link href="/fleet-assets/transports">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
