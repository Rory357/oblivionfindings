import {
    boundaryCentre,
    moveBoundary,
} from '@/components/client-location/boundary-geometry';
import ClientLocationMap from '@/components/client-location/client-location-map';
import '@/components/client-location/location-workspace.css';
import {
    geometryError,
    type Coordinate,
    type Geometry,
    type ZoneSchedule,
} from '@/components/client-location/types';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import {
    CalendarDays,
    Check,
    ChevronsUpDown,
    Circle,
    Copy,
    FileCheck2,
    Layers,
    Loader2,
    MapPin,
    Pentagon,
    Redo2,
    Search,
    Undo2,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    scheduleError,
    scopeLabel,
    WEEKDAY_BUTTONS,
    weekdayNames,
} from './map-model';
import type {
    CatalogueBoundary,
    LinkedGeofence,
    Place,
    SharedBoundary,
    VehicleGeofences,
    VehicleLocation,
} from './map-types';
import './map.css';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import './studio.css';
import {
    fieldProps,
    StudioNotice,
    WizardField,
    WorkspaceWizard,
} from './wizard-kit';
import { todayInAuckland } from './workspace-model';

const jsonHeaders = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
};

type LoadState = 'idle' | 'loading' | 'ready' | 'error';

/** Bounded server search over the shared boundaries this viewer may link. */
function useBoundaryCatalogue(
    vehicleId: number,
    query: string,
    enabled: boolean,
) {
    const [state, setState] = useState<{
        status: LoadState;
        items: CatalogueBoundary[];
        truncated: boolean;
    }>({ status: 'idle', items: [], truncated: false });
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        if (!enabled) return;
        const controller = new AbortController();
        const timer = window.setTimeout(
            () => {
                setState((old) => ({ ...old, status: 'loading' }));
                fetch(
                    `/fleet-assets/vehicles/${vehicleId}/geofences/catalogue?q=${encodeURIComponent(query.trim())}`,
                    {
                        signal: controller.signal,
                        credentials: 'same-origin',
                        headers: jsonHeaders,
                    },
                )
                    .then(async (response) => {
                        if (!response.ok)
                            throw new Error(String(response.status));
                        const data = (await response.json()) as {
                            boundaries: CatalogueBoundary[];
                            truncated: boolean;
                        };
                        setState({
                            status: 'ready',
                            items: data.boundaries,
                            truncated: data.truncated,
                        });
                    })
                    .catch((error: unknown) => {
                        if ((error as Error)?.name !== 'AbortError')
                            setState((old) => ({ ...old, status: 'error' }));
                    });
            },
            query ? 250 : 0,
        );
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [vehicleId, query, enabled, attempt]);
    return { ...state, retry: () => setAttempt((value) => value + 1) };
}

const typeLabel = (type: string) =>
    type === 'polygon' ? 'Polygon' : type === 'circle' ? 'Circle' : type;

/* ── Select existing ──────────────────────────────────────────────────── */

/**
 * "Select shared geofences": link existing boundaries to this vehicle, or
 * take a link away. Links owned by Fleet geofences stay checked and locked.
 */
export function GeofenceSelectDialog({
    vehicleId,
    vehicleName,
    geofences,
    onClose,
    onSaved,
}: {
    vehicleId: number;
    vehicleName: string;
    geofences: VehicleGeofences;
    onClose: () => void;
    onSaved: (next: VehicleGeofences) => void;
}) {
    const [query, setQuery] = useState('');
    const catalogue = useBoundaryCatalogue(vehicleId, query, true);
    const [keep, setKeep] = useState<Set<number>>(
        () =>
            new Set(
                geofences.items
                    .map((item) => item.assignment_id)
                    .filter((id): id is number => id !== null),
            ),
    );
    const [add, setAdd] = useState<Set<number>>(() => new Set());
    const command = useVehicleRecordCommand(isJsonObject);
    // Links whose boundary is gone or out of reach can still be taken away.
    const orphans = geofences.items.filter(
        (item) =>
            item.assignment_id !== null &&
            (item.source_state === 'removed' ||
                item.source_state === 'restricted'),
    );
    const toggle = (set: Set<number>, id: number, on: boolean) => {
        const next = new Set(set);
        if (on) next.add(id);
        else next.delete(id);
        return next;
    };
    const save = async () => {
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicleId}/geofences/selection`,
            {
                keep_assignment_ids: [...keep],
                add_geofence_ids: [...add],
                expected_version: geofences.version,
            },
            { method: 'PUT' },
        );
        if (result && isJsonObject(result.geofences)) {
            onSaved(result.geofences as VehicleGeofences);
            onClose();
        }
    };

    return (
        <Dialog
            open
            onOpenChange={(next) => !next && !command.processing && onClose()}
        >
            <DialogContent className="vehicle-studio vehicle-record-dialog vehicle-geofence-dialog flex max-h-[90vh] w-[min(92vw,720px)] max-w-[min(92vw,720px)] flex-col gap-0 overflow-hidden bg-card p-0">
                <DialogHeader className="vehicle-record-header">
                    <div className="vehicle-record-icon">
                        <Layers className="size-[21px]" aria-hidden />
                    </div>
                    <div>
                        <DialogTitle className="text-section-title">
                            Select shared geofences
                        </DialogTitle>
                        <DialogDescription className="mt-1.5">
                            Link existing boundaries to {vehicleName}
                        </DialogDescription>
                    </div>
                </DialogHeader>
                <div className="vehicle-record-body">
                    {command.message && (
                        <p
                            role="alert"
                            className="mb-4 rounded-lg bg-status-warning-bg p-3 text-sm text-status-warning"
                        >
                            {command.message}
                        </p>
                    )}
                    <Input
                        aria-label="Search shared geofences"
                        placeholder="Search name, site or reference…"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                    <fieldset
                        className="geofence-select-list min-w-0"
                        disabled={command.locked}
                        aria-busy={catalogue.status === 'loading'}
                    >
                        <legend className="sr-only">Shared geofences</legend>
                        {orphans.map((item) => (
                            <label key={item.key}>
                                <input
                                    type="checkbox"
                                    checked={keep.has(item.assignment_id!)}
                                    onChange={(event) =>
                                        setKeep(
                                            toggle(
                                                keep,
                                                item.assignment_id!,
                                                event.target.checked,
                                            ),
                                        )
                                    }
                                />
                                <span>
                                    <strong>{item.label}</strong>
                                    <small>
                                        {item.source_state === 'removed'
                                            ? 'Boundary removed from the register · untick to remove the link'
                                            : 'Boundary not available to you · untick to remove the link'}
                                    </small>
                                </span>
                                <StatusBadge variant="warning" size="sm">
                                    Review
                                </StatusBadge>
                            </label>
                        ))}
                        {catalogue.items.map((boundary) => {
                            const checked =
                                boundary.fleet_link ||
                                (boundary.assignment_id !== null
                                    ? keep.has(boundary.assignment_id)
                                    : add.has(boundary.id));
                            return (
                                <label key={boundary.id}>
                                    <input
                                        type="checkbox"
                                        checked={checked}
                                        disabled={
                                            boundary.fleet_link ||
                                            !boundary.geometry
                                        }
                                        onChange={(event) =>
                                            boundary.assignment_id !== null
                                                ? setKeep(
                                                      toggle(
                                                          keep,
                                                          boundary.assignment_id,
                                                          event.target.checked,
                                                      ),
                                                  )
                                                : setAdd(
                                                      toggle(
                                                          add,
                                                          boundary.id,
                                                          event.target.checked,
                                                      ),
                                                  )
                                        }
                                    />
                                    <span>
                                        <strong>{boundary.name}</strong>
                                        <small>
                                            {scopeLabel(boundary)} · GEO-
                                            {boundary.id}
                                            {boundary.fleet_link
                                                ? ' · linked in Fleet geofences'
                                                : !boundary.geometry
                                                  ? ' · incomplete shape'
                                                  : ''}
                                        </small>
                                    </span>
                                    <StatusBadge variant="neutral" size="sm">
                                        {typeLabel(boundary.type)}
                                    </StatusBadge>
                                </label>
                            );
                        })}
                        {catalogue.status === 'loading' &&
                            catalogue.items.length === 0 && (
                                <p className="geofence-select-note">
                                    <Loader2
                                        className="size-4 animate-spin"
                                        aria-hidden
                                    />
                                    Loading shared geofences…
                                </p>
                            )}
                        {catalogue.status === 'error' && (
                            <p className="geofence-select-note" role="alert">
                                Shared geofences couldn’t be loaded.
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={catalogue.retry}
                                >
                                    Try again
                                </Button>
                            </p>
                        )}
                        {catalogue.status === 'ready' &&
                            catalogue.items.length === 0 && (
                                <p className="geofence-select-note">
                                    {query.trim()
                                        ? 'No shared geofences match this search.'
                                        : 'No shared geofences are available at your sites yet. Create one from the map.'}
                                </p>
                            )}
                        {catalogue.truncated && (
                            <p className="geofence-select-note">
                                Showing the first 50 matches. Search to narrow
                                the list.
                            </p>
                        )}
                    </fieldset>
                </div>
                <DialogFooter className="vehicle-record-footer gap-2">
                    <Button
                        variant="outline"
                        disabled={command.processing}
                        onClick={onClose}
                    >
                        Cancel
                    </Button>
                    <Button
                        disabled={command.processing || command.requiresReload}
                        onClick={() => void save()}
                    >
                        {command.processing && (
                            <Loader2 className="size-4 animate-spin" />
                        )}
                        {command.uncertain
                            ? 'Retry this submission'
                            : 'Save selection'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/* ── Pickers ──────────────────────────────────────────────────────────── */

function BoundaryPicker({
    id,
    vehicleId,
    value,
    onSelect,
    invalid,
    describedBy,
}: {
    id: string;
    vehicleId: number;
    value: SharedBoundary | null;
    onSelect: (boundary: CatalogueBoundary) => void;
    invalid?: boolean;
    describedBy?: string;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const catalogue = useBoundaryCatalogue(vehicleId, query, open);
    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (!next) setQuery('');
            }}
        >
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-label="Existing shared boundary"
                    aria-expanded={open}
                    aria-invalid={invalid}
                    aria-describedby={describedBy}
                    className="w-full justify-between font-normal"
                >
                    <Search className="size-4 shrink-0 text-muted-foreground" />
                    <span className="min-w-0 flex-1 truncate text-left">
                        {value
                            ? `${value.name} · ${scopeLabel(value)}`
                            : 'Select a shared boundary, or draw a new one'}
                    </span>
                    <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-[var(--radix-popover-trigger-width)] min-w-72 p-0"
                align="start"
            >
                <Command shouldFilter={false}>
                    <CommandInput
                        aria-label="Search shared boundaries"
                        placeholder="Search name, site or reference…"
                        value={query}
                        onValueChange={setQuery}
                    />
                    <CommandList>
                        {catalogue.status === 'loading' && (
                            <p className="text-caption p-3">
                                Searching boundaries…
                            </p>
                        )}
                        {catalogue.status === 'error' && (
                            <p
                                className="p-3 text-xs text-status-critical"
                                role="alert"
                            >
                                Boundaries couldn’t be loaded. Close and try
                                again.
                            </p>
                        )}
                        {catalogue.status === 'ready' && (
                            <CommandEmpty>No matching boundaries.</CommandEmpty>
                        )}
                        <CommandGroup>
                            {catalogue.items.map((boundary) => {
                                const unavailable =
                                    boundary.assignment_id !== null ||
                                    boundary.fleet_link ||
                                    !boundary.geometry;
                                return (
                                    <CommandItem
                                        key={boundary.id}
                                        value={String(boundary.id)}
                                        disabled={unavailable}
                                        onSelect={() => {
                                            onSelect(boundary);
                                            setOpen(false);
                                            setQuery('');
                                        }}
                                    >
                                        <div className="min-w-0 flex-1">
                                            <span className="block truncate">
                                                {boundary.name}
                                            </span>
                                            <p className="text-xs text-muted-foreground">
                                                {scopeLabel(boundary)} ·{' '}
                                                {typeLabel(boundary.type)} ·
                                                GEO-{boundary.id}
                                                {boundary.assignment_id !== null
                                                    ? ' · already linked'
                                                    : boundary.fleet_link
                                                      ? ' · linked in Fleet geofences'
                                                      : !boundary.geometry
                                                        ? ' · incomplete shape'
                                                        : ''}
                                            </p>
                                        </div>
                                        {value?.id === boundary.id && (
                                            <Check className="size-4 text-primary" />
                                        )}
                                    </CommandItem>
                                );
                            })}
                        </CommandGroup>
                    </CommandList>
                    {catalogue.truncated && (
                        <p className="text-caption border-t p-2">
                            Showing the first 50 matches. Search to narrow the
                            list.
                        </p>
                    )}
                </Command>
            </PopoverContent>
        </Popover>
    );
}

/** "Find a place": the viewer's sites, then street addresses (3+ letters). */
function PlacePicker({
    id,
    vehicleId,
    value,
    onSelect,
}: {
    id: string;
    vehicleId: number;
    value: Place | null;
    onSelect: (place: Place) => void;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [state, setState] = useState<{
        status: LoadState;
        places: Place[];
        addresses: string;
    }>({ status: 'idle', places: [], addresses: 'not_requested' });
    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        const timer = window.setTimeout(
            () => {
                setState((old) => ({ ...old, status: 'loading' }));
                fetch(
                    `/fleet-assets/vehicles/${vehicleId}/geofences/places?q=${encodeURIComponent(query.trim())}`,
                    {
                        signal: controller.signal,
                        credentials: 'same-origin',
                        headers: jsonHeaders,
                    },
                )
                    .then(async (response) => {
                        if (!response.ok)
                            throw new Error(String(response.status));
                        const data = (await response.json()) as {
                            places: Place[];
                            addresses: string;
                        };
                        setState({ status: 'ready', ...data });
                    })
                    .catch((error: unknown) => {
                        if ((error as Error)?.name !== 'AbortError')
                            setState((old) => ({ ...old, status: 'error' }));
                    });
            },
            query ? 400 : 0,
        );
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [open, query, vehicleId]);
    const sites = state.places.filter((place) => place.kind === 'site');
    const addresses = state.places.filter((place) => place.kind === 'address');
    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (!next) setQuery('');
            }}
        >
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-label="Find a place"
                    aria-expanded={open}
                    className="w-full justify-between font-normal"
                >
                    <MapPin className="size-4 shrink-0 text-muted-foreground" />
                    <span className="min-w-0 flex-1 truncate text-left">
                        {value
                            ? value.label
                            : 'Search your sites or a street address'}
                    </span>
                    <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                className="w-[var(--radix-popover-trigger-width)] min-w-72 p-0"
                align="start"
            >
                <Command shouldFilter={false}>
                    <CommandInput
                        aria-label="Search places"
                        placeholder="Site name or street address…"
                        value={query}
                        onValueChange={setQuery}
                    />
                    <CommandList>
                        {state.status === 'loading' && (
                            <p className="text-caption p-3">Searching…</p>
                        )}
                        {state.status === 'error' && (
                            <p
                                className="p-3 text-xs text-status-critical"
                                role="alert"
                            >
                                Places couldn’t be loaded. You can still move
                                the map by hand.
                            </p>
                        )}
                        {state.status === 'ready' && (
                            <CommandEmpty>
                                No matching sites or addresses.
                            </CommandEmpty>
                        )}
                        {sites.length > 0 && (
                            <CommandGroup heading="Your sites">
                                {sites.map((place) => (
                                    <CommandItem
                                        key={place.key}
                                        value={place.key}
                                        onSelect={() => {
                                            onSelect(place);
                                            setOpen(false);
                                            setQuery('');
                                        }}
                                    >
                                        <div className="min-w-0 flex-1">
                                            <span className="block truncate">
                                                {place.label}
                                            </span>
                                            <p className="text-xs text-muted-foreground">
                                                {place.detail}
                                            </p>
                                        </div>
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        )}
                        {addresses.length > 0 && (
                            <CommandGroup heading="Street addresses">
                                {addresses.map((place) => (
                                    <CommandItem
                                        key={place.key}
                                        value={place.key}
                                        onSelect={() => {
                                            onSelect(place);
                                            setOpen(false);
                                            setQuery('');
                                        }}
                                    >
                                        <div className="min-w-0 flex-1">
                                            <span className="block truncate">
                                                {place.label}
                                            </span>
                                            <p className="line-clamp-2 text-xs text-muted-foreground">
                                                {place.detail}
                                            </p>
                                        </div>
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        )}
                    </CommandList>
                    <p className="text-caption border-t p-2">
                        {state.addresses === 'unavailable'
                            ? 'Address search is unavailable right now. Sites still work.'
                            : 'Type 3 or more letters to search street addresses. Only the typed text is sent.'}
                    </p>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

/* ── Create or manage ─────────────────────────────────────────────────── */

const STEPS = [
    {
        key: 'boundary',
        label: 'Draw or select boundary',
        blurb: 'Reuse the shared geometry',
        icon: MapPin,
    },
    {
        key: 'purpose',
        label: 'Name & purpose',
        blurb: 'Vehicle-specific context',
        icon: Layers,
    },
    {
        key: 'schedule',
        label: 'Schedule',
        blurb: 'Days, times and exceptions',
        icon: CalendarDays,
    },
    {
        key: 'review',
        label: 'Review inactive draft',
        blurb: 'Monitoring is a separate action',
        icon: FileCheck2,
    },
];

function addDays(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);
    const next = new Date(Date.UTC(year, month - 1, day + days));
    return next.toISOString().slice(0, 10);
}

function defaultSchedule(): ZoneSchedule {
    const today = todayInAuckland();
    return {
        timezone: 'Pacific/Auckland',
        weekdays: [1, 2, 3, 4, 5],
        start: '08:00',
        end: '18:00',
        following_day: false,
        first_date: today,
        last_date: addDays(today, 90),
        exception_dates: [],
    };
}

const geometryText = (shape: Geometry | null | undefined) =>
    shape?.type === 'circle'
        ? `${Math.round(shape.radius_m)} m circle`
        : shape
          ? `${shape.coordinates.length} polygon corners`
          : 'Missing';

/** Which step shows a server error for this field. */
function stepForField(field: string): number {
    if (
        field.startsWith('geometry') ||
        field === 'geofence_id' ||
        field === 'geometry_hash' ||
        field === 'source_change_reviewed' ||
        field === 'source'
    )
        return 0;
    if (['label', 'purpose', 'response_proposal'].includes(field)) return 1;
    if (field.startsWith('schedule')) return 2;
    return 3;
}

/**
 * The approved design's FenceWizard: draw or select a shared boundary, name
 * it for this vehicle, schedule it, and save it as an inactive assignment.
 * Linked geometry is read-only; "Make a custom copy" creates a new boundary.
 */
export function GeofenceAssignmentWizard({
    location,
    initial,
    draftPoint,
    onClose,
    onSaved,
    onStale,
}: {
    location: VehicleLocation;
    /** Manage mode: the linked geofence to change. */
    initial?: LinkedGeofence;
    /** "Create geofence here": a 100 m circle at the right-clicked point. */
    draftPoint?: Coordinate;
    onClose: () => void;
    onSaved: (next: VehicleGeofences) => void;
    /** The record changed or access ended: reload the map, then close. */
    onStale: () => void;
}) {
    const vehicle = location.vehicle;
    const ownerSite = location.geofences.owner_site;
    const manage = !!initial?.assignment_id;
    const site = vehicle.home_site;
    const home =
        site && site.lat !== null && site.lng !== null
            ? { lat: site.lat, lng: site.lng }
            : null;
    const [initialValues] = useState(() => ({
        source: initial?.boundary ?? null,
        name: initial?.label ?? '',
        purpose: initial?.purpose ?? '',
        response: initial?.response_proposal ?? '',
        schedule: initial?.schedule ?? defaultSchedule(),
    }));
    const [step, setStep] = useState(0);
    const [source, setSource] = useState<SharedBoundary | null>(
        initialValues.source,
    );
    const [copied, setCopied] = useState(false);
    const [reviewed, setReviewed] = useState(false);
    const [name, setName] = useState(initialValues.name);
    const [purpose, setPurpose] = useState(initialValues.purpose);
    const [response, setResponse] = useState(initialValues.response);
    const [schedule, setSchedule] = useState<ZoneSchedule>(
        initialValues.schedule,
    );
    const [exception, setException] = useState('');
    const [history, setHistory] = useState<(Geometry | null)[]>(() => [
        draftPoint
            ? { type: 'circle', center: draftPoint, radius_m: 100 }
            : null,
    ]);
    const [cursor, setCursor] = useState(0);
    const [drawing, setDrawing] = useState<'circle' | 'polygon' | null>(null);
    const [center, setCenter] = useState<Coordinate>(
        () =>
            draftPoint ??
            (initialValues.source?.geometry
                ? boundaryCentre(initialValues.source.geometry)
                : (home ?? { lat: -41.2865, lng: 174.7762 })),
    );
    const [place, setPlace] = useState<Place | null>(null);
    const [context, setContext] = useState<Coordinate | null>(null);
    const [stepError, setStepError] = useState('');
    const [touched, setTouched] = useState(false);
    const [saved, setSaved] = useState(false);
    const command = useVehicleRecordCommand(isJsonObject);
    const errors = command.errors;

    const linked = !!source && !copied;
    const drawn = history[cursor];
    const shape: Geometry | null = linked ? (source?.geometry ?? null) : drawn;
    const sourceState = initial?.source_state ?? 'current';
    // Manage mode keeps the linked boundary; a changed one needs a review.
    const keepsInitialSource =
        manage && linked && source?.id === initial?.boundary?.id;
    const changed = keepsInitialSource && sourceState === 'changed';

    const touch = () => setTouched(true);
    const commit = (next: Geometry | null) => {
        touch();
        setHistory([...history.slice(0, cursor + 1), next]);
        setCursor(cursor + 1);
    };
    const boundaryError = (): string => {
        if (manage && !copied && !source)
            return 'The linked boundary is no longer available. Make a custom copy to keep this assignment.';
        if (changed && !reviewed)
            return 'The linked boundary has changed. Review its current version before continuing.';
        if (drawing) return 'Finish drawing the boundary before continuing.';
        if (!linked && !ownerSite)
            return 'A new shared boundary needs the vehicle’s site. Select an existing boundary instead.';
        return geometryError(shape) ?? '';
    };
    const purposeError = () =>
        !name.trim() || !purpose.trim() ? 'Enter a name and purpose.' : '';
    const validateStep = (at: number): boolean => {
        const found =
            at === 0
                ? boundaryError()
                : at === 1
                  ? purposeError()
                  : at === 2
                    ? scheduleError(schedule)
                    : '';
        setStepError(found);
        return !found;
    };

    // A server field error returns the person to the step that owns it.
    const errorKey = JSON.stringify(errors);
    useEffect(() => {
        const fields = Object.keys(errors);
        if (!fields.length) return;
        setStep(Math.min(...fields.map(stepForField)));
    }, [errorKey]); // eslint-disable-line react-hooks/exhaustive-deps -- react to new server errors only

    const point = (p: Coordinate) => {
        if (linked) return;
        if (drawing === 'circle') {
            if (drawn?.type === 'circle' && drawn.radius_m === 0) {
                const radius = Math.round(
                    Math.hypot(
                        (p.lat - drawn.center.lat) * 111320,
                        (p.lng - drawn.center.lng) *
                            111320 *
                            Math.cos((p.lat * Math.PI) / 180),
                    ),
                );
                commit({ ...drawn, radius_m: Math.max(1, radius) });
                setDrawing(null);
            } else commit({ type: 'circle', center: p, radius_m: 0 });
        } else if (drawing === 'polygon')
            commit({
                type: 'polygon',
                coordinates: [
                    ...(drawn?.type === 'polygon' ? drawn.coordinates : []),
                    p,
                ],
            });
    };

    const makeCopy = () => {
        const base = source?.geometry ?? initial?.reviewed_geometry ?? null;
        touch();
        setCopied(true);
        setHistory([base]);
        setCursor(0);
        setDrawing(null);
        setName((old) =>
            old.endsWith(' · custom copy') ? old : `${old} · custom copy`,
        );
        setStepError('');
    };

    const submit = async () => {
        if (!command.uncertain) {
            const problems = [
                boundaryError(),
                purposeError(),
                scheduleError(schedule),
            ];
            const bad = problems.findIndex(Boolean);
            if (bad >= 0) {
                setStep(bad);
                setStepError(problems[bad]);
                return;
            }
        }
        const details = {
            label: name.trim(),
            purpose: purpose.trim(),
            response_proposal: response.trim() || null,
            schedule,
        };
        const vehicleUrl = `/fleet-assets/vehicles/${vehicle.id}/geofences`;
        const result = manage
            ? await command.submit(
                  `${vehicleUrl}/${initial!.assignment_id}`,
                  linked && keepsInitialSource
                      ? {
                            source: 'keep',
                            expected_version: initial!.lock_version,
                            geometry_hash: source!.hash,
                            source_change_reviewed: changed ? reviewed : false,
                            ...details,
                        }
                      : linked
                        ? {
                              source: 'existing',
                              expected_version: initial!.lock_version,
                              geofence_id: source!.id,
                              geometry_hash: source!.hash,
                              ...details,
                          }
                        : {
                              source: 'copy',
                              expected_version: initial!.lock_version,
                              geometry: shape,
                              ...details,
                          },
                  { method: 'PUT' },
              )
            : await command.submit(
                  vehicleUrl,
                  linked
                      ? {
                            source: 'existing',
                            geofence_id: source!.id,
                            geometry_hash: source!.hash,
                            ...details,
                        }
                      : { source: 'new', geometry: shape, ...details },
              );
        if (!result) return;
        setSaved(true);
        if (isJsonObject(result.geofences))
            onSaved(result.geofences as VehicleGeofences);
    };

    const dirty =
        touched ||
        name !== initialValues.name ||
        purpose !== initialValues.purpose ||
        response !== initialValues.response ||
        JSON.stringify(schedule) !== JSON.stringify(initialValues.schedule) ||
        source?.id !== initialValues.source?.id;
    const pct = Math.round(
        ([!boundaryError(), !purposeError(), !scheduleError(schedule)].filter(
            Boolean,
        ).length /
            3) *
            100,
    );
    const fieldError = (field: string) =>
        errors[field] ??
        Object.entries(errors).find(([key]) =>
            key.startsWith(`${field}.`),
        )?.[1];
    const setScheduleField = <K extends keyof ZoneSchedule>(
        key: K,
        value: ZoneSchedule[K],
    ) => {
        touch();
        setSchedule((old) => ({ ...old, [key]: value }));
        command.clearError(`schedule.${String(key)}`);
    };
    const footnote = linked
        ? 'Linked geometry is read-only. Make a custom copy to edit.'
        : drawing === 'circle'
          ? 'Click the centre, then the edge.'
          : drawing === 'polygon'
            ? 'Click each corner, then Finish boundary.'
            : 'Drag the boundary or handles. Focus a handle and use arrow keys; Shift makes smaller adjustments. Right-click to draw here.';
    const sourceText = linked
        ? `${source!.name} · GEO-${source!.id} · ${scopeLabel(source)}`
        : copied
          ? `Custom copy · new shared boundary · ${ownerSite?.name ?? 'vehicle site'}`
          : `New shared boundary · ${ownerSite?.name ?? 'vehicle site'}`;
    const profile = [
        vehicle.asset_tag,
        vehicle.registration_number,
        vehicle.name,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <WorkspaceWizard
            title={
                manage
                    ? 'Manage geofence assignment'
                    : 'Create or link geofence'
            }
            description="Shared boundary · vehicle assignment · inactive monitoring"
            railIcon={Layers}
            railSub={profile}
            steps={STEPS}
            step={step}
            setStep={(next) => {
                setStepError('');
                setStep(next);
            }}
            pct={pct}
            context={{
                name: vehicle.name,
                detail: 'Shared boundary · vehicle assignment · inactive monitoring',
            }}
            command={command}
            dirty={dirty}
            saved={saved}
            submitLabel="Save inactive assignment"
            onValidateStep={validateStep}
            onSubmit={() => void submit()}
            onClose={onClose}
            onReload={onStale}
            errorKey={`${errorKey}:${stepError}`}
            success={
                <WizardSuccessPane
                    title="Inactive assignment saved"
                    blurb="The boundary and schedule are linked to this vehicle. No monitoring or alerts are active."
                    actions={<Button onClick={onClose}>Back to map</Button>}
                />
            }
        >
            <div className="vehicle-studio">
                <div className="flow-stack">
                    {stepError && (
                        <StudioNotice
                            tone="critical"
                            title="Assignment not saved"
                        >
                            {stepError}
                        </StudioNotice>
                    )}
                    {step === 0 && (
                        <>
                            <WizardField
                                id="geo-source"
                                label="Existing shared boundary"
                                optional
                                error={fieldError('geofence_id')}
                                hint="Reuse a boundary shared with sites, houses and clients, or draw a new one below."
                            >
                                <BoundaryPicker
                                    id="geo-source"
                                    vehicleId={vehicle.id}
                                    value={linked ? source : null}
                                    invalid={!!fieldError('geofence_id')}
                                    describedBy={
                                        fieldError('geofence_id')
                                            ? 'geo-source-error'
                                            : undefined
                                    }
                                    onSelect={(boundary) => {
                                        touch();
                                        command.clearError('geofence_id');
                                        setSource(boundary);
                                        setCopied(false);
                                        setReviewed(false);
                                        setDrawing(null);
                                        setName(boundary.name);
                                        if (boundary.geometry)
                                            setCenter(
                                                boundaryCentre(
                                                    boundary.geometry,
                                                ),
                                            );
                                        setStepError('');
                                    }}
                                />
                            </WizardField>
                            {linked ? (
                                <>
                                    <StudioNotice
                                        tone={changed ? 'warning' : 'info'}
                                        title={
                                            changed
                                                ? 'Source review required'
                                                : 'Linked geometry is read-only'
                                        }
                                    >
                                        {changed
                                            ? 'This boundary changed after it was linked. The map shows its current version. '
                                            : ''}
                                        Shared edits affect other profiles.{' '}
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="ml-1"
                                            onClick={makeCopy}
                                        >
                                            <Copy className="size-3.5" />
                                            Make a custom copy
                                        </Button>
                                    </StudioNotice>
                                    {changed && (
                                        <label className="inline-check">
                                            <input
                                                type="checkbox"
                                                checked={reviewed}
                                                aria-invalid={
                                                    !!fieldError(
                                                        'source_change_reviewed',
                                                    )
                                                }
                                                onChange={(event) => {
                                                    touch();
                                                    setReviewed(
                                                        event.target.checked,
                                                    );
                                                    command.clearError(
                                                        'source_change_reviewed',
                                                    );
                                                }}
                                            />
                                            I reviewed the current boundary and
                                            it still suits this vehicle
                                        </label>
                                    )}
                                </>
                            ) : manage && !copied ? (
                                <StudioNotice
                                    tone="warning"
                                    title="Linked boundary unavailable"
                                >
                                    {sourceState === 'removed'
                                        ? 'This boundary was removed from the shared register. '
                                        : 'You no longer have access to this boundary. '}
                                    Keep the assignment with a new boundary
                                    owned by the vehicle’s site.{' '}
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="ml-1"
                                        onClick={makeCopy}
                                    >
                                        <Copy className="size-3.5" />
                                        {initial?.reviewed_geometry
                                            ? 'Make a custom copy'
                                            : 'Draw a replacement'}
                                    </Button>
                                </StudioNotice>
                            ) : (
                                <div
                                    className="geo-tools"
                                    role="toolbar"
                                    aria-label="Boundary drawing tools"
                                >
                                    <Button
                                        variant={
                                            drawing === 'circle'
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                        size="sm"
                                        aria-pressed={drawing === 'circle'}
                                        onClick={() => {
                                            setDrawing('circle');
                                            commit(null);
                                        }}
                                    >
                                        <Circle className="size-[15px]" />
                                        Draw circle
                                    </Button>
                                    <Button
                                        variant={
                                            drawing === 'polygon'
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                        size="sm"
                                        aria-pressed={drawing === 'polygon'}
                                        onClick={() => {
                                            setDrawing('polygon');
                                            commit({
                                                type: 'polygon',
                                                coordinates: [],
                                            });
                                        }}
                                    >
                                        <Pentagon className="size-[15px]" />
                                        Draw polygon
                                    </Button>
                                    {drawing === 'polygon' && (
                                        <Button
                                            size="sm"
                                            onClick={() => {
                                                const problem =
                                                    geometryError(shape);
                                                if (problem)
                                                    setStepError(problem);
                                                else {
                                                    setDrawing(null);
                                                    setStepError('');
                                                }
                                            }}
                                        >
                                            Finish boundary
                                        </Button>
                                    )}
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={cursor === 0}
                                        onClick={() => {
                                            setCursor(cursor - 1);
                                            setDrawing(null);
                                        }}
                                    >
                                        <Undo2 className="size-3.5" />
                                        Undo
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={cursor === history.length - 1}
                                        onClick={() => {
                                            setCursor(cursor + 1);
                                            setDrawing(null);
                                        }}
                                    >
                                        <Redo2 className="size-3.5" />
                                        Redo
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => {
                                            commit(null);
                                            setDrawing(null);
                                        }}
                                    >
                                        Clear
                                    </Button>
                                </div>
                            )}
                            <WizardField id="geo-place" label="Find a place">
                                <PlacePicker
                                    id="geo-place"
                                    vehicleId={vehicle.id}
                                    value={place}
                                    onSelect={(next) => {
                                        setPlace(next);
                                        setCenter({
                                            lat: next.lat,
                                            lng: next.lng,
                                        });
                                    }}
                                />
                            </WizardField>
                            <div className="geo-editor-map">
                                <ClientLocationMap
                                    center={center}
                                    focus={linked ? null : center}
                                    focusShape={linked ? shape : null}
                                    searchPoint={
                                        place
                                            ? { lat: place.lat, lng: place.lng }
                                            : null
                                    }
                                    shape={shape}
                                    editing={!linked}
                                    drawing={drawing}
                                    onChange={commit}
                                    onMapPoint={point}
                                    onContext={setContext}
                                />
                                {context && !linked && (
                                    <div className="geo-context">
                                        <Button
                                            size="sm"
                                            onClick={() => {
                                                commit({
                                                    type: 'circle',
                                                    center: context,
                                                    radius_m: 100,
                                                });
                                                setContext(null);
                                                setDrawing(null);
                                            }}
                                        >
                                            Create 100 m circle here
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setContext(null)}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                )}
                            </div>
                            {fieldError('geometry') && (
                                <p
                                    className="text-xs text-status-critical"
                                    role="alert"
                                >
                                    {fieldError('geometry')}
                                </p>
                            )}
                            <p className="studio-footnote">{footnote}</p>
                            {shape?.type === 'circle' && !linked && (
                                <div className="geo-radius">
                                    <label htmlFor="geo-radius">
                                        Radius (metres)
                                    </label>
                                    <Input
                                        id="geo-radius"
                                        type="number"
                                        min="1"
                                        value={shape.radius_m}
                                        onChange={(event) =>
                                            commit({
                                                ...shape,
                                                radius_m: +event.target.value,
                                            })
                                        }
                                    />
                                    <Button
                                        variant="outline"
                                        disabled={!place}
                                        onClick={() =>
                                            place &&
                                            commit(
                                                moveBoundary(
                                                    shape,
                                                    boundaryCentre(shape),
                                                    {
                                                        lat: place.lat,
                                                        lng: place.lng,
                                                    },
                                                ),
                                            )
                                        }
                                    >
                                        Move to searched place
                                    </Button>
                                </div>
                            )}
                        </>
                    )}
                    {step === 1 && (
                        <>
                            <WizardField
                                id="geo-name"
                                label="Geofence name"
                                error={fieldError('label')}
                                hint={
                                    linked
                                        ? 'How this boundary is named on this vehicle. The shared boundary keeps its own name.'
                                        : undefined
                                }
                            >
                                <Input
                                    {...fieldProps(
                                        'geo-name',
                                        fieldError('label'),
                                    )}
                                    maxLength={120}
                                    value={name}
                                    onChange={(event) => {
                                        touch();
                                        setName(event.target.value);
                                        command.clearError('label');
                                    }}
                                />
                            </WizardField>
                            <WizardField
                                id="geo-purpose"
                                label="Purpose for this vehicle"
                                error={fieldError('purpose')}
                            >
                                <Textarea
                                    {...fieldProps(
                                        'geo-purpose',
                                        fieldError('purpose'),
                                    )}
                                    maxLength={2000}
                                    value={purpose}
                                    onChange={(event) => {
                                        touch();
                                        setPurpose(event.target.value);
                                        command.clearError('purpose');
                                    }}
                                />
                            </WizardField>
                            <WizardField
                                id="geo-response"
                                label="Proposed response instructions"
                                optional
                                error={fieldError('response_proposal')}
                            >
                                <Textarea
                                    {...fieldProps(
                                        'geo-response',
                                        fieldError('response_proposal'),
                                    )}
                                    maxLength={2000}
                                    value={response}
                                    onChange={(event) => {
                                        touch();
                                        setResponse(event.target.value);
                                        command.clearError('response_proposal');
                                    }}
                                />
                            </WizardField>
                            <ReviewCard icon={Layers} title="Ownership">
                                <ReviewRow
                                    label="Boundary"
                                    value={
                                        linked
                                            ? `Shared source · ${source!.name}`
                                            : `New shared boundary · ${ownerSite?.name ?? 'vehicle site'}`
                                    }
                                />
                                <ReviewRow
                                    label="Assignment"
                                    value={`Vehicle · ${profile}`}
                                />
                                <ReviewRow
                                    label="Monitoring"
                                    value="Inactive · separate authority required"
                                />
                            </ReviewCard>
                        </>
                    )}
                    {step === 2 && (
                        <>
                            <p className="text-subtle">
                                Pacific/Auckland · schedule uses local time,
                                including daylight saving.
                            </p>
                            <div
                                className="weekday-options"
                                role="group"
                                aria-label="Scheduled days"
                            >
                                {WEEKDAY_BUTTONS.map(({ day, label }) => (
                                    <Button
                                        key={day}
                                        variant={
                                            schedule.weekdays.includes(day)
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                        aria-pressed={schedule.weekdays.includes(
                                            day,
                                        )}
                                        onClick={() =>
                                            setScheduleField(
                                                'weekdays',
                                                schedule.weekdays.includes(day)
                                                    ? schedule.weekdays.filter(
                                                          (entry) =>
                                                              entry !== day,
                                                      )
                                                    : [
                                                          ...schedule.weekdays,
                                                          day,
                                                      ].sort((a, b) => a - b),
                                            )
                                        }
                                    >
                                        {label}
                                    </Button>
                                ))}
                            </div>
                            <div className="geo-field-grid">
                                <WizardField
                                    id="geo-start"
                                    label="Starts at"
                                    error={fieldError('schedule.start')}
                                >
                                    <TimePicker
                                        id="geo-start"
                                        label="Starts at"
                                        value={schedule.start}
                                        invalid={!!fieldError('schedule.start')}
                                        onChange={(value) =>
                                            setScheduleField('start', value)
                                        }
                                    />
                                </WizardField>
                                <WizardField
                                    id="geo-end"
                                    label="Ends at"
                                    error={fieldError('schedule.end')}
                                >
                                    <TimePicker
                                        id="geo-end"
                                        label="Ends at"
                                        value={schedule.end}
                                        invalid={!!fieldError('schedule.end')}
                                        onChange={(value) =>
                                            setScheduleField('end', value)
                                        }
                                    />
                                </WizardField>
                                <WizardField
                                    id="geo-first"
                                    label="First date"
                                    error={fieldError('schedule.first_date')}
                                >
                                    <DatePicker
                                        id="geo-first"
                                        label="First date"
                                        value={schedule.first_date}
                                        invalid={
                                            !!fieldError('schedule.first_date')
                                        }
                                        onChange={(value) =>
                                            setScheduleField(
                                                'first_date',
                                                value,
                                            )
                                        }
                                    />
                                </WizardField>
                                <WizardField
                                    id="geo-last"
                                    label="Last date"
                                    error={fieldError('schedule.last_date')}
                                >
                                    <DatePicker
                                        id="geo-last"
                                        label="Last date"
                                        value={schedule.last_date}
                                        invalid={
                                            !!fieldError('schedule.last_date')
                                        }
                                        onChange={(value) =>
                                            setScheduleField('last_date', value)
                                        }
                                    />
                                </WizardField>
                            </div>
                            <label className="inline-check">
                                <input
                                    type="checkbox"
                                    checked={schedule.following_day}
                                    onChange={(event) =>
                                        setScheduleField(
                                            'following_day',
                                            event.target.checked,
                                        )
                                    }
                                />
                                Ends the following day
                            </label>
                            <div className="geo-radius">
                                <WizardField
                                    id="geo-exception"
                                    label="Exception date"
                                    optional
                                    error={fieldError(
                                        'schedule.exception_dates',
                                    )}
                                >
                                    <DatePicker
                                        id="geo-exception"
                                        label="Exception date"
                                        value={exception}
                                        onChange={setException}
                                    />
                                </WizardField>
                                <Button
                                    variant="outline"
                                    className="self-end"
                                    disabled={!exception}
                                    onClick={() => {
                                        if (
                                            exception < schedule.first_date ||
                                            exception > schedule.last_date
                                        ) {
                                            setStepError(
                                                'Exception date must fall within the schedule.',
                                            );
                                            return;
                                        }
                                        setScheduleField(
                                            'exception_dates',
                                            [
                                                ...new Set([
                                                    ...schedule.exception_dates,
                                                    exception,
                                                ]),
                                            ].sort(),
                                        );
                                        setException('');
                                        setStepError('');
                                    }}
                                >
                                    Exclude date
                                </Button>
                            </div>
                            {schedule.exception_dates.map((date) => (
                                <div className="geo-exception" key={date}>
                                    {formatDateOnly(date)}
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            setScheduleField(
                                                'exception_dates',
                                                schedule.exception_dates.filter(
                                                    (entry) => entry !== date,
                                                ),
                                            )
                                        }
                                    >
                                        Remove exception
                                    </Button>
                                </div>
                            ))}
                        </>
                    )}
                    {step === 3 && (
                        <>
                            <ReviewCard
                                icon={Layers}
                                title="Boundary & assignment"
                                onEdit={() => setStep(0)}
                            >
                                <ReviewRow label="Name" value={name} />
                                <ReviewRow
                                    label="Geometry"
                                    value={geometryText(shape)}
                                />
                                <ReviewRow label="Source" value={sourceText} />
                                <ReviewRow label="Purpose" value={purpose} />
                                <ReviewRow label="Profile" value={profile} />
                            </ReviewCard>
                            <ReviewCard
                                icon={CalendarDays}
                                title="Schedule"
                                onEdit={() => setStep(2)}
                            >
                                <ReviewRow
                                    label="Days"
                                    value={weekdayNames(schedule.weekdays)}
                                />
                                <ReviewRow
                                    label="Hours"
                                    value={`${schedule.start}–${schedule.end}${schedule.following_day ? ' next day' : ''} · Pacific/Auckland`}
                                />
                                <ReviewRow
                                    label="Dates"
                                    value={`${formatDateOnly(schedule.first_date)} to ${formatDateOnly(schedule.last_date)}`}
                                />
                                <ReviewRow
                                    label="Exceptions"
                                    value={
                                        schedule.exception_dates
                                            .map((date) => formatDateOnly(date))
                                            .join(', ') || 'None'
                                    }
                                />
                            </ReviewCard>
                            <StudioNotice title="Save as inactive">
                                Assignment does not enable tracking or alerts.
                                Vehicle monitoring needs an approved response
                                policy, recipient and permitted tracker; the
                                client-specific Control Room policy is not
                                assumed.
                            </StudioNotice>
                        </>
                    )}
                </div>
            </div>
        </WorkspaceWizard>
    );
}
