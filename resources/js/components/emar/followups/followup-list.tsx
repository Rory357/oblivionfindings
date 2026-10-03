import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ClipboardList,
    Clock3,
    Eye,
    History,
    UserRoundCog,
} from 'lucide-react';
import { useState } from 'react';
import {
    EntityContextMenu,
    EntityKebab,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { useOfflineQueueState } from '@/hooks/use-offline-queue';
import { formatDateTime } from '@/lib/datetime';
import { followupSourceUrl, type MedicationFollowup } from './types';

export function FollowupStatus({
    row,
    localState,
}: {
    row: MedicationFollowup;
    localState?: 'queued' | 'rejected';
}) {
    if (localState)
        return (
            <StatusBadge
                variant={localState === 'rejected' ? 'critical' : 'warning'}
            >
                <Clock3 className="size-3.5" aria-hidden />
                {localState === 'rejected'
                    ? 'Needs attention'
                    : 'Saved on device'}
            </StatusBadge>
        );
    if (row.state === 'retired')
        return (
            <StatusBadge variant="neutral">
                <History className="size-3.5" aria-hidden />
                Superseded
            </StatusBadge>
        );
    const done = !!row.completed_at;
    const late =
        done &&
        !!row.due_at &&
        new Date(row.completed_at!).getTime() > new Date(row.due_at).getTime();
    const label = done
        ? late
            ? 'Done late'
            : 'Done'
        : row.state === 'overdue'
          ? 'Overdue'
          : row.state === 'couldnt_check'
            ? 'Couldn’t check'
            : row.due_at
              ? 'Due'
              : 'Time not set';
    const Icon = done
        ? CheckCircle2
        : row.state === 'overdue'
          ? AlertTriangle
          : Clock3;
    return (
        <StatusBadge
            variant={
                done
                    ? 'success'
                    : row.state === 'overdue'
                      ? 'critical'
                      : row.state === 'couldnt_check'
                        ? 'warning'
                        : 'info'
            }
        >
            <Icon className="size-3.5" aria-hidden />
            {label}
        </StatusBadge>
    );
}

export function MedicationFollowupList({
    rows,
    onOpen,
    hiddenControlled = 0,
}: {
    rows: MedicationFollowup[];
    onOpen: (
        row: MedicationFollowup,
        mode?: 'action' | 'history' | 'reassign',
    ) => void;
    hiddenControlled?: number;
}) {
    const [menu, setMenu] = useState<{
        x: number;
        y: number;
        row: MedicationFollowup;
    } | null>(null);
    const queue = useOfflineQueueState();
    const localState = (row: MedicationFollowup) => {
        const url = `/medication-followups/${row.id}/transition`;
        if (
            queue.rejectedSubmissions.some(
                (entry) =>
                    entry.action === 'medication_followup' && entry.url === url,
            )
        )
            return 'rejected' as const;
        if (
            queue.pendingSubmissions.some(
                (entry) =>
                    entry.action === 'medication_followup' && entry.url === url,
            )
        )
            return 'queued' as const;
        return undefined;
    };
    const actions = (row: MedicationFollowup): MenuItem[] => [
        {
            label: row.completed_at ? 'View follow-up' : 'Open follow-up',
            icon: Eye,
            onClick: () => onOpen(row),
        },
        {
            label: 'Details and history',
            icon: History,
            onClick: () => onOpen(row, 'history'),
        },
        ...(row.can_reassign
            ? [
                  {
                      label: 'Reassign',
                      icon: UserRoundCog,
                      onClick: () => onOpen(row, 'reassign'),
                  },
              ]
            : []),
        ...(row.record_url
            ? [
                  {
                      label: 'Open medication record',
                      icon: ClipboardList,
                      onClick: () => window.location.assign(row.record_url!),
                  },
              ]
            : []),
    ];
    return (
        <div className="flex min-w-0 flex-col gap-5">
            {hiddenControlled > 0 && (
                <p className="text-caption">
                    {hiddenControlled} controlled follow-up
                    {hiddenControlled === 1 ? '' : 's'} not shown — needs
                    controlled-medicine access.
                </p>
            )}
            {!rows.length ? (
                <EmptyState
                    icon={ClipboardList}
                    title="Nothing to follow up here"
                    description="Change the filters to see other medication work."
                />
            ) : (
                <>
                    <div className="hidden md:block">
                        <EntityTable
                            rows={rows}
                            rowKey={(r) => r.id}
                            minWidth={800}
                            rowHeight="content"
                            identityLabel="Person"
                            identityWidth="minmax(150px, 1.1fr)"
                            identity={(r) => ({
                                icon: ClipboardList,
                                name: r.client.name,
                                subline: r.site.name,
                            })}
                            onOpen={(r) => onOpen(r)}
                            actionsFor={actions}
                            onRowContextMenu={(e, row) => {
                                e.preventDefault();
                                setMenu({ x: e.clientX, y: e.clientY, row });
                            }}
                            columns={[
                                {
                                    key: 'work',
                                    label: 'Follow-up',
                                    width: 'minmax(190px, 1.5fr)',
                                    cell: (r) => (
                                        <div className="py-2">
                                            <p className="font-medium whitespace-normal">
                                                {r.label}
                                            </p>
                                            <p className="text-caption whitespace-normal">
                                                {r.medication?.name ??
                                                    'Handover'}
                                            </p>
                                        </div>
                                    ),
                                },
                                {
                                    key: 'due',
                                    label: 'Due',
                                    width: 'minmax(145px, 1fr)',
                                    cell: (r) => (
                                        <span className="whitespace-normal">
                                            {formatDateTime(
                                                r.due_at,
                                                'Time not set',
                                            )}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'owner',
                                    label: 'Owner',
                                    width: 'minmax(140px, 1fr)',
                                    cell: (r) => (
                                        <div className="py-2 whitespace-normal">
                                            <p>
                                                {r.owner?.name ??
                                                    (r.lead
                                                        ? 'House lead'
                                                        : 'Unassigned')}
                                            </p>
                                            {r.original_owner &&
                                                r.original_owner.id !==
                                                    r.owner?.id && (
                                                    <p className="text-caption">
                                                        Originally{' '}
                                                        {r.original_owner.name}
                                                    </p>
                                                )}
                                        </div>
                                    ),
                                },
                                {
                                    key: 'state',
                                    label: 'State',
                                    width: '130px',
                                    cell: (r) => (
                                        <FollowupStatus
                                            row={r}
                                            localState={localState(r)}
                                        />
                                    ),
                                },
                            ]}
                        />
                    </div>
                    <ul className="flex flex-col gap-5 md:hidden">
                        {rows.map((row) => (
                            <li key={row.id}>
                                <Card
                                    className="gap-3 p-5"
                                    onContextMenu={(e) => {
                                        e.preventDefault();
                                        setMenu({
                                            x: e.clientX,
                                            y: e.clientY,
                                            row,
                                        });
                                    }}
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="font-semibold">
                                                {row.client.name}
                                            </p>
                                            <p className="text-caption">
                                                {row.site.name}
                                            </p>
                                        </div>
                                        <EntityKebab items={actions(row)} />
                                    </div>
                                    <p className="font-medium">{row.label}</p>
                                    <p className="text-subtle">
                                        {row.medication?.name}
                                    </p>
                                    <FollowupStatus
                                        row={row}
                                        localState={localState(row)}
                                    />
                                    <p>
                                        {formatDateTime(
                                            row.due_at,
                                            'Time not set',
                                        )}
                                    </p>
                                    <p className="text-caption">
                                        Owner:{' '}
                                        {row.owner?.name ??
                                            (row.lead
                                                ? 'House lead'
                                                : 'Unassigned')}
                                        {row.original_owner &&
                                        row.original_owner.id !== row.owner?.id
                                            ? ` · Originally ${row.original_owner.name}`
                                            : ''}
                                    </p>
                                    <Button
                                        type="button"
                                        className="frontline-tap"
                                        variant="outline"
                                        onClick={() => onOpen(row)}
                                    >
                                        Open follow-up
                                    </Button>
                                    {followupSourceUrl(row) && (
                                        <Link
                                            className="frontline-tap text-primary underline"
                                            href={followupSourceUrl(row)!}
                                        >
                                            Open source record
                                        </Link>
                                    )}
                                </Card>
                            </li>
                        ))}
                    </ul>
                </>
            )}
            {menu && (
                <EntityContextMenu
                    {...menu}
                    title={menu.row.label}
                    icon={ClipboardList}
                    items={actions(menu.row)}
                    onClose={() => setMenu(null)}
                />
            )}
        </div>
    );
}
