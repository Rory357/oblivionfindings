import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Textarea } from '@/components/ui/textarea';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import {
    CalendarDays,
    Check,
    Circle,
    MapPin,
    Move,
    Pentagon,
    Redo2,
    ShieldCheck,
    Undo2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { boundaryCentre, moveBoundary } from './boundary-geometry';
import ClientLocationMap from './client-location-map';
import {
    geometryError,
    privateHeaders,
    readJson,
    type Boundary,
    type Coordinate,
    type Geometry,
    type ZoneDraft,
    type ZoneSchedule,
} from './types';
import ZoneAddressSearch from './zone-address-search';

const steps = [
    {
        key: 'boundary',
        label: 'Draw boundary',
        blurb: 'Choose exactly where',
        icon: MapPin,
    },
    {
        key: 'purpose',
        label: 'Name & purpose',
        blurb: 'Make the plan clear',
        icon: ShieldCheck,
    },
    {
        key: 'schedule',
        label: 'Schedule',
        blurb: 'Days, hours and exceptions',
        icon: CalendarDays,
    },
    {
        key: 'review',
        label: 'Review & save',
        blurb: 'Save an inactive draft',
        icon: Check,
    },
];
const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const blankSchedule: ZoneSchedule = {
    timezone: 'Pacific/Auckland',
    weekdays: [],
    start: '',
    end: '',
    following_day: false,
    first_date: '',
    last_date: '',
    exception_dates: [],
};

export default function ZoneDraftDialog({
    center,
    draft,
    boundaries,
    url,
    fingerprint,
    onClose,
    onSaved,
    onAccessEnded,
}: {
    center: Coordinate;
    draft: ZoneDraft | null;
    boundaries: Boundary[];
    url: string;
    fingerprint: string;
    onClose: () => void;
    onSaved: (zone: ZoneDraft) => void;
    onAccessEnded: () => void;
}) {
    const [step, setStep] = useState(0);
    const [name, setName] = useState(draft?.name ?? '');
    const [purpose, setPurpose] = useState(draft?.purpose ?? '');
    const [classification, setClassification] = useState<
        'agreed' | 'attention'
    >(draft?.classification ?? 'agreed');
    const [schedule, setSchedule] = useState<ZoneSchedule>(
        draft?.schedule ?? blankSchedule,
    );
    const [response, setResponse] = useState(draft?.response_proposal ?? '');
    const [history, setHistory] = useState<(Geometry | null)[]>([
        draft?.geometry ?? null,
    ]);
    const [cursor, setCursor] = useState(0);
    const [mode, setMode] = useState<'circle' | 'polygon' | null>(null);
    const [boundary, setBoundary] = useState<Boundary | null>(
        () =>
            boundaries.find(
                (item) =>
                    item.id === draft?.canonical_geofence_id &&
                    item.hash === draft?.canonical_geometry_hash,
            ) ?? null,
    );
    const [linkOpen, setLinkOpen] = useState(false);
    const [sourceReviewed, setSourceReviewed] = useState(false);
    const originalBoundary = boundaries.find(
        (item) => item.id === draft?.canonical_geofence_id,
    );
    const sourceNeedsReview =
        draft?.geometry_source === 'canonical' && !boundary && !sourceReviewed;
    const customEditingAllowed = !boundary && !sourceNeedsReview;
    const [exceptionDate, setExceptionDate] = useState('');
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const [dirty, setDirty] = useState(false);
    const [discard, setDiscard] = useState(false);
    const controller = useRef<AbortController | null>(null);
    const saveIdentity = useRef<{ payload: string; key: string } | null>(null);
    const shape = history[cursor];
    const [place, setPlace] = useState<{
        point: Coordinate;
        label: string;
    } | null>(null);
    const [mapFocus, setMapFocus] = useState<Coordinate | null>(null);
    const [shapeFocus, setShapeFocus] = useState<Geometry | null>(
        draft?.geometry ?? null,
    );
    const drawingCenter = place?.point ?? center;
    useEffect(() => () => controller.current?.abort(), []);
    const change = (next: Geometry | null) => {
        setHistory((old) => [...old.slice(0, cursor + 1), next]);
        setCursor(cursor + 1);
        setBoundary(null);
        setDirty(true);
        setError('');
    };
    const editSchedule = (next: Partial<ZoneSchedule>) => {
        setSchedule({ ...schedule, ...next });
        setDirty(true);
    };
    const requestClose = () => {
        if (!saving) {
            if (dirty) setDiscard(true);
            else onClose();
        }
    };
    const validate = (target: number) => {
        if (target > 0 && sourceNeedsReview)
            return 'Review the changed or unavailable linked boundary before continuing.';
        if (target > 0) {
            const problem = geometryError(shape);
            if (problem) return problem;
        }
        if (target > 1 && (!name.trim() || !purpose.trim()))
            return 'Give this zone a name and explain its purpose for the client.';
        if (target > 2) {
            if (
                !schedule.weekdays.length ||
                !schedule.start ||
                !schedule.end ||
                !schedule.first_date ||
                !schedule.last_date
            )
                return 'Choose the days, both times, and the first and last dates.';
            if (schedule.last_date < schedule.first_date)
                return 'The last date must be on or after the first date.';
            if (
                (!schedule.following_day && schedule.end <= schedule.start) ||
                (schedule.following_day && schedule.end > schedule.start)
            )
                return 'Check the finish time and the following-day setting.';
        }
        return null;
    };
    const go = (next: number) => {
        const problem = validate(next);
        if (problem) setError(problem);
        else {
            setError('');
            setMode(null);
            setStep(next);
        }
    };
    const mapPoint = (point: Coordinate) => {
        if (mode === 'polygon')
            change({
                type: 'polygon',
                coordinates: [
                    ...(shape?.type === 'polygon' ? shape.coordinates : []),
                    point,
                ],
            });
        if (mode === 'circle') {
            if (shape?.type === 'circle' && shape.radius_m === 0) {
                const north = (point.lat - shape.center.lat) * 111320,
                    east =
                        (point.lng - shape.center.lng) *
                        111320 *
                        Math.cos((shape.center.lat * Math.PI) / 180);
                change({
                    ...shape,
                    radius_m: Math.round(Math.hypot(north, east)),
                });
                setMode(null);
            } else change({ type: 'circle', center: point, radius_m: 0 });
        }
    };
    const save = async () => {
        const problem = validate(3);
        if (problem || !shape || saving) {
            setError(problem ?? 'Finish the boundary first.');
            return;
        }
        const payload = {
            name: name.trim(),
            purpose: purpose.trim(),
            classification,
            schedule,
            response_proposal: response.trim() || null,
            access_fingerprint: fingerprint,
            source_change_reviewed: sourceReviewed,
            ...(draft ? { expected_revision: draft.revision } : {}),
            ...(boundary
                ? {
                      geometry_source: 'canonical',
                      canonical_geofence_id: boundary.id,
                      canonical_geometry_hash: boundary.hash,
                  }
                : { geometry_source: 'custom', geometry: shape }),
        };
        const serialized = JSON.stringify(payload);
        if (saveIdentity.current?.payload !== serialized)
            saveIdentity.current = {
                payload: serialized,
                key: crypto.randomUUID(),
            };
        const abort = new AbortController();
        controller.current = abort;
        setSaving(true);
        setError('');
        try {
            const csrf = document.querySelector<HTMLMetaElement>(
                'meta[name="csrf-token"]',
            )?.content;
            const xsrf = document.cookie
                .split('; ')
                .find((part) => part.startsWith('XSRF-TOKEN='))
                ?.slice(11);
            const result = await fetch(draft ? `${url}/${draft.id}` : url, {
                method: draft ? 'PUT' : 'POST',
                signal: abort.signal,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    ...privateHeaders,
                    'Content-Type': 'application/json',
                    ...(csrf
                        ? { 'X-CSRF-TOKEN': csrf }
                        : xsrf
                          ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
                          : {}),
                },
                body: JSON.stringify({
                    ...payload,
                    idempotency_key: saveIdentity.current.key,
                }),
            });
            if (abort.signal.aborted) return;
            if (result.status === 403) {
                onAccessEnded();
                return;
            }
            const data = await readJson<{ zone: ZoneDraft }>(result);
            if (!abort.signal.aborted) onSaved(data.zone);
        } catch (failure) {
            if (!abort.signal.aborted)
                setError(
                    failure instanceof Error
                        ? failure.message
                        : 'The draft could not be saved. Try again.',
                );
        } finally {
            if (!abort.signal.aborted) setSaving(false);
        }
    };

    return (
        <>
            <WizardShell
                open
                onClose={requestClose}
                title={draft ? 'Edit safe zone draft' : 'Draw a safe zone'}
                description="Define a boundary and schedule, then save a private inactive draft."
                railIcon={MapPin}
                railTitle="Safe zone draft"
                railSub="Boundary → purpose → schedule"
                steps={steps.map((item) => ({ ...item, disabled: saving }))}
                stepIndex={step}
                onStepClick={go}
                railExtra={
                    <p className="text-xs text-muted-foreground">
                        Drafts do not monitor location or send alerts. Review
                        and operational setup are separate.
                    </p>
                }
                maxWidth="min(96vw, 1100px)"
                maxHeight="min(88vh, 780px)"
                footerStart={
                    <Button
                        variant="ghost"
                        onClick={requestClose}
                        disabled={saving}
                    >
                        Cancel
                    </Button>
                }
                footerEnd={
                    <>
                        <Button
                            variant="outline"
                            onClick={() => go(step - 1)}
                            disabled={step === 0 || saving}
                        >
                            Back
                        </Button>
                        {step < 3 ? (
                            <Button onClick={() => go(step + 1)}>
                                Continue
                            </Button>
                        ) : (
                            <Button
                                onClick={() => void save()}
                                disabled={saving}
                            >
                                {saving ? 'Saving draft…' : 'Save draft'}
                            </Button>
                        )}
                    </>
                }
            >
                <div className="client-location-editor">
                    {error && (
                        <div
                            role="alert"
                            className="location-message location-error"
                        >
                            {error}
                        </div>
                    )}
                    <WizardStepPane>
                        {step === 0 && (
                            <>
                                <h2>Draw the places that matter</h2>
                                <p>
                                    Draw on the map, use an existing boundary,
                                    or start with a rectangle. Drag the shaded
                                    area to move the whole zone; drag a corner
                                    to reshape it.
                                </p>
                                <ZoneAddressSearch
                                    url={url}
                                    fingerprint={fingerprint}
                                    onAccessEnded={onAccessEnded}
                                    onSelect={(selected) => {
                                        setPlace(selected);
                                        setShapeFocus(null);
                                        setMapFocus(selected.point);
                                    }}
                                />
                                {place && (
                                    <div className="zone-found-place">
                                        <MapPin />
                                        <span>{place.label}</span>
                                        {shape &&
                                            !geometryError(shape) &&
                                            customEditingAllowed && (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() => {
                                                        const next =
                                                            moveBoundary(
                                                                shape,
                                                                boundaryCentre(
                                                                    shape,
                                                                ),
                                                                place.point,
                                                            );
                                                        change(next);
                                                        setMode(null);
                                                        setMapFocus(null);
                                                        setShapeFocus(next);
                                                    }}
                                                >
                                                    <Move />
                                                    Move zone here
                                                </Button>
                                            )}
                                    </div>
                                )}
                                {sourceNeedsReview && (
                                    <div
                                        role="status"
                                        className="location-message"
                                    >
                                        <strong>
                                            Linked boundary needs review
                                        </strong>
                                        <p>
                                            The saved boundary has changed or is
                                            no longer available. The map shows
                                            your saved draft snapshot. Choose
                                            its source explicitly before saving.
                                        </p>
                                        <div className="location-actions">
                                            {originalBoundary && (
                                                <Button
                                                    variant="outline"
                                                    onClick={() => {
                                                        change(
                                                            originalBoundary.geometry,
                                                        );
                                                        setBoundary(
                                                            originalBoundary,
                                                        );
                                                        setSourceReviewed(true);
                                                    }}
                                                >
                                                    Review current site boundary
                                                </Button>
                                            )}
                                            <Button
                                                variant="outline"
                                                onClick={() => {
                                                    setBoundary(null);
                                                    setSourceReviewed(true);
                                                    setDirty(true);
                                                    setError('');
                                                }}
                                            >
                                                Use saved shape as a custom
                                                draft
                                            </Button>
                                        </div>
                                    </div>
                                )}
                                <div className="location-actions">
                                    <Button
                                        disabled={!customEditingAllowed}
                                        variant={
                                            mode === 'polygon'
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() => {
                                            change({
                                                type: 'polygon',
                                                coordinates: [],
                                            });
                                            setMode('polygon');
                                        }}
                                    >
                                        <Pentagon />
                                        Polygon
                                    </Button>
                                    <Button
                                        disabled={!customEditingAllowed}
                                        variant={
                                            mode === 'circle'
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() => {
                                            change(null);
                                            setMode('circle');
                                        }}
                                    >
                                        <Circle />
                                        Circle
                                    </Button>
                                    <Button
                                        disabled={!customEditingAllowed}
                                        variant="outline"
                                        onClick={() => {
                                            change({
                                                type: 'polygon',
                                                coordinates: [
                                                    {
                                                        lat:
                                                            drawingCenter.lat -
                                                            0.0005,
                                                        lng:
                                                            drawingCenter.lng -
                                                            0.0005,
                                                    },
                                                    {
                                                        lat:
                                                            drawingCenter.lat -
                                                            0.0005,
                                                        lng:
                                                            drawingCenter.lng +
                                                            0.0005,
                                                    },
                                                    {
                                                        lat:
                                                            drawingCenter.lat +
                                                            0.0005,
                                                        lng:
                                                            drawingCenter.lng +
                                                            0.0005,
                                                    },
                                                    {
                                                        lat:
                                                            drawingCenter.lat +
                                                            0.0005,
                                                        lng:
                                                            drawingCenter.lng -
                                                            0.0005,
                                                    },
                                                ],
                                            });
                                            setMode(null);
                                        }}
                                    >
                                        Start rectangle
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        aria-label="Undo boundary change"
                                        disabled={
                                            cursor === 0 ||
                                            !customEditingAllowed
                                        }
                                        onClick={() => {
                                            setCursor(cursor - 1);
                                            setBoundary(null);
                                        }}
                                    >
                                        <Undo2 />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        aria-label="Redo boundary change"
                                        disabled={
                                            cursor === history.length - 1 ||
                                            !customEditingAllowed
                                        }
                                        onClick={() => {
                                            setCursor(cursor + 1);
                                            setBoundary(null);
                                        }}
                                    >
                                        <Redo2 />
                                    </Button>
                                    {mode === 'polygon' && (
                                        <Button
                                            onClick={() => {
                                                const problem =
                                                    geometryError(shape);
                                                if (problem) setError(problem);
                                                else {
                                                    setMode(null);
                                                    setError('');
                                                }
                                            }}
                                        >
                                            Finish boundary
                                        </Button>
                                    )}
                                </div>
                                <p role="status" className="location-message">
                                    {mode === 'polygon'
                                        ? 'Click each corner, then Finish boundary. Drag corners or focus one and use arrow keys.'
                                        : mode === 'circle'
                                          ? 'Click the centre, then click the edge. You can also enter an exact radius below.'
                                          : boundary || sourceNeedsReview
                                            ? 'This boundary is linked to a site. Use a custom copy to move or reshape it.'
                                            : 'Drag the shaded area or centre handle to move the whole zone. Drag numbered corners to reshape. Arrow keys move a focused handle; Shift makes smaller changes.'}
                                </p>
                                <ClientLocationMap
                                    center={center}
                                    focusShape={shapeFocus}
                                    focus={mapFocus}
                                    searchPoint={place?.point}
                                    shape={shape}
                                    editing={customEditingAllowed}
                                    drawing={mode}
                                    onMapPoint={mapPoint}
                                    onChange={change}
                                />
                                {shape && !geometryError(shape) && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setMapFocus(null);
                                            setShapeFocus(
                                                structuredClone(shape),
                                            );
                                        }}
                                    >
                                        Show entire boundary
                                    </Button>
                                )}
                                {shape?.type === 'circle' && (
                                    <div className="location-field">
                                        <Label htmlFor="zone-radius">
                                            Radius (metres)
                                        </Label>
                                        <Input
                                            id="zone-radius"
                                            disabled={!customEditingAllowed}
                                            type="number"
                                            min="1"
                                            value={shape.radius_m || ''}
                                            onChange={(e) =>
                                                change({
                                                    ...shape,
                                                    radius_m: Number(
                                                        e.target.value,
                                                    ),
                                                })
                                            }
                                        />
                                    </div>
                                )}
                                {shape?.type === 'polygon' && (
                                    <p>
                                        {shape.coordinates.length} corners ·{' '}
                                        {geometryError(shape) ??
                                            'Boundary encloses an area'}
                                    </p>
                                )}
                                <Popover
                                    open={linkOpen}
                                    onOpenChange={setLinkOpen}
                                >
                                    <PopoverTrigger asChild>
                                        <Button variant="outline">
                                            {boundary
                                                ? `Linked: ${boundary.name}`
                                                : 'Use an existing site boundary'}
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent className="p-0">
                                        <Command>
                                            <CommandInput placeholder="Find a boundary…" />
                                            <CommandList>
                                                <CommandEmpty>
                                                    No eligible site boundaries.
                                                </CommandEmpty>
                                                {boundaries.map((item) => (
                                                    <CommandItem
                                                        key={item.id}
                                                        value={`${item.name} ${item.id}`}
                                                        onSelect={() => {
                                                            change(
                                                                item.geometry,
                                                            );
                                                            setBoundary(item);
                                                            setSourceReviewed(
                                                                true,
                                                            );
                                                            setLinkOpen(false);
                                                            setMode(null);
                                                        }}
                                                    >
                                                        {item.name}
                                                    </CommandItem>
                                                ))}
                                            </CommandList>
                                        </Command>
                                    </PopoverContent>
                                </Popover>
                                {boundary && (
                                    <p>
                                        This links a snapshot. Editing the shape
                                        creates a custom proposal; it cannot
                                        change the shared site boundary.{' '}
                                        <Button
                                            variant="link"
                                            onClick={() => {
                                                setBoundary(null);
                                                setSourceReviewed(true);
                                                setDirty(true);
                                            }}
                                        >
                                            Make a custom copy
                                        </Button>
                                    </p>
                                )}
                            </>
                        )}
                        {step === 1 && (
                            <>
                                <h2>Give the zone a clear purpose</h2>
                                <p>
                                    Use a name the support team will recognise.
                                </p>
                                <div className="location-field">
                                    <Label htmlFor="zone-name">Zone name</Label>
                                    <Input
                                        id="zone-name"
                                        maxLength={120}
                                        value={name}
                                        placeholder="For example, local library"
                                        onChange={(e) => {
                                            setName(e.target.value);
                                            setDirty(true);
                                        }}
                                    />
                                </div>
                                <div className="location-field">
                                    <Label htmlFor="zone-purpose">
                                        Purpose for this client
                                    </Label>
                                    <Textarea
                                        id="zone-purpose"
                                        value={purpose}
                                        maxLength={2000}
                                        onChange={(e) => {
                                            setPurpose(e.target.value);
                                            setDirty(true);
                                        }}
                                    />
                                </div>
                                <fieldset>
                                    <legend>
                                        What does this zone describe?
                                    </legend>
                                    <div className="location-choice-grid">
                                        {(['agreed', 'attention'] as const).map(
                                            (value) => (
                                                <Button
                                                    variant="outline"
                                                    type="button"
                                                    key={value}
                                                    aria-pressed={
                                                        classification === value
                                                    }
                                                    className="location-choice"
                                                    onClick={() => {
                                                        setClassification(
                                                            value,
                                                        );
                                                        setDirty(true);
                                                    }}
                                                >
                                                    <strong>
                                                        {value === 'agreed'
                                                            ? 'Agreed place'
                                                            : 'Area needing attention'}
                                                    </strong>
                                                    <span>
                                                        {value === 'agreed'
                                                            ? 'A place included in the client’s agreed plan.'
                                                            : 'A boundary the team wants to review carefully.'}
                                                    </span>
                                                </Button>
                                            ),
                                        )}
                                    </div>
                                </fieldset>
                            </>
                        )}
                        {step === 2 && (
                            <>
                                <h2>Define when the plan applies</h2>
                                <p>
                                    All times are Pacific/Auckland. These are
                                    proposed hours, not an active monitoring
                                    schedule.
                                </p>
                                <fieldset>
                                    <legend>Scheduled days</legend>
                                    <div className="location-actions">
                                        {weekdays.map((day, i) => (
                                            <Button
                                                key={day}
                                                variant={
                                                    schedule.weekdays.includes(
                                                        i + 1,
                                                    )
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                aria-pressed={schedule.weekdays.includes(
                                                    i + 1,
                                                )}
                                                onClick={() =>
                                                    editSchedule({
                                                        weekdays:
                                                            schedule.weekdays.includes(
                                                                i + 1,
                                                            )
                                                                ? schedule.weekdays.filter(
                                                                      (d) =>
                                                                          d !==
                                                                          i + 1,
                                                                  )
                                                                : [
                                                                      ...schedule.weekdays,
                                                                      i + 1,
                                                                  ].sort(),
                                                    })
                                                }
                                            >
                                                {day}
                                            </Button>
                                        ))}
                                    </div>
                                </fieldset>
                                <div className="location-two-fields">
                                    <div>
                                        <Label htmlFor="zone-start">
                                            Start time
                                        </Label>
                                        <TimePicker
                                            id="zone-start"
                                            label="Start time"
                                            value={schedule.start}
                                            onChange={(start) =>
                                                editSchedule({ start })
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="zone-end">
                                            Finish time
                                        </Label>
                                        <TimePicker
                                            id="zone-end"
                                            label="Finish time"
                                            value={schedule.end}
                                            onChange={(end) =>
                                                editSchedule({ end })
                                            }
                                        />
                                    </div>
                                </div>
                                <label className="location-check">
                                    <input
                                        type="checkbox"
                                        checked={schedule.following_day}
                                        onChange={(e) =>
                                            editSchedule({
                                                following_day: e.target.checked,
                                            })
                                        }
                                    />
                                    Finishes on the following day
                                </label>
                                <div className="location-two-fields">
                                    <div>
                                        <Label htmlFor="zone-from">
                                            First date
                                        </Label>
                                        <DatePicker
                                            id="zone-from"
                                            label="First date"
                                            value={schedule.first_date}
                                            onChange={(first_date) =>
                                                editSchedule({ first_date })
                                            }
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="zone-until">
                                            Last date
                                        </Label>
                                        <DatePicker
                                            id="zone-until"
                                            label="Last date"
                                            value={schedule.last_date}
                                            onChange={(last_date) =>
                                                editSchedule({ last_date })
                                            }
                                        />
                                    </div>
                                </div>
                                <div className="location-field">
                                    <Label htmlFor="zone-exception">
                                        Skip a scheduled date
                                    </Label>
                                    <div className="location-actions">
                                        <DatePicker
                                            id="zone-exception"
                                            label="Exception date"
                                            value={exceptionDate}
                                            onChange={setExceptionDate}
                                        />
                                        <Button
                                            variant="outline"
                                            disabled={!exceptionDate}
                                            onClick={() => {
                                                editSchedule({
                                                    exception_dates: [
                                                        ...new Set([
                                                            ...schedule.exception_dates,
                                                            exceptionDate,
                                                        ]),
                                                    ].sort(),
                                                });
                                                setExceptionDate('');
                                            }}
                                        >
                                            Add exception
                                        </Button>
                                    </div>
                                    {schedule.exception_dates.map((date) => (
                                        <div
                                            key={date}
                                            className="location-actions"
                                        >
                                            <span>{formatDateOnly(date)}</span>
                                            <Button
                                                variant="ghost"
                                                onClick={() =>
                                                    editSchedule({
                                                        exception_dates:
                                                            schedule.exception_dates.filter(
                                                                (item) =>
                                                                    item !==
                                                                    date,
                                                            ),
                                                    })
                                                }
                                            >
                                                Remove
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                                <div className="location-field">
                                    <Label htmlFor="zone-response">
                                        Proposed response or review notes
                                        (optional)
                                    </Label>
                                    <Textarea
                                        id="zone-response"
                                        maxLength={2000}
                                        value={response}
                                        onChange={(e) => {
                                            setResponse(e.target.value);
                                            setDirty(true);
                                        }}
                                    />
                                </div>
                            </>
                        )}
                        {step === 3 && (
                            <>
                                <h2>Review your zone draft</h2>
                                <div className="location-review">
                                    <strong>{name}</strong>
                                    <p>{purpose}</p>
                                    <dl>
                                        <dt>Boundary</dt>
                                        <dd>
                                            {shape?.type === 'circle'
                                                ? `Circle · ${shape.radius_m} m radius`
                                                : `Polygon · ${shape?.coordinates.length ?? 0} corners`}
                                        </dd>
                                        <dt>Scheduled days</dt>
                                        <dd>
                                            {schedule.weekdays
                                                .map((day) => weekdays[day - 1])
                                                .join(', ')}
                                        </dd>
                                        <dt>Hours</dt>
                                        <dd>
                                            {schedule.start}–{schedule.end}
                                            {schedule.following_day
                                                ? ' next day'
                                                : ''}{' '}
                                            · Pacific/Auckland
                                        </dd>
                                        <dt>Dates</dt>
                                        <dd>
                                            {formatDateOnly(
                                                schedule.first_date,
                                            )}
                                            –
                                            {formatDateOnly(schedule.last_date)}
                                        </dd>
                                        <dt>Exceptions</dt>
                                        <dd>
                                            {schedule.exception_dates.length
                                                ? schedule.exception_dates
                                                      .map((date) =>
                                                          formatDateOnly(date),
                                                      )
                                                      .join(', ')
                                                : 'None'}
                                        </dd>
                                        <dt>Proposed response</dt>
                                        <dd>{response || 'Not recorded'}</dd>
                                    </dl>
                                </div>
                                <p className="location-message">
                                    <ShieldCheck />
                                    Save creates a private draft. It does not
                                    start monitoring, change a shared boundary
                                    or send alerts.
                                </p>
                            </>
                        )}
                    </WizardStepPane>
                </div>
            </WizardShell>
            <AlertDialog open={discard} onOpenChange={setDiscard}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Discard unsaved zone changes?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Your saved draft stays as it was. These unsaved
                            changes will be removed.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep editing</AlertDialogCancel>
                        <AlertDialogAction onClick={onClose}>
                            Discard changes
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
