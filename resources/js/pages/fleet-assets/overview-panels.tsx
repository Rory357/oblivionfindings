/* eslint-disable no-restricted-syntax -- Compact chart and agenda controls use native buttons. */
import { EntityStatusChip } from '@/components/lists/entity-cells';
import {
    EntityContextMenu,
    EntityKebab,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import {
    formatDateOnly,
    formatDateTime,
    formatDurationMinutes,
    formatTime,
    toDateInput,
} from '@/lib/datetime';
import {
    ArrowRight,
    CalendarDays,
    Car,
    CheckCircle2,
    CircleHelp,
    ShieldAlert,
    Wrench,
} from 'lucide-react';
import { useMemo, useState, type ComponentProps, type MouseEvent } from 'react';
import { Pie, PieChart, Sector } from 'recharts';
import type { Resource, WorkItem } from './dashboard';

const STATES = [
    {
        name: 'Available now',
        tone: 'success',
        Icon: CheckCircle2,
        detail: 'No recorded conflict',
    },
    { name: 'In use', tone: 'info', Icon: Car, detail: 'Recorded checkout' },
    {
        name: 'Restricted',
        tone: 'critical',
        Icon: ShieldAlert,
        detail: 'Active hold',
    },
    {
        name: 'Unknown',
        tone: 'warning',
        Icon: CircleHelp,
        detail: 'Evidence needs checking',
    },
] as const;

function AvailabilitySector({
    onSelect,
    activeName,
    ...props
}: Omit<ComponentProps<typeof Sector>, 'onSelect'> & {
    name?: string;
    value?: number;
    onSelect: (state: string) => void;
    activeName: string | null;
}) {
    return (
        <Sector
            {...props}
            outerRadius={props.name === activeName ? 99 : 94}
            role="button"
            tabIndex={0}
            aria-label={`Chart: ${props.value} ${props.name} vehicles. Open records`}
            className="fo-donut-sector"
            onClick={() => props.name && onSelect(props.name)}
            onKeyDown={(event) => {
                if (
                    props.name &&
                    (event.key === 'Enter' || event.key === ' ')
                ) {
                    event.preventDefault();
                    onSelect(props.name);
                }
            }}
        />
    );
}

export function AvailabilityDonut({
    vehicles,
    onSelect,
}: {
    vehicles: Resource[];
    onSelect: (state: string) => void;
}) {
    const [active, setActive] = useState<string | null>(null);
    const data = STATES.map((state) => ({
        ...state,
        value: vehicles.filter((vehicle) => vehicle.availability === state.name)
            .length,
        fill: `var(--status-${state.tone})`,
    }));
    const highlighted = data.find((item) => item.name === active);
    return (
        <div className="fo-availability-visual">
            <div className="fo-donut-wrap">
                <PieChart width={210} height={210} accessibilityLayer={false}>
                    <Pie
                        data={data.filter((item) => item.value)}
                        dataKey="value"
                        nameKey="name"
                        cx="50%"
                        cy="50%"
                        innerRadius={74}
                        outerRadius={94}
                        startAngle={90}
                        endAngle={-270}
                        paddingAngle={4}
                        cornerRadius={5}
                        stroke="none"
                        isAnimationActive={false}
                        shape={
                            <AvailabilitySector
                                onSelect={onSelect}
                                activeName={active}
                            />
                        }
                    />
                </PieChart>
                <div className="fo-donut-centre" aria-hidden="true">
                    <Car size={19} />
                    <strong>{highlighted?.value ?? vehicles.length}</strong>
                    <span>{highlighted?.name ?? 'vehicles in scope'}</span>
                </div>
            </div>
            <div
                className="fo-availability-legend"
                aria-label="Vehicle availability breakdown"
            >
                {data.map(({ name, value, tone, Icon, detail }) => (
                    <button
                        key={name}
                        className={active === name ? 'is-highlighted' : ''}
                        onMouseEnter={() => setActive(name)}
                        onMouseLeave={() => setActive(null)}
                        onFocus={() => setActive(name)}
                        onBlur={() => setActive(null)}
                        onClick={() => onSelect(name)}
                        aria-label={`${name}: ${value} ${value === 1 ? 'vehicle' : 'vehicles'}. Open records`}
                    >
                        <span className={`fo-chart-key fo-chart-key-${tone}`}>
                            <Icon size={16} />
                        </span>
                        <span>
                            <strong>{name}</strong>
                            <small>{detail}</small>
                        </span>
                        <b>{value}</b>
                        <ArrowRight size={13} />
                    </button>
                ))}
            </div>
        </div>
    );
}

function workState(item: WorkItem): {
    label: string;
    tone: 'warning' | 'critical' | 'info' | 'neutral';
} {
    if (item.category === 'returns')
        return { label: 'Return overdue', tone: 'critical' };
    if (item.category === 'restricted')
        return { label: 'Active hold', tone: 'critical' };
    if (item.category === 'unassigned')
        return { label: 'Owner needed', tone: 'warning' };
    if (item.category === 'evidence')
        return { label: 'Readiness review', tone: 'warning' };
    if (item.type === 'booking')
        return {
            label:
                item.title === 'Vehicle return'
                    ? 'Return due'
                    : 'Confirmed booking',
            tone: 'info',
        };
    if (item.type === 'appointment')
        return { label: 'Appointment', tone: 'info' };
    if (item.type === 'work')
        return { label: 'Open maintenance', tone: 'warning' };
    return { label: 'Due date', tone: 'neutral' };
}
function dueLabel(value: string | null) {
    return !value
        ? 'Not set'
        : value.length === 10
          ? formatDateOnly(value)
          : formatDateTime(value);
}
function agendaTime(item: WorkItem) {
    if (!item.starts_at)
        return item.due_at?.length === 10
            ? 'Date only · no appointment'
            : dueLabel(item.due_at);
    if (!item.ends_at || item.ends_at === item.starts_at)
        return formatTime(item.starts_at);
    return toDateInput(item.starts_at) === toDateInput(item.ends_at)
        ? `${formatTime(item.starts_at)}–${formatTime(item.ends_at)}`
        : `${formatDateTime(item.starts_at)}–${formatDateTime(item.ends_at)}`;
}
const workIcon = (item: WorkItem) =>
    item.type === 'booking'
        ? Car
        : item.type === 'work'
          ? Wrench
          : CalendarDays;
const workActions = (
    item: WorkItem,
    navigate: (href: string) => void,
): MenuItem[] => [
    {
        label: 'Open source record',
        icon: ArrowRight,
        onClick: () => navigate(item.href),
    },
];

export function WorkRows({
    items,
    navigate,
}: {
    items: WorkItem[];
    zone: string;
    navigate: (href: string) => void;
}) {
    const [context, setContext] = useState<{
        x: number;
        y: number;
        item: WorkItem;
    } | null>(null);
    if (!items.length)
        return (
            <div className="fo-card fo-empty">
                No matching source records in this scope.
            </div>
        );
    return (
        <>
            <EntityTable
                rows={items}
                rowKey={(item) => item.id}
                identityLabel="Record / next step"
                identityWidth="2fr"
                minWidth={650}
                identity={(item) => ({
                    icon: workIcon(item),
                    name: item.title,
                    subline: `${item.resource} · ${item.resource_ref} · ${item.ref}`,
                })}
                columns={[
                    {
                        key: 'state',
                        label: 'State',
                        width: '0.85fr',
                        cell: (item) => (
                            <EntityStatusChip variant={workState(item).tone}>
                                {workState(item).label}
                            </EntityStatusChip>
                        ),
                    },
                    {
                        key: 'owner',
                        label: 'Owner / due',
                        width: '1fr',
                        cell: (item) => (
                            <span className="fo-table-owner">
                                <span>
                                    {item.owner ||
                                        (item.category === 'unassigned'
                                            ? 'Unassigned'
                                            : 'Not set')}
                                </span>
                                <small>{dueLabel(item.due_at)}</small>
                            </span>
                        ),
                    },
                ]}
                actionsFor={(item) => workActions(item, navigate)}
                onOpen={(item) => navigate(item.href)}
                hrefFor={(item) => item.href}
                onRowContextMenu={(event, item) => {
                    event.preventDefault();
                    setContext({ x: event.clientX, y: event.clientY, item });
                }}
            />
            {context && (
                <EntityContextMenu
                    x={context.x}
                    y={context.y}
                    title={context.item.ref}
                    items={workActions(context.item, navigate)}
                    onClose={() => setContext(null)}
                />
            )}
        </>
    );
}

export function UpcomingAgenda({
    items,
    today,
    navigate,
}: {
    items: WorkItem[];
    today: string;
    navigate: (href: string) => void;
}) {
    const [context, setContext] = useState<{
        x: number;
        y: number;
        item: WorkItem;
    } | null>(null);
    const tomorrow = new Date(`${today}T12:00:00Z`);
    tomorrow.setUTCDate(tomorrow.getUTCDate() + 1);
    const tomorrowKey = tomorrow.toISOString().slice(0, 10);
    const groups = new Map<string, WorkItem[]>();
    for (const item of items) {
        const key = toDateInput(item.starts_at || item.due_at);
        groups.set(key, [...(groups.get(key) || []), item]);
    }
    const openContext = (event: MouseEvent, item: WorkItem) => {
        event.preventDefault();
        setContext({ x: event.clientX, y: event.clientY, item });
    };
    if (!items.length)
        return (
            <div className="fo-card fo-empty">
                No matching upcoming records in this scope.
            </div>
        );
    return (
        <div className="fo-agenda-groups">
            {Array.from(groups).map(([day, rows]) => (
                <section key={day} className="fo-agenda-day">
                    <h3>
                        {day === today
                            ? 'Today'
                            : day === tomorrowKey
                              ? 'Tomorrow'
                              : formatDateOnly(day)}
                        <span>{rows.length}</span>
                    </h3>
                    {rows.map((item) => {
                        const Icon = workIcon(item);
                        return (
                            <article
                                key={item.id}
                                className="fo-agenda-row"
                                onContextMenu={(event) =>
                                    openContext(event, item)
                                }
                            >
                                <span className="fo-row-icon">
                                    <Icon size={18} />
                                </span>
                                <button
                                    className="fo-agenda-record"
                                    onClick={() => navigate(item.href)}
                                    aria-label={`Open ${item.ref}`}
                                >
                                    <small>
                                        {item.type === 'booking'
                                            ? 'Booking'
                                            : item.type === 'appointment'
                                              ? 'Service appointment'
                                              : item.type === 'work'
                                                ? 'Maintenance work'
                                                : 'Due date'}
                                    </small>
                                    <strong>
                                        {item.type === 'booking'
                                            ? `${item.resource} · ${item.resource_ref}`
                                            : item.title}
                                    </strong>
                                    <span>{agendaTime(item)}</span>
                                    <small>
                                        {item.owner ||
                                            item.site ||
                                            'Owner not recorded'}{' '}
                                        · {item.ref}
                                    </small>
                                </button>
                                <EntityStatusChip
                                    variant={workState(item).tone}
                                >
                                    {workState(item).label}
                                </EntityStatusChip>
                                <EntityKebab
                                    label={`Actions for ${item.ref}`}
                                    actions={workActions(item, navigate)}
                                />
                            </article>
                        );
                    })}
                </section>
            ))}
            {context && (
                <EntityContextMenu
                    x={context.x}
                    y={context.y}
                    title={context.item.ref}
                    items={workActions(context.item, navigate)}
                    onClose={() => setContext(null)}
                />
            )}
        </div>
    );
}

export function BookingLoad({
    hours: rows,
    site,
    today,
    healthy,
    onDay,
}: {
    hours: { date: string; site_id: number | null; hours: number }[];
    site: string;
    today: string;
    healthy: boolean;
    onDay: (day: string) => void;
}) {
    const dates = useMemo(
        () =>
            Array.from({ length: 7 }, (_, index) => {
                const day = new Date(`${today}T12:00:00Z`);
                day.setUTCDate(day.getUTCDate() + index);
                return day.toISOString().slice(0, 10);
            }),
        [today],
    );
    const hours = dates.map((date) =>
        rows
            .filter(
                (row) =>
                    row.date === date &&
                    (site === 'all' || String(row.site_id) === site),
            )
            .reduce((sum, row) => sum + row.hours, 0),
    );
    const ceiling = Math.max(1, ...hours.map(Math.ceil));
    const total = hours.reduce((sum, value) => sum + value, 0);
    return (
        <section
            className="fo-card fo-booking-load"
            aria-label="Seven-day booking load"
        >
            <div className="fo-section-head">
                <div>
                    <h2>Bookings this week</h2>
                    <p>
                        {formatDateOnly(today)}–{formatDateOnly(dates[6])} ·
                        confirmed reservations · Auckland time
                    </p>
                </div>
                <CalendarDays size={19} />
            </div>
            {!healthy ? (
                <p role="status" className="fo-empty">
                    Booking information is unavailable. Refresh sources to try
                    again.
                </p>
            ) : (
                <>
                    <div className="fo-booking-total">
                        <strong>{formatDurationMinutes(total * 60)}</strong>
                        <span>booked across your selected vehicles</span>
                    </div>
                    <div className="fo-booking-chart">
                        <div className="fo-booking-axis" aria-hidden="true">
                            <span>{ceiling} hr</span>
                            <span>{ceiling / 2} hr</span>
                            <span>0</span>
                        </div>
                        <div
                            className="fo-booking-columns"
                            role="group"
                            aria-label="Booked hours by day; select a day to view reservations"
                        >
                            {dates.map((date, index) => (
                                <button
                                    key={date}
                                    onClick={() => onDay(date)}
                                    aria-label={`${date}: ${formatDurationMinutes(hours[index] * 60)} booked. View bookings.`}
                                >
                                    <span className="fo-booking-track">
                                        <span className="fo-booking-value">
                                            {Number(hours[index].toFixed(1))}{' '}
                                            <small>h</small>
                                        </span>
                                        <span
                                            className="fo-booking-bar"
                                            style={{
                                                height: `${(hours[index] / ceiling) * 100}%`,
                                            }}
                                        />
                                    </span>
                                    <strong>
                                        {new Intl.DateTimeFormat('en-NZ', {
                                            weekday: 'short',
                                            timeZone: 'UTC',
                                        }).format(
                                            new Date(`${date}T12:00:00Z`),
                                        )}
                                    </strong>
                                    <small>
                                        {formatDateOnly(date).replace(
                                            / \d{4}$/,
                                            '',
                                        )}
                                    </small>
                                </button>
                            ))}
                        </div>
                    </div>
                    <p className="fo-chart-note">
                        Select a day to view bookings. Hours measure
                        reservations, not actual use or remaining capacity.
                    </p>
                </>
            )}
        </section>
    );
}
