import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import {
    CalendarRange,
    Compass,
    MapPin,
    Plus,
    Sparkles,
    UserCheck,
} from 'lucide-react';
import { useState } from 'react';
import {
    LEAVE_ACTION_LABEL,
    LeaveActionDialog,
    type LeaveAction,
} from './leave-action-dialog';

export type LeaveItem = {
    id: number;
    starts_on?: string | null;
    ends_on?: string | null;
    destination?: string | null;
    support_required?: string | null;
    risks_and_mitigations?: string | null;
    emergency_contact?: string | null;
    status?: string | null;
    requester?: string | null;
    approver?: string | null;
    approved_at?: string | null;
    approval_notes?: string | null;
    version?: number;
    medication_away_enabled?: boolean;
    departed_at?: string | null;
    returned_at?: string | null;
    withdrawn_at?: string | null;
    withdrawal_reason?: string | null;
    allowed_actions?: LeaveAction[];
    history?: {
        action: string;
        occurred_at: string;
        actor_id: number;
        version: number;
        reason: string | null;
    }[];
};

export type ExcursionItem = {
    id: number;
    starts_at?: string | null;
    ends_at?: string | null;
    destination?: string | null;
    activity_description?: string | null;
    transport_method?: string | null;
    risk_assessment?: string | null;
    outcome_notes?: string | null;
    status?: string | null;
    requester?: string | null;
    approver?: string | null;
    approved_at?: string | null;
    approval_notes?: string | null;
};

type LeaveExcursionsTabProps = {
    clientId: number;
    leave?: LeaveItem[];
    excursions?: ExcursionItem[];
    canManage?: boolean;
    /** Open the Add-Client-style leave wizard. */
    onRequestLeave?: () => void;
    /** Open the Add-Client-style excursion wizard. */
    onPlanExcursion?: () => void;
};

const dateLabel = formatDateOnly;
const dateTimeLabel = formatDateTime;

function statusBadge(status?: string | null): string {
    const s = (status ?? '').toLowerCase();
    if (s === 'approved' || s === 'completed')
        return 'bg-status-success-bg text-status-success';
    if (s === 'declined' || s === 'cancelled')
        return 'bg-status-critical-bg text-status-critical';
    if (s === 'requested' || s === 'proposed')
        return 'bg-status-warning-bg text-status-warning';
    return 'bg-muted text-muted-foreground';
}

export function LeaveExcursionsTab({
    clientId,
    leave = [],
    excursions = [],
    canManage = false,
    onRequestLeave,
    onPlanExcursion,
}: LeaveExcursionsTabProps) {
    const [selection, setSelection] = useState<{
        item: LeaveItem;
        clientId: number;
        action: LeaveAction;
    } | null>(null);
    const selectedItem =
        selection?.clientId === clientId && canManage
            ? leave.find(
                  (item) =>
                      item.id === selection.item.id &&
                      item.version === selection.item.version &&
                      item.allowed_actions?.includes(selection.action),
              )
            : undefined;
    return (
        <div className="space-y-6" data-test="client-leave-excursions-tab">
            {/* eslint-disable-next-line no-restricted-syntax -- intro panel without full Card chrome. */}
            <div className="rounded-lg border bg-card p-4">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-semibold">
                            Leave & excursions
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Planned absences and activities. Approvals are
                            tracked and key events project to the timeline.
                        </p>
                    </div>
                    {canManage ? (
                        <div className="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => onRequestLeave?.()}
                                data-test="leave-request"
                            >
                                <Plus className="mr-1.5 h-3.5 w-3.5" /> Request
                                leave
                            </Button>
                            <Button
                                size="sm"
                                onClick={() => onPlanExcursion?.()}
                                data-test="excursion-plan"
                            >
                                <Plus className="mr-1.5 h-3.5 w-3.5" /> Plan
                                excursion
                            </Button>
                        </div>
                    ) : null}
                </div>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <CalendarRange className="h-4 w-4 text-primary" />
                        Leave requests
                        <Badge variant="outline" className="ml-auto">
                            {leave.length}
                        </Badge>
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    {leave.length > 0 ? (
                        leave.map((item) => (
                            <div
                                key={`leave-${item.id}`}
                                className="rounded-lg border p-3 text-sm"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge
                                                className={cn(
                                                    statusBadge(item.status),
                                                    'capitalize',
                                                )}
                                            >
                                                {item.status ?? 'requested'}
                                            </Badge>
                                            {item.destination ? (
                                                <span className="inline-flex items-center gap-1">
                                                    <MapPin className="h-3.5 w-3.5" />
                                                    {item.destination}
                                                </span>
                                            ) : null}
                                        </div>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {dateLabel(item.starts_on)} —{' '}
                                            {dateLabel(item.ends_on)}
                                        </p>
                                    </div>
                                    {item.requester ? (
                                        <p className="text-xs text-muted-foreground">
                                            Requested by {item.requester}
                                        </p>
                                    ) : null}
                                </div>
                                <div className="text-caption mt-3 space-y-1">
                                    <p>
                                        {item.departed_at
                                            ? 'Actually left: ' +
                                              formatDateTime(item.departed_at)
                                            : 'Actual departure not recorded · planned dates do not mark medication Away.'}
                                    </p>
                                    {item.returned_at && (
                                        <p>
                                            Actually returned:{' '}
                                            {formatDateTime(item.returned_at)}
                                        </p>
                                    )}
                                    {item.withdrawn_at && (
                                        <p>
                                            Withdrawn:{' '}
                                            {formatDateTime(item.withdrawn_at)}{' '}
                                            · {item.withdrawal_reason}
                                        </p>
                                    )}
                                </div>
                                {canManage &&
                                    !!item.allowed_actions?.length && (
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            {item.allowed_actions.map(
                                                (action) => (
                                                    <Button
                                                        key={action}
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            setSelection({
                                                                clientId,
                                                                item,
                                                                action,
                                                            })
                                                        }
                                                    >
                                                        {
                                                            LEAVE_ACTION_LABEL[
                                                                action
                                                            ]
                                                        }
                                                    </Button>
                                                ),
                                            )}
                                        </div>
                                    )}
                                {!!item.history?.length && (
                                    <details className="text-caption mt-3">
                                        <summary className="cursor-pointer focus-visible:ring-2 focus-visible:ring-ring">
                                            Leave history
                                        </summary>
                                        <ul className="mt-2 space-y-1">
                                            {item.history.map((event) => (
                                                <li key={event.version}>
                                                    {event.action} ·{' '}
                                                    {formatDateTime(
                                                        event.occurred_at,
                                                    )}
                                                    {event.reason
                                                        ? ' · ' + event.reason
                                                        : ''}
                                                </li>
                                            ))}
                                        </ul>
                                    </details>
                                )}
                                {item.support_required ? (
                                    <p className="mt-2 text-xs">
                                        <span className="font-medium">
                                            Support:
                                        </span>{' '}
                                        {item.support_required}
                                    </p>
                                ) : null}
                                {item.risks_and_mitigations ? (
                                    <p className="mt-2 text-xs">
                                        <span className="font-medium">
                                            Risks:
                                        </span>{' '}
                                        {item.risks_and_mitigations}
                                    </p>
                                ) : null}
                                {item.emergency_contact ? (
                                    <p className="mt-2 text-xs">
                                        <span className="font-medium">
                                            Emergency contact:
                                        </span>{' '}
                                        {item.emergency_contact}
                                    </p>
                                ) : null}
                                {item.approval_notes ? (
                                    <p className="mt-2 rounded-md bg-muted/50 p-2 text-xs">
                                        <UserCheck className="mr-1 inline h-3 w-3" />
                                        {item.approver ?? 'Approver'}:{' '}
                                        {item.approval_notes}
                                    </p>
                                ) : null}
                            </div>
                        ))
                    ) : (
                        <EmptyState
                            icon={CalendarRange}
                            title="No leave requests yet"
                            description="Use the Request leave button to capture planned time away from the service."
                        />
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Compass className="h-4 w-4 text-primary" />
                        Excursions
                        <Badge variant="outline" className="ml-auto">
                            {excursions.length}
                        </Badge>
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    {excursions.length > 0 ? (
                        excursions.map((item) => (
                            <div
                                key={`ex-${item.id}`}
                                className="rounded-lg border p-3 text-sm"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge
                                                className={cn(
                                                    statusBadge(item.status),
                                                    'capitalize',
                                                )}
                                            >
                                                {item.status ?? 'proposed'}
                                            </Badge>
                                            {item.destination ? (
                                                <span className="inline-flex items-center gap-1">
                                                    <MapPin className="h-3.5 w-3.5" />
                                                    {item.destination}
                                                </span>
                                            ) : null}
                                            {item.transport_method ? (
                                                <Badge variant="outline">
                                                    {item.transport_method}
                                                </Badge>
                                            ) : null}
                                        </div>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {dateTimeLabel(item.starts_at)}
                                            {item.ends_at
                                                ? ` — ${dateTimeLabel(item.ends_at)}`
                                                : ''}
                                        </p>
                                    </div>
                                    {item.requester ? (
                                        <p className="text-xs text-muted-foreground">
                                            Proposed by {item.requester}
                                        </p>
                                    ) : null}
                                </div>
                                {item.activity_description ? (
                                    <p className="mt-2 text-xs">
                                        <Sparkles className="mr-1 inline h-3 w-3" />
                                        {item.activity_description}
                                    </p>
                                ) : null}
                                {item.risk_assessment ? (
                                    <p className="mt-2 text-xs">
                                        <span className="font-medium">
                                            Risk:
                                        </span>{' '}
                                        {item.risk_assessment}
                                    </p>
                                ) : null}
                                {item.outcome_notes ? (
                                    <p className="mt-2 rounded-md bg-status-success-bg p-2 text-xs text-status-success">
                                        Outcome: {item.outcome_notes}
                                    </p>
                                ) : null}
                                {item.approval_notes ? (
                                    <p className="mt-2 rounded-md bg-muted/50 p-2 text-xs">
                                        <UserCheck className="mr-1 inline h-3 w-3" />
                                        {item.approver ?? 'Approver'}:{' '}
                                        {item.approval_notes}
                                    </p>
                                ) : null}
                            </div>
                        ))
                    ) : (
                        <EmptyState
                            icon={Compass}
                            title="No excursions planned"
                            description="Plan an outing or activity from the Plan excursion button."
                        />
                    )}
                </CardContent>
            </Card>
            {selection && selectedItem && (
                <LeaveActionDialog
                    key={
                        clientId +
                        ':' +
                        selectedItem.id +
                        ':' +
                        selectedItem.version +
                        ':' +
                        selection.action
                    }
                    clientId={clientId}
                    item={selectedItem}
                    action={selection.action}
                    onClose={() => setSelection(null)}
                />
            )}
        </div>
    );
}

export default LeaveExcursionsTab;
