import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { Download, MapPin, Radio } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { DeviceEvidence } from './device-evidence';
import {
    time,
    type History,
    type Person,
    type Position,
    type Sample,
    type Workspace,
} from './model';
import { RecordPicker } from './record-picker';

type Event = { id: string; at: string; position?: Position; sample?: Sample };
function HistoricalMap({
    events,
    selected,
    onSelect,
}: {
    events: Event[];
    selected: Event | undefined;
    onSelect: (id: string) => void;
}) {
    const root = useRef<HTMLDivElement>(null),
        map = useRef<L.Map | null>(null),
        layer = useRef<L.LayerGroup | null>(null);
    const [tileError, setTileError] = useState(false);
    const select = useRef(onSelect);
    useEffect(() => {
        select.current = onSelect;
    }, [onSelect]);
    useEffect(() => {
        if (!root.current) return;
        const instance = L.map(root.current, {
            scrollWheelZoom: false,
        }).setView([-41.2865, 174.7762], 5);
        map.current = instance;
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19,
        })
            .on('tileerror', () => setTileError(true))
            .addTo(instance);
        layer.current = L.layerGroup().addTo(instance);
        const resize = new ResizeObserver(() => instance.invalidateSize());
        resize.observe(root.current);
        return () => {
            resize.disconnect();
            instance.remove();
            map.current = null;
        };
    }, []);
    useEffect(() => {
        if (!map.current || !layer.current) return;
        layer.current.clearLayers();
        const points: L.LatLngTuple[] = [];
        events.forEach((event) => {
            if (!event.position) return;
            const point: L.LatLngTuple = [
                event.position.lat,
                event.position.lng,
            ];
            points.push(point);
            const tooltip = document.createElement('span');
            tooltip.textContent = time(event.at);
            L.circleMarker(point, {
                radius: 6,
                color: 'var(--primary)',
                fillOpacity: 0.65,
            })
                .bindTooltip(tooltip)
                .on('click', () => select.current(event.id))
                .addTo(layer.current!);
        });
        if (points.length)
            map.current.fitBounds(points, { padding: [24, 24], maxZoom: 16 });
    }, [events]);
    useEffect(() => {
        if (selected?.position)
            map.current?.panTo([selected.position.lat, selected.position.lng]);
    }, [selected]);
    return (
        <div className="space-y-2">
            <div
                ref={root}
                className="pl-map relative z-0 h-80 rounded-lg border"
                aria-label="Recorded positions for selected day"
            />
            <p className="text-xs text-muted-foreground">
                Recorded points only. Lines are not drawn across unknown
                intervals. Use the timeline to inspect each observation.
            </p>
            {tileError && (
                <p role="status" className="text-sm">
                    Map imagery unavailable. Coordinates remain available in the
                    timeline.
                </p>
            )}
        </div>
    );
}
function EvidenceTimeline({ history }: { history: History }) {
    const [selectedId, setSelectedId] = useState(''),
        [limit, setLimit] = useState(60);
    const events = useMemo<Event[]>(
        () =>
            [
                ...(history.positions ?? []).map((position) => ({
                    id: `p:${position.timestamp}:${position.lat}:${position.lng}`,
                    at: position.timestamp,
                    position,
                })),
                ...(history.samples ?? []).map((sample) => ({
                    id: `s:${sample.at}:${sample.event}:${sample.battery}:${sample.power}:${sample.motion}`,
                    at: sample.at,
                    sample,
                })),
            ].sort((a, b) => Date.parse(a.at) - Date.parse(b.at)),
        [history.positions, history.samples],
    );
    const selected = events.find((e) => e.id === selectedId) ?? events[0];
    return (
        <div className="grid min-w-0 grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_330px]">
            <HistoricalMap
                events={events}
                selected={selected}
                onSelect={setSelectedId}
            />
            <div className="min-w-0 space-y-3">
                <h3 className="font-semibold">Observation timeline</h3>
                <div
                    role="region"
                    aria-label="Observation timeline"
                    tabIndex={0}
                    className="max-h-64 space-y-1 overflow-auto rounded-lg border p-2"
                >
                    {events.slice(0, limit).map((event) => (
                        <Button
                            key={event.id}
                            variant="ghost"
                            className="h-auto w-full justify-start py-2 text-left"
                            aria-pressed={event.id === selected?.id}
                            onClick={() => setSelectedId(event.id)}
                        >
                            {event.position ? (
                                <MapPin className="size-4" />
                            ) : (
                                <Radio className="size-4" />
                            )}
                            <span>
                                <strong className="block text-xs">
                                    {time(event.at)}
                                </strong>
                                <span className="text-xs">
                                    {event.position
                                        ? 'Recorded position'
                                        : 'Device sample'}
                                </span>
                            </span>
                        </Button>
                    ))}
                    {events.length > limit && (
                        <Button
                            variant="outline"
                            onClick={() => setLimit((v) => v + 60)}
                        >
                            Show more observations
                        </Button>
                    )}
                    {!events.length && (
                        <p className="p-3 text-sm">
                            No observations in the authorised window.
                        </p>
                    )}
                </div>
                {selected && (
                    <div
                        className="rounded-lg bg-muted/40 p-3 text-sm"
                        aria-live="polite"
                    >
                        <strong>{time(selected.at)}</strong>
                        {selected.position ? (
                            <p>
                                {selected.position.lat.toFixed(6)},{' '}
                                {selected.position.lng.toFixed(6)} · Accuracy{' '}
                                {selected.position.accuracy == null
                                    ? 'unknown'
                                    : `${selected.position.accuracy} m`}
                            </p>
                        ) : (
                            <p>
                                Battery{' '}
                                {selected.sample?.battery == null
                                    ? 'unknown'
                                    : `${selected.sample.battery}%`}{' '}
                                · {selected.sample?.power.replaceAll('_', ' ')}{' '}
                                ·{' '}
                                {selected.sample?.motion ?? 'Movement unknown'}
                            </p>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
export function HistoryPanel({
    workspace,
    selected,
    onJourney,
    onReport,
}: {
    workspace: Workspace;
    selected?: Person;
    onJourney: (journey: string) => void;
    onReport: (journey?: string) => void;
}) {
    const history = workspace.history;
    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    {selected
                        ? `${selected.name} · recorded day`
                        : 'Choose a person'}
                </CardTitle>
                <CardDescription>
                    Source observations and passenger journeys from Transport &
                    Handover.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-5">
                {selected?.kind === 'staff' ? (
                    <Button asChild variant="outline">
                        <Link href={selected.profileUrl}>
                            View authorised safety-session history
                        </Link>
                    </Button>
                ) : !workspace.canViewHistory ? (
                    <Alert>
                        <AlertDescription>
                            History access has not been granted. Current map
                            access does not include retained personal history.
                        </AlertDescription>
                    </Alert>
                ) : history?.needsSource ? (
                    <p>Choose an authorised source to continue.</p>
                ) : history ? (
                    <>
                        <div className="flex flex-wrap items-end justify-between gap-4">
                            <div className="w-full min-w-0 sm:w-80">
                                <RecordPicker
                                    label="Report window"
                                    value={String(history.journey?.id ?? 'day')}
                                    onChange={(value) =>
                                        onJourney(value === 'day' ? '' : value)
                                    }
                                    options={[
                                        {
                                            value: 'day',
                                            label: 'Full recorded day',
                                            description:
                                                'Within current authority and retention',
                                        },
                                        ...(history.journeys ?? []).map(
                                            (j) => ({
                                                value: String(j.id),
                                                label: `${j.reference} · ${j.destination || j.purpose || 'Passenger journey'}`,
                                                description: `${time(j.departedAt)} · ${j.status}`,
                                            }),
                                        ),
                                    ]}
                                />
                            </div>
                            <Button
                                disabled={!workspace.canExport}
                                onClick={() =>
                                    onReport(
                                        history.journey
                                            ? String(history.journey.id)
                                            : '',
                                    )
                                }
                            >
                                <Download className="size-4" />
                                Export report
                            </Button>
                        </div>
                        {!workspace.canExport && (
                            <p className="text-sm text-muted-foreground">
                                Export requires the separate report permission.
                            </p>
                        )}
                        <div className="rounded-lg border bg-muted/25 p-4">
                            <strong>
                                Report preview ·{' '}
                                {history.positions?.length ?? 0} positions ·{' '}
                                {history.samples?.length ?? 0} samples
                            </strong>
                            <p className="mt-1 text-sm">
                                {history.scope}: {time(history.window?.from)} –{' '}
                                {time(history.window?.to)}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {history.source?.label} ·{' '}
                                {history.source?.reference} · Pacific/Auckland
                            </p>
                        </div>
                        {history.truncated && (
                            <Alert>
                                <AlertDescription>
                                    Partial view: the source scan reached its
                                    limit of 500 per stream. Earlier
                                    observations may be omitted; exports carry
                                    the same notice.
                                </AlertDescription>
                            </Alert>
                        )}
                        <EvidenceTimeline history={history} />
                        <details>
                            <summary className="cursor-pointer text-sm font-semibold">
                                Device evidence table ·{' '}
                                {history.samples?.length ?? 0} samples
                            </summary>
                            <DeviceEvidence samples={history.samples ?? []} />
                        </details>
                        <h3 className="font-semibold">Passenger journeys</h3>
                        {(history.journeys ?? []).map((j) => (
                            <div
                                key={j.id}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4"
                            >
                                <div>
                                    <strong>
                                        {j.reference} ·{' '}
                                        {j.destination ||
                                            j.purpose ||
                                            'Passenger journey'}
                                    </strong>
                                    <p className="text-sm text-muted-foreground">
                                        Departed {time(j.departedAt)} · Arrived{' '}
                                        {time(j.arrivedAt)}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Passengers accounted for{' '}
                                        {time(j.accountedAt)} · {j.status}
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        onClick={() => onJourney(String(j.id))}
                                    >
                                        Preview day slice
                                    </Button>
                                    <Button
                                        disabled={!workspace.canExport}
                                        onClick={() => onReport(String(j.id))}
                                    >
                                        Export outing
                                    </Button>
                                    <Button asChild variant="outline">
                                        <Link href={j.href}>Open journey</Link>
                                    </Button>
                                </div>
                            </div>
                        ))}
                        {!history.journeys?.length && (
                            <p className="text-sm text-muted-foreground">
                                No permitted passenger journeys overlap this
                                day.
                            </p>
                        )}
                        <p className="text-xs text-muted-foreground">
                            Outing reports cover the selected Auckland day
                            inside the passenger window. Vehicle return, keys
                            and handover are separate. Non-vehicle outings are
                            not yet represented by a canonical outing record.
                        </p>
                    </>
                ) : (
                    <p>Select a permitted client and recorded day.</p>
                )}
            </CardContent>
        </Card>
    );
}
