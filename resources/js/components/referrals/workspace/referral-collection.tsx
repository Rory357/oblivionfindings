import {
    EntityCardGrid,
    EntityChip,
    EntityStatusChip,
    EntityTable,
    PersonCell,
    PersonDisc,
    type MenuItem,
} from '@/components/lists';
import { formatDate } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import type { MouseEvent } from 'react';
import { ReferralStageBadge } from '../stage-badge';
import {
    label,
    referralHref,
    shownReference,
    type WorkspaceReferral,
} from '../types';
import { ReferralCard, ReferralPriorityChip } from './referral-card';

/** Cards / table for the Referrals view; both share the page's one MenuItem[] builder. */
export function ReferralCollection({
    rows,
    layout,
    stages,
    actionsFor,
    onContextMenu,
}: {
    rows: WorkspaceReferral[];
    layout: 'cards' | 'table';
    stages: { id: string; label: string }[];
    actionsFor: (record: WorkspaceReferral) => MenuItem[];
    onContextMenu: (event: MouseEvent, record: WorkspaceReferral) => void;
}) {
    if (layout === 'cards')
        return (
            <EntityCardGrid>
                {rows.map((record) => (
                    <ReferralCard
                        key={record.reference}
                        record={record}
                        stages={stages}
                        actions={actionsFor(record)}
                        onContextMenu={(event) => onContextMenu(event, record)}
                    />
                ))}
            </EntityCardGrid>
        );
    return (
        <EntityTable
            rows={rows}
            rowKey={(record) => record.reference}
            identityLabel="Person / referral"
            identityWidth="1.6fr"
            identity={(record) => ({
                mark: <PersonDisc name={record.name} size={30} />,
                name: record.name,
                subline: `${shownReference(record)} · ${label(record.source)}`,
            })}
            hrefFor={(record) => referralHref(record.reference)}
            onOpen={(record) => router.visit(referralHref(record.reference))}
            actionsFor={actionsFor}
            onRowContextMenu={onContextMenu}
            rowHeight="content"
            minWidth={980}
            columns={[
                {
                    key: 'stage',
                    label: 'Stage',
                    width: '1.1fr',
                    // Priority shows under the stage only when it isn't the default.
                    cell: (record) => (
                        <div className="grid justify-items-start gap-1">
                            <ReferralStageBadge
                                stage={record.stage}
                                stages={stages}
                            />
                            <ReferralPriorityChip
                                priority={record.priority}
                                priorityLabel={record.priority_label}
                            />
                        </div>
                    ),
                },
                {
                    key: 'owner',
                    label: 'Owner',
                    width: '1.15fr',
                    cell: (record) => <PersonCell name={record.owner_name} />,
                },
                {
                    key: 'site',
                    label: 'Site',
                    width: '1fr',
                    cell: (record) => (
                        <EntityChip>
                            {record.site_name ?? 'Central intake'}
                        </EntityChip>
                    ),
                },
                {
                    key: 'next',
                    label: 'Next action',
                    width: '1.8fr',
                    cell: (record) => (
                        <span className="break-words">
                            {record.next_action || (
                                <span className="text-muted-foreground">—</span>
                            )}
                        </span>
                    ),
                },
                {
                    key: 'due',
                    label: 'Follow up by',
                    width: '1fr',
                    cell: (record) => (
                        <div className="grid justify-items-start gap-1">
                            <span>
                                {record.next_due_at ? (
                                    record.is_due_today &&
                                    !record.is_overdue ? (
                                        'Due today'
                                    ) : (
                                        formatDate(record.next_due_at)
                                    )
                                ) : (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                )}
                            </span>
                            {record.is_overdue && (
                                <EntityStatusChip
                                    variant="warning"
                                    icon={Clock}
                                >
                                    Overdue
                                </EntityStatusChip>
                            )}
                        </div>
                    ),
                },
            ]}
        />
    );
}
