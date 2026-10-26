/**
 * Worker participation views (approved H&S drawings pg-hsn-104, 104b, 104c):
 * representatives as people cards, committee meetings as two logs (coming up,
 * and held with their loose ends), consultations as a table with where each
 * is up to. Every record keeps its kebab and right-click menu from the page's
 * one actionsFor list (eligibility stays there), and opens its detail dialog.
 * Site ownership that isn't confirmed is always said, never hidden.
 */
import {
    RecordIdentity,
    RecordTable,
    type RecordTableColumn,
} from '@/components/incidents/record-table';
import {
    Log,
    PeopleCards,
    QuietLine,
    SideBySide,
    StageDots,
    type LogItem,
} from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    CONSULT_ORDER,
    CONSULT_STATUS,
    consultationTypeLabel,
    REP_STATUS,
    type ConsultationRow,
    type MeetingRow,
    type RepRow,
} from '@/components/worker-participation/shared';
import { formatDate, formatDateOnly, formatTime } from '@/lib/datetime';
import { titleCase } from '@/pages/health-safety/components/register-row-kit';
import { MessageSquare } from 'lucide-react';
import type { MouseEvent as ReactMouseEvent, ReactNode } from 'react';
import { participationMenu, type ParticipationActions } from './participation-cards';

type OnContext<T> = (event: ReactMouseEvent, kind: string, row: T, tag: string, meta: string) => void;

const SITE_UNCONFIRMED = 'Site ownership not confirmed';

function plural(n: number, one: string, many = `${one}s`): string {
    return `${n} ${n === 1 ? one : many}`;
}

/* ------------------------------------------------------------------ */
/*  Representatives                                                    */
/* ------------------------------------------------------------------ */

export function RepresentativeCards({
    rows,
    actionsFor,
    onOpen,
    onContext,
    footer,
}: {
    rows: RepRow[];
    actionsFor: ParticipationActions;
    onOpen: (id: number) => void;
    onContext: OnContext<RepRow>;
    footer?: ReactNode;
}) {
    if (!rows.length) {
        return <QuietLine title="Health and safety representatives" text="No representatives match this view and these filters." />;
    }
    return (
        <div className="grid gap-3">
            <PeopleCards
                title="Health and safety representatives"
                sub="Elected and appointed representatives"
                cols={3}
                items={rows.map((r) => {
                    const name = r.user?.name ?? 'Representative not recorded';
                    const recorded = Number.isFinite(r.training_days_completed);
                    const below = recorded && r.training_days_completed < 2;
                    const unconfirmed = r.site_ownership === 'unconfirmed';
                    const inactive = r.status !== 'active';
                    return {
                        id: r.id,
                        name,
                        sub: [
                            unconfirmed ? null : (r.site?.name ?? 'Site not recorded'),
                            r.work_group ?? 'Work group not recorded',
                            inactive ? (titleCase(r.status) || 'Status not recorded').toLowerCase() : null,
                        ]
                            .filter(Boolean)
                            .join(' · '),
                        flag: unconfirmed ? (
                            <StatusBadge variant="warning" size="sm">
                                {SITE_UNCONFIRMED}
                            </StatusBadge>
                        ) : below ? (
                            <StatusBadge variant="warning" size="sm">
                                Training under 2 days
                            </StatusBadge>
                        ) : inactive ? (
                            <StatusBadge variant={REP_STATUS[r.status] ?? 'neutral'} size="sm">
                                {titleCase(r.status) || 'Status not recorded'}
                            </StatusBadge>
                        ) : undefined,
                        foot: (
                            <>
                                {r.election_method ? titleCase(r.election_method) : 'Election method not recorded'}{' '}
                                {formatDateOnly(r.elected_at, 'date not recorded')} ·{' '}
                                <b>
                                    {recorded
                                        ? `${plural(r.training_days_completed, 'training day')} recorded`
                                        : 'Training not recorded'}
                                </b>
                            </>
                        ),
                        onOpen: () => onOpen(r.id),
                        menu: participationMenu(actionsFor('representative', r)),
                        onContextMenu: (event) =>
                            onContext(event, 'representative', r, r.status.toUpperCase(), `${name} · ${r.site?.name ?? ''}`),
                    };
                })}
            />
            {footer ? <div className="flex flex-wrap items-center justify-between gap-3">{footer}</div> : null}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Committee meetings                                                 */
/* ------------------------------------------------------------------ */

function meetingItem(
    m: MeetingRow,
    actionsFor: ParticipationActions,
    onOpen: (id: number) => void,
    onContext: OnContext<MeetingRow>,
): LogItem {
    const name = m.committee?.name ?? 'Committee not recorded';
    const missingMinutes = m.status === 'completed' && !m.minutes_document_path;
    const loose = [
        m.site_ownership === 'unconfirmed' ? SITE_UNCONFIRMED : null,
        m.actions_due_count > 0 ? plural(m.actions_due_count, 'action') + ' open' : null,
        missingMinutes ? 'Minutes missing' : null,
        m.status === 'cancelled' ? 'Cancelled' : null,
        m.minutes_document_path ? 'Minutes filed' : null,
    ].filter(Boolean);
    return {
        key: m.id,
        time: m.scheduled_at ? formatDate(m.scheduled_at) : 'No date',
        tone:
            m.status === 'scheduled'
                ? 'info'
                : missingMinutes || m.actions_due_count > 0 || m.site_ownership === 'unconfirmed'
                  ? 'warning'
                  : m.status === 'cancelled'
                    ? 'none'
                    : 'success',
        title: name,
        sub: [
            m.scheduled_at ? formatTime(m.scheduled_at) : 'Time not recorded',
            Number.isFinite(m.attendees_count) ? plural(m.attendees_count, 'attendee') + ' listed' : 'Attendees not recorded',
            ...loose,
        ].join(' · '),
        action: { label: 'View', onClick: () => onOpen(m.id) },
        menu: participationMenu(actionsFor('meeting', m)),
        onContextMenu: (event) => onContext(event, 'meeting', m, m.status.toUpperCase(), name),
    };
}

export function MeetingLogs({
    rows,
    actionsFor,
    onOpen,
    onContext,
    footer,
}: {
    rows: MeetingRow[];
    actionsFor: ParticipationActions;
    onOpen: (id: number) => void;
    onContext: OnContext<MeetingRow>;
    footer?: ReactNode;
}) {
    if (!rows.length) {
        return <QuietLine title="Committee meetings" text="No meetings match this view and these filters." />;
    }
    const upcoming = rows
        .filter((m) => m.status === 'scheduled')
        .sort((a, b) => Date.parse(a.scheduled_at ?? '') - Date.parse(b.scheduled_at ?? ''));
    const held = rows.filter((m) => m.status !== 'scheduled');
    const item = (m: MeetingRow) => meetingItem(m, actionsFor, onOpen, onContext);
    return (
        <div className="grid gap-3">
            <SideBySide>
                {upcoming.length ? (
                    <Log title="Coming up" sub="Scheduled meetings" items={upcoming.map(item)} limit={upcoming.length} />
                ) : (
                    <QuietLine title="Coming up" text="No meetings scheduled in this view." />
                )}
                {held.length ? (
                    <Log title="Held" sub="Completed or cancelled, with each one’s loose ends" items={held.map(item)} limit={held.length} />
                ) : (
                    <QuietLine title="Held" text="No completed or cancelled meetings in this view." />
                )}
            </SideBySide>
            {footer ? <div className="flex flex-wrap items-center justify-between gap-3">{footer}</div> : null}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Consultations                                                      */
/* ------------------------------------------------------------------ */

export function ConsultationTable({
    rows,
    actionsFor,
    onOpen,
    footer,
}: {
    rows: ConsultationRow[];
    actionsFor: ParticipationActions;
    onOpen: (id: number) => void;
    footer?: ReactNode;
}) {
    const columns: RecordTableColumn<ConsultationRow>[] = [
        {
            key: 'topic',
            label: 'Topic',
            className: 'min-w-[16rem] max-w-[26rem]',
            cell: (c) => (
                <RecordIdentity
                    title={c.title}
                    sub={[
                        c.site_ownership === 'unconfirmed' ? SITE_UNCONFIRMED : (c.site?.name ?? 'Site not recorded'),
                        formatDateOnly(c.consultation_date, 'Date not recorded'),
                    ].join(' · ')}
                />
            ),
        },
        { key: 'type', label: 'Type', nowrap: true, cell: (c) => consultationTypeLabel(c.consultation_type) },
        {
            key: 'stage',
            label: 'Where it’s up to',
            nowrap: true,
            cell: (c) => {
                const status = CONSULT_STATUS[c.status];
                const stage = CONSULT_ORDER[c.status];
                const label = status?.label ?? (titleCase(c.status) || 'Status not recorded');
                return stage ? (
                    <StageDots
                        stage={stage}
                        of={4}
                        label={label}
                        tone={status?.tone === 'warning' ? 'warning' : status?.tone === 'success' ? 'success' : undefined}
                    />
                ) : (
                    <span className="text-muted-foreground">{label} · stage not confirmed</span>
                );
            },
        },
        {
            key: 'documents',
            label: 'Documents',
            nowrap: true,
            cell: (c) =>
                c.document_path || c.outcome_document_path ? (
                    [c.document_path ? 'Document' : null, c.outcome_document_path ? 'outcome' : null]
                        .filter(Boolean)
                        .join(' · ')
                ) : (
                    <span className="text-muted-foreground">None recorded</span>
                ),
        },
    ];
    return (
        <RecordTable
            title="Consultations"
            sub="Feedback, then outcome, then close · right-click a row for its options"
            columns={columns}
            rows={rows}
            rowKey={(c) => c.id}
            onOpen={(c) => onOpen(c.id)}
            actionsFor={(c) => participationMenu(actionsFor('consultation', c))}
            menuTitle={(c) => c.title}
            menuIcon={MessageSquare}
            empty={
                <p className="sg-quiet px-4 py-6">
                    <span>No consultations match this view and these filters.</span>
                </p>
            }
            footer={footer}
        />
    );
}
