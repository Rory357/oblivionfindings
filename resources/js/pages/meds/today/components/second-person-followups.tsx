import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import type { SecondPersonFollowUp } from '../types';

export function SecondPersonFollowups({
    rows,
}: {
    rows: SecondPersonFollowUp[];
}) {
    if (!rows.length) return null;
    return (
        <section
            className="space-y-2.5"
            aria-label="Your second-person confirmations"
        >
            <ListCaption
                title="Your second-person confirmations"
                caption="A colleague named you. Review the dose in your own account and answer whether you were there."
            />
            <EntityTable
                rows={rows}
                rowKey={(row) => row.id}
                identityLabel="Person"
                identity={(row) => ({
                    name: row.client_name,
                    subline: row.medication_name,
                })}
                hrefFor={(row) => row.followup_url}
                minWidth={680}
                rowHeight="content"
                actionsFor={() => []}
                columns={[
                    {
                        key: 'due',
                        label: 'Answer by',
                        width: '1.3fr',
                        cell: (row) => (
                            <time dateTime={row.due_at}>
                                {formatDateTime(row.due_at)} NZ time
                            </time>
                        ),
                    },
                    {
                        key: 'state',
                        label: 'State',
                        width: '1fr',
                        cell: (row) => (
                            <StatusBadge
                                variant={
                                    row.status === 'expired'
                                        ? 'critical'
                                        : 'warning'
                                }
                            >
                                {row.status === 'expired'
                                    ? 'Confirmation overdue'
                                    : 'Not yet verified'}
                            </StatusBadge>
                        ),
                    },
                    {
                        key: 'action',
                        label: 'Action',
                        width: '180px',
                        align: 'right',
                        cell: (row) => (
                            <Button size="sm" variant="outline" asChild>
                                <Link href={row.followup_url}>
                                    Review confirmation
                                </Link>
                            </Button>
                        ),
                    },
                ]}
            />
        </section>
    );
}
