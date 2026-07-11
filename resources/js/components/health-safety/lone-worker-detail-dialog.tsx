/* Lone Worker detail modal — param-driven (?session= / ?alert=) like IncidentDetailDialog.
 * Session variant: WizardShell section-rail (Overview / Check-ins / Alerts) + footer Options
 * bar; lifecycle actions take over the body via LoneWorkerActionForm. Alert variant: a thin
 * Control-Room-forward modal (canonical triage stays in the Control Room). */
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { ReviewCard, ReviewRow, WizardShell, type WizardStep } from '@/components/wizard/shell';
import { InfoCard } from '@/components/wizard/primitives';
import {
    FlagBadge,
    initials,
    TONE_BG,
    TONE_DOT,
} from '@/pages/health-safety/components/register-row-kit';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Bell,
    Check,
    CheckCircle2,
    ChevronRight,
    ClipboardCheck,
    Clock,
    FileText,
    ListChecks,
    MapPin,
    Navigation,
    Radio,
    RadioTower,
    Trash2,
    User,
    XCircle,
} from 'lucide-react';
import { type ReactNode, useState } from 'react';
import { LoneWorkerActionForm } from './lone-worker-action-form';
import {
    type ActionTarget,
    ALERT_STATUS_META,
    ALERT_TYPE_META,
    type AlertDetail,
    type Can,
    type Detail,
    overdueByMinutes,
    SESSION_LABEL,
    SESSION_TONE,
    type SessionDetail,
} from './lone-worker-types';
import { Button as GuardrailButton } from '@/components/ui/button';

const ALERT_TYPE_ICON: Record<string, typeof AlertTriangle> = {
    emergency: AlertTriangle,
    overdue_check_in: Clock,
    no_response: Bell,
};

export function LoneWorkerDetailDialog({
    detail,
    open,
    onClose,
    can,
    onOpenSession,
    onOpenAlert,
}: {
    detail: Detail;
    open: boolean;
    onClose: () => void;
    can: Can;
    onOpenSession: (id: number) => void;
    onOpenAlert: (id: string) => void;
}) {
    if (detail._type === 'session') {
        return <SessionDetailDialog d={detail} open={open} onClose={onClose} can={can} onOpenAlert={onOpenAlert} />;
    }
    return <AlertDetailDialog d={detail} open={open} onClose={onClose} can={can} onOpenSession={onOpenSession} />;
}

/* ───────────────────────────── Session detail ───────────────────────────── */

type SectionKey = 'overview' | 'checkins' | 'alerts';

function SessionDetailDialog({
    d,
    open,
    onClose,
    can,
    onOpenAlert,
}: {
    d: SessionDetail;
    open: boolean;
    onClose: () => void;
    can: Can;
    onOpenAlert: (id: string) => void;
}) {
    const [section, setSection] = useState<SectionKey>('overview');
    const [action, setAction] = useState<ActionTarget | null>(null);

    const tone = SESSION_TONE[d.status] ?? 'neutral';
    const canAct = can.manage && (d.status === 'active' || d.status === 'overdue');

    const SECTIONS: WizardStep[] = [
        { key: 'overview', label: 'Overview', blurb: 'Plan & location', icon: ClipboardCheck },
        { key: 'checkins', label: 'Check-ins', blurb: `${d.check_ins.length} logged`, icon: ListChecks },
        { key: 'alerts', label: 'Alerts', blurb: d.alerts.length ? `${d.alerts.length} raised` : 'None', icon: Bell },
    ];
    const stepIndex = Math.max(0, SECTIONS.findIndex((s) => s.key === section));

    const footerStart = (
        <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold', TONE_BG[tone])}>
            <span className={cn('h-1.5 w-1.5 rounded-full', TONE_DOT[tone])} />
            {SESSION_LABEL[d.status] ?? d.status}
        </span>
    );

    const canRemove = can.manage && d.status === 'completed';
    const footerEnd = action ? null : (
        <div className="flex flex-wrap items-center justify-end gap-2">
            {canAct ? (
                <>
                    <ActBtn icon={CheckCircle2} onClick={() => setAction({ kind: 'checkin', session: d })}>
                        Record check-in
                    </ActBtn>
                    <ActBtn icon={Clock} onClick={() => setAction({ kind: 'extend', session: d })}>
                        Extend / edit
                    </ActBtn>
                    <ActBtn icon={XCircle} onClick={() => setAction({ kind: 'end', session: d })}>
                        End session
                    </ActBtn>
                    <GuardrailButton unstyled
                        type="button"
                        onClick={() => setAction({ kind: 'emergency', session: d })}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-status-critical px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-status-critical/90"
                    >
                        <AlertTriangle className="h-4 w-4" /> Trigger emergency
                    </GuardrailButton>
                </>
            ) : (
                <span className="text-xs text-muted-foreground">No lifecycle actions — session {SESSION_LABEL[d.status]?.toLowerCase() ?? d.status}.</span>
            )}
            {canRemove ? (
                <GuardrailButton unstyled
                    type="button"
                    onClick={() => setAction({ kind: 'delete', session: d })}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-status-critical/40 px-3 py-2 text-sm font-medium text-status-critical transition-colors hover:bg-status-critical/10"
                >
                    <Trash2 className="h-4 w-4" /> Remove session
                </GuardrailButton>
            ) : null}
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`Lone worker session #${d.id}`}
            description={`${d.user?.name ?? 'Worker'} — ${d.site?.name ?? 'no site'}`}
            railIcon={Radio}
            railTitle={d.user?.name ?? 'Lone worker'}
            railSub={`Session #${d.id} · ${d.site?.name ?? 'No site'}`}
            steps={SECTIONS}
            stepIndex={stepIndex}
            onStepClick={(i) => setSection(SECTIONS[i].key as SectionKey)}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {action ? (
                <LoneWorkerActionForm
                    target={action}
                    onDone={() => (action.kind === 'delete' ? onClose() : setAction(null))}
                    onCancel={() => setAction(null)}
                />
            ) : section === 'overview' ? (
                <SessionOverview d={d} />
            ) : section === 'checkins' ? (
                <SessionCheckins d={d} />
            ) : (
                <SessionAlerts d={d} onOpenAlert={onOpenAlert} />
            )}
        </WizardShell>
    );
}

function SessionOverview({ d }: { d: SessionDetail }) {
    const overdue = overdueByMinutes(d);
    const lat = d.location_lat != null ? Number(d.location_lat) : null;
    const lng = d.location_lng != null ? Number(d.location_lng) : null;
    const hasCoords = lat != null && lng != null && !Number.isNaN(lat) && !Number.isNaN(lng);

    return (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <ReviewCard icon={ClipboardCheck} title="Monitoring plan">
                <ReviewRow label="Worker" value={d.user?.name ?? '—'} />
                <ReviewRow label="Site" value={d.site?.name ?? '—'} />
                <ReviewRow label="Client" value={d.client?.name ?? '—'} />
                <ReviewRow label="Linked shift" value={d.shift ? `SH-${d.shift.id}` : 'Ad-hoc (no shift)'} />
                <ReviewRow label="Activity" value={d.activity_description || '—'} />
                <ReviewRow label="Started" value={formatDateTime(d.started_at)} />
                <ReviewRow label="Expected end" value={formatDateTime(d.expected_end_at)} />
                <ReviewRow label="Check-in interval" value={d.check_in_interval_minutes ? `Every ${d.check_in_interval_minutes} min` : '—'} />
                <ReviewRow
                    label="Last check-in"
                    value={
                        <span>
                            {formatDateTime(d.last_check_in_at)}
                            {overdue > 0 ? <span className="ml-1 font-semibold text-status-warning">· overdue by {overdue}m</span> : null}
                        </span>
                    }
                />
            </ReviewCard>

            <div className="flex flex-col gap-3">
                <SectionLabel icon={MapPin}>Last-known location</SectionLabel>
                <div className="overflow-hidden rounded-xl border border-border">
                    <div className="relative flex h-24 items-center justify-center bg-gradient-to-br from-primary/15 via-muted to-accent">
                        <Navigation className="h-7 w-7 -rotate-45 text-primary" />
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5">
                        <div className="min-w-0">
                            <div className="truncate text-sm font-medium text-foreground">{d.location || 'No location recorded'}</div>
                            {hasCoords ? (
                                <div className="text-xs tabular-nums text-muted-foreground">
                                    {lat!.toFixed(4)}, {lng!.toFixed(4)}
                                </div>
                            ) : null}
                        </div>
                        {hasCoords ? (
                            <a
                                href={`https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=17/${lat}/${lng}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-1 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium text-foreground transition-colors hover:bg-muted"
                            >
                                Open map <ChevronRight className="h-3 w-3" />
                            </a>
                        ) : null}
                    </div>
                </div>
                {d.tracker ? (
                    <div className="rounded-xl border border-border p-3">
                        <div className="flex items-center justify-between gap-2">
                            <div className="min-w-0">
                                <div className="flex items-center gap-1.5 text-sm font-medium text-foreground">
                                    <Radio className="h-3.5 w-3.5 text-primary" /> {d.tracker.name || 'GPS tracker'}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {d.tracker.battery_level != null ? `Battery ${d.tracker.battery_level}% · ` : ''}
                                    Last seen {formatRelative(d.tracker.last_seen_at)}
                                </div>
                            </div>
                            <GuardrailButton unstyled
                                type="button"
                                onClick={() => router.post(d.tracker!.locate_url, {}, { preserveScroll: true })}
                                className="inline-flex shrink-0 items-center gap-1 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium text-foreground transition-colors hover:bg-muted"
                            >
                                <Navigation className="h-3 w-3" /> Locate now
                            </GuardrailButton>
                        </div>
                        {d.tracker.panic_active ? (
                            <div className="mt-2 flex items-center justify-between gap-2 rounded-lg bg-status-critical-bg px-3 py-2">
                                <span className="flex items-center gap-1.5 text-xs font-semibold text-status-critical">
                                    <AlertTriangle className="h-3.5 w-3.5" /> Panic / man-down active
                                </span>
                                <GuardrailButton unstyled
                                    type="button"
                                    onClick={() => router.post(d.tracker!.acknowledge_panic_url, {}, { preserveScroll: true })}
                                    className="rounded-lg bg-status-critical px-2.5 py-1 text-xs font-semibold text-white transition-colors hover:bg-status-critical/90"
                                >
                                    Acknowledge
                                </GuardrailButton>
                            </div>
                        ) : null}
                    </div>
                ) : null}
                {d.status === 'emergency' && d.emergency_notes ? (
                    <InfoCard icon={AlertTriangle} tone="crit">
                        {d.emergency_notes}
                    </InfoCard>
                ) : null}
            </div>
        </div>
    );
}

function SessionCheckins({ d }: { d: SessionDetail }) {
    const events: { kind: string; label: string; at: string | null; note?: string | null }[] = [
        { kind: 'start', label: 'Session started', at: d.started_at, note: d.activity_description },
        ...d.check_ins.map((c) => ({
            kind: c.status,
            label:
                c.status === 'emergency'
                    ? 'Emergency check-in'
                    : c.status === 'concern'
                      ? 'Checked in · Concern'
                      : 'Checked in · OK',
            at: c.checked_in_at,
            note: c.notes,
        })),
        ...(d.status === 'completed' && d.ended_at ? [{ kind: 'end', label: 'Session ended', at: d.ended_at }] : []),
    ].sort((a, b) => new Date(a.at ?? 0).getTime() - new Date(b.at ?? 0).getTime());

    const kindStyle = (kind: string): { icon: typeof Check; dot: string; fg: string } => {
        switch (kind) {
            case 'ok':
                return { icon: Check, dot: 'bg-status-success', fg: 'text-status-success' };
            case 'concern':
                return { icon: AlertTriangle, dot: 'bg-status-warning', fg: 'text-status-warning' };
            case 'emergency':
                return { icon: AlertTriangle, dot: 'bg-status-critical', fg: 'text-status-critical' };
            case 'start':
                return { icon: Radio, dot: 'bg-primary', fg: 'text-primary' };
            default:
                return { icon: XCircle, dot: 'bg-muted-foreground', fg: 'text-muted-foreground' };
        }
    };

    return (
        <div className="flex flex-col gap-3">
            <SectionLabel icon={ListChecks}>Check-in timeline</SectionLabel>
            {events.length === 0 ? (
                <p className="text-sm text-muted-foreground">No check-ins recorded yet.</p>
            ) : (
                <ol className="relative ml-1 flex flex-col gap-4 border-l border-border pl-5">
                    {events.map((ev, i) => {
                        const s = kindStyle(ev.kind);
                        return (
                            <li key={i} className="relative">
                                <span className={cn('absolute -left-[26px] flex h-4 w-4 items-center justify-center rounded-full ring-4 ring-background', s.dot)}>
                                    <s.icon className="h-2.5 w-2.5 text-white" />
                                </span>
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className={cn('text-sm font-medium', s.fg)}>{ev.label}</span>
                                    <span className="text-xs text-muted-foreground">{formatDateTime(ev.at)}</span>
                                </div>
                                {ev.note ? <p className="mt-0.5 text-xs text-muted-foreground">{ev.note}</p> : null}
                            </li>
                        );
                    })}
                </ol>
            )}
        </div>
    );
}

function SessionAlerts({ d, onOpenAlert }: { d: SessionDetail; onOpenAlert: (id: string) => void }) {
    return (
        <div className="flex flex-col gap-3">
            <SectionLabel icon={Bell}>Alert history</SectionLabel>
            {d.alerts.length === 0 ? (
                <p className="text-sm text-muted-foreground">No alerts have been raised for this session.</p>
            ) : (
                <div className="flex flex-col gap-2">
                    {d.alerts.map((a) => {
                        const meta = ALERT_TYPE_META[a.type] ?? { tone: 'neutral' as const, label: a.type };
                        const Icon = ALERT_TYPE_ICON[a.type] ?? Bell;
                        const status = ALERT_STATUS_META[a.status] ?? { tone: 'neutral' as const, label: a.status };
                        return (
                            <GuardrailButton unstyled
                                key={a.id}
                                type="button"
                                onClick={() => onOpenAlert(a.id)}
                                className="flex items-center gap-3 rounded-xl border border-border px-3 py-2.5 text-left transition-colors hover:bg-muted/50"
                            >
                                <span className={cn('flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', TONE_BG[meta.tone])}>
                                    <Icon className="h-4 w-4" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block text-sm font-medium text-foreground">{meta.label}</span>
                                    <span className="block text-xs text-muted-foreground">
                                        {formatDateTime(a.triggered_at)} · {status.label}
                                    </span>
                                </span>
                                <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                            </GuardrailButton>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

/* ───────────────────────────── Alert detail ───────────────────────────── */

function AlertDetailDialog({
    d,
    open,
    onClose,
    can,
    onOpenSession,
}: {
    d: AlertDetail;
    open: boolean;
    onClose: () => void;
    can: Can;
    onOpenSession: (id: number) => void;
}) {
    const [action, setAction] = useState<ActionTarget | null>(null);

    const meta = ALERT_TYPE_META[d.type] ?? { tone: 'neutral' as const, label: d.type };
    const Icon = ALERT_TYPE_ICON[d.type] ?? Bell;
    const status = ALERT_STATUS_META[d.status] ?? { tone: 'neutral' as const, label: d.status };
    const isLegacy = d.source === 'legacy';
    const sessionId = d.session?.id ?? null;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="overflow-hidden p-0 [&>button]:hidden"
                style={{ maxWidth: 'min(94vw, 460px)', width: 'min(94vw, 460px)' }}
            >
                <DialogTitle className="sr-only">{meta.label} alert</DialogTitle>
                <DialogDescription className="sr-only">Lone worker alert detail</DialogDescription>

                <div className="flex items-center gap-3 border-b border-border px-5 py-3.5">
                    <span className={cn('flex h-9 w-9 items-center justify-center rounded-lg', TONE_BG[meta.tone])}>
                        <Icon className="h-4.5 w-4.5" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h2 className="truncate text-sm font-semibold text-foreground">{meta.label}</h2>
                        <p className="truncate text-xs text-muted-foreground">
                            {d.session?.user?.name ?? 'Lone worker'} · {d.session?.site?.name ?? 'No site'}
                        </p>
                    </div>
                    <GuardrailButton unstyled type="button" onClick={onClose} className="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground" aria-label="Close">
                        <XCircle className="h-4 w-4" />
                    </GuardrailButton>
                </div>

                <div className="px-5 py-4">
                    {action ? (
                        <LoneWorkerActionForm target={action} onDone={() => setAction(null)} onCancel={() => setAction(null)} />
                    ) : (
                        <div className="flex flex-col gap-4">
                            <div className="rounded-xl border border-border">
                                <SummaryRow label="Worker" value={d.session?.user?.name ?? '—'} />
                                <SummaryRow label="Site / Client" value={`${d.session?.site?.name ?? '—'}${d.session?.client?.name ? ` · ${d.session.client.name}` : ''}`} />
                                <SummaryRow label="Triggered" value={formatDateTime(d.triggered_at)} />
                                <SummaryRow
                                    label="Status"
                                    value={
                                        <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium', TONE_BG[status.tone])}>
                                            <span className={cn('h-1.5 w-1.5 rounded-full', TONE_DOT[status.tone])} />
                                            {status.label}
                                        </span>
                                    }
                                />
                                <SummaryRow
                                    label="Source"
                                    value={
                                        <FlagBadge icon={isLegacy ? FileText : RadioTower} tone={isLegacy ? 'neutral' : 'info'} title={isLegacy ? 'Pre-PR4 compatibility record' : 'Canonical · owned by Control Room'}>
                                            {isLegacy ? 'Legacy' : 'Control Room'}
                                        </FlagBadge>
                                    }
                                    last
                                />
                            </div>

                            {d.incident_id ? (
                                <div className="flex items-center gap-1.5 rounded-lg bg-status-info-bg px-3 py-2 text-xs font-medium text-status-info">
                                    <FileText className="h-3.5 w-3.5" /> Escalated to incident INC-{d.incident_id}
                                </div>
                            ) : null}

                            <InfoCard icon={RadioTower} tone="info">
                                SLA, escalation and playbooks for this alert live in the <strong>Control Room</strong>. Acknowledge / resolve here are convenience actions only.
                            </InfoCard>

                            {d.cr_id && d.can_view_control_room ? (
                                <Link
                                    href={`/control-room/alerts/${d.cr_id}`}
                                    className="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-primary px-3.5 py-2.5 text-sm font-semibold text-primary-foreground transition-colors hover:bg-primary/90"
                                >
                                    <RadioTower className="h-4 w-4" /> Open in Control Room
                                </Link>
                            ) : null}

                            {isLegacy && can.manage ? (
                                <div className="flex gap-2">
                                    {d.status === 'active' ? (
                                        <GuardrailButton unstyled
                                            type="button"
                                            onClick={() => setAction({ kind: 'acknowledge', alert: d })}
                                            className="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
                                        >
                                            <Bell className="h-4 w-4" /> Acknowledge
                                        </GuardrailButton>
                                    ) : null}
                                    {d.status !== 'resolved' ? (
                                        <GuardrailButton unstyled
                                            type="button"
                                            onClick={() => setAction({ kind: 'resolve', alert: d })}
                                            className="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-status-critical px-3 py-2 text-sm font-semibold text-white hover:bg-status-critical/90"
                                        >
                                            <Check className="h-4 w-4" /> Resolve
                                        </GuardrailButton>
                                    ) : null}
                                </div>
                            ) : null}

                            {sessionId ? (
                                <GuardrailButton unstyled
                                    type="button"
                                    onClick={() => onOpenSession(sessionId)}
                                    className="inline-flex items-center gap-1 self-start text-sm font-medium text-primary hover:underline"
                                >
                                    <Activity className="h-4 w-4" /> View linked session <ChevronRight className="h-3 w-3" />
                                </GuardrailButton>
                            ) : null}
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

/* ── shared bits ────────────────────────────────────────────────────── */

function SectionLabel({ icon: Icon, children }: { icon: typeof MapPin; children: ReactNode }) {
    return (
        <div className="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-primary uppercase">
            <Icon className="h-3.5 w-3.5" />
            {children}
        </div>
    );
}

function ActBtn({ icon: Icon, onClick, children }: { icon: typeof Clock; onClick: () => void; children: ReactNode }) {
    return (
        <GuardrailButton unstyled
            type="button"
            onClick={onClick}
            className="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm font-medium text-foreground transition-colors hover:bg-muted"
        >
            <Icon className="h-4 w-4" />
            {children}
        </GuardrailButton>
    );
}

function SummaryRow({ label, value, last }: { label: string; value: ReactNode; last?: boolean }) {
    return (
        <div className={cn('flex items-center justify-between gap-3 px-3 py-2', !last && 'border-b border-border')}>
            <span className="text-xs text-muted-foreground">{label}</span>
            <span className="text-right text-sm text-foreground">{value}</span>
        </div>
    );
}
