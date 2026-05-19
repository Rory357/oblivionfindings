import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { formatDate } from '@/lib/fleet-utils';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Calendar, Info, Loader2, Save } from 'lucide-react';
import { useCallback, useEffect, useRef } from 'react';


type Conflict = {
    id: number;
    user_name: string;
    purpose: string;
    starts_at: string | null;
    ends_at: string | null;
    status: string;
};

type VehicleBooking = {
    id: number;
    user_name: string;
    purpose: string;
    starts_at: string | null;
    ends_at: string | null;
    status: string;
};

type ClientOption = {
    id: number;
    name: string;
    transport_needs?: {
        wheelchair_ramp?: boolean;
        hoist?: boolean;
        child_seat?: boolean;
        medical_storage?: boolean;
    } | null;
};

type Vehicle = {
    id: number;
    name: string;
    asset_tag?: string;
    registration_number?: string;
    status?: string;
    home_site?: { id: number; name: string } | null;
    has_wheelchair_ramp?: boolean;
    has_hoist?: boolean;
    has_child_seat_anchors?: boolean;
    has_medical_storage?: boolean;
    seating_capacity?: number | null;
};

type Props = {
    vehicles: Vehicle[];
    sites?: Array<{ id: number; name: string }>;
    conflicts?: Conflict[];
    selected_vehicle_status?: string | null;
    vehicle_bookings?: VehicleBooking[];
    clients?: ClientOption[];
};

function statusBadgeVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'active': return 'default';
        case 'maintenance': return 'outline';
        case 'retired': return 'secondary';
        default: return 'outline';
    }
}

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
        const dateStr = date.toISOString().split('T')[0];

        const dayBookings = bookings.filter(b => {
            if (!b.starts_at || !b.ends_at) return false;
            const start = new Date(b.starts_at).toISOString().split('T')[0];
            const end = new Date(b.ends_at).toISOString().split('T')[0];
            return dateStr >= start && dateStr <= end;
        });

        const isSelected = !!(selectedStart && selectedEnd && dateStr >= selectedStart.split('T')[0] && dateStr <= selectedEnd.split('T')[0]);
        const isToday = dateStr === new Date().toISOString().split('T')[0];

        days.push({ day: d, bookings: dayBookings, isSelected, isToday, date: dateStr });
    }

    return (
        <div>
            <div className="text-center font-medium text-sm mb-2">
                {today.toLocaleString('default', { month: 'long', year: 'numeric' })}
            </div>
            <div className="grid grid-cols-7 text-center text-[10px] text-muted-foreground mb-1">
                {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(d => <div key={d}>{d}</div>)}
            </div>
            <div className="grid grid-cols-7 gap-0.5">
                {days.map((cell, i) => {
                    if (!cell) return <div key={i} />;
                    const hasBooking = cell.bookings.length > 0;
                    return (
                        <div key={i} className={cn(
                            'h-8 flex items-center justify-center text-xs rounded-md relative transition-colors',
                            cell.isToday && 'font-bold ring-1 ring-ring',
                            cell.isSelected && 'bg-primary/10 text-primary dark:bg-primary/40 dark:text-primary/70',
                            hasBooking && !cell.isSelected && 'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
                            !hasBooking && !cell.isSelected && 'hover:bg-muted/50',
                        )} title={hasBooking ? cell.bookings.map(b => `${b.purpose} (${b.user_name})`).join(', ') : ''}>
                            {cell.day}
                            {hasBooking && <span className="absolute bottom-0.5 left-1/2 -translate-x-1/2 h-1 w-1 rounded-full bg-status-critical" />}
                        </div>
                    );
                })}
            </div>
            <div className="flex gap-3 mt-2 text-[10px] text-muted-foreground">
                <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-primary" /> Your selection</span>
                <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-status-critical" /> Booked</span>
                <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-white ring-1 ring-ring" /> Today</span>
            </div>
        </div>
    );
}

export default function BookingCreate({ vehicles, sites, conflicts, selected_vehicle_status, vehicle_bookings, clients }: Props) {
    const { labels } = usePage().props as any;
    const clientSingular = labels?.['client.singular'] ?? 'Client';
    const safeVehicles = vehicles ?? [];
    const safeSites = sites ?? [];
    const safeConflicts = conflicts ?? [];
    const safeClients = clients ?? [];
    const vehicleBookings = vehicle_bookings ?? [];
    const assignedVehicles = safeVehicles.filter((v) => v.home_site);
    const poolVehicles = safeVehicles.filter((v) => !v.home_site);

    const form = useForm({
        asset_id: '',
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

    // Debounce conflict check
    const checkTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const checkAvailability = useCallback(() => {
        if (form.data.asset_id && form.data.starts_at && form.data.ends_at) {
            if (checkTimerRef.current) clearTimeout(checkTimerRef.current);
            checkTimerRef.current = setTimeout(() => {
                router.get('/fleet-assets/bookings/create', {
                    check_asset_id: form.data.asset_id,
                    check_starts_at: form.data.starts_at,
                    check_ends_at: form.data.ends_at,
                }, { preserveState: true, preserveScroll: true, only: ['conflicts', 'selected_vehicle_status', 'vehicle_bookings'] });
            }, 500);
        }
    }, [form.data.asset_id, form.data.starts_at, form.data.ends_at]);

    useEffect(() => {
        checkAvailability();
        return () => {
            if (checkTimerRef.current) clearTimeout(checkTimerRef.current);
        };
    }, [checkAvailability]);

    // Auto-fetch bookings when vehicle changes (even without dates selected)
    useEffect(() => {
        if (form.data.asset_id) {
            router.reload({
                data: {
                    check_asset_id: form.data.asset_id,
                    check_starts_at: form.data.starts_at || undefined,
                    check_ends_at: form.data.ends_at || undefined,
                },
                only: ['conflicts', 'selected_vehicle_status', 'vehicle_bookings'],
                preserveState: true,
            });
        }
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data.asset_id]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/fleet-assets/bookings');
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Bookings', href: '/fleet-assets/bookings' },
                { title: 'Create', href: '#' },
            ]}
        >
            <Head title="Create Booking" />
            <PageShell>
                <PageHero
                    title="Create Booking"
                    description="Request a vehicle booking."
                    backHref="/fleet-assets/bookings"
                    backLabel="Back to Bookings"
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                  <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                    <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Booking Details</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <label className="text-sm font-medium">Vehicle *</label>
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
                                {form.errors.asset_id && <p className="mt-1 text-xs text-destructive">{form.errors.asset_id}</p>}

                                {/* Vehicle Status Indicator */}
                                {selected_vehicle_status && (
                                    <div className="mt-2 flex items-center gap-2">
                                        <Info className="h-3.5 w-3.5 text-muted-foreground" />
                                        <span className="text-xs text-muted-foreground">Vehicle status:</span>
                                        <Badge variant={statusBadgeVariant(selected_vehicle_status)} className="text-[10px]">
                                            {selected_vehicle_status}
                                        </Badge>
                                        {selected_vehicle_status === 'maintenance' && (
                                            <span className="text-xs text-status-warning">This vehicle is currently in maintenance</span>
                                        )}
                                    </div>
                                )}
                            </div>
                            {/* Client Selector */}
                            {safeClients.length > 0 && (
                                <div className="sm:col-span-2">
                                    <label className="text-sm font-medium">{clientSingular} (optional)</label>
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
                                </div>
                            )}

                            {/* Compatibility Warnings */}
                            {compatibilityWarnings.length > 0 && (
                                <div className="sm:col-span-2 rounded-lg border border-status-warning/30 bg-status-warning-bg px-4 py-3 dark:border-status-warning/30">
                                    <div className="flex items-center gap-2 mb-2">
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

                            <div className="sm:col-span-2">
                                <label className="text-sm font-medium">Purpose / Reason *</label>
                                <Input
                                    value={form.data.purpose}
                                    onChange={(e) => form.setData('purpose', e.target.value)}
                                    placeholder="Reason for booking (e.g., client visit, delivery, site inspection)"
                                />
                                {form.errors.purpose && <p className="mt-1 text-xs text-destructive">{form.errors.purpose}</p>}
                            </div>
                            <div className="sm:col-span-2">
                                <label className="text-sm font-medium">Destination</label>
                                <Input
                                    value={form.data.destination}
                                    onChange={(e) => form.setData('destination', e.target.value)}
                                    placeholder="Where are you going?"
                                />
                                {form.errors.destination && <p className="mt-1 text-xs text-destructive">{form.errors.destination}</p>}
                            </div>
                            <div>
                                <label className="text-sm font-medium">Start Date/Time *</label>
                                <Input
                                    type="datetime-local"
                                    value={form.data.starts_at}
                                    onChange={(e) => form.setData('starts_at', e.target.value)}
                                />
                                {form.errors.starts_at && <p className="mt-1 text-xs text-destructive">{form.errors.starts_at}</p>}
                            </div>
                            <div>
                                <label className="text-sm font-medium">End Date/Time *</label>
                                <Input
                                    type="datetime-local"
                                    value={form.data.ends_at}
                                    onChange={(e) => form.setData('ends_at', e.target.value)}
                                />
                                {form.errors.ends_at && <p className="mt-1 text-xs text-destructive">{form.errors.ends_at}</p>}
                            </div>
                            <div>
                                <label className="text-sm font-medium">Passenger Count</label>
                                <Input
                                    type="number"
                                    min="0"
                                    value={form.data.passengers}
                                    onChange={(e) => form.setData('passengers', e.target.value)}
                                    placeholder="Number of passengers"
                                />
                                {form.errors.passengers && <p className="mt-1 text-xs text-destructive">{form.errors.passengers}</p>}
                            </div>
                            <div>
                                {/* spacer for alignment */}
                            </div>
                            <div>
                                <label className="text-sm font-medium">Pickup Location</label>
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
                                {form.errors.pickup_site_id && <p className="mt-1 text-xs text-destructive">{form.errors.pickup_site_id}</p>}
                            </div>
                            <div>
                                <label className="text-sm font-medium">Return Location</label>
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
                                {form.errors.return_site_id && <p className="mt-1 text-xs text-destructive">{form.errors.return_site_id}</p>}
                            </div>
                            <div className="sm:col-span-2">
                                <label className="text-sm font-medium">Notes</label>
                                <textarea
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    rows={2}
                                    value={form.data.notes}
                                    onChange={(e) => form.setData('notes', e.target.value)}
                                    placeholder="Any additional notes..."
                                />
                                {form.errors.notes && <p className="mt-1 text-xs text-destructive">{form.errors.notes}</p>}
                            </div>
                        </CardContent>
                    </Card>

                    </div>{/* end left column */}

                    {/* Right column: Availability Preview & Calendar */}
                    <div className="space-y-4">
                    {/* Availability Calendar */}
                    {form.data.asset_id && (
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Calendar className="h-4 w-4 text-primary" />
                                    Vehicle Availability
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <AvailabilityCalendar
                                    bookings={vehicleBookings}
                                    selectedStart={form.data.starts_at}
                                    selectedEnd={form.data.ends_at}
                                />

                                {/* Existing bookings list */}
                                {vehicleBookings.length > 0 && (
                                    <div className="space-y-1 mt-3">
                                        <p className="text-xs font-medium text-muted-foreground">Existing Bookings:</p>
                                        {vehicleBookings.map(b => (
                                            <div key={b.id} className="flex justify-between rounded border px-2 py-1 text-[10px]">
                                                <span>{b.purpose} — {b.user_name}</span>
                                                <span className="text-muted-foreground">
                                                    {b.starts_at ? formatDate(b.starts_at) : '---'} - {b.ends_at ? formatDate(b.ends_at) : '---'}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {safeConflicts.length > 0 && (
                        <Card className="border-status-warning/30 dark:border-status-warning/30">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base text-status-warning dark:text-status-warning">
                                    <AlertTriangle className="h-4 w-4" />
                                    Conflicting Bookings ({safeConflicts.length})
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
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
                            </CardContent>
                        </Card>
                    )}

                    {form.data.asset_id && form.data.starts_at && form.data.ends_at && safeConflicts.length === 0 && (
                        <div className="flex items-center gap-2 rounded-lg border border-status-success/30 bg-status-success-bg px-4 py-3 text-sm text-status-success dark:border-status-success/30 dark:bg-status-success-bg dark:text-status-success">
                            <Calendar className="h-4 w-4" />
                            Vehicle is available for the selected dates.
                        </div>
                    )}

                    {!form.data.asset_id && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-12 text-center">
                                <Calendar className="mb-3 h-10 w-10 text-muted-foreground/30" />
                                <p className="text-sm text-muted-foreground">Select a vehicle and dates to check availability</p>
                            </CardContent>
                        </Card>
                    )}
                    </div>{/* end right column */}
                  </div>{/* end grid */}

                    <div className="flex items-center gap-2">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
                            Submit Booking
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/fleet-assets/bookings">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
