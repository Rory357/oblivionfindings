import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    ArrowRight,
    ArrowUpRight,
    Bell,
    CalendarDays,
    Camera,
    Car,
    Check,
    CheckCircle2,
    ClipboardCheck,
    Clock3,
    FileText,
    Gauge,
    History,
    MoreHorizontal,
    Paperclip,
    ShieldAlert,
    ShieldCheck,
    Upload,
    UserRound,
    Wrench,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { EvidenceShelf } from './evidence-field';
import { type Run } from './flows';
import {
    type VehicleModel,
    dateLabel,
    serviceStatus,
    VehicleSurface,
} from './operations';
import { Badge } from './ui';
const formatKm = (n: number) => (n ? `${n.toLocaleString()} km` : 'No reading');
function Menu({
    items,
}: {
    items: { label: string; run: () => void; disabled?: boolean }[];
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" aria-label="Record actions">
                    <MoreHorizontal size={18} />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {items.map((i) => (
                    <DropdownMenuItem
                        key={i.label}
                        disabled={i.disabled}
                        onSelect={i.run}
                    >
                        {i.label}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
function SectionHeading({
    eyebrow,
    title,
    children,
}: {
    eyebrow?: string;
    title: string;
    children?: React.ReactNode;
}) {
    return (
        <div className="studio-section-heading">
            <div>
                {eyebrow && <span className="studio-eyebrow">{eyebrow}</span>}
                <h2 className="text-section-title">{title}</h2>
            </div>
            {children}
        </div>
    );
}
export function StudioSurface({
    readiness,
    model: m,
    view,
    sub,
    onNav,
    onWork,
    onCheck,
}: {
    readiness: string;
    model: VehicleModel;
    view: string;
    sub: string;
    onNav: (v: string, s?: string) => void;
    onWork: (id: string) => void;
    onCheck: () => void;
}) {
    const d = m.data,
        s = m.nextSchedule;
    const [query, setQuery] = useState('');
    if (view === 'overview' && sub === 'summary')
        return (
            <div className="studio-overview">
                <section className="studio-readiness">
                    <div className="readiness-primary">
                        <div
                            className={`status-medallion ${m.hold ? 'restricted' : 'clear'}`}
                        >
                            {m.hold ? (
                                <ShieldAlert size={30} />
                            ) : (
                                <ShieldCheck size={30} />
                            )}
                        </div>
                        <div>
                            <span className="studio-eyebrow">
                                VEHICLE READINESS
                            </span>
                            <h2>
                                {m.hold
                                    ? 'Restricted for use'
                                    : readiness === 'Ready'
                                      ? 'Ready for next journey'
                                      : 'Readiness needs review'}
                            </h2>
                            <p>
                                {m.hold
                                    ? 'Condition concern · independent release required'
                                    : readiness === 'Ready'
                                      ? 'Current example evidence recorded. Review the driver and journey at checkout.'
                                      : 'Review missing, stale or overdue evidence before confirming use.'}
                            </p>
                        </div>
                        <Badge tone={m.hold ? 'critical' : 'info'}>
                            {m.hold ? 'Action required' : readiness}
                        </Badge>
                    </div>
                    <div className="readiness-action">
                        <div>
                            <small>Next action</small>
                            <strong>
                                {m.hold
                                    ? 'Review repair & release'
                                    : readiness === 'Ready'
                                      ? 'Confirm the next journey'
                                      : 'Resolve outstanding evidence'}
                            </strong>
                            <span>
                                {m.hold
                                    ? 'Operations Manager · RST-DEMO-12'
                                    : d.profile.owner}
                            </span>
                        </div>
                        <Button
                            disabled={m.hold ? !m.canManage : !m.canRequest}
                            onClick={() =>
                                m.hold
                                    ? m.release()
                                    : onNav(
                                          readiness === 'Ready'
                                              ? 'calendar'
                                              : 'compliance',
                                      )
                            }
                        >
                            {m.hold
                                ? 'Review release'
                                : readiness === 'Ready'
                                  ? 'Book this vehicle'
                                  : 'Review evidence'}
                            <ArrowRight size={16} />
                        </Button>
                    </div>
                    <div className="readiness-facts">
                        {[
                            {
                                icon: Wrench,
                                label: 'Open work',
                                value: String(
                                    d.works.filter(
                                        (w) =>
                                            ![
                                                'Completed',
                                                'Cancelled',
                                            ].includes(w.status),
                                    ).length,
                                ),
                                go: () => onNav('maintenance'),
                            },
                            {
                                icon: ClipboardCheck,
                                label: 'Next check',
                                value: dateLabel(d.checkDue),
                                go: () => onNav('checks'),
                            },
                            {
                                icon: Gauge,
                                label: 'Latest mileage',
                                value: formatKm(m.odo),
                                go: () => onNav('compliance', 'mileage'),
                            },
                        ].map((x) => (
                            <button key={x.label} onClick={x.go}>
                                <x.icon size={18} />
                                <span>
                                    <small>{x.label}</small>
                                    <strong>{x.value}</strong>
                                </span>
                                <ArrowUpRight size={14} />
                            </button>
                        ))}
                    </div>
                </section>
                <section className="studio-next">
                    <SectionHeading
                        eyebrow="LOOKING AHEAD"
                        title="Next service"
                    >
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => onNav('compliance', 'schedules')}
                        >
                            All <ArrowRight size={14} />
                        </Button>
                    </SectionHeading>
                    {s ? (
                        <>
                            <div className="service-date">
                                <CalendarDays size={25} />
                                <div>
                                    <strong>
                                        {s.due
                                            ? dateLabel(s.due)
                                            : 'Distance based'}
                                    </strong>
                                    <span>
                                        {s.name} · {s.id}
                                    </span>
                                </div>
                            </div>
                            <div className="service-distance">
                                <span>
                                    {s.dueKm
                                        ? formatKm(s.dueKm)
                                        : 'Date trigger only'}
                                </span>
                                <Badge
                                    tone={
                                        serviceStatus(s, m.odo).overdue
                                            ? 'critical'
                                            : 'warning'
                                    }
                                >
                                    {serviceStatus(s, m.odo).overdue
                                        ? 'Overdue'
                                        : 'Upcoming'}
                                </Badge>
                            </div>
                            <p className="text-caption text-muted-foreground">
                                {s.owner}
                            </p>
                            <Button
                                className="w-full"
                                variant="outline"
                                disabled={!m.canManage}
                                onClick={() => m.plan(s.id, true)}
                            >
                                Plan service
                            </Button>
                        </>
                    ) : (
                        <>
                            <p className="studio-empty-line">
                                No service schedule recorded.
                            </p>
                            <Button
                                onClick={() => m.schedule()}
                                disabled={!m.canManage}
                            >
                                Set up service schedule
                            </Button>
                        </>
                    )}
                </section>
                <section className="studio-health">
                    <SectionHeading title="Compliance at a glance">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => onNav('compliance')}
                        >
                            View evidence <ArrowRight size={14} />
                        </Button>
                    </SectionHeading>
                    <div className="health-grid">
                        {d.compliance.map((c) => (
                            <button
                                className="health-cell"
                                key={c.id}
                                onClick={() => onNav('compliance')}
                            >
                                <span
                                    className={
                                        c.evidence ||
                                        c.applies === 'Not applicable'
                                            ? 'health-icon success'
                                            : 'health-icon warning'
                                    }
                                >
                                    {c.evidence ||
                                    c.applies === 'Not applicable' ? (
                                        <Check size={17} />
                                    ) : (
                                        <Clock3 size={17} />
                                    )}
                                </span>
                                <div>
                                    <strong>{c.name}</strong>
                                    <small>
                                        {c.applies === 'Not applicable'
                                            ? 'Not applicable'
                                            : c.due
                                              ? dateLabel(c.due)
                                              : c.high
                                                ? formatKm(c.high)
                                                : 'Needs evidence'}
                                    </small>
                                </div>
                                <ArrowUpRight size={14} />
                            </button>
                        ))}
                    </div>
                </section>
                <section className="studio-activity">
                    <SectionHeading title="What happens next">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => onNav('calendar')}
                        >
                            Calendar <ArrowRight size={14} />
                        </Button>
                    </SectionHeading>
                    <div className="next-event-row">
                        <span className="timeline-node">
                            <ClipboardCheck size={16} />
                        </span>
                        <div>
                            <strong>Vehicle condition check</strong>
                            <small>
                                {dateLabel(d.checkDue)} · {d.checkOwner}
                            </small>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!m.canRequest}
                            onClick={onCheck}
                        >
                            Start check
                        </Button>
                    </div>
                    <div className="next-event-row">
                        <span className="timeline-node">
                            <Bell size={16} />
                        </span>
                        <div>
                            <strong>Reminder follow-up</strong>
                            <small>Service and compliance owners</small>
                        </div>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => onNav('compliance', 'reminders')}
                        >
                            Review
                        </Button>
                    </div>
                </section>
            </div>
        );
    if (view === 'overview' && sub === 'details')
        return (
            <div className="profile-details-studio">
                <section className="studio-card profile-identity">
                    <div
                        className="profile-photo"
                        onClick={() => m.canManage && m.setPhotoOpen(true)}
                    >
                        {d.photo?.url ? (
                            <img src={d.photo.url} alt="Kōwhai van" />
                        ) : (
                            <Car size={74} />
                        )}
                        <Button
                            variant="secondary"
                            disabled={!m.canManage}
                            onClick={() => m.setPhotoOpen(true)}
                        >
                            <Camera size={16} />
                            {d.photo ? 'Change photo' : 'Upload profile photo'}
                        </Button>
                    </div>
                    <div>
                        <span className="studio-eyebrow">VH-014 · KWH014</span>
                        <h2 className="text-section-title">Toyota Hiace</h2>
                        <p className="muted">
                            Kōwhai House · Community transport
                        </p>
                        <Badge tone="info">{d.profile.life}</Badge>
                    </div>
                </section>
                <section className="studio-card">
                    <SectionHeading title="Vehicle details">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!m.canManage}
                            onClick={m.profile}
                        >
                            Edit details
                        </Button>
                    </SectionHeading>
                    <dl className="facts-grid">
                        {[
                            ['VIN / chassis', d.profile.vin],
                            ['Category', d.profile.category],
                            ['Responsible role', d.profile.owner],
                            ['Insurance', d.profile.insurance],
                            ['Warranty', d.profile.warranty],
                            ['Ownership', d.profile.lease],
                        ].map(([label, value]) => (
                            <div key={label}>
                                <dt>{label}</dt>
                                <dd>{value}</dd>
                            </div>
                        ))}
                    </dl>
                </section>
                <section className="studio-card profile-documents">
                    <EvidenceShelf
                        files={d.documents.filter((x) => x.owner === 'VH-014')}
                        disabled={!m.canManage}
                        onAdd={() => m.setUploadFor('VH-014')}
                        title="Vehicle documents"
                    />
                </section>
                <section className="studio-card">
                    <SectionHeading title="Lifecycle history" />
                    <div className="compact-timeline">
                        {d.profile.history.map((x, i) => (
                            <div key={i}>
                                <span className="timeline-node">
                                    <History size={14} />
                                </span>
                                <p>{x}</p>
                            </div>
                        ))}
                    </div>
                </section>
            </div>
        );
    if (view === 'compliance' && sub === 'schedules')
        return (
            <div className="studio-page">
                <SectionHeading
                    eyebrow="PLANNED MAINTENANCE"
                    title="Service schedules"
                >
                    <Button
                        onClick={() => m.schedule()}
                        disabled={!m.canManage}
                    >
                        Add schedule
                    </Button>
                </SectionHeading>
                <div className="service-card-grid">
                    {d.schedules.map((s) => {
                        const status = serviceStatus(s, m.odo),
                            progress =
                                s.dueKm && s.lastKm
                                    ? Math.max(
                                          0,
                                          Math.min(
                                              100,
                                              ((m.odo - s.lastKm) /
                                                  (s.dueKm - s.lastKm)) *
                                                  100,
                                          ),
                                      )
                                    : 0;
                        return (
                            <section
                                className="service-schedule-card"
                                key={s.id}
                            >
                                <div className="service-card-head">
                                    <span className="feature-icon">
                                        <Wrench size={23} />
                                    </span>
                                    <div>
                                        <h3 className="text-section-title">
                                            {s.name}
                                        </h3>
                                        <small>{s.id}</small>
                                    </div>
                                    <Menu
                                        items={[
                                            {
                                                label: 'Manage schedule',
                                                run: () => m.schedule(s.id),
                                                disabled: !m.canManage,
                                            },
                                            {
                                                label: 'Reminder history',
                                                run: () =>
                                                    m.reminder(s.id, 'detail'),
                                            },
                                            {
                                                label: 'View completed work',
                                                run: () =>
                                                    onNav(
                                                        'compliance',
                                                        'history',
                                                    ),
                                            },
                                        ]}
                                    />
                                </div>
                                <div className="service-card-due">
                                    <div>
                                        <small>Next due</small>
                                        <strong>
                                            {s.due
                                                ? dateLabel(s.due)
                                                : formatKm(s.dueKm)}
                                        </strong>
                                    </div>
                                    <Badge
                                        tone={
                                            status.overdue ? 'critical' : 'info'
                                        }
                                    >
                                        {status.overdue
                                            ? 'Overdue'
                                            : 'Scheduled'}
                                    </Badge>
                                </div>
                                {s.dueKm > 0 && (
                                    <div className="distance-meter">
                                        <div>
                                            <span>
                                                {formatKm(m.odo)} current
                                            </span>
                                            <strong>
                                                {formatKm(
                                                    Math.max(
                                                        0,
                                                        s.dueKm - m.odo,
                                                    ),
                                                )}{' '}
                                                remaining
                                            </strong>
                                        </div>
                                        <progress
                                            value={progress}
                                            max={100}
                                            aria-label={`${s.name} distance interval used`}
                                        />
                                        <small>
                                            Due at {formatKm(s.dueKm)}
                                            {m.readingStale
                                                ? ' · reading is stale'
                                                : ''}
                                        </small>
                                    </div>
                                )}
                                {!s.dueKm && s.due && (
                                    <div className="distance-meter">
                                        <div>
                                            <span>Date-based interval</span>
                                            <strong>
                                                {Math.max(
                                                    0,
                                                    Math.round(
                                                        (new Date(
                                                            s.due + 'T12:00',
                                                        ).getTime() -
                                                            new Date(
                                                                '2026-09-22T12:00',
                                                            ).getTime()) /
                                                            86400000,
                                                    ),
                                                )}{' '}
                                                days remaining
                                            </strong>
                                        </div>
                                        <progress
                                            value={
                                                s.days
                                                    ? Math.max(
                                                          0,
                                                          Math.min(
                                                              100,
                                                              100 -
                                                                  ((new Date(
                                                                      s.due +
                                                                          'T12:00',
                                                                  ).getTime() -
                                                                      new Date(
                                                                          '2026-09-22T12:00',
                                                                      ).getTime()) /
                                                                      86400000 /
                                                                      s.days) *
                                                                      100,
                                                          ),
                                                      )
                                                    : 0
                                            }
                                            max={100}
                                            aria-label={
                                                s.name + ' date interval used'
                                            }
                                        />
                                        <small>
                                            Next service by {dateLabel(s.due)}
                                        </small>
                                    </div>
                                )}
                                <dl className="schedule-facts">
                                    <div>
                                        <dt>Interval</dt>
                                        <dd>
                                            {[
                                                s.days ? `${s.days} days` : '',
                                                s.distance
                                                    ? formatKm(s.distance)
                                                    : '',
                                            ]
                                                .filter(Boolean)
                                                .join(' or ')}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Owner</dt>
                                        <dd>{s.owner}</dd>
                                    </div>
                                    <div>
                                        <dt>Last service</dt>
                                        <dd>{dateLabel(s.last)}</dd>
                                    </div>
                                </dl>
                                <div className="service-card-actions">
                                    <Button
                                        disabled={!m.canManage}
                                        onClick={() => m.plan(s.id, true)}
                                    >
                                        Plan service
                                    </Button>
                                    <Button
                                        variant="outline"
                                        disabled={!m.canManage}
                                        onClick={() => m.setUploadFor(s.id)}
                                    >
                                        <Upload size={15} />
                                        Upload evidence
                                    </Button>
                                </div>
                                {d.documents.some((x) => x.owner === s.id) && (
                                    <EvidenceShelf
                                        title="Schedule documents"
                                        files={d.documents.filter(
                                            (x) => x.owner === s.id,
                                        )}
                                        disabled={!m.canManage}
                                        onAdd={() => m.setUploadFor(s.id)}
                                    />
                                )}
                            </section>
                        );
                    })}
                </div>
                {!d.schedules.length && (
                    <div className="studio-empty">
                        <Wrench size={35} />
                        <h3>No service schedules yet</h3>
                        <p>
                            Add the vehicle or component requirement and
                            supporting service documents.
                        </p>
                        <Button
                            onClick={() => m.schedule()}
                            disabled={!m.canManage}
                        >
                            Set up service schedule
                        </Button>
                    </div>
                )}
                <p className="studio-footnote">
                    The first reached date or distance trigger needs action.
                    Intervals and reminder lead times are synthetic examples.
                </p>
            </div>
        );
    if (
        (view === 'compliance' && sub === 'history') ||
        (view === 'maintenance' && sub === 'history')
    )
        return <HistoryStudio model={m} onWork={onWork} />;
    if (view === 'compliance' && sub === 'summary')
        return (
            <section className="studio-card">
                <SectionHeading
                    eyebrow="EVIDENCE & OBLIGATIONS"
                    title="Service & compliance"
                >
                    <Button
                        variant="outline"
                        onClick={() => onNav('compliance', 'schedules')}
                    >
                        <Wrench size={16} />
                        Service schedules
                    </Button>
                </SectionHeading>
                <div className="compliance-table">
                    <div className="compliance-table-head">
                        <span>Requirement</span>
                        <span>Recorded status</span>
                        <span>Next due / coverage</span>
                        <span>Owner & evidence</span>
                        <span />
                    </div>
                    {d.compliance.map((c) => (
                        <div className="compliance-table-row" key={c.id}>
                            <div className="record-name">
                                <span className="feature-icon small">
                                    <ShieldCheck size={18} />
                                </span>
                                <div>
                                    <strong>{c.name}</strong>
                                    <small>{c.id}</small>
                                </div>
                            </div>
                            <div>
                                <Badge
                                    tone={
                                        c.outcome === 'Failed'
                                            ? 'critical'
                                            : c.evidence ||
                                                c.applies === 'Not applicable'
                                              ? 'success'
                                              : 'warning'
                                    }
                                >
                                    {c.applies === 'Not applicable'
                                        ? 'Not applicable'
                                        : c.outcome}
                                </Badge>
                                <small className="muted block">
                                    {c.applies}
                                </small>
                            </div>
                            <div>
                                <strong>
                                    {c.due
                                        ? dateLabel(c.due)
                                        : c.high
                                          ? `${formatKm(c.low)} – ${formatKm(c.high)}`
                                          : 'Not recorded'}
                                </strong>
                                {c.high > 0 && (
                                    <small className="muted block">
                                        {m.odo > c.high
                                            ? 'Licence range exceeded'
                                            : `${formatKm(c.high - m.odo)} remaining`}
                                    </small>
                                )}
                            </div>
                            <div>
                                <small>{d.profile.owner}</small>
                                <button
                                    className="inline-evidence"
                                    onClick={() => m.setUploadFor(c.id)}
                                    disabled={!m.canManage}
                                >
                                    <Paperclip size={13} />
                                    {
                                        d.documents.filter(
                                            (x) => x.owner === c.id,
                                        ).length
                                    }{' '}
                                    files · add evidence
                                </button>
                            </div>
                            <Menu
                                items={[
                                    {
                                        label: 'Update evidence',
                                        run: () => m.compliance(c.id),
                                        disabled: !m.canManage,
                                    },
                                    {
                                        label: 'Upload document',
                                        run: () => m.setUploadFor(c.id),
                                        disabled: !m.canManage,
                                    },
                                    {
                                        label: 'Plan appointment',
                                        run: () => m.plan(c.id),
                                        disabled: !m.canManage,
                                    },
                                    {
                                        label: 'View source record',
                                        run: () =>
                                            m.detail(`${c.name} source`, [
                                                ['Reference', c.id],
                                                ['Applicability', c.applies],
                                                [
                                                    'Basis',
                                                    c.basis || 'Not recorded',
                                                ],
                                                [
                                                    'Evidence',
                                                    c.evidence ||
                                                        'Not recorded',
                                                ],
                                            ]),
                                    },
                                ]}
                            />
                        </div>
                    ))}
                </div>
                <div className="studio-footer-action">
                    <span>
                        <Bell size={16} />
                        Reminders have their own owner and delivery history.
                    </span>
                    <Button
                        variant="ghost"
                        onClick={() => onNav('compliance', 'reminders')}
                    >
                        Review reminders <ArrowRight size={14} />
                    </Button>
                </div>
            </section>
        );
    if (view === 'compliance' && sub === 'reminders')
        return (
            <section className="studio-card">
                <SectionHeading
                    eyebrow="FOLLOW-UP"
                    title="Reminders & delivery"
                >
                    <Badge
                        tone={
                            Object.values(d.reminders).some(
                                (r) => r.status === 'Delivery failed',
                            )
                                ? 'critical'
                                : 'info'
                        }
                    >
                        {
                            Object.values(d.reminders).filter(
                                (r) => r.status === 'Delivery failed',
                            ).length
                        }{' '}
                        delivery failures
                    </Badge>
                </SectionHeading>
                <div className="reminder-list">
                    {[
                        ...d.schedules.map((s) => ({
                            id: s.id,
                            name: s.name,
                            owner: s.owner,
                            due: s.due,
                        })),
                        ...d.compliance
                            .filter((c) => c.applies === 'Applicable')
                            .map((c) => ({
                                id: c.id,
                                name: c.name,
                                owner: d.profile.owner,
                                due: c.due,
                            })),
                    ].map((r) => (
                        <div className="reminder-line" key={r.id}>
                            <span
                                className={`timeline-node ${d.reminders[r.id]?.status === 'Delivery failed' ? 'warning' : ''}`}
                            >
                                <Bell size={17} />
                            </span>
                            <div>
                                <strong>{r.name}</strong>
                                <small>
                                    {r.owner} ·{' '}
                                    {r.due
                                        ? dateLabel(r.due)
                                        : 'Distance / evidence trigger'}
                                </small>
                            </div>
                            <Badge
                                tone={
                                    d.reminders[r.id]?.status ===
                                    'Delivery failed'
                                        ? 'critical'
                                        : 'neutral'
                                }
                            >
                                {d.reminders[r.id]?.status ?? 'Scheduled'}
                            </Badge>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!m.canManage}
                                onClick={() =>
                                    m.reminder(
                                        r.id,
                                        d.reminders[r.id]?.status ===
                                            'Delivery failed'
                                            ? 'retry'
                                            : 'ack',
                                    )
                                }
                            >
                                {d.reminders[r.id]?.status === 'Delivery failed'
                                    ? 'Retry'
                                    : 'Acknowledge'}
                            </Button>
                            <Menu
                                items={[
                                    {
                                        label: 'Delivery history',
                                        run: () => m.reminder(r.id, 'detail'),
                                    },
                                    {
                                        label: 'Act on obligation',
                                        run: () =>
                                            d.schedules.some(
                                                (s) => s.id === r.id,
                                            )
                                                ? m.plan(r.id, true)
                                                : m.compliance(r.id),
                                        disabled: !m.canManage,
                                    },
                                    {
                                        label: 'Manage schedule / policy context',
                                        run: () =>
                                            d.schedules.some(
                                                (s) => s.id === r.id,
                                            )
                                                ? m.schedule(r.id)
                                                : m.detail('Reminder policy', [
                                                      [
                                                          'Owner',
                                                          'Operations Manager',
                                                      ],
                                                      [
                                                          'Channel',
                                                          'In-app task',
                                                      ],
                                                      [
                                                          'Lead time',
                                                          '7 days · example',
                                                      ],
                                                      [
                                                          'Backup',
                                                          'Operations Manager',
                                                      ],
                                                  ]),
                                        disabled: !m.canManage,
                                    },
                                ]}
                            />
                        </div>
                    ))}
                </div>
                <p className="studio-footnote">
                    Acknowledgement keeps the obligation open. Backup owner:
                    Operations Manager. Example lead times are managed with each
                    schedule.
                </p>
            </section>
        );
    if (view === 'maintenance' && sub === 'summary')
        return (
            <section className="studio-card">
                <SectionHeading eyebrow="MAINTENANCE" title="Open work">
                    <Badge tone="info">
                        {
                            d.works.filter(
                                (w) =>
                                    !['Completed', 'Cancelled'].includes(
                                        w.status,
                                    ),
                            ).length
                        }{' '}
                        work records
                    </Badge>
                </SectionHeading>
                <div className="work-card-grid">
                    {d.works
                        .filter(
                            (w) =>
                                !['Completed', 'Cancelled'].includes(w.status),
                        )
                        .map((w) => (
                            <button
                                className="work-preview-card"
                                key={w.id}
                                onClick={() => onWork(w.id)}
                            >
                                <div>
                                    <span className="feature-icon">
                                        <Wrench size={20} />
                                    </span>
                                    <Badge
                                        tone={
                                            w.outcome === 'Failed'
                                                ? 'critical'
                                                : 'info'
                                        }
                                    >
                                        {w.status}
                                    </Badge>
                                </div>
                                <h3 className="text-section-title">
                                    {w.title}
                                </h3>
                                <p>
                                    {w.id} · {w.source}
                                </p>
                                <footer>
                                    <span>
                                        <UserRound size={14} />
                                        {w.owner}
                                    </span>
                                    <ArrowUpRight size={17} />
                                </footer>
                            </button>
                        ))}
                </div>
                {!d.works.length && (
                    <div className="studio-empty">
                        <Wrench size={28} />
                        <h3>No open work</h3>
                        <p>
                            Use Report a problem when a concern needs
                            assessment.
                        </p>
                    </div>
                )}
                <div className="studio-footer-action">
                    <span>
                        <ShieldCheck size={17} />
                        Work completion and authorised release remain separate.
                    </span>
                    <Button
                        variant="ghost"
                        onClick={() => onNav('maintenance', 'history')}
                    >
                        View work history <ArrowRight size={14} />
                    </Button>
                </div>
            </section>
        );
    return (
        <VehicleSurface
            model={m}
            view={view}
            sub={sub}
            onNav={onNav}
            onWork={onWork}
            onCheck={onCheck}
        />
    );
}
function HistoryStudio({
    model: m,
    onWork,
}: {
    model: VehicleModel;
    onWork: (id: string) => void;
}) {
    const [filter, setFilter] = useState('all'),
        [query, setQuery] = useState('');
    const records = m.data.works
        .slice()
        .sort((a, b) =>
            (b.completed || b.cancelledAt || '').localeCompare(
                a.completed || a.cancelledAt || '',
            ),
        )
        .filter(
            (w) =>
                [
                    'Completed',
                    'Completed · awaiting release',
                    'Cancelled',
                ].includes(w.status) &&
                `${w.title} ${w.id} ${w.provider}`
                    .toLowerCase()
                    .includes(query.toLowerCase()) &&
                (filter === 'all' ||
                    (filter === 'completed'
                        ? w.status !== 'Cancelled'
                        : w.status === 'Cancelled')),
        );
    return (
        <section className="studio-card history-studio">
            <SectionHeading
                eyebrow="VEHICLE RECORD"
                title="Service & work history"
            >
                <div className="studio-inline">
                    <Input
                        aria-label="Find service history"
                        placeholder="Find a service or reference…"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                    />
                    <select
                        aria-label="Service history filter"
                        value={filter}
                        onChange={(e) => setFilter(e.target.value)}
                    >
                        <option value="all">All outcomes</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </SectionHeading>
            <div className="history-summary">
                <div>
                    <Wrench size={18} />
                    <strong>
                        {records.filter((w) => w.status !== 'Cancelled').length}
                    </strong>
                    <span>Completed records</span>
                </div>
                <div>
                    <Paperclip size={18} />
                    <strong>{records.filter((w) => w.evidence).length}</strong>
                    <span>Source evidence</span>
                </div>
                <div>
                    <Clock3 size={18} />
                    <strong>
                        {records.filter((w) => w.status === 'Cancelled').length}
                    </strong>
                    <span>Cancelled visits</span>
                </div>
            </div>
            <div className="service-timeline">
                {records.map((w) => (
                    <article key={w.id}>
                        <div className="history-date">
                            <strong>
                                {w.completed || w.cancelledAt
                                    ? new Date(
                                          (w.completed || w.cancelledAt) +
                                              'T12:00',
                                      ).toLocaleDateString('en-NZ', {
                                          day: 'numeric',
                                          month: 'short',
                                      })
                                    : 'Undated'}
                            </strong>
                            <small>
                                {(w.completed || w.cancelledAt || '').slice(
                                    0,
                                    4,
                                )}
                            </small>
                        </div>
                        <span
                            className={`history-dot ${w.status === 'Cancelled' ? 'cancelled' : ''}`}
                        >
                            {w.status === 'Cancelled' ? (
                                <XCircle size={20} />
                            ) : (
                                <CheckCircle2 size={20} />
                            )}
                        </span>
                        <div className="history-event">
                            <header>
                                <div>
                                    <h3 className="text-section-title">
                                        {w.title}
                                    </h3>
                                    <p>
                                        {w.id} ·{' '}
                                        {w.provider || 'Provider visit'}
                                    </p>
                                </div>
                                <Badge
                                    tone={
                                        w.status === 'Cancelled'
                                            ? 'neutral'
                                            : 'success'
                                    }
                                >
                                    {w.status}
                                </Badge>
                            </header>
                            <p>
                                {w.notes ||
                                    w.feedback ||
                                    'Completion evidence and original source retained.'}
                            </p>
                            <div className="history-event-meta">
                                <span>
                                    <Gauge size={14} />
                                    {w.odo
                                        ? formatKm(w.odo)
                                        : 'No mileage recorded'}
                                </span>
                                <span>
                                    <FileText size={14} />
                                    {w.evidence ||
                                        'Cancellation reason retained'}
                                </span>
                            </div>
                            <footer>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => onWork(w.id)}
                                >
                                    Open service record{' '}
                                    <ArrowUpRight size={14} />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    disabled={!m.canManage}
                                    onClick={() => m.setUploadFor(w.id)}
                                >
                                    <Upload size={14} />
                                    Upload evidence
                                </Button>
                            </footer>
                        </div>
                    </article>
                ))}
            </div>
            {!records.length && (
                <div className="studio-empty">
                    <History size={30} />
                    <h3>No matching history</h3>
                    <p>
                        Completed services and cancelled visits will appear
                        here.
                    </p>
                </div>
            )}
        </section>
    );
}
export function StudioChecks({
    model: m,
    runs,
    onStart,
    onView,
    templates,
}: {
    model: VehicleModel;
    runs: Run[];
    onStart: (template: string) => void;
    onView: (run: Run) => void;
    templates: boolean;
}) {
    return (
        <div className="checks-studio">
            <section className="studio-card">
                <SectionHeading
                    eyebrow={
                        templates
                            ? 'CONTROLLED TEMPLATES'
                            : 'ORIGINAL OBSERVATIONS'
                    }
                    title={
                        templates ? 'Check templates' : 'Checks & inspections'
                    }
                >
                    <Button
                        disabled={!m.canRequest}
                        onClick={() => onStart('condition')}
                    >
                        <ClipboardCheck size={16} />
                        Start check
                    </Button>
                </SectionHeading>
                {templates ? (
                    <div className="template-grid">
                        {[
                            {
                                id: 'condition',
                                name: 'Vehicle condition record',
                                version: 'DEMO-3',
                                use: 'Before vehicle use',
                            },
                            {
                                id: 'return',
                                name: 'Return condition record',
                                version: 'DEMO-2',
                                use: 'After vehicle use',
                            },
                        ].map((t) => (
                            <article key={t.id}>
                                <span className="feature-icon">
                                    <ClipboardCheck size={25} />
                                </span>
                                <Badge tone="info">{t.version}</Badge>
                                <h3 className="text-section-title">{t.name}</h3>
                                <p>{t.use} · 2 example observations</p>
                                <span className="muted">
                                    <Paperclip size={14} /> Photos and documents
                                    supported
                                </span>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        m.canRequest
                                            ? onStart(t.id)
                                            : m.detail(t.name, [
                                                  ['Version', t.version],
                                                  [
                                                      'Questions',
                                                      'Exterior and cabin condition',
                                                  ],
                                                  ['Access', 'View only'],
                                                  [
                                                      'Evidence',
                                                      'Original files retained with the run',
                                                  ],
                                              ])
                                    }
                                >
                                    {m.canRequest
                                        ? 'Use template'
                                        : 'View template'}
                                    <ArrowRight size={14} />
                                </Button>
                            </article>
                        ))}
                    </div>
                ) : (
                    <div className="check-record-list">
                        {runs.map((r) => (
                            <div key={r.id}>
                                <button
                                    className="check-record-open"
                                    onClick={() => onView(r)}
                                >
                                    <span
                                        className={`feature-icon ${r.outcome === 'Failed' ? 'warning' : ''}`}
                                    >
                                        <ClipboardCheck size={21} />
                                    </span>
                                    <span>
                                        <strong>{r.template}</strong>
                                        <small>
                                            {r.id} · {r.version} ·{' '}
                                            {dateLabel(r.observed)}
                                        </small>
                                    </span>
                                    <Badge
                                        tone={
                                            r.outcome === 'Failed'
                                                ? 'critical'
                                                : r.outcome === 'Passed'
                                                  ? 'success'
                                                  : 'warning'
                                        }
                                    >
                                        {r.outcome}
                                    </Badge>
                                    <ArrowUpRight size={17} />
                                </button>
                                <div className="check-record-evidence">
                                    <span>
                                        <Paperclip size={14} />
                                        {(r.attachments?.length ?? 0) +
                                            m.data.documents.filter(
                                                (x) => x.owner === r.id,
                                            ).length}{' '}
                                        evidence files
                                    </span>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={!m.canRequest}
                                        onClick={() => m.setUploadFor(r.id)}
                                    >
                                        <Upload size={14} />
                                        Upload evidence
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={!m.canManage}
                                        onClick={m.amendment}
                                    >
                                        Add amendment
                                    </Button>
                                </div>
                            </div>
                        ))}
                        {!runs.length && (
                            <div className="studio-empty">
                                <ClipboardCheck size={30} />
                                <h3>No checks submitted yet</h3>
                                <p>
                                    Start a check and attach the original
                                    observations.
                                </p>
                            </div>
                        )}
                    </div>
                )}
            </section>
            <aside className="studio-card check-plan">
                <SectionHeading
                    eyebrow="NEXT REQUIREMENT"
                    title="Vehicle condition"
                />
                <div className="check-due-date">
                    <CalendarDays size={24} />
                    <strong>{dateLabel(m.data.checkDue)}</strong>
                </div>
                <Badge
                    tone={m.data.checkDue < '2026-09-22' ? 'critical' : 'info'}
                >
                    {m.data.checkDue < '2026-09-22' ? 'Overdue' : 'Scheduled'}
                </Badge>
                <dl>
                    <dt>Owner</dt>
                    <dd>{m.data.checkOwner}</dd>
                    <dt>Template</dt>
                    <dd>DEMO-3 · example requirement</dd>
                </dl>
                <Button
                    variant="outline"
                    className="w-full"
                    disabled={!m.canManage}
                    onClick={m.checkPlan}
                >
                    Manage requirement
                </Button>
                <Button
                    variant="ghost"
                    className="w-full"
                    disabled={!m.canRequest}
                    onClick={() => onStart('condition')}
                >
                    Start a retest
                </Button>
                <p className="studio-footnote">
                    Original answers and files stay with the submitted version.
                    A pass does not release a restriction.
                </p>
            </aside>
        </div>
    );
}
