import {
    EntityCard,
    EntityChip,
    EntityStatusChip,
    PersonDisc,
    type MenuItem,
} from '@/components/lists';
import { formatDate } from '@/lib/datetime';
import { CalendarDays, Clock, Flag, PauseCircle } from 'lucide-react';
import type { MouseEvent, ReactNode } from 'react';
import {
    DEFAULT_PRIORITY,
    priorityText,
    priorityTone,
} from '../priority-badge';
import { ReferralStageBadge } from '../stage-badge';
import { label, referralHref, type WorkspaceReferral } from '../types';

const finished = (stage: string) => ['closed', 'converted'].includes(stage);

/** Card and table chip: priority shows only when it isn't the default (urgent critical, high warning). */
export function ReferralPriorityChip({
    priority,
    priorityLabel,
}: {
    priority: string;
    priorityLabel?: string | null;
}) {
    if (!priority || priority === DEFAULT_PRIORITY) return null;
    return (
        <EntityStatusChip variant={priorityTone(priority)} icon={Flag}>
            {priorityText(priority, priorityLabel)}
        </EntityStatusChip>
    );
}

/** Due chip: emphasis only where the work is actually overdue. */
export function ReferralDueChip({ record }: { record: WorkspaceReferral }) {
    if (record.is_overdue)
        return (
            <EntityStatusChip variant="warning" icon={Clock}>
                Overdue
                {record.next_due_at
                    ? ` · ${formatDate(record.next_due_at)}`
                    : ''}
            </EntityStatusChip>
        );
    if (!record.next_due_at || finished(record.stage)) return null;
    return (
        <EntityChip icon={CalendarDays}>
            {record.is_due_today ? 'Due today' : formatDate(record.next_due_at)}
        </EntityChip>
    );
}

/** The distinct next-step area shared by cards and pipeline cards (mockup `.referral-next-step`). */
export function ReferralNextStep({ record }: { record: WorkspaceReferral }) {
    return (
        <div className="grid w-full gap-1 rounded-md bg-muted p-3">
            <span className="text-caption text-muted-foreground">
                {finished(record.stage) ? 'Last recorded action' : 'Next step'}
            </span>
            <p className="text-sm break-words whitespace-normal">
                {record.next_action || 'No next action recorded'}
            </p>
        </div>
    );
}

export function ReferralCard({
    record,
    actions,
    onContextMenu,
    stages,
    showStage = true,
    openLabel,
}: {
    record: WorkspaceReferral;
    actions: MenuItem[];
    onContextMenu: (event: MouseEvent) => void;
    stages: { id: string; label: string }[];
    /** Pipeline columns already name the stage; cards elsewhere lead with it. */
    showStage?: boolean;
    openLabel?: string;
}) {
    const chips: ReactNode = (
        <>
            {showStage && (
                <ReferralStageBadge stage={record.stage} stages={stages} />
            )}
            <ReferralPriorityChip
                priority={record.priority}
                priorityLabel={record.priority_label}
            />
            <EntityChip>{record.site_name ?? 'Central intake'}</EntityChip>
            {record.hold_reason && (
                <EntityChip icon={PauseCircle}>
                    {record.hold_reason_label ?? label(record.hold_reason)}
                </EntityChip>
            )}
            <ReferralDueChip record={record} />
        </>
    );
    return (
        <EntityCard
            meridian={record.is_overdue ? 'warning' : 'neutral'}
            mark={<PersonDisc name={record.name} size={40} />}
            name={record.name}
            href={referralHref(record.reference)}
            linkLabel={`Open ${record.name}`}
            subline={`${record.reference} · ${label(record.source)}`}
            actions={actions}
            onContextMenu={onContextMenu}
            openLabel={openLabel}
            chips={chips}
            alerts={<ReferralNextStep record={record} />}
            footer={{
                personName: record.owner_name,
                primary: record.owner_name ?? record.team,
                secondary: record.owner_name
                    ? undefined
                    : 'Coordinator not yet assigned',
            }}
        />
    );
}
