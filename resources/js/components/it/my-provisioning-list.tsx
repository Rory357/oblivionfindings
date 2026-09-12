import { EntityCard, EntityCardGrid } from '@/components/lists/entity-card';
import {
    EntityContextMenu,
    useEntityContextMenu,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { ArrowUpRight, Package } from 'lucide-react';

export interface MyProvisioningRow {
    id: number;
    reference: string;
    title: string;
    type: string;
    status: string;
    approval_status: string;
    created_at: string | null;
    updated_at: string | null;
    due_date: string | null;
    href: string;
}
export interface MyProvisioningPage {
    data: MyProvisioningRow[];
    total: number;
    matched: number;
    links: { url: string | null; label: string; active: boolean }[];
    last_page: number;
}

export const provisioningStatus = (status: string) =>
    ({
        pending: 'Waiting for IT',
        in_progress: 'In progress',
        failed: 'Needs IT attention',
        done: 'Completed',
        cancelled: 'Cancelled',
    })[status] ?? status.replaceAll('_', ' ');

export function MyProvisioningList({
    page,
    view,
}: {
    page: MyProvisioningPage;
    view: 'cards' | 'table';
}) {
    const menu = useEntityContextMenu<number>();
    const current = page.data.find((row) => row.id === menu.ctx?.record);
    const actions = (row: MyProvisioningRow) => [
        {
            label: 'Track request',
            icon: ArrowUpRight,
            onClick: () => router.visit(row.href),
        },
    ];
    return (
        <section className="space-y-4" aria-labelledby="my-provisioning-title">
            <div>
                <h2 id="my-provisioning-title" className="text-section-title">
                    Equipment & access requests
                </h2>
                <p className="text-subtle">
                    {page.data.length} of {page.matched} matching requests shown
                    · {page.total} total
                </p>
            </div>
            {page.data.length === 0 ? (
                <p className="text-subtle rounded-xl border border-border bg-card p-5">
                    No equipment or access requests match these filters.
                </p>
            ) : view === 'table' ? (
                <EntityTable
                    rows={page.data}
                    rowKey={(row) => row.id}
                    identity={(row) => ({
                        name: row.title,
                        subline: row.reference,
                        icon: Package,
                    })}
                    hrefFor={(row) => row.href}
                    actionsFor={actions}
                    onRowContextMenu={(event, row) => menu.open(event, row.id)}
                    columns={[
                        {
                            key: 'status',
                            label: 'Status',
                            width: '1fr',
                            cell: (row) => (
                                <StatusBadge
                                    status={row.status}
                                    label={provisioningStatus(row.status)}
                                />
                            ),
                        },
                        {
                            key: 'approval',
                            label: 'Approval',
                            width: '1fr',
                            cell: (row) => (
                                <StatusBadge status={row.approval_status} />
                            ),
                        },
                        {
                            key: 'updated',
                            label: 'Updated',
                            width: '1.2fr',
                            cell: (row) => formatDateTime(row.updated_at),
                        },
                    ]}
                />
            ) : (
                <EntityCardGrid>
                    {page.data.map((row) => (
                        <EntityCard
                            key={row.id}
                            name={row.title}
                            meridian={
                                row.status === 'failed'
                                    ? 'critical'
                                    : row.approval_status === 'pending'
                                      ? 'warning'
                                      : 'success'
                            }
                            icon={Package}
                            subline={row.reference}
                            href={row.href}
                            actions={actions(row)}
                            onContextMenu={(event) => menu.open(event, row.id)}
                            chips={
                                <>
                                    <StatusBadge
                                        status={row.status}
                                        label={provisioningStatus(row.status)}
                                    />
                                    <StatusBadge
                                        status={row.approval_status}
                                        label={`Approval: ${row.approval_status.replaceAll('_', ' ')}`}
                                    />
                                </>
                            }
                            footer={{
                                personName: null,
                                primary: 'Updated',
                                secondary: formatDateTime(row.updated_at),
                            }}
                        />
                    ))}
                </EntityCardGrid>
            )}
            <LaravelPagination
                links={page.links}
                lastPage={page.last_page}
                preserveScroll
            />
            {menu.ctx && current ? (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    title={current.title}
                    icon={Package}
                    onClose={menu.close}
                    items={actions(current)}
                />
            ) : null}
        </section>
    );
}
