import { localDateTimeLabel } from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    ArrowRight,
    ArrowUpRight,
    Bell,
    CalendarDays,
    CheckCircle2,
    Clock3,
    Gauge,
    History,
    Paperclip,
    Plus,
    ShieldCheck,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';
import {
    CollectionToggle,
    RecordCollection,
    useCollectionView,
} from './collection-view';
import { type VehicleModel, dateLabel } from './operations';
import { MileageFeed } from './telemetry-studio';
import { Badge } from './ui';
type Props = {
    model: VehicleModel;
    onNav: (view: string, sub?: string) => void;
    onWork: (id: string) => void;
};
export function MileageStudio({ model: m, onNav, onWork }: Props) {
    const layout = useCollectionView('mileage');
    const [query, setQuery] = useState(''),
        [filter, setFilter] = useState('All readings'),
        [expanded, setExpanded] = useState('');
    const all = m.data.readings,
        latest = all[0],
        prior = latest?.corrects
            ? all.find((r) => r.id === latest.corrects)
            : all.find((r) => r.id !== latest?.id),
        distance = m.data.schedules
            .filter((s) => s.dueKm > 0)
            .sort((a, b) => a.dueKm - b.dueKm)[0];
    const rows = all.filter(
        (r) =>
            (filter === 'All readings' ||
                (filter === 'Corrections' && !!r.corrects) ||
                (filter === 'With evidence' &&
                    m.data.documents.some((f) => f.owner === r.id))) &&
            `${r.id} ${r.author} ${r.source} ${r.value}`
                .toLowerCase()
                .includes(query.toLowerCase()),
    );
    const active = all
            .filter((r) => !all.some((x) => x.corrects === r.id))
            .slice()
            .reverse(),
        low = Math.min(...active.map((r) => r.value)),
        high = Math.max(...active.map((r) => r.value));
    const points = active
        .map(
            (r, i) =>
                `${20 + (i * 300) / Math.max(active.length - 1, 1)},${84 - ((r.value - low) / Math.max(high - low, 1)) * 60}`,
        )
        .join(' ');
    return (
        <div className="activity-studio">
            <div className="activity-title">
                <div>
                    <span className="studio-eyebrow">DISTANCE & EVIDENCE</span>
                    <h2 className="text-section-title">Mileage history</h2>
                    <p className="muted">
                        Dashboard readings, their sources and the service
                        distance they inform.
                    </p>
                </div>
                <Button disabled={!m.canManage} onClick={() => m.mileage()}>
                    <Plus size={16} />
                    Add reading
                </Button>
            </div>
            <MileageFeed model={m} compact />
            <div className="mileage-overview">
                <section className="studio-card mileage-current">
                    <span className="feature-icon">
                        <Gauge size={23} />
                    </span>
                    <div>
                        <span className="studio-eyebrow">
                            CURRENT RECORDED ODOMETER
                        </span>
                        <strong>
                            {latest ? m.odo.toLocaleString() : '—'}{' '}
                            <small>km</small>
                        </strong>
                        <p>
                            {latest
                                ? localDateTimeLabel(latest.at)
                                : 'No reading recorded'}
                        </p>
                        <Badge tone={m.readingStale ? 'warning' : 'info'}>
                            {m.readingStale
                                ? 'Stale evidence'
                                : latest
                                  ? 'Recorded observation'
                                  : 'Reading needed'}
                        </Badge>
                    </div>
                    <div className="mileage-mini">
                        <span>{all.length} records</span>
                        <strong>
                            {latest && prior
                                ? `${latest.value - prior.value >= 0 ? '+' : ''}${(latest.value - prior.value).toLocaleString()} km`
                                : '—'}
                        </strong>
                        <small>
                            {latest?.corrects
                                ? 'Correction difference'
                                : 'Since previous reading'}
                        </small>
                    </div>
                </section>
                <section className="studio-card mileage-service">
                    <span className="studio-eyebrow">
                        NEXT DISTANCE TRIGGER
                    </span>
                    <h3>
                        {distance
                            ? distance.name
                            : 'No distance service scheduled'}
                    </h3>
                    <strong>
                        {distance
                            ? `${Math.max(0, distance.dueKm - m.planningOdo).toLocaleString()} km`
                            : '—'}
                    </strong>
                    <span>
                        {distance
                            ? `Due at ${distance.dueKm.toLocaleString()} km · ${dateLabel(distance.due)}`
                            : 'Add a service schedule to track distance.'}
                    </span>
                    <Button
                        variant="ghost"
                        onClick={() => onNav('compliance', 'schedules')}
                    >
                        View service schedule <ArrowUpRight size={15} />
                    </Button>
                </section>
            </div>
            <div className="mileage-layout">
                <section className="studio-card">
                    <div className="activity-toolbar">
                        <Input
                            aria-label="Search mileage readings"
                            placeholder="Reading, person or source…"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                        <select
                            aria-label="Mileage history filter"
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                        >
                            <option>All readings</option>
                            <option>Corrections</option>
                            <option>With evidence</option>
                        </select>
                        <CollectionToggle
                            label="Mileage readings"
                            {...layout}
                        />
                    </div>
                    <RecordCollection
                        label="Mileage readings"
                        view={layout.view}
                        columns={[
                            { label: 'Observed', width: '1.3fr' },
                            { label: 'Source / recorded by', width: '1.3fr' },
                            { label: 'Status', width: '.8fr' },
                        ]}
                        records={rows.map((r) => {
                            const superseded = all.some(
                                    (x) => x.corrects === r.id,
                                ),
                                files = m.data.documents.filter(
                                    (f) => f.owner === r.id,
                                );
                            const open = () =>
                                setExpanded(expanded === r.id ? '' : r.id);
                            return {
                                id: r.id,
                                name: `${r.value.toLocaleString()} km`,
                                subline: r.id,
                                icon: Gauge,
                                alert:
                                    r.corrects && !superseded
                                        ? 'warning'
                                        : undefined,
                                fields: [
                                    <>{localDateTimeLabel(r.at)}</>,
                                    <>
                                        <strong>{r.source}</strong>
                                        <small>{r.author}</small>
                                        <small>
                                            {files.length} evidence files
                                        </small>
                                    </>,
                                    <>
                                        <Badge
                                            tone={
                                                superseded
                                                    ? 'neutral'
                                                    : r.corrects
                                                      ? 'warning'
                                                      : r.id === latest?.id
                                                        ? 'info'
                                                        : 'neutral'
                                            }
                                        >
                                            {superseded
                                                ? 'Superseded'
                                                : r.corrects
                                                  ? 'Correction'
                                                  : r.id === latest?.id
                                                    ? 'Current'
                                                    : 'Recorded'}
                                        </Badge>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={open}
                                        >
                                            {expanded === r.id
                                                ? 'Hide details'
                                                : 'View details'}
                                        </Button>
                                    </>,
                                ],
                                open,
                                footer: {
                                    personName: r.author,
                                    primary: r.author,
                                    secondary: 'Recorded observation',
                                },
                                actions: [
                                    {
                                        label: 'View details',
                                        icon: Gauge,
                                        onClick: open,
                                    },
                                    ...(m.canManage
                                        ? [
                                              {
                                                  label: 'Add evidence',
                                                  icon: Paperclip,
                                                  onClick: () =>
                                                      m.setUploadFor(r.id),
                                              },
                                          ]
                                        : []),
                                    ...(m.canManage && !superseded
                                        ? [
                                              {
                                                  label: 'Correct reading',
                                                  icon: Gauge,
                                                  onClick: () => m.mileage(r),
                                              },
                                          ]
                                        : []),
                                    ...(m.data.works.some(
                                        (w) => w.id === r.source,
                                    )
                                        ? [
                                              {
                                                  label: 'Open source work',
                                                  icon: Wrench,
                                                  onClick: () =>
                                                      onWork(r.source),
                                              },
                                          ]
                                        : []),
                                ],
                            };
                        })}
                        empty={
                            <>
                                <h3>No matching readings</h3>
                                <p>
                                    Change the filter or add a dashboard
                                    observation.
                                </p>
                            </>
                        }
                    />
                    {(() => {
                        const r = rows.find((r) => r.id === expanded);
                        if (!r) return null;
                        const superseded = all.some((x) => x.corrects === r.id),
                            files = m.data.documents.filter(
                                (f) => f.owner === r.id,
                            );
                        return (
                            <div className="reading-selected-detail">
                                <div className="studio-section-heading">
                                    <h3>
                                        {r.value.toLocaleString()} km · {r.id}
                                    </h3>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setExpanded('')}
                                    >
                                        Close details
                                    </Button>
                                </div>
                                {r.corrects && (
                                    <p>
                                        Corrects <strong>{r.corrects}</strong>.
                                        The original remains in this history.
                                    </p>
                                )}
                                <p>
                                    {r.reason ||
                                        'No additional observation notes.'}
                                </p>
                                <div className="reading-files">
                                    {files.map((f) => (
                                        <a
                                            href={f.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            key={f.id}
                                        >
                                            <Paperclip size={14} />
                                            {f.name}
                                        </a>
                                    ))}
                                </div>
                                <div className="library-actions">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={!m.canManage}
                                        onClick={() => m.setUploadFor(r.id)}
                                    >
                                        <Paperclip size={14} />
                                        Add evidence
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={!m.canManage || superseded}
                                        onClick={() => m.mileage(r)}
                                    >
                                        Correct reading
                                    </Button>
                                    {m.data.works.some(
                                        (w) => w.id === r.source,
                                    ) && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => onWork(r.source)}
                                        >
                                            Open source work{' '}
                                            <ArrowUpRight size={14} />
                                        </Button>
                                    )}
                                </div>
                            </div>
                        );
                    })()}
                </section>
                <aside className="studio-card mileage-insight">
                    <span className="studio-eyebrow">READING PROGRESSION</span>
                    <h3>Recorded distance</h3>
                    {active.length > 1 ? (
                        <>
                            <svg
                                viewBox="0 0 340 105"
                                role="img"
                                aria-label={`Odometer readings from ${low.toLocaleString()} to ${high.toLocaleString()} kilometres`}
                            >
                                <path d="M20 90H320" stroke="var(--border)" />
                                <polyline
                                    points={points}
                                    fill="none"
                                    stroke="var(--primary)"
                                    strokeWidth="3"
                                />
                                {active.map((r, i) => (
                                    <circle
                                        key={r.id}
                                        cx={
                                            20 +
                                            (i * 300) /
                                                Math.max(active.length - 1, 1)
                                        }
                                        cy={
                                            84 -
                                            ((r.value - low) /
                                                Math.max(high - low, 1)) *
                                                60
                                        }
                                        r="4"
                                        fill="var(--primary)"
                                    />
                                ))}
                            </svg>
                            <div className="spark-labels">
                                <span>{dateLabel(active[0].at)}</span>
                                <span>{dateLabel(active.at(-1)!.at)}</span>
                            </div>
                        </>
                    ) : (
                        <p>Two readings will show a progression.</p>
                    )}
                    <p className="studio-footnote">
                        Observations in time order. Corrections replace the
                        plotted original; this is not a GPS route or a
                        daily-distance estimate.
                    </p>
                    <div className="mileage-insight-note">
                        <ShieldCheck size={18} />
                        <p>
                            Original observations stay intact. Every correction
                            requires a reason.
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        disabled={!m.canManage}
                        onClick={() => m.followup(undefined, 'Vehicle profile')}
                    >
                        <Bell size={15} />
                        Remind me to check mileage
                    </Button>
                    <Button variant="ghost" onClick={() => onNav('trips')}>
                        Open trip history <ArrowRight size={15} />
                    </Button>
                </aside>
            </div>
        </div>
    );
}
export function RemindersStudio({ model: m, onNav, onWork }: Props) {
    const layout = useCollectionView('reminders');
    const [filter, setFilter] = useState('All reminders'),
        [query, setQuery] = useState('');
    const d = m.data;
    const obligations = [
        ...d.schedules.map((s) => ({
            id: s.id,
            name: s.name,
            owner: s.owner,
            due: s.due,
            lead: s.lead,
        })),
        ...d.compliance
            .filter((c) => c.applies === 'Applicable')
            .map((c) => ({
                id: c.id,
                name: c.name,
                owner: d.profile.owner,
                due: c.due,
                lead: 7,
            })),
    ];
    const failed = Object.values(d.reminders).filter(
        (r) => r.status === 'Delivery failed',
    ).length;
    const rows = d.followups.filter(
        (r) =>
            (filter === 'All reminders' ||
                (filter === 'Scheduled' && r.status === 'Scheduled') ||
                (filter === 'Completed' && r.status === 'Completed') ||
                (filter === 'Paused' && r.status === 'Paused')) &&
            `${r.title} ${r.owner} ${r.source}`
                .toLowerCase()
                .includes(query.toLowerCase()),
    );
    const openSource = (id: string) =>
        d.documents.some((f) => f.id === id)
            ? onNav('overview', 'documents')
            : d.works.some((w) => w.id === id)
              ? onWork(id)
              : d.schedules.some((s) => s.id === id)
                ? onNav('compliance', 'schedules')
                : d.compliance.some((c) => c.id === id)
                  ? onNav('compliance')
                  : onNav('overview');
    return (
        <div className="activity-studio">
            <div className="activity-title">
                <div>
                    <span className="studio-eyebrow">TIMELY FOLLOW-UP</span>
                    <h2 className="text-section-title">Reminders</h2>
                    <p className="muted">
                        One place for vehicle follow-ups and obligation
                        reminders.
                    </p>
                </div>
                <div className="library-actions">
                    <Button variant="outline" onClick={() => onNav('calendar')}>
                        <CalendarDays size={16} />
                        Open calendar
                    </Button>
                    <Button
                        disabled={!m.canManage}
                        onClick={() => m.followup()}
                    >
                        <Plus size={16} />
                        Add reminder
                    </Button>
                </div>
            </div>
            <div className="reminder-summary">
                <div>
                    <span className="feature-icon">
                        <Bell size={20} />
                    </span>
                    <strong>
                        {
                            d.followups.filter(
                                (r) =>
                                    !['Completed', 'Paused'].includes(r.status),
                            ).length
                        }
                    </strong>
                    <span>Open follow-ups</span>
                </div>
                <div>
                    <span className="feature-icon">
                        <Wrench size={20} />
                    </span>
                    <strong>{obligations.length}</strong>
                    <span>Linked obligations</span>
                </div>
                <div>
                    <span className="feature-icon warning">
                        <Clock3 size={20} />
                    </span>
                    <strong>{failed}</strong>
                    <span>Delivery needs attention</span>
                </div>
            </div>
            <section className="studio-card reminder-workspace">
                <div className="activity-toolbar">
                    <div>
                        <h3 className="text-section-title">
                            Vehicle follow-ups
                        </h3>
                        <small className="muted">
                            Dates appear on the vehicle calendar without
                            blocking bookings.
                        </small>
                    </div>
                    <Input
                        aria-label="Search reminders"
                        placeholder="Title, owner or source…"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                    />
                    <select
                        aria-label="Reminder filter"
                        value={filter}
                        onChange={(e) => setFilter(e.target.value)}
                    >
                        <option>All reminders</option>
                        <option>Scheduled</option>
                        <option>Completed</option>
                        <option>Paused</option>
                    </select>
                    <CollectionToggle label="Reminders" {...layout} />
                </div>
                <RecordCollection
                    label="Vehicle follow-ups"
                    view={layout.view}
                    columns={[
                        { label: 'Due / repeat' },
                        { label: 'Owner & source', width: '1.2fr' },
                        { label: 'Status / calendar', width: '1.1fr' },
                    ]}
                    records={rows.map((r) => ({
                        id: r.id,
                        name: r.title,
                        subline: r.notes || r.id,
                        icon: Bell,
                        fields: [
                            <>
                                <strong>{localDateTimeLabel(r.at)}</strong>
                                <small>
                                    {r.repeat
                                        ? `Every ${r.repeat} calendar months`
                                        : 'One-off'}
                                </small>
                            </>,
                            <>
                                <span>{r.owner}</span>
                                <button
                                    className="inline-evidence"
                                    onClick={() => openSource(r.source)}
                                >
                                    {r.source}
                                    <ArrowUpRight size={13} />
                                </button>
                                <small>{r.id}</small>
                            </>,
                            <>
                                <Badge
                                    tone={
                                        r.status === 'Completed'
                                            ? 'success'
                                            : r.status === 'Paused'
                                              ? 'neutral'
                                              : 'info'
                                    }
                                >
                                    {r.status}
                                </Badge>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        onNav('calendar', r.at.slice(0, 10))
                                    }
                                >
                                    <CalendarDays size={14} />
                                    View in calendar
                                </Button>
                            </>,
                        ],
                        open: () => m.followupAction(r.id, 'detail'),
                        footer: {
                            personName: r.owner,
                            primary: r.owner,
                            secondary: 'Follow-up owner',
                        },
                        actions: [
                            {
                                label: 'View reminder & activity',
                                icon: Bell,
                                onClick: () => m.followupAction(r.id, 'detail'),
                            },
                            {
                                label: 'View in calendar',
                                icon: CalendarDays,
                                onClick: () =>
                                    onNav('calendar', r.at.slice(0, 10)),
                            },
                            ...(m.canManage
                                ? [
                                      {
                                          label: 'Edit / reschedule',
                                          icon: CalendarDays,
                                          onClick: () => m.followup(r.id),
                                      },
                                  ]
                                : []),
                            ...(m.canManage && r.status === 'Scheduled'
                                ? [
                                      {
                                          label: 'Acknowledge',
                                          icon: Bell,
                                          onClick: () =>
                                              m.followupAction(r.id, 'ack'),
                                      },
                                  ]
                                : []),
                            ...(m.canManage &&
                            !['Completed', 'Paused'].includes(r.status)
                                ? [
                                      {
                                          label: 'Complete follow-up',
                                          icon: CheckCircle2,
                                          onClick: () =>
                                              m.followupAction(
                                                  r.id,
                                                  'complete',
                                              ),
                                      },
                                      {
                                          label: 'Pause reminder',
                                          icon: Clock3,
                                          onClick: () =>
                                              m.followupAction(r.id, 'pause'),
                                      },
                                  ]
                                : []),
                        ],
                    }))}
                    empty={
                        <>
                            <h3>
                                {d.followups.length
                                    ? 'No matching reminders'
                                    : 'No follow-ups yet'}
                            </h3>
                            <p>
                                Add a mileage check, document follow-up or
                                workshop call with an owner and due date.
                            </p>
                            <Button
                                variant="outline"
                                disabled={!m.canManage}
                                onClick={() => m.followup()}
                            >
                                Create reminder
                            </Button>
                        </>
                    }
                />
            </section>
            <section className="studio-card reminder-obligations">
                <div className="studio-section-heading">
                    <div>
                        <span className="studio-eyebrow">
                            FROM SERVICE & COMPLIANCE
                        </span>
                        <h3 className="text-section-title">
                            Obligation reminders
                        </h3>
                    </div>
                    <Badge tone={failed ? 'critical' : 'info'}>
                        {failed
                            ? `${failed} delivery failed`
                            : 'Delivery history available'}
                    </Badge>
                </div>
                <RecordCollection
                    label="Obligation reminders"
                    view={layout.view}
                    columns={[
                        { label: 'Due / owner', width: '1.3fr' },
                        { label: 'Delivery status' },
                        { label: 'Next action', width: '1.1fr' },
                    ]}
                    records={obligations.map((r) => {
                        const status = d.reminders[r.id]?.status || 'Scheduled';
                        return {
                            id: r.id,
                            name: r.name,
                            subline: r.id,
                            icon: Bell,
                            alert:
                                status === 'Delivery failed'
                                    ? 'critical'
                                    : undefined,
                            fields: [
                                <>
                                    <strong>
                                        {r.due
                                            ? dateLabel(r.due)
                                            : 'By distance / evidence'}
                                    </strong>
                                    <small>{r.owner}</small>
                                </>,
                                <Badge
                                    tone={
                                        status === 'Delivery failed'
                                            ? 'critical'
                                            : 'neutral'
                                    }
                                >
                                    {status}
                                </Badge>,
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!m.canManage}
                                    onClick={() =>
                                        m.reminder(
                                            r.id,
                                            status === 'Delivery failed'
                                                ? 'retry'
                                                : 'ack',
                                        )
                                    }
                                >
                                    {status === 'Delivery failed'
                                        ? 'Retry delivery'
                                        : 'Acknowledge'}
                                </Button>,
                            ],
                            open: () => m.reminder(r.id, 'detail'),
                            footer: {
                                personName: r.owner,
                                primary: r.owner,
                                secondary: 'Obligation owner',
                            },
                            actions: [
                                {
                                    label: 'Delivery history',
                                    icon: History,
                                    onClick: () => m.reminder(r.id, 'detail'),
                                },
                                {
                                    label: 'View in calendar',
                                    icon: CalendarDays,
                                    onClick: () => onNav('calendar', r.due),
                                },
                                {
                                    label: 'Open source obligation',
                                    icon: ArrowUpRight,
                                    onClick: () => openSource(r.id),
                                },
                                ...(m.canManage
                                    ? [
                                          {
                                              label: 'Add linked follow-up',
                                              icon: Bell,
                                              onClick: () =>
                                                  m.followup(undefined, r.id),
                                          },
                                          {
                                              label: 'Manage reminder plan',
                                              icon: CalendarDays,
                                              onClick: () =>
                                                  d.schedules.some(
                                                      (s) => s.id === r.id,
                                                  )
                                                      ? m.schedule(r.id)
                                                      : m.detail(
                                                            'Compliance reminder policy',
                                                            [
                                                                [
                                                                    'Owner',
                                                                    'Operations Manager',
                                                                ],
                                                                [
                                                                    'Lead time',
                                                                    r.lead +
                                                                        ' days · example',
                                                                ],
                                                                [
                                                                    'Delivery',
                                                                    'In-app task',
                                                                ],
                                                                [
                                                                    'Operational approval',
                                                                    'Required before real delivery',
                                                                ],
                                                            ],
                                                        ),
                                          },
                                          {
                                              label:
                                                  status === 'Delivery failed'
                                                      ? 'Retry delivery'
                                                      : 'Acknowledge',
                                              icon: Bell,
                                              onClick: () =>
                                                  m.reminder(
                                                      r.id,
                                                      status ===
                                                          'Delivery failed'
                                                          ? 'retry'
                                                          : 'ack',
                                                  ),
                                          },
                                      ]
                                    : []),
                            ],
                        };
                    })}
                />
                <p className="studio-footnote">
                    Acknowledgement does not complete the service or compliance
                    obligation. Reminder delivery is demonstrated locally;
                    production tasks and notification delivery remain
                    source-owned.
                </p>
            </section>
        </div>
    );
}
