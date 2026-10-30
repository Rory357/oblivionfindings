import { localDateTimeLabel } from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    ArrowLeft,
    ArrowRight,
    CalendarDays,
    KeyRound,
    MoreHorizontal,
    ShieldCheck,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';
import { EvidenceShelf } from './evidence-field';
import { type VehicleModel, dateLabel } from './operations';
import { Badge, Row } from './ui';
export function RecordStudio({
    model: m,
    id,
    onBack,
    onCheck,
}: {
    model: VehicleModel;
    id: string;
    onBack: () => void;
    onCheck: (id: string) => void;
}) {
    const [tab, setTab] = useState('overview');
    const w = m.data.works.find((x) => x.id === id);
    if (!w) return <div className="studio-empty">Work record unavailable.</div>;
    const closed = [
        'Completed',
        'Cancelled',
        'Completed · awaiting release',
    ].includes(w.status);
    const source = () =>
        w.source.startsWith('CHK')
            ? onCheck(w.source)
            : m.detail('Original source', [
                  ['Reference', w.source],
                  ['Vehicle', 'VH-014'],
                  [
                      'Current evidence',
                      m.data.compliance.find((c) => c.id === w.source)
                          ?.evidence ||
                          w.evidence ||
                          'Service schedule',
                  ],
                  ['Recorded notes', w.notes || 'No additional notes'],
              ]);
    const primary = () =>
        closed
            ? m.release()
            : w.status === 'Awaiting assessment'
              ? m.assess(w.id)
              : !w.start
                ? m.plan(w.id)
                : m.complete(w.id);
    return (
        <div className="content">
            <div className="work-next-action">
                <span className="feature-icon">
                    <Wrench size={23} />
                </span>
                <div>
                    <strong>
                        {w.title} · {w.id}
                    </strong>
                    <small>
                        Next:{' '}
                        {closed
                            ? m.hold
                                ? 'authorised release review'
                                : 'record retained'
                            : w.status === 'Awaiting assessment'
                              ? 'assess the concern'
                              : !w.start
                                ? 'plan the appointment'
                                : 'record work outcome'}{' '}
                        · {w.owner}
                    </small>
                </div>
                <Badge tone={w.status === 'Cancelled' ? 'neutral' : 'info'}>
                    {w.status}
                </Badge>
                <Button
                    disabled={
                        !m.canManage ||
                        w.status === 'Cancelled' ||
                        (closed && !m.hold)
                    }
                    onClick={primary}
                >
                    {closed
                        ? 'Review release'
                        : w.status === 'Awaiting assessment'
                          ? 'Assess work'
                          : !w.start
                            ? 'Plan appointment'
                            : 'Record outcome'}
                </Button>
            </div>
            <div className="studio-work-layout">
                <section className="studio-card">
                    <div
                        className="record-tabs"
                        role="tablist"
                        aria-label="Work record sections"
                    >
                        {['overview', 'appointment', 'evidence', 'costs'].map(
                            (t) => (
                                <button
                                    role="tab"
                                    aria-selected={tab === t}
                                    key={t}
                                    onClick={() => setTab(t)}
                                >
                                    {t[0].toUpperCase() + t.slice(1)}
                                </button>
                            ),
                        )}
                    </div>
                    {tab === 'overview' ? (
                        <>
                            <div className="studio-section-heading">
                                <h2 className="text-section-title">
                                    Work summary
                                </h2>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!m.canManage}
                                    onClick={() => m.assess(w.id)}
                                >
                                    Update assessment
                                </Button>
                            </div>
                            <dl className="facts-grid">
                                <div>
                                    <dt>Original source</dt>
                                    <dd>
                                        <button
                                            className="inline-evidence"
                                            onClick={source}
                                        >
                                            {w.source} <ArrowRight size={13} />
                                        </button>
                                    </dd>
                                </div>
                                <div>
                                    <dt>Owner</dt>
                                    <dd>{w.owner}</dd>
                                </div>
                                <div>
                                    <dt>Target date</dt>
                                    <dd>{dateLabel(w.target)}</dd>
                                </div>
                                <div>
                                    <dt>Outcome</dt>
                                    <dd>{w.outcome || 'Not recorded'}</dd>
                                </div>
                                <div>
                                    <dt>Completed</dt>
                                    <dd>{dateLabel(w.completed)}</dd>
                                </div>
                                <div>
                                    <dt>Completion odometer</dt>
                                    <dd>
                                        {w.odo
                                            ? w.odo.toLocaleString() + ' km'
                                            : 'Not recorded'}
                                    </dd>
                                </div>
                            </dl>
                            {m.data.compliance.some(
                                (c) => c.id === w.source,
                            ) && (
                                <Button
                                    variant="outline"
                                    className="mt-4"
                                    disabled={!m.canManage}
                                    onClick={() => m.compliance(w.source)}
                                >
                                    Update source evidence
                                </Button>
                            )}
                            <details className="work-fold" open>
                                <summary>Assessment & completion notes</summary>
                                <p>
                                    {w.notes ||
                                        'No assessment notes recorded yet.'}
                                </p>
                            </details>
                            <details className="work-fold">
                                <summary>Reporter feedback</summary>
                                <p>
                                    {w.feedback ||
                                        'No feedback recorded. Completion asks for the outcome and next action.'}
                                </p>
                            </details>
                        </>
                    ) : tab === 'appointment' ? (
                        <>
                            <div className="studio-section-heading">
                                <h2 className="text-section-title">
                                    Provider appointment
                                </h2>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={!m.canManage}
                                    onClick={() => m.plan(w.id)}
                                >
                                    Manage appointment
                                </Button>
                            </div>
                            <dl className="facts-grid">
                                {[
                                    ['Provider', w.provider || 'Not selected'],
                                    [
                                        'Start',
                                        w.start
                                            ? localDateTimeLabel(w.start)
                                            : 'Not planned',
                                    ],
                                    [
                                        'End',
                                        w.end
                                            ? localDateTimeLabel(w.end)
                                            : 'Not planned',
                                    ],
                                    [
                                        'Confirmation',
                                        w.confirmed || 'Internal plan only',
                                    ],
                                    [
                                        'Availability',
                                        w.unavailable
                                            ? 'Vehicle unavailable during appointment'
                                            : 'No appointment block',
                                    ],
                                    [
                                        'Estimated maintenance',
                                        w.estimateStart
                                            ? dateLabel(w.estimateStart) +
                                              ' – ' +
                                              dateLabel(w.estimateEnd || '')
                                            : 'No estimate recorded',
                                    ],
                                ].map(([label, value]) => (
                                    <div key={label}>
                                        <dt>{label}</dt>
                                        <dd>{value}</dd>
                                    </div>
                                ))}
                            </dl>
                            <p className="studio-footnote">
                                Provider confirmation, appointment dates and
                                advisory estimates retain separate meanings.
                            </p>
                        </>
                    ) : tab === 'evidence' ? (
                        <>
                            <EvidenceShelf
                                title="Work evidence"
                                files={m.data.documents.filter(
                                    (f) => f.owner === w.id,
                                )}
                                onAdd={() => m.setUploadFor(w.id)}
                                disabled={!m.canManage}
                            />
                            <Row
                                title="Completion evidence reference"
                                value={w.evidence || 'Not recorded'}
                            />
                            <Button
                                className="mt-4"
                                variant="outline"
                                onClick={source}
                            >
                                Open original source
                            </Button>
                        </>
                    ) : (
                        <>
                            <h2 className="text-section-title">
                                Costs & Finance context
                            </h2>
                            <dl className="facts-grid">
                                {[
                                    [
                                        'Quote / estimate',
                                        w.quote || 'Not recorded',
                                    ],
                                    [
                                        'Approval reference',
                                        w.approval || 'Not recorded',
                                    ],
                                    ['Parts', w.parts || 'Not recorded'],
                                    ['Labour', w.labour || 'Not recorded'],
                                ].map(([label, value]) => (
                                    <div key={label}>
                                        <dt>{label}</dt>
                                        <dd>{value}</dd>
                                    </div>
                                ))}
                            </dl>
                            <Button
                                variant="outline"
                                className="mt-4"
                                disabled={!m.canManage}
                                onClick={() => m.assess(w.id)}
                            >
                                Update recorded costs
                            </Button>
                            <p className="studio-footnote">
                                Finance owns spend and invoice approval. These
                                references do not approve payment.
                            </p>
                        </>
                    )}
                    <details className="work-fold">
                        <summary>Activity · {w.history.length} entries</summary>
                        {w.history.map((h, i) => (
                            <p key={i}>{h}</p>
                        ))}
                    </details>
                </section>
                <aside className="studio-card">
                    <span className="studio-eyebrow">VEHICLE CONTROL</span>
                    <h2 className="text-section-title">
                        {m.hold ? 'Restriction active' : 'Release & custody'}
                    </h2>
                    <div className="status-medallion restricted my-5">
                        <ShieldCheck size={28} />
                    </div>
                    <p className="text-caption text-muted-foreground">
                        {m.hold
                            ? 'Work completion keeps the restriction in place until an authorised release is recorded.'
                            : 'Check current readiness and record keys, checkout and return for each booking.'}
                    </p>
                    <Button
                        variant="outline"
                        className="mt-5 w-full"
                        disabled={!m.canManage}
                        onClick={m.release}
                    >
                        Review vehicle release
                    </Button>
                    <Button
                        variant="ghost"
                        className="mt-2 w-full"
                        onClick={onBack}
                    >
                        <ArrowLeft size={15} />
                        Back to vehicle
                    </Button>
                </aside>
            </div>
        </div>
    );
}
export function BookingsStudio({ model: m }: { model: VehicleModel }) {
    return (
        <section className="studio-card">
            <div className="studio-section-heading">
                <div>
                    <span className="studio-eyebrow">BOOKING WORKFLOW</span>
                    <h2 className="text-section-title">Bookings & custody</h2>
                </div>
                <div className="studio-inline">
                    <Button
                        variant="outline"
                        disabled={!m.canManage}
                        onClick={() => m.booking(undefined, undefined, true)}
                    >
                        Add unavailable period
                    </Button>
                    <Button
                        disabled={!m.canRequest}
                        onClick={() => m.booking()}
                    >
                        Request booking
                    </Button>
                </div>
            </div>
            <div className="booking-journey">
                {[
                    'Request & evidence',
                    'Approval route',
                    'Confirmed',
                    'Keys & checkout',
                    'Return & condition',
                ].map((s, i) => (
                    <span key={s}>
                        <strong>{i + 1}</strong>
                        {s}
                        {i < 4 && <ArrowRight size={13} />}
                    </span>
                ))}
            </div>
            {m.data.bookings.map((b) => {
                const active = !['Returned', 'Cancelled'].includes(b.status),
                    mode =
                        b.status === 'Pending approval'
                            ? 'approve'
                            : b.status === 'Confirmed'
                              ? 'out'
                              : 'return';
                return (
                    <div className="booking-card" key={b.id}>
                        <span className="feature-icon">
                            <KeyRound size={22} />
                        </span>
                        <div className="booking-main">
                            <strong>{b.purpose}</strong>
                            <small>
                                {b.id} · {localDateTimeLabel(b.start)} →{' '}
                                {localDateTimeLabel(b.end)}
                            </small>
                            <p>
                                {b.block
                                    ? 'Unavailable period'
                                    : b.driver +
                                      ' · ' +
                                      (b.approvalRoute || 'Approval required')}
                                {b.exemptionReason
                                    ? ' · ' + b.exemptionReason
                                    : ''}
                            </p>
                            {b.outKm > 0 && (
                                <p>
                                    Checkout {b.outKm.toLocaleString()} km
                                    {b.returnKm > 0
                                        ? ' → return ' +
                                          b.returnKm.toLocaleString() +
                                          ' km'
                                        : ''}
                                </p>
                            )}
                        </div>
                        <Badge
                            tone={
                                b.status === 'Confirmed'
                                    ? 'success'
                                    : b.status === 'Pending approval'
                                      ? 'warning'
                                      : 'info'
                            }
                        >
                            {b.status === 'Checked out' &&
                            b.end < '2026-09-22T09:30'
                                ? 'Overdue return'
                                : b.status}
                        </Badge>
                        <div className="booking-actions">
                            {active && !b.block && (
                                <Button
                                    size="sm"
                                    disabled={!m.canManage}
                                    onClick={() =>
                                        m.bookingTransition(b.id, mode)
                                    }
                                >
                                    {mode === 'approve'
                                        ? 'Review & approve'
                                        : mode === 'out'
                                          ? 'Check out'
                                          : 'Record return'}
                                </Button>
                            )}
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={'Actions for ' + b.id}
                                    >
                                        <MoreHorizontal size={18} />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        onSelect={() =>
                                            m.detail('Booking ' + b.id, [
                                                ['Status', b.status],
                                                [
                                                    'Approval route',
                                                    b.approvalRoute ||
                                                        'Approval required',
                                                ],
                                                [
                                                    'Reason / authority',
                                                    b.exemptionReason ||
                                                        'Coordinator review',
                                                ],
                                                [
                                                    'Evidence',
                                                    m.data.documents
                                                        .filter(
                                                            (f) =>
                                                                f.owner ===
                                                                b.id,
                                                        )
                                                        .map((f) => f.name)
                                                        .join(', ') ||
                                                        'No files',
                                                ],
                                                ['Pickup & keys', b.pickup],
                                                [
                                                    'Condition',
                                                    b.condition ||
                                                        'Not recorded',
                                                ],
                                                [
                                                    'History',
                                                    b.history.join('\n'),
                                                ],
                                            ])
                                        }
                                    >
                                        View record & history
                                    </DropdownMenuItem>
                                    {active && (
                                        <>
                                            <DropdownMenuItem
                                                disabled={
                                                    !m.canManage ||
                                                    b.status === 'Checked out'
                                                }
                                                onSelect={() =>
                                                    m.booking(
                                                        b.start,
                                                        b.id,
                                                        b.block,
                                                    )
                                                }
                                            >
                                                Change times
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                disabled={
                                                    !m.canManage ||
                                                    b.status === 'Checked out'
                                                }
                                                onSelect={() =>
                                                    m.bookingTransition(
                                                        b.id,
                                                        'cancel',
                                                    )
                                                }
                                            >
                                                Cancel booking / block
                                            </DropdownMenuItem>
                                        </>
                                    )}
                                    <DropdownMenuItem
                                        disabled={!m.canManage}
                                        onSelect={() => m.setUploadFor(b.id)}
                                    >
                                        Upload evidence
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>
                );
            })}
            {!m.data.bookings.length && (
                <div className="studio-empty">
                    <CalendarDays size={32} />
                    <h3>No booking requests yet</h3>
                    <p>Select a calendar slot or request a booking to begin.</p>
                </div>
            )}
            <p className="studio-footnote">
                Approval not required is an explicit, evidenced decision by an
                authorised coordinator. Restrictions and conflicts always block
                confirmation or checkout. Report-only staff still need
                coordinator verification.
            </p>
        </section>
    );
}
