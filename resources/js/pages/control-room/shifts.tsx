import { ControlRoomPage } from '@/components/control-room/control-room-page';
import {
    ControlRoomRowActions,
    type ControlRoomRowAction,
} from '@/components/control-room/control-room-row-actions';
import {
    AddOperatorNotePopup,
    StartDutyPopup,
} from '@/components/control-room/duty/duty-popups';
import {
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
} from '@/components/page';
import { PopupCallout, PopupFacts } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import {
    formatDateTime,
    formatDurationMinutes,
    formatRelative,
} from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import {
    ArrowRightLeft,
    CalendarDays,
    Clock,
    Copy,
    Eye,
    MessageSquarePlus,
    Pin,
    PlayCircle,
    StickyNote,
} from 'lucide-react';
import { useEffect, useState } from 'react';

// --- Types ---

interface StaffMember {
    id: number;
    name: string;
}

interface ActiveShift {
    id: number;
    name: string;
    starts_at: string;
    ends_at: string | null;
    status: string;
    shift_lead: StaffMember | null;
    team_members: StaffMember[];
    open_alerts_at_start: number;
    alerts_created: number;
    alerts_resolved: number;
    alerts_escalated: number;
    handover_status: 'none' | 'prepared' | 'accepted';
    handover_version: number;
    handover_prepared_at: string | null;
    is_stale?: boolean;
    stale_after_hours?: number;
    incoming_lead: StaffMember | null;
    actions: {
        can_open_handover: boolean;
        can_add_note: boolean;
        can_copy_summary: boolean;
    };
}

interface OperatorNote {
    id: number;
    type: string;
    content: string;
    is_pinned: boolean;
    requires_followup: boolean;
    followup_at: string | null;
    alert_id: number | null;
    alert_reference: string | null;
    user: StaffMember | null;
    created_at: string;
}

interface RecentShift {
    id: number;
    name: string;
    starts_at: string;
    ends_at: string | null;
    status: string;
    shift_lead: StaffMember | null;
    alerts_created: number;
    alerts_resolved: number;
    alerts_escalated: number;
    duration_minutes: number | null;
    actions: {
        can_copy_summary: boolean;
    };
}

interface Props {
    activeShift: ActiveShift | null;
    notes: OperatorNote[];
    recentShifts: RecentShift[];
    openAlertsCount: number;
    criticalAlertsCount: number;
    staff: StaffMember[];
    eligibleLeads: StaffMember[];
    can: {
        manage: boolean;
    };
}

// --- Helpers ---

const NOTE_TYPE: Record<string, { label: string; tone: StatusVariant }> = {
    note: { label: 'Note', tone: 'neutral' },
    action: { label: 'Action', tone: 'info' },
    escalation: { label: 'Escalation', tone: 'warning' },
    decision: { label: 'Decision', tone: 'info' },
    handover: { label: 'Handover', tone: 'success' },
};

const ROSTERING_HREF = '/operations/rostering';

function dutySummary(duty: {
    name: string;
    alerts_created: number;
    alerts_resolved: number;
    alerts_escalated: number;
}) {
    return `${duty.name}: ${duty.alerts_created} created, ${duty.alerts_resolved} resolved, ${duty.alerts_escalated} escalated`;
}

function dutyCounts(duty: {
    alerts_created: number;
    alerts_resolved: number;
    alerts_escalated: number;
}) {
    return `${duty.alerts_created} ${duty.alerts_created === 1 ? 'alert' : 'alerts'} created · ${duty.alerts_resolved} resolved · ${duty.alerts_escalated} escalated`;
}

const copy = (text: string) => void navigator.clipboard?.writeText(text);

// --- Page ---

/**
 * Duty handover › Current duty — who is responsible for the Control Room now,
 * what has happened on this duty and who accepts next. Planned shifts and
 * staffing stay in Workforce › Rostering; this page records the actual duty.
 * Preparing a handover opens its own page and never moves ownership by itself.
 */
export default function ControlRoomShifts({
    activeShift,
    notes,
    recentShifts,
    openAlertsCount,
    criticalAlertsCount,
    staff,
    eligibleLeads,
    can,
}: Props) {
    const [startOpen, setStartOpen] = useState(false);
    const [noteOpen, setNoteOpen] = useState(false);

    useEffect(() => {
        const interval = window.setInterval(() => {
            if (document.hidden) return;
            router.reload({
                // async: a refresh never cancels a note or duty being saved.
                async: true,
                only: [
                    'activeShift',
                    'notes',
                    'recentShifts',
                    'openAlertsCount',
                    'criticalAlertsCount',
                ],
                preserveScroll: true,
            });
        }, 30_000);

        return () => window.clearInterval(interval);
    }, []);

    const duty = activeShift;
    const prepared = duty?.handover_status === 'prepared';
    const handoverHref = duty ? `/control-room/shifts/${duty.id}/handover` : null;
    const canHandover = Boolean(duty?.actions.can_open_handover);
    const canNote = Boolean(duty?.actions.can_add_note && can.manage);
    const stale = Boolean(duty?.is_stale) && !prepared;

    const dutyActions = (): ControlRoomRowAction[] => {
        if (!duty || !handoverHref) return [];
        const items: ControlRoomRowAction[] = [];
        if (canHandover) {
            items.push({
                key: 'handover',
                label: prepared ? 'Review prepared handover' : 'Prepare handover',
                icon: Eye,
                onSelect: () => router.visit(handoverHref),
            });
        }
        if (canNote) {
            items.push({
                key: 'add-note',
                label: 'Add operator note',
                icon: MessageSquarePlus,
                onSelect: () => setNoteOpen(true),
            });
        }
        if (duty.actions.can_copy_summary) {
            items.push({
                key: 'copy-summary',
                label: 'Copy duty summary',
                icon: Copy,
                onSelect: () => copy(dutySummary(duty)),
            });
        }
        return items;
    };

    const chip = !duty ? (
        <PageHeaderStatusChip variant="neutral">No duty running</PageHeaderStatusChip>
    ) : prepared ? (
        <PageHeaderStatusChip variant="warning">Handover prepared</PageHeaderStatusChip>
    ) : (
        <PageHeaderStatusChip variant="success">Duty running</PageHeaderStatusChip>
    );

    return (
        <ControlRoomPage
            headTitle="Current duty"
            header={{
                titleChip: chip,
                subline: 'Who is responsible now, what carries forward and who accepts next',
                actions: (
                    <>
                        <PageHeaderGlassButton icon={CalendarDays} asChild>
                            <Link href={ROSTERING_HREF}>Open Rostering</Link>
                        </PageHeaderGlassButton>
                        {duty && canNote ? (
                            <PageHeaderGlassButton
                                icon={MessageSquarePlus}
                                onClick={() => setNoteOpen(true)}
                            >
                                Add operator note
                            </PageHeaderGlassButton>
                        ) : null}
                        {duty && canHandover && handoverHref ? (
                            <PageHeaderPrimaryButton icon={ArrowRightLeft} asChild>
                                <Link href={handoverHref}>
                                    {prepared ? 'Review handover' : 'Prepare handover'}
                                </Link>
                            </PageHeaderPrimaryButton>
                        ) : !duty && can.manage ? (
                            <PageHeaderPrimaryButton
                                icon={PlayCircle}
                                onClick={() => setStartOpen(true)}
                            >
                                Start duty
                            </PageHeaderPrimaryButton>
                        ) : null}
                    </>
                ),
                meters: (
                    <>
                        <PageHeaderMeterBlock
                            label="Open now"
                            tone={openAlertsCount > 0 ? 'warning' : 'brand'}
                            href="/control-room"
                            ariaLabel="Show the open alerts"
                        >
                            <PageHeaderMeterBig>{openAlertsCount}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>active alerts</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Critical"
                            tone={criticalAlertsCount > 0 ? 'critical' : 'brand'}
                            href="/control-room?severity=critical"
                            ariaLabel="Show critical alerts"
                        >
                            <PageHeaderMeterBig>{criticalAlertsCount}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>act first</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        {duty ? (
                            <PageHeaderMeterBlock
                                label="Handover"
                                tone={prepared || stale ? 'warning' : 'brand'}
                                {...(canHandover && handoverHref
                                    ? { href: handoverHref }
                                    : {
                                          onClick: () =>
                                              document
                                                  .getElementById('current-duty')
                                                  ?.scrollIntoView({ block: 'start' }),
                                      })}
                                ariaLabel={
                                    prepared ? 'Review the prepared handover' : 'Prepare the handover'
                                }
                            >
                                <PageHeaderMeterBig>
                                    {prepared ? 'Prepared' : 'Not prepared'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {prepared
                                        ? `for ${duty.incoming_lead?.name ?? 'the incoming lead'} to accept`
                                        : stale
                                          ? `running over ${duty.stale_after_hours ?? 16} hours`
                                          : 'the outgoing lead prepares it'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        ) : null}
                    </>
                ),
            }}
        >
            {duty ? (
                prepared ? (
                    <PopupCallout tone="warning">
                        <span className="flex flex-wrap items-center justify-between gap-3">
                            <span>
                                <b className="font-semibold">Ownership has not changed yet.</b>{' '}
                                Prepared for {duty.incoming_lead?.name ?? 'the incoming lead'}{' '}
                                <span title={formatDateTime(duty.handover_prepared_at)}>
                                    {formatRelative(duty.handover_prepared_at)}
                                </span>
                                . This duty stays active until they accept.
                            </span>
                            {canHandover && handoverHref ? (
                                <Button asChild size="sm" variant="outline">
                                    <Link href={handoverHref}>Open handover</Link>
                                </Button>
                            ) : null}
                        </span>
                    </PopupCallout>
                ) : (
                    <PopupCallout tone="info">
                        Preparing a handover does not transfer ownership. The outgoing duty
                        stays responsible until the named incoming lead accepts.
                    </PopupCallout>
                )
            ) : null}
            {stale ? (
                <PopupCallout tone="warning">
                    This duty has been running for more than {duty?.stale_after_hours ?? 16}{' '}
                    hours. The lead should prepare a handover; a manager with override access can
                    prepare it with a recorded reason if the lead isn&apos;t available.
                </PopupCallout>
            ) : null}

            <div
                className={
                    recentShifts.length
                        ? 'grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(320px,0.6fr)]'
                        : 'grid min-w-0 items-start gap-5'
                }
            >
                <div className="flex min-w-0 flex-col gap-5">
                    {duty ? (
                        <>
                            <CurrentDutyCard duty={duty} actions={dutyActions()} />
                            <OperatorNotes
                                notes={notes}
                                onAdd={canNote ? () => setNoteOpen(true) : undefined}
                            />
                        </>
                    ) : (
                        <Card className="min-w-0 px-5 py-10">
                            <EmptyState
                                icon={Clock}
                                title="No duty is running"
                                description="Start a duty to record who is responsible for the Control Room now. Planned shifts and staffing stay in Workforce › Rostering."
                                action={
                                    can.manage ? (
                                        <Button type="button" onClick={() => setStartOpen(true)}>
                                            <PlayCircle aria-hidden="true" />
                                            Start duty
                                        </Button>
                                    ) : undefined
                                }
                                secondaryAction={
                                    <Button asChild variant="outline">
                                        <Link href={ROSTERING_HREF}>Open Rostering</Link>
                                    </Button>
                                }
                            />
                        </Card>
                    )}
                </div>
                <PastDuties duties={recentShifts} />
            </div>

            {can.manage && !duty ? (
                <StartDutyPopup
                    open={startOpen}
                    onClose={() => setStartOpen(false)}
                    eligibleLeads={eligibleLeads}
                    staff={staff}
                />
            ) : null}
            {duty && canNote ? (
                <AddOperatorNotePopup
                    open={noteOpen}
                    onClose={() => setNoteOpen(false)}
                    duty={duty}
                />
            ) : null}
        </ControlRoomPage>
    );
}

// --- Current duty ---

function CurrentDutyCard({
    duty,
    actions,
}: {
    duty: ActiveShift;
    actions: ControlRoomRowAction[];
}) {
    const prepared = duty.handover_status === 'prepared';
    return (
        <ControlRoomRowActions label={`Actions for ${duty.name}`} items={actions}>
            {({ rowProps, overflowButton }) => (
                <Card
                    {...rowProps}
                    id="current-duty"
                    aria-labelledby="current-duty-title"
                    className="min-w-0 scroll-mt-5 gap-4 px-5 py-5 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <h2
                                id="current-duty-title"
                                className="text-section-title flex flex-wrap items-center gap-2 break-words"
                            >
                                <span className="min-w-0 break-words">{duty.name}</span>
                                <StatusBadge variant={prepared ? 'warning' : 'success'} size="sm">
                                    {prepared ? 'Handover prepared' : 'Running'}
                                </StatusBadge>
                            </h2>
                            <p className="text-subtle mt-0.5" title={formatDateTime(duty.starts_at)}>
                                Started {formatRelative(duty.starts_at)}
                            </p>
                        </div>
                        {overflowButton}
                    </div>
                    {/* Fields with no value are left out, not shown as "not recorded". */}
                    <PopupFacts
                        rows={[
                            ...(duty.shift_lead ? [['Lead', duty.shift_lead.name] as const] : []),
                            ...(duty.team_members.length
                                ? [
                                      [
                                          'Team',
                                          duty.team_members.map((member) => member.name).join(' · '),
                                      ] as const,
                                  ]
                                : []),
                            ['Started', formatDateTime(duty.starts_at)] as const,
                            [
                                'Open when it started',
                                `${duty.open_alerts_at_start} active ${duty.open_alerts_at_start === 1 ? 'alert' : 'alerts'}`,
                            ] as const,
                            ['This duty', dutyCounts(duty)] as const,
                        ]}
                    />
                </Card>
            )}
        </ControlRoomRowActions>
    );
}

// --- Operator notes ---

function OperatorNotes({
    notes,
    onAdd,
}: {
    notes: OperatorNote[];
    onAdd?: () => void;
}) {
    return (
        <Card className="min-w-0 gap-0 overflow-hidden py-0">
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                <div className="min-w-0">
                    <h2 className="text-section-title">Operator notes</h2>
                    <p className="text-subtle mt-0.5">
                        Observations, actions and decisions on this duty. Pinned notes and
                        follow-ups go into the handover.
                    </p>
                </div>
                {onAdd && notes.length > 0 ? (
                    <Button type="button" size="sm" variant="outline" onClick={onAdd}>
                        <MessageSquarePlus aria-hidden="true" />
                        Add note
                    </Button>
                ) : null}
            </div>
            {notes.length === 0 ? (
                <div className="px-5 py-8">
                    <EmptyState
                        variant="compact"
                        icon={StickyNote}
                        title="No operator notes for this duty yet"
                        action={
                            onAdd ? (
                                <Button type="button" size="sm" onClick={onAdd}>
                                    <MessageSquarePlus aria-hidden="true" />
                                    Add operator note
                                </Button>
                            ) : undefined
                        }
                    />
                </div>
            ) : (
                <ol className="divide-y divide-border">
                    {notes.map((note) => {
                        const type = NOTE_TYPE[note.type] ?? {
                            label: note.type,
                            tone: 'neutral' as const,
                        };
                        return (
                            <li
                                key={note.id}
                                className={
                                    note.is_pinned
                                        ? 'bg-status-warning-bg/40 px-5 py-3.5'
                                        : 'px-5 py-3.5'
                                }
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <span className="flex flex-wrap items-center gap-1.5">
                                        <StatusBadge variant={type.tone} size="sm">
                                            {type.label}
                                        </StatusBadge>
                                        {note.is_pinned ? (
                                            <StatusBadge variant="warning" size="sm">
                                                <Pin aria-hidden="true" className="size-3" />
                                                Pinned
                                            </StatusBadge>
                                        ) : null}
                                        {note.requires_followup ? (
                                            <StatusBadge
                                                variant="critical"
                                                size="sm"
                                                title={
                                                    note.followup_at
                                                        ? formatDateTime(note.followup_at)
                                                        : undefined
                                                }
                                            >
                                                Follow-up required
                                                {note.followup_at
                                                    ? ` · ${formatRelative(note.followup_at)}`
                                                    : ''}
                                            </StatusBadge>
                                        ) : null}
                                    </span>
                                    <span
                                        className="text-caption shrink-0 tabular-nums"
                                        title={formatDateTime(note.created_at)}
                                    >
                                        {formatRelative(note.created_at)}
                                    </span>
                                </div>
                                <p className="mt-1.5 text-sm whitespace-pre-wrap">{note.content}</p>
                                <p className="text-caption mt-1">
                                    {note.user?.name}
                                    {note.alert_id ? (
                                        <>
                                            {note.user ? ' · ' : null}
                                            <Link
                                                href={`/control-room/alerts/${note.alert_id}`}
                                                className="font-medium text-primary hover:underline"
                                            >
                                                {note.alert_reference ?? `Alert ${note.alert_id}`}
                                            </Link>
                                        </>
                                    ) : null}
                                </p>
                            </li>
                        );
                    })}
                </ol>
            )}
        </Card>
    );
}

// --- Past duties ---

function PastDuties({ duties }: { duties: RecentShift[] }) {
    // No empty side panel: the card shows only once a duty has finished.
    if (duties.length === 0) return null;
    return (
        <Card className="min-w-0 gap-0 overflow-hidden py-0" aria-labelledby="past-duties-heading">
            <div className="border-b border-border px-5 py-4">
                <h2 id="past-duties-heading" className="text-section-title">
                    Past duties
                </h2>
                <p className="text-subtle mt-0.5">The last ten finished duties.</p>
            </div>
            <ol className="divide-y divide-border">
                    {duties.map((duty) => {
                        const actions: ControlRoomRowAction[] = duty.actions.can_copy_summary
                            ? [
                                  {
                                      key: 'copy-summary',
                                      label: 'Copy duty summary',
                                      icon: Copy,
                                      onSelect: () => copy(dutySummary(duty)),
                                  },
                              ]
                            : [];
                        return (
                            <ControlRoomRowActions
                                key={duty.id}
                                label={`Actions for ${duty.name}`}
                                items={actions}
                            >
                                {({ rowProps, overflowButton }) => (
                                    <li
                                        {...rowProps}
                                        className="flex items-start gap-3 px-5 py-3 outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                                    >
                                        <Clock
                                            aria-hidden="true"
                                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                        />
                                        <div className="min-w-0 flex-1">
                                            <p className="font-semibold break-words">{duty.name}</p>
                                            <p className="text-caption">
                                                {[
                                                    duty.shift_lead
                                                        ? `Lead ${duty.shift_lead.name}`
                                                        : null,
                                                    formatDateTime(duty.starts_at),
                                                    duty.duration_minutes === null
                                                        ? null
                                                        : formatDurationMinutes(duty.duration_minutes),
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                            <p className="text-caption">{dutyCounts(duty)}</p>
                                        </div>
                                        {overflowButton}
                                    </li>
                                )}
                            </ControlRoomRowActions>
                        );
                    })}
            </ol>
        </Card>
    );
}
