import {
    boundaryCentre,
    moveBoundary,
} from '@/components/client-location/boundary-geometry';
import ClientLocationMap from '@/components/client-location/client-location-map';
import {
    geometryError,
    type Coordinate,
    type Geometry,
} from '@/components/client-location/types';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import {
    CalendarDays,
    Circle,
    Copy,
    FileCheck2,
    Layers,
    MapPin,
    Pentagon,
    Redo2,
    Undo2,
} from 'lucide-react';
import { useState } from 'react';
import type { SharedFence } from './map-studio';
import { Modal, Notice, Picker } from './ui';
export type FenceAssignment = {
    purpose: string;
    weekdays: number[];
    start: string;
    end: string;
    overnight: boolean;
    first: string;
    last: string;
    exceptions: string[];
    sourceId?: string | number;
    sourceVersion?: string;
    response: string;
};
const home = { lat: -41.2838, lng: 174.7743 };
const geometry = (f: SharedFence): Geometry =>
    f.type === 'circle'
        ? {
              type: 'circle',
              center: f.center || home,
              radius_m: f.radius_m || 100,
          }
        : { type: 'polygon', coordinates: f.coordinates || [] };
export function FenceWizard({
    fences,
    initial,
    draftPoint,
    onSave,
    onClose,
}: {
    fences: SharedFence[];
    initial?: SharedFence;
    draftPoint?: Coordinate;
    onSave: (f: SharedFence) => void;
    onClose: () => void;
}) {
    const a = initial?.assignment;
    const [step, setStep] = useState(0),
        [name, setName] = useState(initial?.name || ''),
        [purpose, setPurpose] = useState(a?.purpose || ''),
        [response, setResponse] = useState(a?.response || ''),
        [source, setSource] = useState<string | number | undefined>(
            a?.sourceId || initial?.id,
        ),
        [sourceVersion, setSourceVersion] = useState<string | undefined>(
            a?.sourceVersion || initial?.revision || '1',
        );
    const [history, setHistory] = useState<(Geometry | null)[]>([
            initial
                ? geometry(initial)
                : draftPoint
                  ? { type: 'circle', center: draftPoint, radius_m: 100 }
                  : null,
        ]),
        [cursor, setCursor] = useState(0),
        [drawing, setDrawing] = useState<'circle' | 'polygon' | null>(null),
        [center, setCenter] = useState(draftPoint || home),
        [search, setSearch] = useState(''),
        [context, setContext] = useState<Coordinate | null>(null);
    const [weekdays, setWeekdays] = useState(a?.weekdays || [1, 2, 3, 4, 5]),
        [start, setStart] = useState(a?.start || '08:00'),
        [end, setEnd] = useState(a?.end || '18:00'),
        [overnight, setOvernight] = useState(a?.overnight || false),
        [first, setFirst] = useState(a?.first || '2026-09-22'),
        [last, setLast] = useState(a?.last || '2026-12-31'),
        [exceptions, setExceptions] = useState<string[]>(a?.exceptions || []),
        [exception, setException] = useState('');
    const [copied, setCopied] = useState(false);
    const [done, setDone] = useState(false),
        [dirty, setDirty] = useState(false),
        [discard, setDiscard] = useState(false),
        [error, setError] = useState('');
    const shape = history[cursor],
        selected = fences.find((f) => String(f.id) === String(source));
    const sourceChanged =
        !!source && (!selected || (selected.revision || '1') !== sourceVersion);
    const touch = () => setDirty(true);
    const commit = (next: Geometry | null) => {
        touch();
        setHistory([...history.slice(0, cursor + 1), next]);
        setCursor(cursor + 1);
    };
    const close = () => (done || !dirty ? onClose() : setDiscard(true));
    const boundaryError = () =>
        sourceChanged
            ? 'The linked boundary has changed. Select and review its current version.'
            : drawing
              ? 'Finish drawing the boundary before continuing.'
              : geometryError(shape) || '';
    const scheduleError = () =>
        !weekdays.length
            ? 'Choose at least one scheduled day.'
            : !start || !end
              ? 'Choose start and end times.'
              : !overnight && end <= start
                ? 'For overnight hours, select Ends the following day.'
                : overnight && end > start
                  ? 'Overnight windows must finish on or before the starting clock time.'
                  : !first || !last || last < first
                    ? 'Choose a valid first and last date.'
                    : exceptions.some((x) => x < first || x > last)
                      ? 'Exception dates must fall within the schedule.'
                      : '';
    const point = (p: Coordinate) => {
        if (source) return;
        if (drawing === 'circle') {
            if (shape?.type === 'circle' && shape.radius_m === 0) {
                const radius = Math.round(
                    Math.hypot(
                        (p.lat - shape.center.lat) * 111320,
                        (p.lng - shape.center.lng) *
                            111320 *
                            Math.cos((p.lat * Math.PI) / 180),
                    ),
                );
                commit({ ...shape, radius_m: radius });
                setDrawing(null);
            } else commit({ type: 'circle', center: p, radius_m: 0 });
        } else if (drawing === 'polygon')
            commit({
                type: 'polygon',
                coordinates: [
                    ...(shape?.type === 'polygon' ? shape.coordinates : []),
                    p,
                ],
            });
    };
    const save = () => {
        const errors = [
            boundaryError(),
            !name.trim() || !purpose.trim()
                ? 'Name the boundary and describe its purpose.'
                : '',
            scheduleError(),
        ];
        const bad = errors.findIndex(Boolean);
        if (bad >= 0) {
            setStep(bad);
            setError(errors[bad]);
            return;
        }
        const next: SharedFence = {
            id:
                (!copied ? initial?.id : undefined) ||
                'GEO-DEMO-' + crypto.randomUUID().slice(0, 6),
            name: name.trim(),
            scope: source
                ? 'Linked shared boundary · inactive'
                : 'Shared · Kōwhai House · inactive',
            type: shape!.type,
            center: shape!.type === 'circle' ? shape!.center : undefined,
            radius_m: shape!.type === 'circle' ? shape!.radius_m : undefined,
            coordinates:
                shape!.type === 'polygon' ? shape!.coordinates : undefined,
            color: 'var(--primary)',
            revision: copied ? '1' : initial?.revision || '1',
            assignment: {
                purpose,
                weekdays,
                start,
                end,
                overnight,
                first,
                last,
                exceptions,
                sourceId: source,
                sourceVersion,
                response,
            },
        };
        onSave(next);
        setDone(true);
    };
    return (
        <>
            <WizardShell
                open
                title={
                    initial
                        ? 'Manage geofence assignment'
                        : 'Create or link geofence'
                }
                description="Shared boundary · vehicle assignment · inactive monitoring"
                railIcon={Layers}
                railTitle="Geofence assignment"
                railSub="Kōwhai van · VH-014"
                maxWidth="min(92vw,1100px)"
                steps={[
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
                ]}
                stepIndex={step}
                onStepClick={setStep}
                pct={Math.round(
                    ([
                        !boundaryError(),
                        !!name.trim() && !!purpose.trim(),
                        !scheduleError(),
                    ].filter(Boolean).length /
                        3) *
                        100,
                )}
                onClose={close}
                footerStart={
                    <Button variant="outline" onClick={close}>
                        Cancel
                    </Button>
                }
                footerEnd={
                    <>
                        <Button
                            variant="outline"
                            disabled={!step}
                            onClick={() => setStep(step - 1)}
                        >
                            Back
                        </Button>
                        <Button
                            onClick={() => {
                                if (step === 3) {
                                    save();
                                    return;
                                }
                                const e =
                                    step === 0
                                        ? boundaryError()
                                        : step === 1
                                          ? !name.trim() || !purpose.trim()
                                              ? 'Enter a name and purpose.'
                                              : ''
                                          : scheduleError();
                                if (e) {
                                    setError(e);
                                    return;
                                }
                                setError('');
                                setStep(step + 1);
                            }}
                        >
                            {step === 3
                                ? 'Save inactive assignment'
                                : 'Continue'}
                        </Button>
                    </>
                }
                success={
                    done ? (
                        <WizardSuccessPane
                            title="Inactive assignment saved"
                            blurb="The boundary and schedule are linked to this vehicle. No monitoring or alerts are active."
                            actions={
                                <Button onClick={onClose}>Back to map</Button>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    <div className="flow-stack">
                        {error && (
                            <Notice
                                tone="critical"
                                title="Assignment not saved"
                            >
                                {error}
                            </Notice>
                        )}
                        {step === 0 && (
                            <>
                                <Picker
                                    label="Existing shared boundary"
                                    value={String(source || '')}
                                    onChange={(id) => {
                                        const f = fences.find(
                                            (x) => String(x.id) === id,
                                        )!;
                                        setSource(f.id);
                                        setSourceVersion(f.revision || '1');
                                        setName(f.name || String(f.id));
                                        commit(geometry(f));
                                        setDrawing(null);
                                        setCenter(boundaryCentre(geometry(f)));
                                    }}
                                    options={fences.map((f) => ({
                                        id: String(f.id),
                                        name: f.name || String(f.id),
                                        detail: `${f.scope} · version ${f.revision || '1'}`,
                                    }))}
                                />
                                {source ? (
                                    <Notice
                                        title={
                                            sourceChanged
                                                ? 'Source review required'
                                                : 'Linked geometry is read-only'
                                        }
                                    >
                                        Shared edits affect other profiles.{' '}
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => {
                                                touch();
                                                setCopied(true);
                                                setSource(undefined);
                                                setSourceVersion(undefined);
                                                setName(
                                                    name + ' · custom copy',
                                                );
                                            }}
                                        >
                                            <Copy size={14} />
                                            Make a custom copy
                                        </Button>
                                    </Notice>
                                ) : (
                                    <div className="geo-tools">
                                        <Button
                                            variant={
                                                drawing === 'circle'
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                            size="sm"
                                            onClick={() => {
                                                setDrawing('circle');
                                                commit(null);
                                            }}
                                        >
                                            <Circle size={15} />
                                            Draw circle
                                        </Button>
                                        <Button
                                            variant={
                                                drawing === 'polygon'
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                            size="sm"
                                            onClick={() => {
                                                setDrawing('polygon');
                                                commit({
                                                    type: 'polygon',
                                                    coordinates: [],
                                                });
                                            }}
                                        >
                                            <Pentagon size={15} />
                                            Draw polygon
                                        </Button>
                                        {drawing === 'polygon' && (
                                            <Button
                                                size="sm"
                                                onClick={() => {
                                                    const e =
                                                        geometryError(shape);
                                                    if (e) setError(e);
                                                    else {
                                                        setDrawing(null);
                                                        setError('');
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
                                            <Undo2 size={14} />
                                            Undo
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={
                                                cursor === history.length - 1
                                            }
                                            onClick={() => {
                                                setCursor(cursor + 1);
                                                setDrawing(null);
                                            }}
                                        >
                                            <Redo2 size={14} />
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
                                <Picker
                                    label="Find a place (demo locations)"
                                    value={search}
                                    onChange={(id) => {
                                        setSearch(id);
                                        const p =
                                            id === 'home'
                                                ? home
                                                : {
                                                      lat: -41.2865,
                                                      lng: 174.7762,
                                                  };
                                        setCenter(p);
                                    }}
                                    options={[
                                        {
                                            id: 'home',
                                            name: 'Kōwhai House',
                                            detail: 'Synthetic site location',
                                        },
                                        {
                                            id: 'pickup',
                                            name: 'Community pickup',
                                            detail: 'Synthetic nearby location',
                                        },
                                    ]}
                                />
                                <div className="geo-editor-map">
                                    <ClientLocationMap
                                        center={center}
                                        focus={center}
                                        shape={shape}
                                        editing={!source}
                                        drawing={drawing}
                                        onChange={commit}
                                        onMapPoint={point}
                                        onContext={setContext}
                                    />
                                    {context && !source && (
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
                                <p className="studio-footnote">
                                    {source
                                        ? 'Linked geometry is read-only. Make a custom copy to edit.'
                                        : drawing === 'circle'
                                          ? 'Click the centre, then the edge.'
                                          : drawing === 'polygon'
                                            ? 'Click each corner, then Finish boundary.'
                                            : 'Drag the boundary or handles. Focus a handle and use arrow keys; Shift makes smaller adjustments. Right-click to draw here.'}
                                </p>
                                {shape?.type === 'circle' && !source && (
                                    <div className="geo-radius">
                                        <label htmlFor="geo-radius">
                                            Radius (metres)
                                        </label>
                                        <Input
                                            id="geo-radius"
                                            type="number"
                                            min="1"
                                            value={shape.radius_m}
                                            onChange={(e) =>
                                                commit({
                                                    ...shape,
                                                    radius_m: +e.target.value,
                                                })
                                            }
                                        />
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                commit(
                                                    moveBoundary(
                                                        shape,
                                                        boundaryCentre(shape),
                                                        center,
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
                                <div className="field">
                                    <label htmlFor="geo-name">
                                        Geofence name
                                    </label>
                                    <Input
                                        id="geo-name"
                                        value={name}
                                        onChange={(e) => {
                                            touch();
                                            setName(e.target.value);
                                        }}
                                    />
                                </div>
                                <div className="field">
                                    <label htmlFor="geo-purpose">
                                        Purpose for this vehicle
                                    </label>
                                    <Textarea
                                        id="geo-purpose"
                                        value={purpose}
                                        onChange={(e) => {
                                            touch();
                                            setPurpose(e.target.value);
                                        }}
                                    />
                                </div>
                                <div className="field">
                                    <label htmlFor="geo-response">
                                        Proposed response instructions
                                        (optional)
                                    </label>
                                    <Textarea
                                        id="geo-response"
                                        value={response}
                                        onChange={(e) => {
                                            touch();
                                            setResponse(e.target.value);
                                        }}
                                    />
                                </div>
                                <ReviewCard icon={Layers} title="Ownership">
                                    <ReviewRow
                                        label="Boundary"
                                        value={
                                            source
                                                ? 'Shared source · ' + source
                                                : 'New shared boundary · Kōwhai House'
                                        }
                                    />
                                    <ReviewRow
                                        label="Assignment"
                                        value="Vehicle VH-014 · Kōwhai van"
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
                                <p>
                                    Pacific/Auckland · schedule uses local time,
                                    including daylight saving.
                                </p>
                                <div className="weekday-options">
                                    {[
                                        'Sun',
                                        'Mon',
                                        'Tue',
                                        'Wed',
                                        'Thu',
                                        'Fri',
                                        'Sat',
                                    ].map((d, i) => (
                                        <Button
                                            key={d}
                                            variant={
                                                weekdays.includes(i)
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                            aria-pressed={weekdays.includes(i)}
                                            onClick={() => {
                                                touch();
                                                setWeekdays(
                                                    weekdays.includes(i)
                                                        ? weekdays.filter(
                                                              (x) => x !== i,
                                                          )
                                                        : [...weekdays, i],
                                                );
                                            }}
                                        >
                                            {d}
                                        </Button>
                                    ))}
                                </div>
                                <div className="geo-field-grid">
                                    <TimePicker
                                        id="geo-start"
                                        label="Starts at"
                                        value={start}
                                        onChange={(v) => {
                                            touch();
                                            setStart(v);
                                        }}
                                    />
                                    <TimePicker
                                        id="geo-end"
                                        label="Ends at"
                                        value={end}
                                        onChange={(v) => {
                                            touch();
                                            setEnd(v);
                                        }}
                                    />
                                    <DatePicker
                                        id="geo-first"
                                        label="First date"
                                        value={first}
                                        onChange={(v) => {
                                            touch();
                                            setFirst(v);
                                        }}
                                    />
                                    <DatePicker
                                        id="geo-last"
                                        label="Last date"
                                        value={last}
                                        onChange={(v) => {
                                            touch();
                                            setLast(v);
                                        }}
                                    />
                                </div>
                                <label className="inline-check">
                                    <input
                                        type="checkbox"
                                        checked={overnight}
                                        onChange={(e) => {
                                            touch();
                                            setOvernight(e.target.checked);
                                        }}
                                    />
                                    Ends the following day
                                </label>
                                <div className="geo-radius">
                                    <DatePicker
                                        id="geo-exception"
                                        label="Exception date"
                                        value={exception}
                                        onChange={setException}
                                    />
                                    <Button
                                        variant="outline"
                                        disabled={!exception}
                                        onClick={() => {
                                            if (
                                                exception < first ||
                                                exception > last
                                            ) {
                                                setError(
                                                    'Exception date must fall within the schedule.',
                                                );
                                                return;
                                            }
                                            touch();
                                            setExceptions([
                                                ...new Set([
                                                    ...exceptions,
                                                    exception,
                                                ]),
                                            ]);
                                            setException('');
                                            setError('');
                                        }}
                                    >
                                        Exclude date
                                    </Button>
                                </div>
                                {exceptions.map((d) => (
                                    <div className="geo-exception" key={d}>
                                        {d}
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => {
                                                touch();
                                                setExceptions(
                                                    exceptions.filter(
                                                        (x) => x !== d,
                                                    ),
                                                );
                                            }}
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
                                        value={
                                            shape?.type === 'circle'
                                                ? `${shape.radius_m} m circle`
                                                : shape
                                                  ? `${shape.coordinates.length} polygon corners`
                                                  : 'Missing'
                                        }
                                    />
                                    <ReviewRow
                                        label="Source"
                                        value={
                                            source
                                                ? `${source} · version ${sourceVersion}`
                                                : 'New shared boundary'
                                        }
                                    />
                                    <ReviewRow
                                        label="Purpose"
                                        value={purpose}
                                    />
                                    <ReviewRow
                                        label="Profile"
                                        value="VH-014 · Kōwhai van"
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={CalendarDays}
                                    title="Schedule"
                                    onEdit={() => setStep(2)}
                                >
                                    <ReviewRow
                                        label="Days"
                                        value={weekdays
                                            .map(
                                                (d) =>
                                                    [
                                                        'Sun',
                                                        'Mon',
                                                        'Tue',
                                                        'Wed',
                                                        'Thu',
                                                        'Fri',
                                                        'Sat',
                                                    ][d],
                                            )
                                            .join(', ')}
                                    />
                                    <ReviewRow
                                        label="Hours"
                                        value={`${start}–${end}${overnight ? ' next day' : ''} · Pacific/Auckland`}
                                    />
                                    <ReviewRow
                                        label="Dates"
                                        value={`${first} to ${last}`}
                                    />
                                    <ReviewRow
                                        label="Exceptions"
                                        value={exceptions.join(', ') || 'None'}
                                    />
                                </ReviewCard>
                                <Notice title="Save as inactive">
                                    Assignment does not enable tracking or
                                    alerts. Vehicle monitoring needs an approved
                                    response policy, recipient and permitted
                                    tracker; the client-specific Control Room
                                    policy is not assumed.
                                </Notice>
                            </>
                        )}
                    </div>
                </WizardStepPane>
            </WizardShell>
            {discard && (
                <Modal
                    title="Discard geofence changes?"
                    description="Unsaved boundary and assignment"
                    icon={Layers}
                    onClose={() => setDiscard(false)}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setDiscard(false)}
                            >
                                Keep editing
                            </Button>
                            <Button variant="destructive" onClick={onClose}>
                                Discard draft
                            </Button>
                        </>
                    }
                >
                    The saved boundary and assignment remain unchanged.
                </Modal>
            )}
        </>
    );
}
