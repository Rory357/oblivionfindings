import { EntityCard } from '@/components/lists/entity-card';
import {
    EntityChip,
    EntityStatusChip,
    PersonDisc,
} from '@/components/lists/entity-cells';
import {
    compactMenu,
    EntityKebab,
    type MenuItem,
} from '@/components/lists/entity-menu';
import type { ShiftCtxItem } from '@/components/rostering';
import { ParticipationOwnershipChip } from '@/components/worker-participation/ownership-notice';
import {
    CONSULT_ORDER,
    CONSULT_STATUS,
    consultationTypeLabel,
    MEETING_STATUS,
    REP_STATUS,
    type ConsultationRow,
    type MeetingRow,
    type RepRow,
} from '@/components/worker-participation/shared';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import { titleCase } from '@/pages/health-safety/components/register-row-kit';
import {
    Calendar,
    FileCheck2,
    FileText,
    GraduationCap,
    Info,
    MessageSquare,
    Users,
    Wrench,
} from 'lucide-react';
import type { MouseEvent, ReactNode } from 'react';

export type ParticipationRow = RepRow | MeetingRow | ConsultationRow;
export type ParticipationKind = 'representative' | 'meeting' | 'consultation';
export type ParticipationActions = (
    kind: string,
    row: ParticipationRow,
) => ShiftCtxItem[];

/** Presentation adapter only; eligibility and callbacks stay in actionsFor. */
export function participationMenu(items: ShiftCtxItem[]): MenuItem[] {
    return compactMenu(
        items.map((item) =>
            item.sep
                ? { separator: true }
                : {
                      label: item.label,
                      icon: () => <span aria-hidden="true">{item.icon}</span>,
                      danger: item.tone === 'critical',
                      onClick: item.onClick,
                  },
        ),
    );
}

export function ParticipationRowMenu({
    kind,
    row,
    name,
    actionsFor,
}: {
    kind: ParticipationKind;
    row: ParticipationRow;
    name: string;
    actionsFor: ParticipationActions;
}) {
    return (
        <EntityKebab
            frontline
            label={`Actions for ${name}`}
            actions={participationMenu(actionsFor(kind, row))}
        />
    );
}

const status = (tone: string, label: string) => (
    <EntityStatusChip
        variant={
            tone === 'critical' || tone === 'warning' || tone === 'success'
                ? tone
                : 'neutral'
        }
        icon={Info}
    >
        {label}
    </EntityStatusChip>
);

export function ParticipationCards<T extends ParticipationRow>({
    kind,
    rows,
    actionsFor,
    onOpen,
    onContextMenu,
}: {
    kind: ParticipationKind;
    rows: T[];
    actionsFor: ParticipationActions;
    onOpen: (id: number) => void;
    onContextMenu: (
        event: MouseEvent,
        kind: string,
        row: T,
        tag: string,
        meta: string,
    ) => void;
}) {
    return (
        <ul
            className="grid gap-5 p-4 md:hidden"
            aria-label={`${titleCase(kind)} records`}
        >
            {rows.map((row) => {
                let name: string;
                let subline: ReactNode;
                let chips: ReactNode;
                let alerts: ReactNode;
                let meridian: 'warning' | 'neutral' = 'neutral';
                if (kind === 'representative') {
                    const rep = row as RepRow;
                    name = rep.user?.name ?? 'Representative not recorded';
                    subline = rep.site?.name ?? 'Site not recorded';
                    const below =
                        Number.isFinite(rep.training_days_completed) &&
                        rep.training_days_completed < 2;
                    meridian = below ? 'warning' : 'neutral';
                    chips = (
                        <>
                            {status(
                                REP_STATUS[rep.status] ?? 'neutral',
                                titleCase(rep.status) || 'Status not recorded',
                            )}
                            <EntityChip>
                                {rep.work_group ?? 'Work group not recorded'}
                            </EntityChip>
                            <EntityChip>
                                {rep.election_method
                                    ? titleCase(rep.election_method)
                                    : 'Election method not recorded'}
                            </EntityChip>
                            <EntityChip icon={Calendar}>
                                Elected{' '}
                                {formatDateOnly(
                                    rep.elected_at,
                                    'date not recorded',
                                )}
                            </EntityChip>
                        </>
                    );
                    alerts = (
                        <EntityStatusChip
                            variant={below ? 'warning' : 'neutral'}
                            icon={GraduationCap}
                        >
                            {Number.isFinite(rep.training_days_completed)
                                ? `${rep.training_days_completed} training ${rep.training_days_completed === 1 ? 'day' : 'days'} recorded`
                                : 'Training not recorded'}
                        </EntityStatusChip>
                    );
                } else if (kind === 'meeting') {
                    const meeting = row as MeetingRow;
                    name = meeting.committee?.name ?? 'Committee not recorded';
                    subline = formatDateTimeLong(
                        meeting.scheduled_at,
                        'Scheduled time not recorded',
                    );
                    const missingMinutes =
                        meeting.status === 'completed' &&
                        !meeting.minutes_document_path;
                    meridian =
                        missingMinutes || meeting.actions_due_count > 0
                            ? 'warning'
                            : 'neutral';
                    chips = (
                        <>
                            {status(
                                MEETING_STATUS[meeting.status] ?? 'neutral',
                                titleCase(meeting.status) ||
                                    'Status not recorded',
                            )}
                            <EntityChip icon={Users}>
                                {meeting.attendees_count} attendees listed
                            </EntityChip>
                            <EntityChip icon={Wrench}>
                                {meeting.actions_due_count} action items
                                outstanding
                            </EntityChip>
                        </>
                    );
                    alerts = (
                        <EntityStatusChip
                            variant={missingMinutes ? 'warning' : 'neutral'}
                            icon={FileText}
                        >
                            {meeting.minutes_document_path
                                ? 'Minutes recorded'
                                : missingMinutes
                                  ? 'Completed · minutes missing'
                                  : 'Minutes not recorded'}
                        </EntityStatusChip>
                    );
                } else {
                    const consultation = row as ConsultationRow;
                    name = consultation.title;
                    subline = consultation.site?.name ?? 'Site not recorded';
                    const current = CONSULT_STATUS[consultation.status];
                    const stage = CONSULT_ORDER[consultation.status];
                    chips = (
                        <>
                            {status(
                                current?.tone ?? 'neutral',
                                current?.label ??
                                    (titleCase(consultation.status) ||
                                        'Status not recorded'),
                            )}
                            <EntityChip icon={MessageSquare}>
                                {consultationTypeLabel(
                                    consultation.consultation_type,
                                )}
                            </EntityChip>
                            <EntityChip icon={Calendar}>
                                {formatDateOnly(
                                    consultation.consultation_date,
                                    'Consultation date not recorded',
                                )}
                            </EntityChip>
                            <EntityChip>
                                {stage
                                    ? `Stage ${stage} of 4`
                                    : 'Stage not confirmed'}
                            </EntityChip>
                        </>
                    );
                    alerts = (
                        <>
                            <EntityChip icon={FileText}>
                                Consultation document{' '}
                                {consultation.document_path
                                    ? 'recorded'
                                    : 'not recorded'}
                            </EntityChip>
                            <EntityChip icon={FileCheck2}>
                                Outcome document{' '}
                                {consultation.outcome_document_path
                                    ? 'recorded'
                                    : 'not recorded'}
                            </EntityChip>
                        </>
                    );
                }
                return (
                    <li key={row.id}>
                        <EntityCard
                            frontline
                            name={name}
                            subline={subline}
                            meridian={
                                row.site_ownership === 'unconfirmed'
                                    ? 'warning'
                                    : meridian
                            }
                            mark={
                                kind === 'representative' ? (
                                    <PersonDisc
                                        name={(row as RepRow).user?.name}
                                        size={40}
                                    />
                                ) : undefined
                            }
                            icon={kind === 'meeting' ? Calendar : MessageSquare}
                            actions={participationMenu(actionsFor(kind, row))}
                            onContextMenu={(event) =>
                                onContextMenu(
                                    event,
                                    kind,
                                    row,
                                    row.status.toUpperCase(),
                                    name,
                                )
                            }
                            chips={chips}
                            alerts={
                                <>
                                    <ParticipationOwnershipChip
                                        state={row.site_ownership}
                                    />
                                    {alerts}
                                </>
                            }
                            footer={{
                                primary: `${titleCase(kind)} record`,
                                trailing: (
                                    <button
                                        type="button"
                                        className="frontline-tap frontline-focus shrink-0 text-sm font-semibold text-primary"
                                        aria-label={`View ${kind}: ${name}`}
                                        onClick={() => onOpen(row.id)}
                                    >
                                        View
                                    </button>
                                ),
                            }}
                        />
                    </li>
                );
            })}
        </ul>
    );
}
