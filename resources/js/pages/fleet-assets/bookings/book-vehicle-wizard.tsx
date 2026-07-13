/* Book-vehicle wizard — the former full-page bookings/create.tsx folded into a
 * WizardShell modal on the bookings index. Every field and the live
 * conflict-check behaviour (500ms debounce, partial reload of the
 * booking_conflicts / booking_vehicle_status / booking_vehicle_bookings props
 * with the same check_asset_id / check_starts_at / check_ends_at params) are
 * preserved from the retired page. POSTs to the existing store route. */
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
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
import { formatDate, formatDateTime } from '@/lib/fleet-utils';
import { toDateInput } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    CalendarClock,
    Car,
    ClipboardList,
    Info,
    Loader2,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

export type BookingConflict = {
    id: number;
    user_name: string;
    purpose: string;
    starts_at: string | null;
    ends_at: string | null;
    status: string;
};

export type WizardVehicleBooking = {
    id: number;
    user_name: string;
    purpose: string;
    starts_at: string | null;
    ends_at: string | null;
    status: string;
};

export type WizardClientOption = {
    id: number;
    name: string;
    transport_needs?: {
        wheelchair_ramp?: boolean;
        hoist?: boolean;
        child_seat?: boolean;
        medical_storage?: boolean;
    } | null;
};

export type WizardVehicleOption = {
    id: number;
    name: string;
    asset_tag?: string;
    status?: string;
    home_site?: { id: number; name: string } | null;
    has_wheelchair_ramp?: boolean;
    has_hoist?: boolean;
    has_child_seat_anchors?: boolean;
    has_medical_storage?: boolean;
    seating_capacity?: number | null;
};

export type BookingWizardOptions = {
    vehicles: WizardVehicleOption[];
    sites: Array<{ id: number; name: string }>;
    clients: WizardClientOption[];
};

const STEPS: readonly WizardStep[] = [
    { key: 'vehicle', label: 'Vehicle & when', blurb: 'Pick a vehicle and dates', icon: Car },
    { key: 'details', label: 'Purpose & passengers', blurb: 'Trip details', icon: Users },
    { key: 'review', label: 'Review', blurb: 'Check and submit', icon: ClipboardList },
];

function statusBadgeVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'active': return 'default';
        case 'maintenance': return 'outline';
        case 'retired': return 'secondary';
        default: return 'outline';
    }
}

/* Month-at-a-glance availability grid — ported unchanged from the create page. */
function AvailabilityCalendar({ bookings, selectedStart, selectedEnd }: {
    bookings: Array<{ starts_at: string | null; ends_at: string | null; purpose: string; status: string; user_name: string }>;
    selectedStart: string;
    selectedEnd: string;
}) {
    const today = new Date();
    const year = today.getFullYear();
    const month = today.getMonth();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const firstDay = new Date(year, month, 1).getDay(); // 0=Sun

    type DayCell = {
        day: number;
        bookings: typeof bookings;
        isSelected: boolean;
        isToday: boolean;
        date: string;
    };

    const days: (DayCell | null)[] = [];
    for (let i = 0; i < firstDay; i++) days.push(null);
    for (let d = 1; d <= daysInMonth; d++) {
        const date = new Date(year, month, d);
        const dateStr = toDateInput(date);

        const dayBookings = bookings.filter(b => {
            if (!b.starts_at || !b.ends_at) return false;
            const start = toDateInput(b.starts_at);
            const end = toDateInput(b.ends_at);
            return dateStr >= start && dateStr <= end;
        });

        const isSelected = !!(selectedStart && selectedEnd && dateStr >= selectedStart.split('T')[0] && dateStr <= selectedEnd.split('T')[0]);
        const isToday = dateStr === toDateInput(new Date());

        days.push({ day: d, bookings: dayBookings, isSelected, isToday, date: dateStr });
    }

    return (
        <div>
            <div className="mb-2 text-center text-sm font-medium">
                {today.toLocaleString('default', { month: 'long', year: 'numeric' })}
            </div>
            <div className="mb-1 grid grid-cols-7 text-center text-[10px] text-muted-foreground">
                {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(d => <div key={d}>{d}</div>)}
            </div>
            <div className="grid grid-cols-7 gap-0.5">
                {days.map((cell, i) => {
                    if (!cell) return <div key={i} />;
                    const hasBooking = cell.bookings.length > 0;
                    return (
                        <div key={i} className={cn(
                            'relative flex h-8 items-center justify-center rounded-md text-xs transition-colors',
                            cell.isToday && 'font-bold ring-1 ring-ring',
                            cell.isSelected && 'bg-primary/10 text-primary dark:bg-primary/40 dark:text-primary/70',
                            hasBooking && !cell.isSelected && 'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
                            !hasBooking && !cell.isSelected && 'hover:bg-muted/50',
                        )} title={hasBooking ? cell.bookings.map(b => `${b.purpose} (${b.user_name})`).join(', ') : ''}>
                            {cell.day}
                            {hasBooking && <span className="absolute bottom-0.5 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full bg-status-critical" />}
                        </div>
                    );
                })}
            </div>
            <div className="mt-2 flex gap-3 text-[10px] text-muted-foreground">
                <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-primary" /> Your selection</span>
                <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-status-critical" /> Booked</span>
                <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-white ring-1 ring-ring" /> Today</span>
            </div>
        </div>
    );
}

function Field({ label, required, error, children, className }: {
    label: string;
    required?: boolean;
    error?: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <div className={className}>
            <label className="text-sm font-medium">
                {label}
                {required ? ' *' : ''}
            </label>
            <div className="mt-1">{children}</div>
            {error && <p className="mt-1 text-xs text-destructive">{error}</p>}
        </div>
    );
}

export function BookVehicleWizard({
    open,
    onClose,
    options,
    conflicts,
    vehicleStatus,
    vehicleBookings,
    initialVehicleId,
}: {
    open: boolean;
    onClose: () => void;
    /** Undefined until the deferred `booking_options` prop has been fetched. */
    options: BookingWizardOptions | null | undefined;
    conflicts: BookingConflict[] | undefined;
    vehicleStatus: string | null | undefined;
    vehicleBookings: WizardVehicleBooking[] | undefined;
    /** Pre-selects a vehicle (dashboard per-vehicle "Book" arrives via ?new=1&asset_id=…). */
    initialVehicleId?: string | null;
}) {
    const { labels } = usePage().props as { labels?: Record<string, string> };
    const clientSingular = labels?.['client.singular'] ?? 'Client';

    const safeVehicles = options?.vehicles ?? [];
    const safeSites = options?.sites ?? [];
    const safeClients = options?.clients ?? [];
    const safeConflicts = conflicts ?? [];
    const safeVehicleBookings = vehicleBookings ?? [];
    const assignedVehicles = safeVehicles.filter((v) => v.home_site);
    const poolVehicles = safeVehicles.filter((v) => !v.home_site);

    const [step, setStep] = useState(0);

    // Baked into the form defaults so the open-reset below keeps the
    // pre-selection, and the vehicle-selected effects (availability fetch)
    // fire as soon as the modal opens.
    const form = useForm({
        asset_id: initialVehicleId ?? '',
        client_id: '',
        purpose: '',
        destination: '',
        starts_at: '',
        ends_at: '',
        passengers: '',
        pickup_site_id: '',
        return_site_id: '',
        notes: '',
    });

    // Reset the flow whenever the modal reopens.
    useEffect(() => {
        if (open) {
            setStep(0);
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    // Fetch the deferred option lists the first time the modal opens.
    useEffect(() => {
        if (open && !options) {
            router.reload({ only: ['booking_options'] });
        }
    }, [open, options]);

    // Client transport needs matching
    const selectedClient = safeClients.find((c) => String(c.id) === form.data.client_id) ?? null;
    const selectedVehicle = safeVehicles.find((v) => String(v.id) === form.data.asset_id) ?? null;

    const compatibilityWarnings: string[] = [];
    if (selectedClient?.transport_needs && selectedVehicle) {
        const needs = selectedClient.transport_needs;
        if (needs.wheelchair_ramp && !selectedVehicle.has_wheelchair_ramp) {
            compatibilityWarnings.push(`${clientSingular} requires wheelchair ramp, but this vehicle does not have one.`);
        }
        if (needs.hoist && !selectedVehicle.has_hoist) {
            compatibilityWarnings.push(`${clientSingular} requires a hoist, but this vehicle does not have one.`);
        }
        if (needs.child_seat && !selectedVehicle.has_child_seat_anchors) {
            compatibilityWarnings.push(`${clientSingular} requires child seat anchors, but this vehicle does not have them.`);
        }
        if (needs.medical_storage && !selectedVehicle.has_medical_storage) {
            compatibilityWarnings.push(`${clientSingular} requires medical storage, but this vehicle does not have it.`);
        }
    }

    // Debounced conflict check — same 500ms debounce + partial reload the
    // create page used, now pointed at the index route's wizard props.
    const checkTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const checkAvailability = useCallback(() => {
        if (form.data.asset_id && form.data.starts_at && form.data.ends_at) {
            if (checkTimerRef.current) clearTimeout(checkTimerRef.current);
            checkTimerRef.current = setTimeout(() => {
                router.reload({
                    data: {
                        check_asset_id: form.data.asset_id,
                        check_starts_at: form.data.starts_at,
                        check_ends_at: form.data.ends_at,
                    },
                    only: ['booking_conflicts', 'booking_vehicle_status', 'booking_vehicle_bookings'],
                });
            }, 500);
        }
    }, [form.data.asset_id, form.data.starts_at, form.data.ends_at]);

    useEffect(() => {
        if (!open) return;
        checkAvailability();
        return () => {
            if (checkTimerRef.current) clearTimeout(checkTimerRef.current);
        };
    }, [open, checkAvailability]);

    // Auto-fetch bookings when vehicle changes (even without dates selected)
    useEffect(() => {
        if (open && form.data.asset_id) {
            router.reload({
                data: {
                    check_asset_id: form.data.asset_id,
                    check_starts_at: form.data.starts_at || undefined,
                    check_ends_at: form.data.ends_at || undefined,
                },
                only: ['booking_conflicts', 'booking_vehicle_status', 'booking_vehicle_bookings'],
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, form.data.asset_id]);

    const stepOneComplete = !!(form.data.asset_id && form.data.starts_at && form.data.ends_at);
    const stepTwoComplete = form.data.purpose.trim().length > 0;
    const canContinue = step === 0 ? stepOneComplete : step === 1 ? stepTwoComplete : true;

    const submit = () => {
        form.post('/fleet-assets/bookings', {
            // On success the server redirects to the new booking's detail page,
            // so no success pane is needed here.
            onError: (errors) => {
                if (errors.asset_id || errors.starts_at || errors.ends_at) setStep(0);
                else if (errors.purpose || errors.destination || errors.passengers || errors.pickup_site_id || errors.return_site_id || errors.notes) setStep(1);
            },
        });
    };

    const vehicleLabel = selectedVehicle
        ? `${selectedVehicle.name}${selectedVehicle.asset_tag ? ` (${selectedVehicle.asset_tag})` : ''}`
        : '';
    const siteName = (id: string) => safeSites.find((s) => String(s.id) === id)?.name ?? '';

    const serverErrors = Object.entries(form.errors) as Array<[string, string]>;

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title="Book vehicle"
            description="Request a vehicle booking for a trip or task."
            railIcon={CalendarClock}
            railTitle="Book vehicle"
            railSub="Request a booking"
            steps={STEPS}
            stepIndex={step}
            onStepClick={(i) => {
                // Steps are freely revisitable backwards; jumping forward
                // requires every earlier step's gate to be satisfied.
                const gates = [stepOneComplete, stepTwoComplete, true];
                if (i <= step || gates.slice(0, i).every(Boolean)) setStep(i);
            }}
            footerStart={
                step === 0 && stepOneComplete && safeConflicts.length === 0 ? (
                    <span className="inline-flex items-center gap-1.5 text-[13px] font-medium text-status-success">
                        <Calendar className="h-3.5 w-3.5" /> Vehicle available for these dates
                    </span>
                ) : step === 0 && safeConflicts.length > 0 ? (
                    <span className="inline-flex items-center gap-1.5 text-[13px] font-medium text-status-warning">
                        <AlertTriangle className="h-3.5 w-3.5" /> {safeConflicts.length} conflicting booking{safeConflicts.length !== 1 ? 's' : ''}
                    </span>
                ) : null
            }
            footerEnd={
                <>
                    {step > 0 ? (
                        // eslint-disable-next-line no-restricted-syntax -- WizardShell footer contract uses styled native buttons.
                        <button
                            type="button"
                            onClick={() => setStep((s) => s - 1)}
                            className="rounded-md px-3 py-2 text-[13px] font-semibold text-foreground hover:bg-muted"
                        >
                            Back
                        </button>
                    ) : null}
                    {/* eslint-disable-next-line no-restricted-syntax -- WizardShell footer contract uses styled native buttons. */}
                    <button
                        type="button"
                        disabled={!canContinue || form.processing}
                        onClick={() => (step === STEPS.length - 1 ? submit() : setStep((s) => s + 1))}
                        className="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-[13px] font-semibold text-primary-foreground disabled:opacity-50"
                    >
                        {form.processing ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : null}
                        {step === STEPS.length - 1 ? 'Submit booking' : 'Continue'}
                    </button>
                </>
            }
        >
            {!options ? (
                <div className="flex h-full min-h-[280px] flex-col items-center justify-center gap-3 text-muted-foreground">
                    <Loader2 className="h-6 w-6 animate-spin" />
                    <p className="text-sm">Loading vehicles…</p>
                </div>
            ) : step === 0 ? (
                <WizardStepPane>
                    <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                        <div className="space-y-4">
                            <Field label="Vehicle" required error={form.errors.asset_id}>
                                <Select value={form.data.asset_id} onValueChange={(v) => form.setData('asset_id', v)}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select vehicle" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {assignedVehicles.length > 0 && (
                                            <SelectGroup>
                                                <SelectLabel>Assigned Vehicles</SelectLabel>
                                                {assignedVehicles.map((v) => (
                                                    <SelectItem key={v.id} value={String(v.id)}>
                                                        {v.name}{v.asset_tag ? ` (${v.asset_tag})` : ''} - {v.home_site?.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectGroup>
                                        )}
                                        {poolVehicles.length > 0 && (
                                            <SelectGroup>
                                                <SelectLabel>Pool Vehicles</SelectLabel>
                                                {poolVehicles.map((v) => (
                                                    <SelectItem key={v.id} value={String(v.id)}>
                                                        {v.name}{v.asset_tag ? ` (${v.asset_tag})` : ''}
                                                    </SelectItem>
                                                ))}
                                            </SelectGroup>
                                        )}
                                        {assignedVehicles.length === 0 && poolVehicles.length === 0 && safeVehicles.map((v) => (
                                            <SelectItem key={v.id} value={String(v.id)}>
                                                {v.name}{v.asset_tag ? ` (${v.asset_tag})` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {vehicleStatus && (
                                    <div className="mt-2 flex items-center gap-2">
                                        <Info className="h-3.5 w-3.5 text-muted-foreground" />
                                        <span className="text-xs text-muted-foreground">Vehicle status:</span>
                                        <Badge variant={statusBadgeVariant(vehicleStatus)} className="text-[10px]">
                                            {vehicleStatus}
                                        </Badge>
                                        {vehicleStatus === 'maintenance' && (
                                            <span className="text-xs text-status-warning">This vehicle is currently in maintenance</span>
                                        )}
                                    </div>
                                )}
                            </Field>

                            {safeClients.length > 0 && (
                                <Field label={`${clientSingular} (optional)`}>
                                    <Select value={form.data.client_id} onValueChange={(v) => form.setData('client_id', v)}>
                                        <SelectTrigger>
                                            <SelectValue placeholder={`Select ${clientSingular.toLowerCase()} for accessibility matching`} />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {safeClients.map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>
                                                    {c.name}
                                                    {c.transport_needs && Object.values(c.transport_needs).some(Boolean) ? ' *' : ''}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <p className="mt-1 text-[10px] text-muted-foreground">Selecting a client checks vehicle accessibility compatibility.</p>
                                </Field>
                            )}

                            {compatibilityWarnings.length > 0 && (
                                <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg px-4 py-3 dark:border-status-warning/30">
                                    <div className="mb-2 flex items-center gap-2">
                                        <AlertTriangle className="h-4 w-4 text-status-warning" />
                                        <span className="text-sm font-medium text-status-warning dark:text-status-warning">Accessibility Mismatch</span>
                                    </div>
                                    <ul className="space-y-1">
                                        {compatibilityWarnings.map((w, i) => (
                                            <li key={i} className="text-xs text-status-warning dark:text-status-warning">- {w}</li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Start Date/Time" required error={form.errors.starts_at}>
                                    <Input
                                        type="datetime-local"
                                        value={form.data.starts_at}
                                        onChange={(e) => form.setData('starts_at', e.target.value)}
                                    />
                                </Field>
                                <Field label="End Date/Time" required error={form.errors.ends_at}>
                                    <Input
                                        type="datetime-local"
                                        value={form.data.ends_at}
                                        onChange={(e) => form.setData('ends_at', e.target.value)}
                                    />
                                </Field>
                            </div>

                            {stepOneComplete && safeConflicts.length === 0 && (
                                <div className="flex items-center gap-2 rounded-lg border border-status-success/30 bg-status-success-bg px-4 py-3 text-sm text-status-success dark:border-status-success/30 dark:bg-status-success-bg dark:text-status-success">
                                    <Calendar className="h-4 w-4" />
                                    Vehicle is available for the selected dates.
                                </div>
                            )}

                            {safeConflicts.length > 0 && (
                                <div className="rounded-lg border border-status-warning/30 p-4 dark:border-status-warning/30">
                                    <div className="mb-2 flex items-center gap-2 text-sm font-medium text-status-warning dark:text-status-warning">
                                        <AlertTriangle className="h-4 w-4" />
                                        Conflicting Bookings ({safeConflicts.length})
                                    </div>
                                    <p className="mb-3 text-xs text-muted-foreground">
                                        The following existing bookings overlap with your selected dates.
                                    </p>
                                    <div className="space-y-2">
                                        {safeConflicts.map((c) => (
                                            <div key={c.id} className="flex items-center justify-between rounded-md border border-status-warning/30 bg-status-warning-bg px-3 py-2 dark:border-status-warning/30">
                                                <div>
                                                    <p className="text-sm font-medium">{c.user_name}</p>
                                                    <p className="text-xs text-muted-foreground">{c.purpose}</p>
                                                </div>
                                                <div className="text-right">
                                                    <Badge variant="outline" className="text-[10px]">
                                                        {c.status.replace(/_/g, ' ')}
                                                    </Badge>
                                                    <p className="mt-0.5 text-[10px] text-muted-foreground">
                                                        {c.starts_at ? formatDate(c.starts_at) : '---'} -{' '}
                                                        {c.ends_at ? formatDate(c.ends_at) : '---'}
                                                    </p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>

                        <div>
                            {form.data.asset_id ? (
                                <div className="rounded-xl border border-border bg-card/70 p-4">
                                    <div className="mb-3 flex items-center gap-2 text-sm font-bold">
                                        <Calendar className="h-4 w-4 text-primary" />
                                        Vehicle Availability
                                    </div>
                                    <AvailabilityCalendar
                                        bookings={safeVehicleBookings}
                                        selectedStart={form.data.starts_at}
                                        selectedEnd={form.data.ends_at}
                                    />
                                    {safeVehicleBookings.length > 0 && (
                                        <div className="mt-3 space-y-1">
                                            <p className="text-xs font-medium text-muted-foreground">Existing Bookings:</p>
                                            {safeVehicleBookings.map(b => (
                                                <div key={b.id} className="flex justify-between rounded border px-2 py-1 text-[10px]">
                                                    <span>{b.purpose} — {b.user_name}</span>
                                                    <span className="text-muted-foreground">
                                                        {b.starts_at ? formatDate(b.starts_at) : '---'} - {b.ends_at ? formatDate(b.ends_at) : '---'}
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="flex flex-col items-center justify-center rounded-xl border border-border bg-card/70 py-12 text-center">
                                    <Calendar className="mb-3 h-10 w-10 text-muted-foreground/30" />
                                    <p className="px-4 text-sm text-muted-foreground">Select a vehicle and dates to check availability</p>
                                </div>
                            )}
                        </div>
                    </div>
                </WizardStepPane>
            ) : step === 1 ? (
                <WizardStepPane>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Purpose / Reason" required error={form.errors.purpose} className="sm:col-span-2">
                            <Input
                                value={form.data.purpose}
                                onChange={(e) => form.setData('purpose', e.target.value)}
                                placeholder="Reason for booking (e.g., client visit, delivery, site inspection)"
                            />
                        </Field>
                        <Field label="Destination" error={form.errors.destination} className="sm:col-span-2">
                            <Input
                                value={form.data.destination}
                                onChange={(e) => form.setData('destination', e.target.value)}
                                placeholder="Where are you going?"
                            />
                        </Field>
                        <Field label="Passenger Count" error={form.errors.passengers}>
                            <Input
                                type="number"
                                min="0"
                                value={form.data.passengers}
                                onChange={(e) => form.setData('passengers', e.target.value)}
                                placeholder="Number of passengers"
                            />
                        </Field>
                        <div className="hidden sm:block" aria-hidden="true" />
                        <Field label="Pickup Location" error={form.errors.pickup_site_id}>
                            <Select value={form.data.pickup_site_id} onValueChange={(v) => form.setData('pickup_site_id', v)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select pickup site" />
                                </SelectTrigger>
                                <SelectContent>
                                    {safeSites.map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Return Location" error={form.errors.return_site_id}>
                            <Select value={form.data.return_site_id} onValueChange={(v) => form.setData('return_site_id', v)}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select return site" />
                                </SelectTrigger>
                                <SelectContent>
                                    {safeSites.map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Notes" error={form.errors.notes} className="sm:col-span-2">
                            <textarea
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                placeholder="Any additional notes..."
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : (
                <WizardStepPane>
                    {serverErrors.length > 0 && (
                        <div className="mb-4 rounded-lg border border-status-critical/30 bg-status-critical-bg px-4 py-3">
                            <div className="mb-1 flex items-center gap-2 text-sm font-medium text-status-critical dark:text-status-critical">
                                <AlertTriangle className="h-4 w-4" />
                                The booking could not be submitted
                            </div>
                            <ul className="space-y-0.5">
                                {serverErrors.map(([key, message]) => (
                                    <li key={key} className="text-xs text-status-critical dark:text-status-critical">- {message}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {safeConflicts.length > 0 && (
                        <div className="mb-4 flex items-center gap-2 rounded-lg border border-status-warning/30 bg-status-warning-bg px-4 py-3 text-sm text-status-warning dark:border-status-warning/30 dark:text-status-warning">
                            <AlertTriangle className="h-4 w-4 shrink-0" />
                            {safeConflicts.length} existing booking{safeConflicts.length !== 1 ? 's overlap' : ' overlaps'} your dates — the request may be declined.
                        </div>
                    )}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ReviewCard icon={Car} title="Vehicle & when" onEdit={() => setStep(0)}>
                            <ReviewRow label="Vehicle" value={vehicleLabel} />
                            <ReviewRow label={clientSingular} value={selectedClient?.name} />
                            <ReviewRow label="Start" value={form.data.starts_at ? formatDateTime(form.data.starts_at) : undefined} />
                            <ReviewRow label="End" value={form.data.ends_at ? formatDateTime(form.data.ends_at) : undefined} />
                        </ReviewCard>
                        <ReviewCard icon={Users} title="Purpose & passengers" onEdit={() => setStep(1)}>
                            <ReviewRow label="Purpose" value={form.data.purpose} />
                            <ReviewRow label="Destination" value={form.data.destination} />
                            <ReviewRow label="Passengers" value={form.data.passengers} />
                            <ReviewRow label="Pickup" value={siteName(form.data.pickup_site_id)} />
                            <ReviewRow label="Return" value={siteName(form.data.return_site_id)} />
                            <ReviewRow label="Notes" value={form.data.notes} />
                        </ReviewCard>
                    </div>
                    <p className="mt-4 text-xs text-muted-foreground">
                        Your request is submitted for approval — you'll be notified when it's approved or declined.
                    </p>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}
