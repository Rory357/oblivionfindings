import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import {
    DateTimeField,
    localDateTimeLabel,
    validLocalDateTime,
} from '@/components/fleet-assets/maintenance/date-time-field';
import {
    TimePicker,
    displayTime,
} from '@/components/fleet-assets/maintenance/time-picker';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import {
    Activity,
    ArrowRight,
    Bell,
    CalendarDays,
    Check,
    CheckCircle2,
    ChevronDown,
    CircleHelp,
    Clock,
    FileCheck2,
    Layers,
    MapPin,
    Maximize2,
    Navigation,
    Pencil,
    Plus,
    Radio,
    Route,
    ShieldAlert,
    ShieldCheck,
    Users,
    X,
    ZoomIn,
    ZoomOut,
} from 'lucide-react';
import React, { useRef, useState } from 'react';

import {
    ActivityHistory,
    LocatePanel,
    LocateStatus,
    useLocatePreview,
} from './history-and-locate';
import { BoundaryEditor, MapActions } from './map-tools';

export type Point = [number, number];
export type Zone = {
    id: string;
    name: string;
    place: string;
    purpose: string;
    kind: 'agreed' | 'attention';
    shape: 'circle' | 'polygon';
    centre: Point;
    radius: number;
    points: Point[];
    days: number[];
    start: string;
    end: string;
    overnight: boolean;
    from: string;
    until: string;
    pauseDate: string;
    draft?: boolean;
    confirmation: string;
    response: string;
};
type Outing = {
    id: string;
    name: string;
    place: string;
    worker: string;
    start: string;
    arrival: string;
    end: string;
    note: string;
    draft?: boolean;
};
const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const places = [
    {
        id: 'house',
        name: 'Example House',
        sub: 'Existing place · P-DEMO-01',
        point: [115, 226] as Point,
    },
    {
        id: 'programme',
        name: 'Day programme',
        sub: 'Existing place · P-DEMO-02',
        point: [184, 81] as Point,
    },
    {
        id: 'gardens',
        name: 'Example Gardens',
        sub: 'Existing place · P-DEMO-03',
        point: [356, 219] as Point,
    },
    {
        id: 'library',
        name: 'Town library',
        sub: 'Existing place · P-DEMO-04',
        point: [532, 96] as Point,
    },
];
const staff = [
    {
        id: 'jamie',
        name: 'Jamie Taylor',
        sub: 'Assigned support worker · Example House',
    },
    {
        id: 'priya',
        name: 'Priya Chen',
        sub: 'Permitted outing coordinator · Example House',
    },
];
const baseZones: Zone[] = [
    {
        id: 'G-DEMO-01 · rule 3',
        name: 'Home',
        place: 'house',
        purpose: 'Agreed residential support',
        kind: 'agreed',
        shape: 'circle',
        centre: [115, 226],
        radius: 68,
        points: [],
        days: [0, 1, 2, 3, 4, 5, 6],
        start: '00:00',
        end: '23:59',
        overnight: false,
        from: '2026-09-01',
        until: '2026-09-30',
        pauseDate: '',
        confirmation: 'Awaiting approved rule',
        response: 'Existing Control Room response',
    },
    {
        id: 'G-DEMO-02 · rule 2',
        name: 'Day programme',
        place: 'programme',
        purpose: 'Agreed daytime programme',
        kind: 'agreed',
        shape: 'polygon',
        centre: [184, 81],
        radius: 65,
        points: [
            [112, 43],
            [242, 43],
            [258, 114],
            [144, 130],
        ],
        days: [0, 1, 2, 3, 4],
        start: '09:00',
        end: '15:00',
        overnight: false,
        from: '2026-09-01',
        until: '2026-09-30',
        pauseDate: '2026-09-25',
        confirmation: 'Awaiting approved rule',
        response: 'Existing Control Room response',
    },
    {
        id: 'G-DEMO-03 · rule 1',
        name: 'Garden walks',
        place: 'gardens',
        purpose: 'Agreed weekend community outings',
        kind: 'agreed',
        shape: 'circle',
        centre: [356, 219],
        radius: 87,
        points: [],
        days: [5, 6],
        start: '10:00',
        end: '16:00',
        overnight: false,
        from: '2026-09-01',
        until: '2026-09-30',
        pauseDate: '',
        confirmation: 'Awaiting approved rule',
        response: 'Existing Control Room response',
    },
    {
        id: 'G-DEMO-04 · rule 1',
        name: 'Waterside review area',
        place: 'gardens',
        purpose: 'Individual support-plan attention area',
        kind: 'attention',
        shape: 'polygon',
        centre: [610, 240],
        radius: 60,
        points: [
            [572, 166],
            [655, 166],
            [655, 290],
            [600, 290],
        ],
        days: [0, 1, 2, 3, 4, 5, 6],
        start: '00:00',
        end: '23:59',
        overnight: false,
        from: '2026-09-01',
        until: '2026-09-30',
        pauseDate: '',
        confirmation: 'Awaiting approved rule',
        response: 'Existing Control Room response',
    },
];
const baseOutings: Outing[] = [
    {
        id: 'O-DEMO-21',
        name: 'Garden walk',
        place: 'gardens',
        worker: 'jamie',
        start: '2026-09-20T13:30',
        arrival: '2026-09-20T13:45',
        end: '2026-09-20T15:00',
        note: 'Agreed community walk. Jamie remains the responsible worker. Travel context does not suppress alerts.',
    },
    {
        id: 'O-DEMO-22',
        name: 'Library visit',
        place: 'library',
        worker: 'priya',
        start: '2026-09-20T15:00',
        arrival: '2026-09-20T15:15',
        end: '2026-09-20T16:30',
        note: 'A temporary visit to the library. Scheduled end is visible; an extension needs a separate review.',
    },
];
const zoneSteps = [
    {
        key: 'place',
        label: 'Place & purpose',
        blurb: 'Client-specific use',
        icon: MapPin,
    },
    {
        key: 'boundary',
        label: 'Boundary',
        blurb: 'Circle or custom shape',
        icon: Layers,
    },
    {
        key: 'schedule',
        label: 'Schedule',
        blurb: 'When this rule applies',
        icon: CalendarDays,
    },
    {
        key: 'response',
        label: 'Response',
        blurb: 'Existing response pathway',
        icon: Bell,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check the proposed rule',
        icon: FileCheck2,
    },
];
const outingSteps = [
    {
        key: 'plan',
        label: 'Outing plan',
        blurb: 'Destination and worker',
        icon: Route,
    },
    {
        key: 'times',
        label: 'Time bounds',
        blurb: 'Departure, arrival, return',
        icon: Clock,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'A temporary proposal',
        icon: FileCheck2,
    },
];
function Info({
    children,
    warning = false,
}: {
    children: React.ReactNode;
    warning?: boolean;
}) {
    return (
        <div className={`v2-info ${warning ? 'warning' : ''}`}>
            <CircleHelp className="size-4 shrink-0" />
            <div>{children}</div>
        </div>
    );
}
function Box({
    title,
    subtitle,
    action,
    children,
}: {
    title: string;
    subtitle?: string;
    action?: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <section className="v2-box">
            <header>
                <div>
                    <h3>{title}</h3>
                    {subtitle && <p>{subtitle}</p>}
                </div>
                {action}
            </header>
            {children}
        </section>
    );
}
function Field({
    id,
    label,
    children,
}: {
    id: string;
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="v2-field">
            <label htmlFor={id}>{label}</label>
            {children}
        </div>
    );
}
function Picker({
    id,
    label,
    value,
    onChange,
    options = places,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (s: string) => void;
    options?: { id: string; name: string; sub: string }[];
}) {
    const [open, setOpen] = useState(false);
    const selected = options.find((p) => p.id === value);
    return (
        <Field id={id} label={label}>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <button
                        id={id}
                        role="combobox"
                        aria-label={label}
                        aria-expanded={open}
                        className="v2-picker"
                    >
                        <MapPin className="size-4" />
                        <span>
                            <strong>
                                {selected?.name || 'Search and choose a record'}
                            </strong>
                            {selected && <small>{selected.sub}</small>}
                        </span>
                        <ChevronDown className="ml-auto size-4" />
                    </button>
                </PopoverTrigger>
                <PopoverContent
                    className="w-[390px] max-w-[85vw] p-0"
                    align="start"
                >
                    <Command>
                        <CommandInput
                            placeholder={`Search ${label.toLowerCase()}`}
                            aria-label={`Search ${label.toLowerCase()}`}
                        />
                        <CommandList>
                            <CommandEmpty>
                                No matching permitted records.
                            </CommandEmpty>
                            {options.map((p) => (
                                <CommandItem
                                    key={p.id}
                                    value={`${p.name} ${p.sub}`}
                                    onSelect={() => {
                                        onChange(p.id);
                                        setOpen(false);
                                    }}
                                >
                                    <div>
                                        <strong>{p.name}</strong>
                                        <small className="block text-muted-foreground">
                                            {p.sub}
                                        </small>
                                    </div>
                                    {p.id === value && (
                                        <Check className="ml-auto size-4" />
                                    )}
                                </CommandItem>
                            ))}
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
        </Field>
    );
}
function FictionMap({
    zones = baseZones,
    selected = '',
    onSelect,
    observation = true,
    uncertain = false,
    accuracyKnown = true,
    outside = false,
    draw,
    onPoint,
    zoneLayer = true,
}: {
    zones?: Zone[];
    selected?: string;
    onSelect?: (id: string) => void;
    observation?: boolean;
    uncertain?: boolean;
    accuracyKnown?: boolean;
    outside?: boolean;
    draw?: Zone;
    onPoint?: (point: Point) => void;
    zoneLayer?: boolean;
}) {
    const [zoom, setZoom] = useState(1);
    const point: Point = outside
        ? [556, 305]
        : uncertain
          ? [439, 227]
          : [356, 219];
    return (
        <div className={`v2-map ${draw ? 'drawing' : ''}`}>
            <svg
                viewBox="0 0 700 345"
                data-map-zoom={zoom}
                role="img"
                aria-label={
                    draw
                        ? 'Fictional boundary drawing surface'
                        : 'Fictional places, proposed zones and latest observation'
                }
                onClick={(e) => {
                    if (!onPoint) return;
                    const r = e.currentTarget.getBoundingClientRect();
                    onPoint([
                        Math.round(((e.clientX - r.left) * 700) / r.width),
                        Math.round(((e.clientY - r.top) * 345) / r.height),
                    ]);
                }}
            >
                <g
                    transform={`translate(350 172) scale(${zoom}) translate(-350 -172)`}
                >
                    <rect width="700" height="345" fill="var(--muted)" />
                    {Array.from({ length: 20 }, (_, i) => (
                        <rect
                            key={i}
                            x={(i % 5) * 150 + 12}
                            y={Math.floor(i / 5) * 92 + 10}
                            width="120"
                            height="65"
                            rx="9"
                            fill="var(--card)"
                            opacity=".6"
                        />
                    ))}
                    <path
                        d="M0 162H700M292 0V345M486 0L420 345"
                        fill="none"
                        stroke="var(--card)"
                        strokeWidth="21"
                    />
                    <path
                        d="M676 0Q620 180 684 345"
                        stroke="var(--status-info-bg)"
                        strokeWidth="29"
                        fill="none"
                    />
                    {zoneLayer &&
                        !draw &&
                        zones
                            .filter((z) => true)
                            .map((z) => (
                                <g
                                    key={z.id}
                                    role="button"
                                    tabIndex={0}
                                    aria-label={`Show zone ${z.name}`}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        onSelect?.(z.id);
                                    }}
                                    onKeyDown={(e) => {
                                        if (
                                            e.key === 'Enter' ||
                                            e.key === ' '
                                        ) {
                                            e.preventDefault();
                                            onSelect?.(z.id);
                                        }
                                    }}
                                    className="v2-map-zone"
                                    data-zone-id={z.id}
                                    style={{
                                        color:
                                            z.kind === 'attention'
                                                ? 'var(--status-warning)'
                                                : 'var(--primary)',
                                    }}
                                >
                                    {z.shape === 'circle' ? (
                                        <circle
                                            cx={z.centre[0]}
                                            cy={z.centre[1]}
                                            r={z.radius}
                                            fill="currentColor"
                                            fillOpacity=".08"
                                            stroke="currentColor"
                                            strokeWidth={
                                                selected === z.id ? 3 : 1.5
                                            }
                                            strokeDasharray={
                                                !z.draft && z.days.includes(6)
                                                    ? undefined
                                                    : '5 4'
                                            }
                                        />
                                    ) : (
                                        <polygon
                                            points={z.points
                                                .map((p) => p.join(','))
                                                .join(' ')}
                                            fill="currentColor"
                                            fillOpacity=".08"
                                            stroke="currentColor"
                                            strokeWidth={
                                                selected === z.id ? 3 : 1.5
                                            }
                                            strokeDasharray="5 4"
                                        />
                                    )}
                                </g>
                            ))}
                    {places.map((p) => (
                        <g key={p.id}>
                            <rect
                                x={p.point[0] - 52}
                                y={p.point[1] - 30}
                                width="104"
                                height="22"
                                rx="5"
                                fill="var(--card)"
                            />
                            <text
                                x={p.point[0]}
                                y={p.point[1] - 15}
                                textAnchor="middle"
                                fill="var(--foreground)"
                                fontSize="10"
                            >
                                {p.name}
                            </text>
                        </g>
                    ))}
                    {draw &&
                        (draw.shape === 'circle' ? (
                            <circle
                                cx={draw.centre[0]}
                                cy={draw.centre[1]}
                                r={draw.radius}
                                fill="var(--primary)"
                                fillOpacity=".12"
                                stroke="var(--primary)"
                                strokeWidth="2"
                            />
                        ) : (
                            <>
                                <polygon
                                    points={draw.points
                                        .map((p) => p.join(','))
                                        .join(' ')}
                                    fill="var(--primary)"
                                    fillOpacity=".12"
                                    stroke="var(--primary)"
                                    strokeWidth="2"
                                />
                                {draw.points.map((p, i) => (
                                    <circle
                                        key={i}
                                        cx={p[0]}
                                        cy={p[1]}
                                        r="4"
                                        fill="var(--primary)"
                                    />
                                ))}
                            </>
                        ))}
                    {observation && !draw && (
                        <g>
                            {accuracyKnown && (
                                <circle
                                    cx={point[0]}
                                    cy={point[1]}
                                    r={uncertain ? 45 : 15}
                                    fill="var(--primary)"
                                    fillOpacity=".12"
                                    stroke="var(--primary)"
                                    strokeDasharray="3 3"
                                />
                            )}
                            <circle
                                cx={point[0]}
                                cy={point[1]}
                                r="7"
                                stroke="var(--card)"
                                strokeWidth="3"
                                fill="var(--primary)"
                            />
                        </g>
                    )}
                </g>
            </svg>
            <span className="v2-map-caption">
                Fictional map ·{' '}
                {draw
                    ? 'click to shape the proposed boundary'
                    : 'illustrative geometry, no live tracking'}
            </span>
            {!draw && (
                <div className="v2-map-tools">
                    <Button
                        size="icon"
                        variant="outline"
                        aria-label="Zoom map in"
                        onClick={() => setZoom(Math.min(1.4, zoom + 0.2))}
                    >
                        <ZoomIn className="size-4" />
                    </Button>
                    <Button
                        size="icon"
                        variant="outline"
                        aria-label="Reset map view"
                        onClick={() => setZoom(1)}
                    >
                        <Navigation className="size-4" />
                    </Button>
                    <Button
                        size="icon"
                        variant="outline"
                        aria-label="Zoom map out"
                        onClick={() => setZoom(Math.max(0.8, zoom - 0.2))}
                    >
                        <ZoomOut className="size-4" />
                    </Button>
                </div>
            )}
        </div>
    );
}
function DraftGuard({
    open,
    onKeep,
    onDiscard,
}: {
    open: boolean;
    onKeep: () => void;
    onDiscard: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onKeep}>
            <DialogContent onCloseAutoFocus={(e) => e.preventDefault()}>
                <DialogHeader>
                    <DialogTitle>Discard this draft?</DialogTitle>
                    <DialogDescription>
                        Your unsaved preview entries will be lost. No
                        operational rule has changed.
                    </DialogDescription>
                </DialogHeader>
                <div className="flex justify-end gap-2">
                    <Button variant="outline" onClick={onKeep}>
                        Keep editing
                    </Button>
                    <Button onClick={onDiscard}>Discard draft</Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
function ZoneWizard({
    initial,
    seed,
    onClose,
    onSave,
    returnFocus,
}: {
    initial?: Zone;
    seed?: { shape: Zone['shape']; point?: Point };
    onClose: () => void;
    onSave: (zone: Zone) => void;
    returnFocus: () => void;
}) {
    const [zone, setZone] = useState<Zone>(
        initial
            ? { ...initial, draft: true, response: '' }
            : {
                  id: `DRAFT-${Date.now()}`,
                  name: '',
                  place: '',
                  purpose: '',
                  kind: 'agreed',
                  shape: seed?.shape || 'polygon',
                  centre: seed?.point || [356, 219],
                  radius: 70,
                  points:
                      seed?.shape === 'polygon' && seed.point
                          ? [seed.point]
                          : [],
                  days: [],
                  start: '',
                  end: '',
                  overnight: false,
                  from: '',
                  until: '',
                  pauseDate: '',
                  confirmation: '',
                  response: '',
                  draft: true,
              },
    );
    const [boundaryReady, setBoundaryReady] = useState(!!initial);
    const cancelRef = useRef<HTMLButtonElement>(null);
    const [step, setStep] = useState(0),
        [error, setError] = useState(''),
        [discard, setDiscard] = useState(false),
        [changed, setChanged] = useState(false),
        [done, setDone] = useState(false),
        [fail, setFail] = useState(false),
        [retried, setRetried] = useState(false);
    const set = (key: keyof Zone, value: any) => {
        setZone((z) => ({ ...z, [key]: value }));
        setChanged(true);
        setError('');
    };
    function validate(all = false) {
        let message = '';
        let target = step;
        if (
            (all || step === 1) &&
            (!zone.name.trim() || !zone.purpose.trim())
        ) {
            message = 'Enter a zone name and its purpose in the support plan.';
            target = 1;
        } else if (
            (all || step === 0) &&
            (!boundaryReady ||
                (zone.shape === 'polygon' && zone.points.length < 3))
        ) {
            message =
                'Draw the boundary, then choose Finish boundary. Custom shapes need at least three corners.';
            target = 0;
        } else if (
            (all || step === 2) &&
            (!zone.days.length ||
                !zone.start ||
                !zone.end ||
                !zone.from ||
                !zone.until ||
                zone.until < zone.from ||
                zone.start === zone.end ||
                (!zone.overnight && zone.end < zone.start) ||
                (zone.pauseDate &&
                    (zone.pauseDate < zone.from ||
                        zone.pauseDate > zone.until)))
        ) {
            message =
                'Choose days, complete the date range and times, and confirm overnight hours if needed. Exceptions must fall within the date range.';
            target = 2;
        } else if ((all || step === 3) && !zone.response) {
            message =
                'Choose the proposed trigger for the existing Control Room response.';
            target = 3;
        }
        if (message) {
            setStep(target);
            setError(message);
            requestAnimationFrame(() =>
                document.getElementById('zone-error')?.focus(),
            );
            return false;
        }
        return true;
    }
    const close = () => (changed && !done ? setDiscard(true) : onClose());
    const scheduleText = `${zone.days.map((i) => days[i]).join(', ') || 'No days chosen'} · ${displayTime(zone.start)}–${displayTime(zone.end)}${zone.overnight ? ' next day' : ''}`;
    return (
        <>
            <WizardShell
                open
                onClose={close}
                onCloseAutoFocus={(e) => {
                    e.preventDefault();
                    requestAnimationFrame(() =>
                        requestAnimationFrame(returnFocus),
                    );
                }}
                title={initial ? 'Edit safe zone proposal' : 'Draw safe zone'}
                description="Synthetic proposal for Alex Hale. Saving adds a preview draft; monitoring is not enabled."
                railIcon={MapPin}
                railTitle={initial ? 'Edit safe zone' : 'New safe zone'}
                railSub="Alex Hale · CL-DEMO-024"
                steps={[zoneSteps[1], zoneSteps[0], ...zoneSteps.slice(2)]}
                stepIndex={step}
                onStepClick={(i) => {
                    setStep(i);
                    setError('');
                }}
                pct={Math.round(
                    ([
                        boundaryReady,
                        zone.name,
                        zone.purpose,
                        zone.days.length,
                        zone.start,
                        zone.end,
                        zone.from,
                        zone.until,
                        zone.response,
                    ].filter(Boolean).length /
                        9) *
                        100,
                )}
                pctLabel="Draft detail"
                maxWidth="min(94vw,1140px)"
                maxHeight="min(87vh,810px)"
                railExtra={
                    <div className="draft-note">
                        Client-specific rule referencing canonical geometry. No
                        live rule, alert or consent changes.
                    </div>
                }
                footerStart={
                    <>
                        <Button
                            ref={cancelRef}
                            variant="outline"
                            onClick={close}
                        >
                            {done ? 'Close' : 'Cancel'}
                        </Button>
                        {step > 0 && !done && (
                            <Button
                                variant="ghost"
                                onClick={() => {
                                    setStep(step - 1);
                                    setError('');
                                }}
                            >
                                Back
                            </Button>
                        )}
                    </>
                }
                footerEnd={
                    !done && (
                        <Button
                            onClick={() => {
                                if (!validate(step === 4)) return;
                                if (step < 4) {
                                    setStep(step + 1);
                                    return;
                                }
                                if (fail && !retried) {
                                    setRetried(true);
                                    setError(
                                        'The illustrative save failed. Your entries are retained; retry only adds this local draft.',
                                    );
                                    return;
                                }
                                onSave(zone);
                                setDone(true);
                            }}
                        >
                            {step < 4
                                ? step === 0
                                    ? 'Name & schedule this zone'
                                    : 'Continue'
                                : retried
                                  ? 'Retry preview save'
                                  : 'Add draft to preview'}
                            <ArrowRight className="size-4" />
                        </Button>
                    )
                }
            >
                {done ? (
                    <div className="v2-success">
                        <CheckCircle2 className="size-10 text-primary" />
                        <h3>Zone draft added to this preview</h3>
                        <p>
                            {zone.name} · {scheduleText}
                        </p>
                        <Info warning>
                            The rule is not monitoring. Current authority,
                            boundary version, overlap behaviour and response
                            policy must be approved before activation.
                        </Info>
                    </div>
                ) : (
                    <WizardStepPane key={step}>
                        {error && (
                            <div
                                id="zone-error"
                                role="alert"
                                tabIndex={-1}
                                className="v2-error"
                            >
                                {error}
                            </div>
                        )}
                        {step === 1 && (
                            <div className="v2-form">
                                <h3>Define this safe zone</h3>
                                <p>
                                    Give the area a clear name and explain its
                                    place in Alex’s support plan. Link an
                                    existing place if useful.
                                </p>
                                <div className="v2-options">
                                    {(['agreed', 'attention'] as const).map(
                                        (k) => (
                                            <button
                                                key={k}
                                                className={
                                                    zone.kind === k
                                                        ? 'chosen'
                                                        : ''
                                                }
                                                aria-pressed={zone.kind === k}
                                                onClick={() => {
                                                    set('kind', k);
                                                    set('response', '');
                                                }}
                                            >
                                                {k === 'agreed' ? (
                                                    <MapPin />
                                                ) : (
                                                    <ShieldAlert />
                                                )}
                                                <strong>
                                                    {k === 'agreed'
                                                        ? 'Agreed place'
                                                        : 'Area needing attention'}
                                                </strong>
                                                <small>
                                                    {k === 'agreed'
                                                        ? 'A place included in the individual plan.'
                                                        : 'Entry may need a response under the plan.'}
                                                </small>
                                            </button>
                                        ),
                                    )}
                                </div>
                                <Picker
                                    id="zone-place"
                                    label="Linked place · optional"
                                    value={zone.place}
                                    onChange={(id) => {
                                        const p = places.find(
                                            (x) => x.id === id,
                                        )!;
                                        setZone((z) => ({
                                            ...z,
                                            place: id,
                                            name: z.name || p.name,
                                        }));
                                        setChanged(true);
                                    }}
                                />
                                <Field id="zone-name" label="Safe zone name">
                                    <Input
                                        id="zone-name"
                                        value={zone.name}
                                        onChange={(e) =>
                                            set('name', e.target.value)
                                        }
                                    />
                                </Field>
                                <Field
                                    id="zone-purpose"
                                    label="Purpose for this client"
                                >
                                    <Textarea
                                        id="zone-purpose"
                                        value={zone.purpose}
                                        onChange={(e) =>
                                            set('purpose', e.target.value)
                                        }
                                        placeholder="Explain how this place relates to Alex’s agreed support plan."
                                    />
                                </Field>
                                <Info>
                                    A boundary can be reused. The client’s
                                    purpose, schedule, authority and response
                                    rules remain separate.
                                </Info>
                            </div>
                        )}
                        {step === 0 && (
                            <div className="v2-form">
                                <div>
                                    <h3>Draw the safe zone</h3>
                                    <p>
                                        Outline the area first. You’ll name it
                                        and choose when it applies next.
                                    </p>
                                </div>
                                <BoundaryEditor
                                    zone={zone}
                                    initialReady={boundaryReady}
                                    onReady={setBoundaryReady}
                                    onChange={(g) => {
                                        setZone((v) => ({ ...v, ...g }));
                                        setChanged(true);
                                        setError('');
                                    }}
                                />
                                <Info>
                                    A safe zone is an agreed area in the support
                                    plan, not a guarantee of safety. This
                                    drawing creates a proposal only.
                                </Info>
                            </div>
                        )}
                        {step === 2 && (
                            <div className="v2-form">
                                <h3>When should this apply?</h3>
                                <p>
                                    Weekly hours plus a bounded date range.
                                    Times are Pacific/Auckland.
                                </p>
                                <div
                                    className="v2-days"
                                    aria-label="Days this zone applies"
                                >
                                    {days.map((d, i) => (
                                        <button
                                            key={d}
                                            aria-label={d}
                                            aria-pressed={zone.days.includes(i)}
                                            onClick={() =>
                                                set(
                                                    'days',
                                                    zone.days.includes(i)
                                                        ? zone.days.filter(
                                                              (x) => x !== i,
                                                          )
                                                        : [
                                                              ...zone.days,
                                                              i,
                                                          ].sort(),
                                                )
                                            }
                                        >
                                            {d}
                                        </button>
                                    ))}
                                </div>
                                <div className="v2-two">
                                    <Field id="zone-start" label="Starts at">
                                        <TimePicker
                                            id="zone-start"
                                            label="Zone start time"
                                            value={zone.start}
                                            onChange={(v) => set('start', v)}
                                        />
                                    </Field>
                                    <Field id="zone-end" label="Ends at">
                                        <TimePicker
                                            id="zone-end"
                                            label="Zone end time"
                                            value={zone.end}
                                            onChange={(v) => set('end', v)}
                                        />
                                    </Field>
                                </div>
                                <label className="v2-check">
                                    <input
                                        type="checkbox"
                                        checked={zone.overnight}
                                        onChange={(e) =>
                                            set('overnight', e.target.checked)
                                        }
                                    />
                                    Ends the following day
                                </label>
                                <div className="v2-two">
                                    <Field id="zone-from" label="First date">
                                        <DatePicker
                                            id="zone-from"
                                            label="Zone first date"
                                            value={zone.from}
                                            onChange={(v) => set('from', v)}
                                        />
                                    </Field>
                                    <Field id="zone-until" label="Last date">
                                        <DatePicker
                                            id="zone-until"
                                            label="Zone last date"
                                            value={zone.until}
                                            onChange={(v) => set('until', v)}
                                        />
                                    </Field>
                                </div>
                                <Field
                                    id="zone-pause"
                                    label="One-date exception · optional"
                                >
                                    <DatePicker
                                        id="zone-pause"
                                        label="Zone exception date"
                                        value={zone.pauseDate}
                                        onChange={(v) => set('pauseDate', v)}
                                    />
                                    {zone.pauseDate && (
                                        <Button
                                            variant="ghost"
                                            onClick={() => set('pauseDate', '')}
                                        >
                                            Remove exception
                                        </Button>
                                    )}
                                </Field>
                                <Info>
                                    This exception proposes skipping this rule
                                    on that date. It does not disable
                                    collection, other rules or alerts.
                                    Daylight-saving and overlapping schedules
                                    need approved evaluation rules.
                                </Info>
                            </div>
                        )}
                        {step === 3 && (
                            <div className="v2-form">
                                <h3>What would need attention?</h3>
                                <p>
                                    Use the existing Control Room
                                    acknowledgement, triage and escalation
                                    pathway.
                                </p>
                                <div className="v2-options">
                                    {(zone.kind === 'agreed'
                                        ? [
                                              'Leaving this zone',
                                              'Arrival not confirmed',
                                          ]
                                        : ['Entering this area']
                                    ).map((value) => (
                                        <button
                                            key={value}
                                            className={
                                                zone.response === value
                                                    ? 'chosen'
                                                    : ''
                                            }
                                            aria-pressed={
                                                zone.response === value
                                            }
                                            onClick={() =>
                                                set('response', value)
                                            }
                                        >
                                            <Bell />
                                            <strong>{value}</strong>
                                            <small>
                                                Proposed trigger · not active
                                                monitoring
                                            </small>
                                        </button>
                                    ))}
                                </div>
                                <Field
                                    id="zone-confirmation"
                                    label="Confirmation requirement · optional proposal"
                                >
                                    <Textarea
                                        id="zone-confirmation"
                                        value={zone.confirmation}
                                        onChange={(e) =>
                                            set('confirmation', e.target.value)
                                        }
                                        placeholder="Describe the review needed for accuracy, repeated crossings or expected travel. No threshold is assumed."
                                    />
                                </Field>
                                <Info warning>
                                    Response owner, direction/dwell rules,
                                    accuracy thresholds and overlapping-zone
                                    behaviour await approved configuration. A
                                    planned outing does not automatically
                                    suppress an alert.
                                </Info>
                                <div className="v2-static">
                                    <Users className="size-5" />
                                    <div>
                                        <strong>
                                            Authorised Control Room response
                                        </strong>
                                        <p>
                                            No family or other recipient is
                                            added by this rule. Sharing is
                                            reviewed separately.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}
                        {step === 4 && (
                            <div className="v2-form">
                                <h3>Review the proposed rule</h3>
                                <ReviewCard
                                    icon={MapPin}
                                    title="Place & boundary"
                                    onEdit={() => setStep(1)}
                                >
                                    <ReviewRow
                                        label="Client"
                                        value="Alex Hale · CL-DEMO-024"
                                    />
                                    <ReviewRow label="Rule" value={zone.name} />
                                    <ReviewRow
                                        label="Purpose"
                                        value={zone.purpose}
                                    />
                                    <ReviewRow
                                        label="Boundary"
                                        value={
                                            zone.shape === 'circle'
                                                ? `Circle · ${zone.radius} illustrative units`
                                                : `Custom · ${zone.points.length} corners`
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={CalendarDays}
                                    title="Schedule"
                                    onEdit={() => setStep(2)}
                                >
                                    <ReviewRow
                                        label="Weekly"
                                        value={scheduleText}
                                    />
                                    <ReviewRow
                                        label="Date range"
                                        value={`${zone.from} to ${zone.until}`}
                                    />
                                    <ReviewRow
                                        label="Exception"
                                        value={
                                            zone.pauseDate || 'None proposed'
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Bell}
                                    title="Response"
                                    onEdit={() => setStep(3)}
                                >
                                    <ReviewRow
                                        label="Trigger"
                                        value={zone.response}
                                    />
                                    <ReviewRow
                                        label="Ownership"
                                        value="Existing Control Room pathway"
                                    />
                                    <ReviewRow
                                        label="Authority"
                                        value="Canonical consent and rule review required"
                                    />
                                </ReviewCard>
                                <Info warning>
                                    Overlaps are shown for review, with no
                                    automatic precedence or “any zone is safe”
                                    rule. This draft cannot enable monitoring.
                                </Info>
                                <label className="v2-check">
                                    <input
                                        type="checkbox"
                                        checked={fail}
                                        onChange={(e) =>
                                            setFail(e.target.checked)
                                        }
                                    />
                                    Demonstrate failed preview save
                                </label>
                            </div>
                        )}
                    </WizardStepPane>
                )}
            </WizardShell>
            <DraftGuard
                open={discard}
                onKeep={() => {
                    setDiscard(false);
                    requestAnimationFrame(() => cancelRef.current?.focus());
                }}
                onDiscard={onClose}
            />
        </>
    );
}

function OutingWizard({
    onClose,
    onSave,
    returnFocus,
}: {
    onClose: () => void;
    onSave: (o: Outing) => void;
    returnFocus: () => void;
}) {
    const cancelRef = useRef<HTMLButtonElement>(null);
    const [o, setO] = useState<Outing>({
        id: `O-DRAFT-${Date.now()}`,
        name: '',
        place: '',
        worker: '',
        start: '',
        arrival: '',
        end: '',
        note: '',
        draft: true,
    });
    const [step, setStep] = useState(0),
        [error, setError] = useState(''),
        [discard, setDiscard] = useState(false),
        [done, setDone] = useState(false);
    const dirty = !!(o.name || o.place || o.worker || o.start || o.note);
    const change = (key: keyof Outing, v: string) => {
        setO({ ...o, [key]: v });
        setError('');
    };
    const close = () => (dirty && !done ? setDiscard(true) : onClose());
    function valid() {
        if (!o.name.trim() || !o.place || !o.worker) {
            setStep(0);
            setError(
                'Enter an outing name, destination and responsible worker.',
            );
            return false;
        }
        if (
            step > 0 &&
            (![o.start, o.arrival, o.end].every(validLocalDateTime) ||
                o.arrival < o.start ||
                o.end <= o.arrival)
        ) {
            setStep(1);
            setError(
                'Choose complete times in order: departure, expected arrival, then return.',
            );
            return false;
        }
        setError('');
        return true;
    }
    return (
        <>
            <WizardShell
                open
                title="Plan a temporary outing"
                description="Synthetic proposal only. No care plan, rule, alert or permission is changed."
                onClose={close}
                onCloseAutoFocus={(e) => {
                    e.preventDefault();
                    requestAnimationFrame(() =>
                        requestAnimationFrame(returnFocus),
                    );
                }}
                railIcon={Route}
                railTitle="Temporary outing"
                railSub="Alex Hale · CL-DEMO-024"
                steps={outingSteps}
                stepIndex={step}
                onStepClick={setStep}
                pct={Math.round(
                    ([
                        o.name,
                        o.place,
                        o.worker,
                        o.start,
                        o.arrival,
                        o.end,
                    ].filter(Boolean).length /
                        6) *
                        100,
                )}
                pctLabel="Draft detail"
                maxWidth="min(94vw,1080px)"
                maxHeight="min(87vh,790px)"
                railExtra={
                    <div className="draft-note">
                        A temporary plan has an explicit end. Travel context
                        does not grant access or blanket-suppress alerts.
                    </div>
                }
                footerStart={
                    <>
                        <Button
                            ref={cancelRef}
                            variant="outline"
                            onClick={close}
                        >
                            {done ? 'Close' : 'Cancel'}
                        </Button>
                        {step > 0 && !done && (
                            <Button
                                variant="ghost"
                                onClick={() => setStep(step - 1)}
                            >
                                Back
                            </Button>
                        )}
                    </>
                }
                footerEnd={
                    !done && (
                        <Button
                            onClick={() => {
                                if (!valid()) return;
                                if (step < 2) setStep(step + 1);
                                else {
                                    onSave(o);
                                    setDone(true);
                                }
                            }}
                        >
                            {step === 2 ? 'Add outing draft' : 'Continue'}
                            <ArrowRight className="size-4" />
                        </Button>
                    )
                }
            >
                {done ? (
                    <div className="v2-success">
                        <CheckCircle2 className="size-10 text-primary" />
                        <h3>Outing draft added to the preview</h3>
                        <p>
                            {o.name} · {localDateTimeLabel(o.end)}
                        </p>
                        <Info>
                            No active zone or alert has been changed. The
                            temporary plan needs the normal authority and
                            support review.
                        </Info>
                    </div>
                ) : (
                    <WizardStepPane key={step}>
                        <div className="v2-form">
                            {error && (
                                <div className="v2-error" role="alert">
                                    {error}
                                </div>
                            )}
                            {step === 0 && (
                                <>
                                    <h3>Plan the visit and the journey</h3>
                                    <Field id="outing-name" label="Outing name">
                                        <Input
                                            id="outing-name"
                                            value={o.name}
                                            onChange={(e) =>
                                                change('name', e.target.value)
                                            }
                                        />
                                    </Field>
                                    <Picker
                                        id="outing-place"
                                        label="Destination"
                                        value={o.place}
                                        onChange={(v) => change('place', v)}
                                    />
                                    <Picker
                                        id="outing-worker"
                                        label="Responsible worker"
                                        value={o.worker}
                                        onChange={(v) => change('worker', v)}
                                        options={staff}
                                    />
                                    <Field
                                        id="outing-note"
                                        label="Travel and support context"
                                    >
                                        <Textarea
                                            id="outing-note"
                                            value={o.note}
                                            onChange={(e) =>
                                                change('note', e.target.value)
                                            }
                                            placeholder="Expected travel, support arrangements and what should be reviewed if the plan changes."
                                        />
                                    </Field>
                                    <Info>
                                        The worker is the proposed outing
                                        contact. Control Room response ownership
                                        and recipient sharing remain separate.
                                    </Info>
                                </>
                            )}
                            {step === 1 && (
                                <>
                                    <h3>Set departure, arrival and return</h3>
                                    <DateTimeField
                                        id="outing-departure"
                                        label="Departure"
                                        value={o.start}
                                        onChange={(v) => change('start', v)}
                                    />
                                    <DateTimeField
                                        id="outing-arrival"
                                        label="Expected arrival"
                                        value={o.arrival}
                                        onChange={(v) => change('arrival', v)}
                                    />
                                    <DateTimeField
                                        id="outing-return"
                                        label="Expected return / plan ends"
                                        value={o.end}
                                        onChange={(v) => change('end', v)}
                                    />
                                    <Info>
                                        No grace period is assumed. Unconfirmed
                                        arrival or return needs its own approved
                                        response rule; missing telemetry is not
                                        proof of a missed visit.
                                    </Info>
                                </>
                            )}
                            {step === 2 && (
                                <>
                                    <h3>Review this temporary plan</h3>
                                    <ReviewCard
                                        icon={Route}
                                        title="Outing"
                                        onEdit={() => setStep(0)}
                                    >
                                        <ReviewRow
                                            label="Name"
                                            value={o.name}
                                        />
                                        <ReviewRow
                                            label="Destination"
                                            value={
                                                places.find(
                                                    (p) => p.id === o.place,
                                                )?.name
                                            }
                                        />
                                        <ReviewRow
                                            label="Worker"
                                            value={
                                                staff.find(
                                                    (p) => p.id === o.worker,
                                                )?.name
                                            }
                                        />
                                        <ReviewRow
                                            label="Travel context"
                                            value={o.note || 'Not provided'}
                                        />
                                    </ReviewCard>
                                    <ReviewCard
                                        icon={Clock}
                                        title="Time bounds"
                                        onEdit={() => setStep(1)}
                                    >
                                        <ReviewRow
                                            label="Departure"
                                            value={localDateTimeLabel(o.start)}
                                        />
                                        <ReviewRow
                                            label="Expected arrival"
                                            value={localDateTimeLabel(
                                                o.arrival,
                                            )}
                                        />
                                        <ReviewRow
                                            label="Plan ends"
                                            value={localDateTimeLabel(o.end)}
                                        />
                                    </ReviewCard>
                                    <Info warning>
                                        The plan cannot activate a zone
                                        exception, extend collection or share
                                        location. Any approved temporary rule
                                        must end at its own recorded end time.
                                    </Info>
                                </>
                            )}
                        </div>
                    </WizardStepPane>
                )}
            </WizardShell>
            <DraftGuard
                open={discard}
                onKeep={() => {
                    setDiscard(false);
                    requestAnimationFrame(() => cancelRef.current?.focus());
                }}
                onDiscard={onClose}
            />
        </>
    );
}

export function LocationWorkspace({
    scenario,
    checked,
    onOpen,
    onConsents,
    onAccessEnded,
}: {
    scenario: string;
    checked: string;
    onOpen: (s: any) => void;
    onConsents: () => void;
    onAccessEnded: () => void;
}) {
    const [view, setView] = useState('today'),
        [demo, setDemo] = useState('routine'),
        [day, setDay] = useState(6),
        [zones, setZones] = useState(baseZones),
        [outings, setOutings] = useState(baseOutings),
        [selected, setSelected] = useState(baseZones[2].id),
        [layers, setLayers] = useState(true),
        [dialog, setDialog] = useState(''),
        [editing, setEditing] = useState<Zone | undefined>(),
        [trip, setTrip] = useState(baseOutings[0]),
        [owner, setOwner] = useState(false),
        [resolved, setResolved] = useState(false),
        [outcome, setOutcome] = useState(''),
        [error, setError] = useState(''),
        [logFilter, setLogFilter] = useState('all'),
        [notice, setNotice] = useState('');
    const opener = useRef<HTMLElement | null>(null);
    const drawButton = useRef<HTMLButtonElement>(null);
    const [drawSeed, setDrawSeed] = useState<{
        shape: Zone['shape'];
        point?: Point;
    }>();
    const locate = useLocatePreview(onAccessEnded);
    const limited = scenario === 'limited';
    const unavailable =
        !locate.received &&
        (scenario === 'unavailable' ||
            scenario === 'empty' ||
            demo === 'offline');
    const uncertain =
        !locate.received && (scenario === 'accuracy' || demo === 'boundary');
    const stale = !locate.received && scenario === 'stale';
    const outside = !locate.received && demo === 'outside';
    const alert =
        demo === 'outside' || demo === 'arrival' || demo === 'offline';
    const selectedZone = zones.find((z) => z.id === selected) || zones[2];
    const open = (name: string) => {
        opener.current = document.activeElement as HTMLElement;
        setError('');
        setDialog(name);
    };
    const close = () => setDialog('');
    const restoreFocus = () => {
        const target = opener.current?.isConnected
            ? opener.current
            : drawButton.current;
        target?.focus();
    };
    const drawZone = (shape: Zone['shape'] = 'polygon', point?: Point) => {
        setEditing(undefined);
        setDrawSeed({ shape, point });
        opener.current = drawButton.current;
        setDialog('zone-new');
    };
    const detailsZone = (id: string) => {
        setSelected(id);
        open('zone-detail');
    };
    const editZone = (id: string) => {
        setEditing(zones.find((z) => z.id === id));
        opener.current = drawButton.current;
        setDialog('zone-new');
    };
    const openLocate = () => open('locate');
    const mapActions = {
        selected: selectedZone,
        limited,
        onDraw: drawZone,
        onDetails: detailsZone,
        onEdit: editZone,
        onLocate: openLocate,
    };
    const observedTime = locate.received
        ? '2:36 pm'
        : stale
          ? '11:10 am'
          : '2:32 pm';
    const viewCheck = locate.received ? '2:36 pm' : checked;
    const title =
        demo === 'outside'
            ? 'Observation outside the active zones'
            : demo === 'arrival'
              ? 'Outing arrival check-in not confirmed'
              : 'Tracker contact needs checking';
    const tabs = [
        ['today', 'Today', MapPin],
        ['zones', 'Safe zones & schedule', CalendarDays],
        ['outings', 'Outings', Route],
        ['activity', 'Activity', Activity],
    ] as const;
    const activeText =
        unavailable || uncertain || stale
            ? 'Zone status cannot be confirmed'
            : outside
              ? 'Outside the illustrated active zones'
              : 'Observed in Garden walks';
    const events = [
        ...(locate.received
            ? [
                  {
                      type: 'observed',
                      time: '2:36 pm',
                      title: 'New device report at Example Gardens',
                      detail: 'Received after Locate now · T-DEMO-08 · assignment A-DEMO-052 · reported accuracy ±18 m',
                  },
              ]
            : []),
        ...(!['empty', 'unavailable'].includes(scenario) && demo !== 'offline'
            ? [
                  {
                      type: 'observed',
                      time: scenario === 'stale' ? '11:10 am' : '2:32 pm',
                      title:
                          demo === 'outside'
                              ? 'Device reported outside mapped areas'
                              : 'Device reported at Example Gardens',
                      detail:
                          scenario === 'accuracy'
                              ? 'Accuracy not supplied; zone status cannot be confirmed.'
                              : demo === 'boundary'
                                ? 'Reported accuracy is uncertain near the boundary.'
                                : 'Tracker T-DEMO-08 · assignment A-DEMO-052 · reported accuracy ±24 m',
                  },
              ]
            : []),
        {
            type: 'response',
            time: demo === 'arrival' ? checked : '1:43 pm',
            title:
                demo === 'arrival'
                    ? 'Arrival check-in remains unconfirmed'
                    : 'Jamie recorded arrival for the garden walk',
            detail: 'Worker-recorded outing event; separate from device observations.',
        },
        {
            type: 'planned',
            time: '3:00 pm',
            title: 'Garden walk expected return',
            detail: 'Temporary outing O-DEMO-21 ends; no automatic extension.',
        },
        {
            type: 'planned',
            time: '3:00 pm',
            title: 'Library visit scheduled to begin',
            detail: 'Priya Chen · Town library · planned, not a recorded location.',
        },
        {
            type: 'planned',
            time: '4:00 pm',
            title: 'Garden walks schedule ends',
            detail: 'Rule G-DEMO-03 · illustrated weekend hours end.',
        },
    ];
    return (
        <div className="v2-workspace">
            <div className="v2-toolbar">
                <div
                    className="v2-view-switch"
                    role="group"
                    aria-label="Location workspace views"
                >
                    {tabs.map(([key, label, Icon]) => (
                        <Button
                            key={key}
                            variant={view === key ? 'default' : 'ghost'}
                            aria-pressed={view === key}
                            onClick={() => setView(key)}
                        >
                            <Icon className="size-4" />
                            {label}
                        </Button>
                    ))}
                </div>
                <label className="v2-demo">
                    Scenario
                    <select
                        aria-label="Plan example"
                        disabled={[
                            'stale',
                            'accuracy',
                            'unavailable',
                            'empty',
                        ].includes(scenario)}
                        value={demo}
                        onChange={(e) => {
                            setDemo(e.target.value);
                            setOwner(false);
                            setResolved(false);
                            setOutcome('');
                            setNotice('');
                        }}
                    >
                        <option value="routine">Routine outing</option>
                        <option value="outside">Outside active zones</option>
                        <option value="arrival">Arrival not confirmed</option>
                        <option value="offline">Tracker unavailable</option>
                        <option value="boundary">Boundary uncertain</option>
                    </select>
                </label>
            </div>
            {notice && (
                <div className="v2-toast" role="status">
                    <CheckCircle2 className="size-4" />
                    {notice}
                    <button
                        aria-label="Dismiss preview notice"
                        onClick={() => setNotice('')}
                    >
                        <X className="size-4" />
                    </button>
                </div>
            )}
            {view === 'today' && (
                <>
                    <div className="v2-now-strip">
                        <div>
                            <small>Latest device observation</small>
                            <strong>
                                {unavailable
                                    ? 'Unavailable'
                                    : locate.received
                                      ? '2:36 pm · new observation'
                                      : stale
                                        ? '11:10 am · older observation'
                                        : '2:32 pm · 20 Sep 2026'}
                            </strong>
                            <span>
                                {unavailable
                                    ? 'No position inferred'
                                    : locate.received
                                      ? 'Received after Locate now · NZST (UTC+12)'
                                      : stale
                                        ? `3 hr ${checked === '2:36 pm' ? '26' : '25'} min before the view check`
                                        : `NZST (UTC+12) · ${checked === '2:36 pm' ? '4' : '3'} min before the view check`}
                            </span>
                        </div>
                        <div>
                            <small>Individual plan</small>
                            <strong>{activeText}</strong>
                            <span>Home and Garden walks scheduled now</span>
                        </div>
                        <div>
                            <small>Next planned change</small>
                            <strong>3:00 pm · garden walk ends</strong>
                            <button onClick={() => setView('outings')}>
                                Review today’s outings{' '}
                                <ArrowRight className="size-3" />
                            </button>
                        </div>
                    </div>
                    {alert && (
                        <div className="v2-attention">
                            <ShieldAlert className="size-5" />
                            <div>
                                <strong>
                                    {resolved
                                        ? 'Response recorded; observation unchanged'
                                        : title}
                                </strong>
                                <p>
                                    {resolved
                                        ? 'The outcome is in the activity record. This does not establish Alex’s current position.'
                                        : owner
                                          ? 'Jamie Taylor is checking · Control Room response CR-DEMO-19'
                                          : 'Review the observation alongside the support plan and outing context.'}
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                onClick={() => open('alert')}
                            >
                                {resolved ? 'View response' : 'Review alert'}
                                <ArrowRight className="size-4" />
                            </Button>
                        </div>
                    )}
                    <div className="v2-main-grid">
                        <Box
                            title="Latest observation & agreed places"
                            subtitle={`Synthetic Sunday 20 September · view checked ${viewCheck}`}
                            action={
                                <div className="v2-inline">
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        aria-pressed={layers}
                                        onClick={() => setLayers(!layers)}
                                    >
                                        <Layers className="size-4" />
                                        Zones
                                    </Button>
                                    {!limited && (
                                        <Button
                                            ref={drawButton}
                                            data-draw-zone
                                            variant="outline"
                                            onClick={() => drawZone()}
                                        >
                                            <Pencil className="size-4" />
                                            Draw safe zone
                                        </Button>
                                    )}
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        aria-label="Enlarge location map"
                                        onClick={() => open('map')}
                                    >
                                        <Maximize2 className="size-4" />
                                    </Button>
                                </div>
                            }
                        >
                            {unavailable ? (
                                <div className="v2-unavailable">
                                    <Radio className="size-8" />
                                    <h4>
                                        {scenario === 'empty'
                                            ? 'No observation recorded'
                                            : 'No current device observation'}
                                    </h4>
                                    <p>
                                        Zone presence cannot be determined.
                                        Scheduled places do not establish where
                                        Alex is.
                                    </p>
                                    <Button
                                        variant="outline"
                                        onClick={() => onOpen('assignment')}
                                    >
                                        View tracking assignment
                                    </Button>
                                </div>
                            ) : (
                                <MapActions {...mapActions}>
                                    {' '}
                                    <FictionMap
                                        zones={zones}
                                        selected={selected}
                                        onSelect={setSelected}
                                        uncertain={uncertain}
                                        accuracyKnown={
                                            locate.received ||
                                            scenario !== 'accuracy'
                                        }
                                        outside={outside}
                                        zoneLayer={layers}
                                    />
                                </MapActions>
                            )}
                            <div className="v2-map-readout">
                                <div>
                                    <span className="v2-dot" />
                                    <strong>
                                        {unavailable
                                            ? 'Position unavailable'
                                            : outside
                                              ? 'Demo walkway'
                                              : uncertain
                                                ? 'Near the Garden walks boundary'
                                                : 'Example Gardens'}
                                    </strong>
                                    <span>
                                        {unavailable
                                            ? ''
                                            : stale
                                              ? 'Observed 11:10 am'
                                              : locate.received
                                                ? 'Observed 2:36 pm · ±18 m'
                                                : `Observed 2:32 pm · ${uncertain ? 'accuracy uncertain' : '±24 m'}`}
                                    </span>
                                </div>
                                <p>
                                    {scenario === 'accuracy' && !locate.received
                                        ? 'Accuracy was not supplied. No accuracy area or confirmed zone is inferred.'
                                        : uncertain
                                          ? 'The uncertainty area overlaps the boundary. Do not call this a confirmed crossing.'
                                          : stale
                                            ? 'This older point does not confirm a current zone or position.'
                                            : 'A device observation does not establish wellbeing or a current position.'}
                                </p>
                            </div>
                            <div className="v2-selected-zone">
                                <MapPin className="size-4" />
                                <div>
                                    <strong>{selectedZone.name}</strong>
                                    <span>
                                        {selectedZone.id} ·{' '}
                                        {selectedZone.kind === 'attention'
                                            ? 'Attention area'
                                            : 'Agreed place'}{' '}
                                        ·{' '}
                                        {selectedZone.start === '00:00'
                                            ? 'All day'
                                            : `${displayTime(selectedZone.start)}–${displayTime(selectedZone.end)}`}
                                    </span>
                                </div>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => open('zone-detail')}
                                >
                                    View rule <ArrowRight className="size-4" />
                                </Button>
                            </div>
                        </Box>
                        <div className="v2-stack">
                            <Box
                                title="Today’s plan"
                                subtitle="Agreed places and time-bound outings"
                                action={
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setView('zones')}
                                    >
                                        View schedule
                                    </Button>
                                }
                            >
                                <div className="v2-plan-line">
                                    <span className="v2-plan-marker">
                                        <Check className="size-3" />
                                    </span>
                                    <div>
                                        <strong>Garden walk</strong>
                                        <p>1:30–3:00 pm · Jamie Taylor</p>
                                        <StatusBadge variant="info">
                                            Illustrative outing in progress
                                        </StatusBadge>
                                        <button
                                            className="v2-text-link"
                                            onClick={() => {
                                                setTrip(outings[0]);
                                                open('outing-detail');
                                            }}
                                        >
                                            View outing and travel context
                                        </button>
                                    </div>
                                </div>
                                <div className="v2-plan-line">
                                    <span className="v2-plan-marker muted">
                                        <Clock className="size-3" />
                                    </span>
                                    <div>
                                        <strong>Library visit</strong>
                                        <p>3:00–4:30 pm · Priya Chen</p>
                                        <StatusBadge variant="neutral">
                                            Planned
                                        </StatusBadge>
                                        <p>Temporary plan ends at 4:30 pm</p>
                                    </div>
                                </div>
                                {!limited && (
                                    <div className="v2-box-footer">
                                        <Button
                                            variant="outline"
                                            onClick={() => open('outing-new')}
                                        >
                                            <Plus className="size-4" />
                                            Plan an outing
                                        </Button>
                                    </div>
                                )}
                            </Box>
                            <Box
                                title="Tracking source"
                                action={
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => onOpen('assignment')}
                                    >
                                        View
                                    </Button>
                                }
                            >
                                <div className="v2-source">
                                    <div>
                                        <Radio className="size-5 text-primary" />
                                        <strong>
                                            Personal tracker · T-DEMO-08
                                        </strong>
                                    </div>
                                    <p>Assignment A-DEMO-052 · Alex Hale</p>
                                    <dl>
                                        <dt>Last contact</dt>
                                        <dd>
                                            {unavailable
                                                ? 'Not available'
                                                : stale
                                                  ? '11:10 am'
                                                  : locate.received
                                                    ? '2:36 pm'
                                                    : '2:32 pm'}
                                        </dd>
                                        <dt>Battery sample</dt>
                                        <dd>
                                            {['accuracy', 'unavailable', 'empty'].includes(scenario) || demo === 'offline' || demo === 'boundary'
                                                ? 'Not supplied'
                                                : scenario === 'stale'
                                                  ? '68% · sampled 11:10 am'
                                                  : '68% · sampled 2:32 pm'}
                                        </dd>
                                    </dl>
                                    {!limited && (
                                        <Button
                                            variant="outline"
                                            onClick={openLocate}
                                        >
                                            {locate.busy
                                                ? 'View locate request'
                                                : 'Locate now'}
                                        </Button>
                                    )}
                                </div>
                            </Box>
                            <LocateStatus locate={locate} />
                            <div className="v2-small-note">
                                <ShieldCheck className="size-4" />
                                Collection, staff access and recipient sharing
                                are separate.
                                <button onClick={() => onOpen('sharing')}>
                                    Review sharing
                                </button>
                            </div>
                        </div>
                    </div>
                </>
            )}
            {view === 'zones' && (
                <>
                    <div className="v2-section-title">
                        <div>
                            <h3>Safe zones & schedules</h3>
                            <p>
                                Draw the area, give it a name, then choose its
                                days and hours.
                            </p>
                        </div>
                        {!limited && (
                            <Button
                                ref={drawButton}
                                data-draw-zone
                                onClick={() => drawZone()}
                            >
                                <Pencil className="size-4" />
                                Draw safe zone
                            </Button>
                        )}
                    </div>
                    <section className="v3-zone-overview">
                        <div className="v3-zone-map">
                            <MapActions {...mapActions}>
                                <FictionMap
                                    zones={zones}
                                    selected={selected}
                                    onSelect={setSelected}
                                    observation={false}
                                />
                            </MapActions>
                            <div className="v3-map-legend">
                                <span>
                                    <i />
                                    Agreed area
                                </span>
                                <span>
                                    <i className="attention" />
                                    Attention area
                                </span>
                                <span>
                                    Dashed boundaries: draft or not scheduled
                                    now
                                </span>
                            </div>
                        </div>
                        <aside className="v3-zone-inspector">
                            <span className="v3-eyebrow">SELECTED ZONE</span>
                            <h3>{selectedZone.name}</h3>
                            <StatusBadge
                                variant={
                                    selectedZone.draft ? 'warning' : 'info'
                                }
                            >
                                {selectedZone.draft
                                    ? 'Draft · not monitoring'
                                    : 'Illustrative configured rule'}
                            </StatusBadge>
                            <p>{selectedZone.purpose}</p>
                            <dl>
                                <dt>Boundary</dt>
                                <dd>
                                    {selectedZone.shape === 'circle'
                                        ? 'Circle'
                                        : 'Custom · ' +
                                          selectedZone.points.length +
                                          ' corners'}
                                </dd>
                                <dt>Applies</dt>
                                <dd>
                                    {selectedZone.days
                                        .map((d) => days[d])
                                        .join(', ') || 'Not yet set'}
                                </dd>
                                <dt>Hours</dt>
                                <dd>
                                    {displayTime(selectedZone.start)}–
                                    {displayTime(selectedZone.end)}
                                    {selectedZone.overnight ? ' next day' : ''}
                                </dd>
                            </dl>
                            <Button
                                variant="outline"
                                onClick={() => detailsZone(selectedZone.id)}
                            >
                                View zone details
                            </Button>
                            {!limited && (
                                <Button
                                    variant="ghost"
                                    onClick={() => editZone(selectedZone.id)}
                                >
                                    <Pencil className="size-4" />
                                    Edit zone proposal
                                </Button>
                            )}
                            <p className="micro">
                                Right-click a boundary for its actions. Use Map
                                actions for the same keyboard-accessible
                                options.
                            </p>
                        </aside>
                    </section>
                    <div className="v2-schedule">
                        <div className="v2-schedule-head">
                            <div>
                                <strong>Weekly pattern</strong>
                                <p>14–20 September 2026 · Pacific/Auckland</p>
                            </div>
                            <div
                                className="v2-days"
                                role="group"
                                aria-label="Schedule day"
                            >
                                {days.map((d, i) => (
                                    <button
                                        key={d}
                                        aria-pressed={day === i}
                                        onClick={() => setDay(i)}
                                    >
                                        {d}
                                        <small>{14 + i}</small>
                                    </button>
                                ))}
                            </div>
                        </div>
                        <div className="v2-scale">
                            <span>
                                {days[day]} {14 + day} Sep
                            </span>
                            <div>
                                {[
                                    '00:00',
                                    '06:00',
                                    '12:00',
                                    '18:00',
                                    '24:00',
                                ].map((t) => (
                                    <small key={t}>{t}</small>
                                ))}
                            </div>
                        </div>
                        {zones
                            .filter((z) => !z.draft)
                            .map((z) => (
                                <button
                                    key={z.id}
                                    className="v2-schedule-row"
                                    onClick={() => {
                                        setSelected(z.id);
                                        open('zone-detail');
                                    }}
                                >
                                    <span>
                                        <strong>{z.name}</strong>
                                        <small>
                                            {z.kind === 'attention'
                                                ? 'Attention on entry'
                                                : z.days.includes(day)
                                                  ? `${displayTime(z.start)}–${displayTime(z.end)}`
                                                  : 'Not scheduled this day'}
                                        </small>
                                    </span>
                                    <div className="v2-track">
                                        {z.days.includes(day) && (
                                            <span
                                                className={
                                                    z.kind === 'attention'
                                                        ? 'attention'
                                                        : ''
                                                }
                                                style={{
                                                    left: `${((parseInt(z.start) * 60 + parseInt(z.start.slice(3))) / 1440) * 100}%`,
                                                    width: `${((parseInt(z.end) * 60 + parseInt(z.end.slice(3)) - (parseInt(z.start) * 60 + parseInt(z.start.slice(3)))) / 1440) * 100}%`,
                                                }}
                                            >
                                                {z.start === '00:00'
                                                    ? 'All day'
                                                    : z.name}
                                            </span>
                                        )}
                                    </div>
                                </button>
                            ))}
                        <div className="v2-schedule-note">
                            <Info>
                                Overlapping schedules are visible here. Their
                                combined effect needs an approved rule; this
                                view does not assume that any zone grants
                                permission or proves safety.
                            </Info>
                        </div>
                    </div>
                    <div className="v2-zone-list">
                        {zones.map((z) => (
                            <div key={z.id} className="v2-zone-row">
                                <span className="v2-zone-icon">
                                    {z.kind === 'attention' ? (
                                        <ShieldAlert />
                                    ) : (
                                        <MapPin />
                                    )}
                                </span>
                                <div className="grow">
                                    <strong>{z.name}</strong>
                                    <p>{z.purpose}</p>
                                    <span className="micro">
                                        {z.id} ·{' '}
                                        {z.shape === 'circle'
                                            ? 'Circle'
                                            : 'Custom boundary'}{' '}
                                        ·{' '}
                                        {z.days.map((i) => days[i]).join(', ')}
                                        {z.overnight ? ' · overnight' : ''}
                                    </span>
                                </div>
                                <StatusBadge
                                    variant={
                                        z.draft
                                            ? 'warning'
                                            : z.days.includes(6)
                                              ? 'info'
                                              : 'neutral'
                                    }
                                >
                                    {z.draft
                                        ? 'Draft · not monitoring'
                                        : z.days.includes(6)
                                          ? 'Scheduled today'
                                          : 'Weekday pattern'}
                                </StatusBadge>
                                <Button
                                    variant="ghost"
                                    onClick={() => {
                                        setSelected(z.id);
                                        open('zone-detail');
                                    }}
                                >
                                    View rule
                                    <ArrowRight className="size-4" />
                                </Button>
                            </div>
                        ))}
                    </div>
                    <Info>
                        Friday 25 September is an illustrative exception for the
                        day programme. View its rule to inspect the bounded
                        dates and exception. No real monitoring configuration is
                        loaded.
                    </Info>
                </>
            )}
            {view === 'outings' && (
                <>
                    <div className="v2-section-title">
                        <div>
                            <h3>Outings & temporary plans</h3>
                            <p>
                                Keep the destination, responsible person and
                                explicit end together.
                            </p>
                        </div>
                        {!limited && (
                            <Button onClick={() => open('outing-new')}>
                                <Plus className="size-4" />
                                Plan an outing
                            </Button>
                        )}
                    </div>
                    <div className="v2-outings">
                        {outings.map((o, i) => (
                            <article key={o.id}>
                                <div className="v2-outing-icon">
                                    <Route />
                                </div>
                                <div>
                                    <StatusBadge
                                        variant={
                                            o.draft
                                                ? 'warning'
                                                : i === 0
                                                  ? 'info'
                                                  : 'neutral'
                                        }
                                    >
                                        {o.draft
                                            ? 'Draft · no rule changes'
                                            : i === 0
                                              ? 'Illustrative outing in progress'
                                              : 'Planned'}
                                    </StatusBadge>
                                    <h3>{o.name}</h3>
                                    <p>
                                        {
                                            places.find((p) => p.id === o.place)
                                                ?.name
                                        }{' '}
                                        ·{' '}
                                        {
                                            staff.find((p) => p.id === o.worker)
                                                ?.name
                                        }
                                    </p>
                                    <dl>
                                        <dt>Departure</dt>
                                        <dd>{localDateTimeLabel(o.start)}</dd>
                                        <dt>Expected arrival</dt>
                                        <dd>{localDateTimeLabel(o.arrival)}</dd>
                                        <dt>Expected return / ends</dt>
                                        <dd>{localDateTimeLabel(o.end)}</dd>
                                    </dl>
                                    <p>
                                        {o.note ||
                                            'Travel context not provided.'}
                                    </p>
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            setTrip(o);
                                            open('outing-detail');
                                        }}
                                    >
                                        View outing
                                        <ArrowRight className="size-4" />
                                    </Button>
                                </div>
                            </article>
                        ))}
                    </div>
                    <Info warning>
                        Expected travel is context for a response, not a blanket
                        alert exemption. An elapsed plan does not automatically
                        extend, confirm return or broaden access.
                    </Info>
                </>
            )}
            {view === 'activity' && (
                <>
                    <ActivityHistory
                        events={[
                            ...(resolved
                                ? [
                                      {
                                          type: 'response',
                                          time: checked,
                                          title: 'Jamie recorded an outcome',
                                          detail: outcome,
                                      },
                                  ]
                                : []),
                            ...events.filter(
                                (e) => !unavailable || e.type !== 'observed',
                            ),
                        ]}
                        empty={scenario === 'empty' && !locate.received}
                        filter={logFilter}
                        onFilter={setLogFilter}
                        onAccessEnded={onAccessEnded}
                    />
                    <div className="v2-box-footer">
                        {!limited && (
                            <Button
                                variant="outline"
                                onClick={() => onOpen('export')}
                            >
                                Review governed export
                            </Button>
                        )}
                        <Button variant="ghost" onClick={onConsents}>
                            View relevant Consents
                        </Button>
                    </div>
                </>
            )}
            {dialog === 'zone-new' && (
                <ZoneWizard
                    initial={editing}
                    seed={drawSeed}
                    onClose={close}
                    returnFocus={restoreFocus}
                    onSave={(z) => {
                        const draftId =
                            z.draft && z.id.startsWith('DRAFT')
                                ? z.id
                                : `DRAFT-${Date.now()}`;
                        setSelected(draftId);
                        setZones((current) => [
                            ...current.filter((x) => x.id !== z.id || !x.draft),
                            {
                                ...z,
                                id: draftId,
                                draft: true,
                            },
                        ]);
                        setNotice(
                            'Zone draft added locally. Monitoring unchanged.',
                        );
                    }}
                />
            )}
            {dialog === 'outing-new' && (
                <OutingWizard
                    onClose={close}
                    returnFocus={restoreFocus}
                    onSave={(o) => {
                        setOutings((v) => [...v, o]);
                        setNotice(
                            'Outing draft added locally. Active rules unchanged.',
                        );
                    }}
                />
            )}
            <Dialog
                open={!!dialog && !['zone-new', 'outing-new'].includes(dialog)}
                onOpenChange={(o) => !o && close()}
            >
                <DialogContent
                    className="v2-detail-dialog"
                    onCloseAutoFocus={(e) => {
                        e.preventDefault();
                        opener.current?.focus();
                    }}
                    style={{
                        width:
                            dialog === 'map'
                                ? 'min(94vw,1050px)'
                                : 'min(92vw,750px)',
                        maxWidth:
                            dialog === 'map'
                                ? 'min(94vw,1050px)'
                                : 'min(92vw,750px)',
                        maxHeight: '88vh',
                        overflowY: 'auto',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {dialog === 'locate'
                                ? 'Locate now · tracker request'
                                : dialog === 'zone-detail'
                                  ? selectedZone.name
                                  : dialog === 'outing-detail'
                                    ? trip.name
                                    : dialog === 'alert'
                                      ? 'Location alert · Control Room response'
                                      : dialog === 'map'
                                        ? 'Agreed places & last observation'
                                        : dialog === 'observation'
                                          ? 'Recorded device observation'
                                          : 'Recorded staff response'}
                        </DialogTitle>
                        <DialogDescription>
                            Synthetic Client Location preview · no operational
                            record or message is changed.
                        </DialogDescription>
                    </DialogHeader>
                    {dialog === 'locate' && (
                        <LocatePanel
                            locate={locate}
                            limited={limited}
                            offline={unavailable}
                        />
                    )}
                    {dialog === 'zone-detail' && (
                        <div className="v2-form">
                            <StatusBadge
                                variant={
                                    selectedZone.draft ? 'warning' : 'info'
                                }
                            >
                                {selectedZone.draft
                                    ? 'Draft · not monitoring'
                                    : 'Illustrative configured rule'}
                            </StatusBadge>
                            <FictionMap
                                zones={[selectedZone]}
                                observation={false}
                                selected={selectedZone.id}
                            />
                            <dl className="v2-detail-list">
                                <dt>Canonical reference</dt>
                                <dd>{selectedZone.id}</dd>
                                <dt>Client purpose</dt>
                                <dd>{selectedZone.purpose}</dd>
                                <dt>Weekly pattern</dt>
                                <dd>
                                    {selectedZone.days
                                        .map((i) => days[i])
                                        .join(', ')}{' '}
                                    · {displayTime(selectedZone.start)}–
                                    {displayTime(selectedZone.end)}
                                    {selectedZone.overnight ? ' next day' : ''}
                                </dd>
                                <dt>Effective dates</dt>
                                <dd>
                                    {selectedZone.from} to {selectedZone.until}
                                </dd>
                                <dt>One-date exception</dt>
                                <dd>
                                    {selectedZone.pauseDate ||
                                        'None illustrated'}
                                </dd>
                                <dt>Response ownership</dt>
                                <dd>Existing Control Room pathway</dd>
                                <dt>Rule activation</dt>
                                <dd>
                                    Current authority and policy validation
                                    required
                                </dd>
                            </dl>
                            <Info>
                                Boundary identity is shared; this
                                client-specific rule cannot overwrite other
                                uses. Example times, overlaps and response
                                settings are not adopted policy.
                            </Info>
                            {!limited && (
                                <Button
                                    onClick={() => {
                                        setEditing(selectedZone);
                                        setDialog('zone-new');
                                    }}
                                >
                                    <Pencil className="size-4" />
                                    Review proposed changes
                                </Button>
                            )}
                        </div>
                    )}
                    {dialog === 'outing-detail' && (
                        <div className="v2-form">
                            <StatusBadge
                                variant={trip.draft ? 'warning' : 'info'}
                            >
                                {trip.draft
                                    ? 'Draft · not active'
                                    : 'Illustrative individual plan'}
                            </StatusBadge>
                            <dl className="v2-detail-list">
                                <dt>Reference</dt>
                                <dd>{trip.id}</dd>
                                <dt>Destination</dt>
                                <dd>
                                    {
                                        places.find((p) => p.id === trip.place)
                                            ?.name
                                    }
                                </dd>
                                <dt>Responsible worker</dt>
                                <dd>
                                    {
                                        staff.find((p) => p.id === trip.worker)
                                            ?.name
                                    }
                                </dd>
                                <dt>Departure</dt>
                                <dd>{localDateTimeLabel(trip.start)}</dd>
                                <dt>Expected arrival</dt>
                                <dd>{localDateTimeLabel(trip.arrival)}</dd>
                                <dt>Expected return / plan ends</dt>
                                <dd>{localDateTimeLabel(trip.end)}</dd>
                            </dl>
                            <Info>
                                {trip.note || 'Travel context not provided.'}
                            </Info>
                            <Info warning>
                                At the recorded end, temporary rules must expire
                                under the approved configuration. Return remains
                                unconfirmed until evidenced. Changing this plan
                                does not silently extend a grant or mute alerts.
                            </Info>
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setDialog('');
                                    setView('activity');
                                }}
                            >
                                View related activity
                                <ArrowRight className="size-4" />
                            </Button>
                        </div>
                    )}
                    {dialog === 'alert' && (
                        <div className="v2-form">
                            <div className="v2-attention compact">
                                <ShieldAlert className="size-5" />
                                <div>
                                    <strong>{title}</strong>
                                    <p>
                                        CR-DEMO-19 ·{' '}
                                        {demo === 'arrival'
                                            ? 'expected worker check-in 1:45 pm'
                                            : 'device evidence 2:32 pm'}{' '}
                                        · NZST
                                    </p>
                                </div>
                            </div>
                            <dl className="v2-detail-list">
                                <dt>Response owner</dt>
                                <dd>
                                    {owner
                                        ? 'Jamie Taylor · acknowledged in preview'
                                        : 'Unassigned in this illustrative response'}
                                </dd>
                                <dt>Current evidence</dt>
                                <dd>
                                    {demo === 'offline'
                                        ? 'No current device contact; location unknown'
                                        : demo === 'arrival'
                                          ? 'Worker arrival check-in absent. A later device report is not a worker check-in.'
                                          : 'Device report is outside the illustrated active areas.'}
                                </dd>
                                <dt>Outing context</dt>
                                <dd>
                                    Garden walk · Jamie Taylor · planned return
                                    3:00 pm
                                </dd>
                                <dt>Next action</dt>
                                <dd>
                                    Review the individual support instructions
                                    and confirm with the responsible worker.
                                </dd>
                            </dl>
                            <Info>
                                No contact is made by this preview. A planned
                                outing does not dismiss the evidence. Triage and
                                escalation remain owned by the existing Control
                                Room.
                            </Info>
                            {resolved ? (
                                <Info>
                                    <strong>
                                        Outcome recorded in the preview
                                    </strong>
                                    <p>{outcome}</p>
                                    <p>
                                        The device observation and zone status
                                        have not been rewritten.
                                    </p>
                                </Info>
                            ) : limited ? (
                                <Info>
                                    View-only access. An authorised responder
                                    must acknowledge or record an outcome.
                                </Info>
                            ) : !owner ? (
                                <Button
                                    onClick={() => {
                                        setOwner(true);
                                        setNotice(
                                            'Jamie acknowledged the illustrative response. No message sent.',
                                        );
                                    }}
                                >
                                    Acknowledge in preview
                                </Button>
                            ) : (
                                <>
                                    <Field
                                        id="response-outcome"
                                        label="Response outcome"
                                    >
                                        <Textarea
                                            id="response-outcome"
                                            value={outcome}
                                            onChange={(e) => {
                                                setOutcome(e.target.value);
                                                setError('');
                                            }}
                                            placeholder="Record what was checked, with whom, and the resulting action."
                                        />
                                    </Field>
                                    {error && (
                                        <div className="v2-error" role="alert">
                                            {error}
                                        </div>
                                    )}
                                    <Button
                                        onClick={() => {
                                            if (!outcome.trim()) {
                                                setError(
                                                    'Record the check and outcome before resolving this example.',
                                                );
                                                document
                                                    .getElementById(
                                                        'response-outcome',
                                                    )
                                                    ?.focus();
                                                return;
                                            }
                                            setResolved(true);
                                            setNotice(
                                                'Response outcome recorded in local activity.',
                                            );
                                        }}
                                    >
                                        Record outcome in preview
                                    </Button>
                                </>
                            )}
                        </div>
                    )}
                    {(dialog === 'map' || dialog === 'observation') && (
                        <div className="v2-form">
                            <FictionMap
                                zones={zones}
                                selected={selected}
                                onSelect={setSelected}
                                observation={!unavailable}
                                uncertain={uncertain}
                                accuracyKnown={
                                    locate.received || scenario !== 'accuracy'
                                }
                                outside={outside}
                            />
                            <p>
                                {unavailable
                                    ? 'No position available.'
                                    : `Observed 20 September 2026 · ${observedTime} NZST (UTC+12) · tracker T-DEMO-08`}
                            </p>
                            <Info>
                                Planned places and drawn boundaries are context.
                                They are not additional observations or a
                                wellbeing assessment.
                            </Info>
                        </div>
                    )}
                    {dialog === 'response-event' && (
                        <div className="v2-form">
                            <p>
                                Jamie Taylor · garden walk · 20 September 2026
                            </p>
                            <Info>
                                {demo === 'arrival'
                                    ? 'The expected worker arrival check-in is absent in this example.'
                                    : 'Jamie recorded arrival at 1:43 pm in this synthetic outing record.'}
                            </Info>
                            <p>
                                Worker-recorded activity is distinct from a GPS
                                observation. The canonical response and audit
                                history remain the source.
                            </p>
                        </div>
                    )}
                    <div className="flex justify-end">
                        <Button variant="outline" onClick={close}>
                            Close
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    );
}
