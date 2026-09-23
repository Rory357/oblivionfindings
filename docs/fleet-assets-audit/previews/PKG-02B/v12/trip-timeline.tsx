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
import { useSessionState } from './session-state';
import { journeyLocation, type Journey } from './trip-data';

type Event = Journey['events'][number];
type View = 'list' | 'card' | 'table';

export function TripTimeline({
    trip,
    point,
    focusedEvent,
    filter,
    onFilter,
    onSelect,
}: {
    trip: Journey;
    point: number;
    focusedEvent: string;
    filter: string;
    onFilter: (value: string) => void;
    onSelect: (event: Event) => void;
}) {
    const [savedView, setView] = useSessionState<View>('timeline-view', 'list');
    const view = ['list', 'card', 'table'].includes(savedView)
        ? savedView
        : 'list';
    const events = trip.events.filter(
        (e) =>
            filter === 'All events' ||
            (filter === 'Driving'
                ? e.kind === 'driving' || e.kind === 'speed'
                : e.kind === 'power'),
    );
    const selected = events.find(
        (e) => e.point === point && (!focusedEvent || e.title === focusedEvent),
    );
    const at = (e: Event) =>
        e.at ||
        (e.point === 0
            ? trip.start
            : e.point === trip.path.length - 1
              ? trip.end
              : 'Time unavailable');
    const coordinates = (e: Event) =>
        `${trip.path[e.point].lat.toFixed(5)}, ${trip.path[e.point].lng.toFixed(5)}`;
    const symbol = (e: Event) => {
        const Icon =
            e.kind === 'power'
                ? BatteryCharging
                : e.kind === 'driving'
                  ? Activity
                  : Zap;
        return (
            <span className={`timeline-symbol timeline-symbol-${e.kind}`}>
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
                        {(
                            [
                                { id: 'list', label: 'List', Icon: List },
                                { id: 'card', label: 'Card', Icon: LayoutGrid },
                                { id: 'table', label: 'Table', Icon: Table2 },
                            ] as const
                        ).map(({ id, label, Icon }) => (
                            <button
                                key={id}
                                type="button"
                                aria-label={`${label} view`}
                                aria-pressed={view === id}
                                onClick={() => setView(id)}
                            >
                                <Icon size={14} aria-hidden="true" />
                                {label}
                            </button>
                        ))}
                    </div>
                    <select
                        aria-label="Trip event filter"
                        value={filter}
                        onChange={(e) => onFilter(e.target.value)}
                    >
                        <option>All events</option>
                        <option>Driving</option>
                        <option>Vehicle faults</option>
                    </select>
                </div>
            </div>
            {!events.length ? (
                <div className="timeline-empty">
                    <Activity size={22} />
                    <strong>No matching events</strong>
                    <span>Choose another event type to see this journey.</span>
                    <button
                        type="button"
                        onClick={() => onFilter('All events')}
                    >
                        Show all events
                    </button>
                </div>
            ) : view === 'table' ? (
                <div
                    className="timeline-table-scroll"
                    tabIndex={0}
                    role="region"
                    aria-label="Journey event table"
                >
                    <table className="timeline-table">
                        <caption className="sr-only">
                            Journey events, locations and map points
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">Time</th>
                                <th scope="col">Event</th>
                                <th scope="col">Location</th>
                                <th scope="col">Map</th>
                            </tr>
                        </thead>
                        <tbody>
                            {events.map((e) => (
                                <tr
                                    key={`${e.point}-${e.title}`}
                                    data-selected={selected === e}
                                >
                                    <td className="timeline-time">{at(e)}</td>
                                    <td>
                                        <span className="timeline-table-event">
                                            {symbol(e)}
                                            <strong>{e.title}</strong>
                                        </span>
                                    </td>
                                    <td>{journeyLocation(trip, e.point)}</td>
                                    <td>
                                        <button
                                            type="button"
                                            aria-label={`Show ${e.title} on map`}
                                            aria-pressed={selected === e}
                                            onClick={() => onSelect(e)}
                                        >
                                            <MapPin
                                                size={14}
                                                aria-hidden="true"
                                            />
                                            {e.point + 1}
                                            <ArrowUpRight
                                                size={12}
                                                aria-hidden="true"
                                            />
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <ul
                    className={`timeline-events timeline-${view}`}
                    aria-label="Journey events"
                >
                    {events.map((e) => (
                        <li key={`${e.point}-${e.title}`}>
                            <button
                                type="button"
                                className="timeline-event"
                                aria-label={`Show ${e.title} on map`}
                                aria-pressed={selected === e}
                                onClick={() => onSelect(e)}
                            >
                                {symbol(e)}
                                <span className="timeline-time">{at(e)}</span>
                                <span className="timeline-event-copy">
                                    <strong>{e.title}</strong>
                                    <span className="timeline-location">
                                        {journeyLocation(trip, e.point)}
                                    </span>
                                </span>
                                {view === 'card' && (
                                    <span className="timeline-card-detail">
                                        {e.detail}
                                        <small>{coordinates(e)}</small>
                                    </span>
                                )}
                                <span className="timeline-map-link">
                                    <MapPin size={13} aria-hidden="true" />
                                    Point {e.point + 1}
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
                        <small>{coordinates(selected)}</small>
                    </div>
                </div>
            )}
            <p className="timeline-footnote">
                Select an event to locate it on the map. Locations are synthetic
                examples.
            </p>
        </section>
    );
}
