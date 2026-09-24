import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    ArrowRight,
    ArrowUpRight,
    Bell,
    CalendarDays,
    Camera,
    Car,
    Check,
    ClipboardCheck,
    Clock3,
    FileText,
    Gauge,
    History,
    Paperclip,
    ShieldAlert,
    ShieldCheck,
    Upload,
    Wrench,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { MileageStudio, RemindersStudio } from './activity-studio';
import { ChecklistLibrary, useChecklists } from './checklist-library';
import {
    CollectionToggle,
    RecordCollection,
    useCollectionView,
} from './collection-view';
import { EvidenceShelf } from './evidence-field';
import { type Run } from './flows';
import {
    type VehicleModel,
    dateLabel,
    serviceStatus,
    VehicleSurface,
} from './operations';
import { DocumentsStudio, FinanceStudio } from './record-workspaces';
import { Badge } from './ui';
const formatKm = (n: number) => (n ? `${n.toLocaleString()} km` : 'No reading');
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
    const layout = useCollectionView(`${view}-${sub}`);
    if (view === 'overview' && sub === 'documents')
        return <DocumentsStudio model={m} onNav={onNav} onWork={onWork} />;
    if (view === 'overview' && sub === 'finance')
        return <FinanceStudio model={m} />;
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
                                        serviceStatus(s, m.planningOdo).overdue
                                            ? 'critical'
                                            : 'warning'
                                    }
                                >
                                    {serviceStatus(s, m.planningOdo).overdue
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
                        <h2 className="text-section-title">
                            {d.profile.make} {d.profile.model}
                        </h2>
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
                            [
                                'Fuel / seats',
                                d.profile.fuel +
                                    ' · ' +
                                    d.profile.seats +
                                    ' seats',
                            ],
                            ['Responsible role', d.profile.owner],
                            ['Insurance', d.profile.insurance],
                            ['Warranty', d.profile.warranty],
                            ['Ownership', d.profile.lease],
                        ].map(([label, value]) => (
                            <div key={label}>
                                <dt>{label}</dt>
                                <dd>{value || 'Not recorded'}</dd>
                            </div>
                        ))}
                    </dl>
                </section>
                <section className="studio-card profile-documents">
                    <EvidenceShelf
                        files={d.documents.filter((x) => x.owner === 'VH-014')}
                        disabled={!m.canManage}
                        onAdd={() => m.uploadDocument()}
                        title="Vehicle documents"
                    />
                    <Button
                        variant="ghost"
                        onClick={() => onNav('overview', 'documents')}
                    >
                        Manage documents & renewals <ArrowRight size={14} />
                    </Button>
                    <Button
                        variant="outline"
                        onClick={() => onNav('overview', 'finance')}
                    >
                        Finance records <ArrowRight size={14} />
                    </Button>
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
                    <CollectionToggle label="Service schedules" {...layout} />
                    <Button
                        onClick={() => m.schedule()}
                        disabled={!m.canManage}
                    >
                        Add schedule
                    </Button>
                </SectionHeading>
                <RecordCollection
                    label="Service schedules"
                    view={layout.view}
                    columns={[
                        { label: 'Next due / remaining', width: '1.2fr' },
                        { label: 'Interval & responsibility', width: '1.1fr' },
                        { label: 'Evidence & actions', width: '1.5fr' },
                    ]}
                    records={d.schedules.map((s) => {
                        const status = serviceStatus(s, m.planningOdo),
                            interval =
                                [
                                    s.months ? `${s.months} months` : '',
                                    s.distance ? formatKm(s.distance) : '',
                                ]
                                    .filter(Boolean)
                                    .join(' or ') || 'Not set';
                        const files = d.documents.filter(
                            (x) => x.owner === s.id,
                        );
                        const progress =
                            s.dueKm && s.lastKm
                                ? Math.max(
                                      0,
                                      Math.min(
                                          100,
                                          ((m.planningOdo - s.lastKm) /
                                              (s.dueKm - s.lastKm)) *
                                              100,
                                      ),
                                  )
                                : 0;
                        const open = () =>
                            m.detail(s.name, [
                                ['Reference', s.id],
                                ['Interval', interval],
                                ['Owner', s.owner],
                                ['Next due', dateLabel(s.due)],
                                [
                                    'Distance trigger',
                                    s.dueKm ? formatKm(s.dueKm) : 'Not set',
                                ],
                                ['Last service', dateLabel(s.last)],
                            ]);
                        return {
                            id: s.id,
                            name: s.name,
                            subline: s.id,
                            icon: Wrench,
                            alert: status.overdue ? 'critical' : undefined,
                            fields: [
                                <>
                                    <strong>
                                        {s.due
                                            ? dateLabel(s.due)
                                            : formatKm(s.dueKm)}
                                    </strong>
                                    <Badge
                                        tone={
                                            status.overdue ? 'critical' : 'info'
                                        }
                                    >
                                        {status.overdue
                                            ? 'Overdue'
                                            : 'Scheduled'}
                                    </Badge>
                                    {s.dueKm > 0 ? (
                                        <>
                                            <span>
                                                {formatKm(
                                                    Math.max(
                                                        0,
                                                        s.dueKm - m.planningOdo,
                                                    ),
                                                )}{' '}
                                                remaining
                                            </span>
                                            <progress
                                                value={progress}
                                                max={100}
                                                aria-label={`${s.name} distance interval used`}
                                            />
                                            <small>
                                                Due at {formatKm(s.dueKm)} ·{' '}
                                                {m.tracker.usable
                                                    ? 'tracker estimate'
                                                    : 'recorded odometer'}
                                                {m.readingStale
                                                    ? ' · stale reading'
                                                    : ''}
                                            </small>
                                        </>
                                    ) : s.due ? (
                                        <small>
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
                                        </small>
                                    ) : null}
                                </>,
                                <>
                                    <strong>{interval}</strong>
                                    <span>{s.owner}</span>
                                    <small>
                                        Last service {dateLabel(s.last)}
                                    </small>
                                </>,
                                <>
                                    <div className="collection-actions">
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
                                            <Upload size={14} />
                                            Upload evidence
                                        </Button>
                                    </div>
                                    <small>
                                        {files.length} supporting files
                                    </small>
                                    {files.map((f) => (
                                        <a
                                            className="inline-evidence"
                                            key={f.id}
                                            href={f.url}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            <Paperclip size={13} />
                                            {f.name}
                                        </a>
                                    ))}
                                </>,
                            ],
                            open,
                            footer: {
                                personName: s.owner,
                                primary: s.owner,
                                secondary: 'Schedule owner',
                            },
                            actions: [
                                {
                                    label: 'View schedule',
                                    icon: Wrench,
                                    onClick: open,
                                },
                                ...(m.canManage
                                    ? [
                                          {
                                              label: 'Manage schedule',
                                              icon: Wrench,
                                              onClick: () => m.schedule(s.id),
                                          },
                                          {
                                              label: 'Plan service',
                                              icon: CalendarDays,
                                              onClick: () => m.plan(s.id, true),
                                          },
                                          {
                                              label: 'Upload evidence',
                                              icon: Upload,
                                              onClick: () =>
                                                  m.setUploadFor(s.id),
                                          },
                                      ]
                                    : []),
                                {
                                    label: 'Reminder history',
                                    icon: Bell,
                                    onClick: () => m.reminder(s.id, 'detail'),
                                },
                                {
                                    label: 'View completed work',
                                    icon: History,
                                    onClick: () =>
                                        onNav('compliance', 'history'),
                                },
                            ],
                        };
                    })}
                    empty={
                        <>
                            <h3>No service schedules yet</h3>
                            <p>
                                Add a vehicle or component requirement and
                                supporting documents.
                            </p>
                            <Button
                                disabled={!m.canManage}
                                onClick={() => m.schedule()}
                            >
                                Set up service schedule
                            </Button>
                        </>
                    }
                />
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
                    <CollectionToggle
                        label="Compliance requirements"
                        {...layout}
                    />
                    <Button
                        variant="outline"
                        onClick={() => onNav('compliance', 'schedules')}
                    >
                        <Wrench size={16} />
                        Service schedules
                    </Button>
                </SectionHeading>
                <RecordCollection
                    label="Compliance requirements"
                    view={layout.view}
                    columns={[
                        { label: 'Recorded status' },
                        { label: 'Next due / coverage' },
                        { label: 'Owner & evidence', width: '1.4fr' },
                    ]}
                    records={d.compliance.map((c) => {
                        const open = () =>
                            m.detail(`${c.name} source`, [
                                ['Reference', c.id],
                                ['Applicability', c.applies],
                                ['Basis', c.basis || 'Not recorded'],
                                ['Evidence', c.evidence || 'Not recorded'],
                            ]);
                        const tone =
                            c.outcome === 'Failed'
                                ? 'critical'
                                : c.evidence || c.applies === 'Not applicable'
                                  ? 'success'
                                  : 'warning';
                        return {
                            id: c.id,
                            name: c.name,
                            subline: c.id,
                            icon: ShieldCheck,
                            alert: tone,
                            fields: [
                                <>
                                    <Badge tone={tone}>
                                        {c.applies === 'Not applicable'
                                            ? 'Not applicable'
                                            : c.outcome}
                                    </Badge>
                                    <small>{c.applies}</small>
                                </>,
                                <>
                                    <strong>
                                        {c.due
                                            ? dateLabel(c.due)
                                            : c.high
                                              ? `${formatKm(c.low)} – ${formatKm(c.high)}`
                                              : 'Not recorded'}
                                    </strong>
                                    {c.high > 0 && (
                                        <small>
                                            {m.planningOdo > c.high
                                                ? 'Licence range exceeded'
                                                : `${formatKm(c.high - m.planningOdo)} remaining`}
                                        </small>
                                    )}
                                    {m.canManage && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                m.compliance(c.id, 1)
                                            }
                                        >
                                            Update dates & evidence
                                        </Button>
                                    )}
                                </>,
                                <>
                                    <span>{d.profile.owner}</span>
                                    <button
                                        className="inline-evidence"
                                        disabled={!m.canManage}
                                        onClick={() => m.compliance(c.id, 1)}
                                    >
                                        <Paperclip size={14} />
                                        {
                                            d.documents.filter(
                                                (x) => x.owner === c.id,
                                            ).length
                                        }{' '}
                                        files · add evidence
                                    </button>
                                </>,
                            ],
                            open,
                            footer: {
                                personName: d.profile.owner,
                                primary: d.profile.owner,
                                secondary: 'Responsible owner',
                            },
                            actions: [
                                {
                                    label: 'View source record',
                                    icon: FileText,
                                    onClick: open,
                                },
                                ...(m.canManage
                                    ? [
                                          {
                                              label: 'Update evidence',
                                              icon: ShieldCheck,
                                              onClick: () => m.compliance(c.id),
                                          },
                                          {
                                              label: 'Upload document',
                                              icon: Upload,
                                              onClick: () =>
                                                  m.compliance(c.id, 1),
                                          },
                                          {
                                              label: 'Plan appointment',
                                              icon: CalendarDays,
                                              onClick: () => m.plan(c.id),
                                          },
                                      ]
                                    : []),
                            ],
                        };
                    })}
                />
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
        return <RemindersStudio model={m} onNav={onNav} onWork={onWork} />;
    if (view === 'compliance' && sub === 'mileage')
        return <MileageStudio model={m} onNav={onNav} onWork={onWork} />;
    if (view === 'maintenance' && sub === 'summary')
        return (
            <section className="studio-card">
                <SectionHeading eyebrow="MAINTENANCE" title="Open work">
                    <CollectionToggle
                        label="Open maintenance work"
                        {...layout}
                    />
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
                <RecordCollection
                    label="Open maintenance work"
                    view={layout.view}
                    columns={[
                        { label: 'Status' },
                        { label: 'Source' },
                        { label: 'Responsible owner' },
                    ]}
                    records={d.works
                        .filter(
                            (w) =>
                                !['Completed', 'Cancelled'].includes(w.status),
                        )
                        .map((w) => ({
                            id: w.id,
                            name: w.title,
                            subline: w.id,
                            icon: Wrench,
                            alert:
                                w.outcome === 'Failed' ? 'critical' : undefined,
                            fields: [
                                <Badge
                                    tone={
                                        w.outcome === 'Failed'
                                            ? 'critical'
                                            : 'info'
                                    }
                                >
                                    {w.status}
                                </Badge>,
                                <span>{w.source}</span>,
                                <span>{w.owner}</span>,
                            ],
                            open: () => onWork(w.id),
                            footer: {
                                personName: w.owner,
                                primary: w.owner,
                                secondary: 'Maintenance owner',
                            },
                            actions: [
                                {
                                    label: 'Open work record',
                                    icon: Wrench,
                                    onClick: () => onWork(w.id),
                                },
                                ...(m.canManage
                                    ? [
                                          {
                                              label: 'Upload evidence',
                                              icon: Upload,
                                              onClick: () =>
                                                  m.setUploadFor(w.id),
                                          },
                                      ]
                                    : []),
                            ],
                        }))}
                    empty={
                        <>
                            <h3>No open work</h3>
                            <p>
                                Use Report a problem when a concern needs
                                assessment.
                            </p>
                        </>
                    }
                />
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
    const layout = useCollectionView('service-history');
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
                    <CollectionToggle label="Service history" {...layout} />
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
            <RecordCollection
                label="Service & work records"
                view={layout.view}
                columns={[
                    { label: 'Date / mileage' },
                    { label: 'Outcome', width: '1.1fr' },
                    { label: 'Evidence & notes', width: '1.6fr' },
                ]}
                records={records.map((w) => ({
                    id: w.id,
                    name: w.title,
                    subline: `${w.id} · ${w.provider || 'Provider not recorded'}`,
                    icon: w.status === 'Cancelled' ? XCircle : Wrench,
                    alert: w.status.includes('awaiting')
                        ? 'warning'
                        : undefined,
                    fields: [
                        <>
                            <strong>
                                {w.completed || w.cancelledAt
                                    ? dateLabel(
                                          w.completed || w.cancelledAt || '',
                                      )
                                    : 'Undated'}
                            </strong>
                            <small>
                                {w.odo
                                    ? formatKm(w.odo)
                                    : 'No mileage recorded'}
                            </small>
                        </>,
                        <Badge
                            tone={
                                w.status === 'Cancelled'
                                    ? 'neutral'
                                    : w.status.includes('awaiting')
                                      ? 'warning'
                                      : 'success'
                            }
                        >
                            {w.status}
                        </Badge>,
                        <>
                            <span>
                                {w.notes ||
                                    w.feedback ||
                                    'Completion evidence and original source retained.'}
                            </span>
                            <small>
                                {w.evidence ||
                                    (w.status === 'Cancelled'
                                        ? 'Cancellation reason retained'
                                        : 'No evidence reference recorded')}
                            </small>
                            <Button
                                variant="ghost"
                                size="sm"
                                disabled={!m.canManage}
                                onClick={() => m.setUploadFor(w.id)}
                            >
                                <Upload size={14} />
                                Upload evidence
                            </Button>
                        </>,
                    ],
                    open: () => onWork(w.id),
                    footer: {
                        primary: w.provider || 'Provider not recorded',
                        secondary: 'Open the original service record',
                    },
                    actions: [
                        {
                            label: 'Open service record',
                            icon: Wrench,
                            onClick: () => onWork(w.id),
                        },
                        ...(m.canManage
                            ? [
                                  {
                                      label: 'Upload evidence',
                                      icon: Upload,
                                      onClick: () => m.setUploadFor(w.id),
                                  },
                              ]
                            : []),
                    ],
                }))}
                empty={
                    <>
                        <h3>No matching history</h3>
                        <p>
                            Completed services and cancelled visits will appear
                            here.
                        </p>
                    </>
                }
            />
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
    const library = useChecklists();
    const layout = useCollectionView('recent-checks');
    const requirement =
        library.templates.find((t) => t.id === m.data.checkTemplate) ||
        library.templates[0];
    return (
        <div className="checks-studio">
            <aside className="studio-card check-plan">
                <SectionHeading
                    eyebrow="NEXT REQUIREMENT"
                    title={requirement.name}
                />
                <div className="check-due-date">
                    <CalendarDays size={24} />
                    <strong>{dateLabel(m.data.checkDue)}</strong>
                </div>
                <div className="check-plan-status">
                    <Badge
                        tone={
                            m.data.checkDue < '2026-09-22' ? 'critical' : 'info'
                        }
                    >
                        {m.data.checkDue < '2026-09-22'
                            ? 'Overdue'
                            : 'Scheduled'}
                    </Badge>
                </div>
                <dl>
                    <dt>Owner</dt>
                    <dd>{m.data.checkOwner}</dd>
                    <dt>Template</dt>
                    <dd>{requirement.version} · latest published demo</dd>
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
                    onClick={() => onStart(requirement.id)}
                >
                    Start a retest
                </Button>
                <p className="studio-footnote">
                    Original answers and files stay with the submitted version.
                    A pass does not release a restriction.
                </p>
            </aside>
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
                    {!templates && (
                        <CollectionToggle label="Recent checks" {...layout} />
                    )}
                    <Button
                        disabled={!m.canRequest}
                        onClick={() => onStart(requirement.id)}
                    >
                        <ClipboardCheck size={16} />
                        Start check
                    </Button>
                </SectionHeading>
                {templates ? (
                    <ChecklistLibrary
                        canManage={m.canManage}
                        canRun={m.canRequest}
                        onRun={onStart}
                        onView={(t) =>
                            m.detail(t.name, [
                                ['Version', t.version],
                                ['Use', t.use],
                                ['Assignment', t.scope],
                                [
                                    'Evidence',
                                    t.evidence ? 'Required' : 'Optional',
                                ],
                                ...t.questions.map(
                                    (q, i) =>
                                        [
                                            String(i + 1) + '. ' + q.label,
                                            q.kind +
                                                ' · ' +
                                                (q.required
                                                    ? 'Required'
                                                    : 'Optional'),
                                        ] as [string, string],
                                ),
                            ])
                        }
                    />
                ) : (
                    <RecordCollection
                        label="Recent checks"
                        view={layout.view}
                        columns={[
                            { label: 'Observed / version', width: '1.1fr' },
                            { label: 'Result', width: '.7fr' },
                            { label: 'Evidence & follow-up', width: '1.6fr' },
                        ]}
                        records={runs.map((r) => ({
                            id: r.id,
                            name: r.template,
                            subline: r.id,
                            icon: ClipboardCheck,
                            alert:
                                r.outcome === 'Failed'
                                    ? 'critical'
                                    : r.outcome === 'Passed'
                                      ? 'success'
                                      : 'warning',
                            fields: [
                                <>
                                    <strong>{dateLabel(r.observed)}</strong>
                                    <small>Template {r.version}</small>
                                </>,
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
                                </Badge>,
                                <>
                                    <small>
                                        {(r.attachments?.length ?? 0) +
                                            m.data.documents.filter(
                                                (x) => x.owner === r.id,
                                            ).length}{' '}
                                        evidence files
                                    </small>
                                    <div className="collection-actions">
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
                                            onClick={() => m.amendment(r.id)}
                                        >
                                            Add amendment
                                        </Button>
                                    </div>
                                </>,
                            ],
                            open: () => onView(r),
                            footer: {
                                primary: r.id,
                                secondary: 'Original observations retained',
                            },
                            actions: [
                                {
                                    label: 'View check',
                                    icon: ClipboardCheck,
                                    onClick: () => onView(r),
                                },
                                ...(m.canRequest
                                    ? [
                                          {
                                              label: 'Upload evidence',
                                              icon: Upload,
                                              onClick: () =>
                                                  m.setUploadFor(r.id),
                                          },
                                      ]
                                    : []),
                                ...(m.canManage
                                    ? [
                                          {
                                              label: 'Add amendment',
                                              icon: FileText,
                                              onClick: () => m.amendment(r.id),
                                          },
                                      ]
                                    : []),
                            ],
                        }))}
                        empty={
                            <>
                                <h3>No checks submitted yet</h3>
                                <p>
                                    Start a check and attach the original
                                    observations.
                                </p>
                            </>
                        }
                    />
                )}
            </section>
        </div>
    );
}
