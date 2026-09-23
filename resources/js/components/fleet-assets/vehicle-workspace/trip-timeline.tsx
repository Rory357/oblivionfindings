import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatTime } from '@/lib/datetime';
import {
    Activity,
    ArrowUpRight,
    BatteryCharging,
    LayoutGrid,
    List,
    MapPin,
    Table2,
    Zap,
} from 'lucide-react';
import { useState } from 'react';
import {
    coordinates,
    pointLocation,
    readStored,
    TIMELINE_FILTERS,
    timelineMatches,
    writeStored,
    type TimelineFilter,
} from './trip-model';
import type { TripDetail, TripEvent } from './trip-types';

type View = 'list' | 'card' | 'table';

const VIEWS = [
    { id: 'list', label: 'List', Icon: List },
    { id: 'card', label: 'Card', Icon: LayoutGrid },
    { id: 'table', label: 'Table', Icon: Table2 },
] as const;

const VIEW_KEY = 'vehicle-trip-history.timeline-view';

/** Events recorded during the selected trip; selecting one finds it on the map. */
export function TripTimeline({
    detail,
    point,
    focusedEventKey,
    filter,
    onFilter,
    onSelect,
}: {
    detail: TripDetail;
    point: number;
    focusedEventKey: string;
    filter: TimelineFilter;
    onFilter: (value: TimelineFilter) => void;
    onSelect: (event: TripEvent) => void;
}) {
    const [savedView, setSavedView] = useState<View>(() => {
        const stored = readStored<string>(VIEW_KEY, 'list');
        return stored === 'card' || stored === 'table' ? stored : 'list';
    });
    const view: View = savedView;
    const chooseView = (next: View) => {
        setSavedView(next);
        writeStored(VIEW_KEY, next);
    };
    const events = detail.events.filter((event) =>
        timelineMatches(event, filter),
    );
    const selected = events.find(
        (event) =>
            event.point === point &&
            (!focusedEventKey || event.key === focusedEventKey),
    );
    const location = (event: TripEvent) =>
        detail.points.length
            ? pointLocation(detail.points[event.point])
            : 'No recorded position';
    const where = (event: TripEvent) =>
        detail.points[event.point]
            ? coordinates(detail.points[event.point])
            : 'Coordinates not recorded';
    const symbol = (event: TripEvent) => {
        const Icon =
            event.kind === 'power'
                ? BatteryCharging
                : event.kind === 'driving'
                  ? Activity
                  : Zap;
        return (
            <span className={`timeline-symbol timeline-symbol-${event.kind}`}>
                <Icon size={16} aria-hidden="true" />
            </span>
        );
    };

    return (
        <section
            className="studio-card trip-timeline"
            aria-label="Journey timeline"
        >
            <div className="timeline-heading">
                <h3>
                    Journey timeline <span>{events.length}</span>
                </h3>
                <div className="timeline-controls">
                    <div
                        className="timeline-view-switch"
                        role="group"
                        aria-label="Timeline view"
                    >
                        {VIEWS.map(({ id, label, Icon }) => (
                            // eslint-disable-next-line no-restricted-syntax -- Segmented view switch from the approved design.
                            <button
                                key={id}
                                type="button"
                                aria-label={`${label} view`}
                                aria-pressed={view === id}
                                onClick={() => chooseView(id)}
                            >
                                <Icon size={14} aria-hidden="true" />
                                {label}
                            </button>
                        ))}
                    </div>
                    <select
                        className="select"
                        aria-label="Trip event filter"
                        value={filter}
                        onChange={(event) =>
                            onFilter(event.target.value as TimelineFilter)
                        }
                    >
                        {TIMELINE_FILTERS.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>
            </div>
            {!events.length ? (
                <div className="timeline-empty">
                    <Activity size={22} aria-hidden="true" />
                    <strong>No matching events</strong>
                    <span>
                        {detail.events.length
                            ? 'Choose another event type to see this journey.'
                            : 'The tracker recorded no events during this trip.'}
                    </span>
                    {filter !== 'all' && (
                        <Button
                            type="button"
                            variant="link"
                            size="sm"
                            onClick={() => onFilter('all')}
                        >
                            Show all events
                        </Button>
                    )}
                </div>
            ) : view === 'table' ? (
                <div className="timeline-table-scroll">
                    <Table className="timeline-table">
                        <caption className="sr-only">
                            Journey events, locations and map points
                        </caption>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Time</TableHead>
                                <TableHead>Event</TableHead>
                                <TableHead>Location</TableHead>
                                <TableHead>Map</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {events.map((event) => (
                                <TableRow
                                    key={event.key}
                                    data-selected={selected === event}
                                >
                                    <TableCell className="timeline-time">
                                        {formatTime(event.at)}
                                    </TableCell>
                                    <TableCell>
                                        <span className="timeline-table-event">
                                            {symbol(event)}
                                            <strong>{event.title}</strong>
                                        </span>
                                    </TableCell>
                                    <TableCell>{location(event)}</TableCell>
                                    <TableCell>
                                        {/* eslint-disable-next-line no-restricted-syntax -- Map-point chip that selects the event (approved design). */}
                                        <button
                                            type="button"
                                            aria-label={`Show ${event.title} on map`}
                                            aria-pressed={selected === event}
                                            onClick={() => onSelect(event)}
                                        >
                                            <MapPin
                                                size={14}
                                                aria-hidden="true"
                                            />
                                            {event.point + 1}
                                            <ArrowUpRight
                                                size={12}
                                                aria-hidden="true"
                                            />
                                        </button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            ) : (
                <ul
                    className={`timeline-events timeline-${view}`}
                    aria-label="Journey events"
                >
                    {events.map((event) => (
                        <li key={event.key}>
                            {/* eslint-disable-next-line no-restricted-syntax -- Full-width event row that selects the event (approved design). */}
                            <button
                                type="button"
                                className="timeline-event"
                                aria-label={`Show ${event.title} on map`}
                                aria-pressed={selected === event}
                                onClick={() => onSelect(event)}
                            >
                                {symbol(event)}
                                <span className="timeline-time">
                                    {formatTime(event.at)}
                                </span>
                                <span className="timeline-event-copy">
                                    <strong>{event.title}</strong>
                                    <span className="timeline-location">
                                        {location(event)}
                                    </span>
                                </span>
                                {view === 'card' && (
                                    <span className="timeline-card-detail">
                                        {event.detail}
                                        <small>{where(event)}</small>
                                    </span>
                                )}
                                <span className="timeline-map-link">
                                    <MapPin size={13} aria-hidden="true" />
                                    Point {event.point + 1}
                                    <ArrowUpRight
                                        size={12}
                                        aria-hidden="true"
                                    />
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {selected && view !== 'card' && (
                <div className="timeline-selected-detail" aria-live="polite">
                    <MapPin size={15} aria-hidden="true" />
                    <div>
                        <strong>
                            {selected.title} · Point {selected.point + 1}
                        </strong>
                        <p>{selected.detail}</p>
                        <small>{where(selected)}</small>
                    </div>
                </div>
            )}
            <p className="timeline-footnote">
                Select an event to locate it on the map. Locations are looked-up
                addresses or the recorded coordinates.
            </p>
        </section>
    );
}
