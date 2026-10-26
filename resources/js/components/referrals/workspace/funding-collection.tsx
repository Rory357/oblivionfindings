import {
    EntityCard,
    EntityCardGrid,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    PersonCell,
    PersonDisc,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDate } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { ArrowUpRight, CalendarDays, Wallet } from 'lucide-react';
import { ReferralStageBadge } from '../stage-badge';
import {
    referralHref,
    type Page,
    type Permissions,
    type WorkspaceFundingRow,
} from '../types';

const fundingHref = (row: WorkspaceFundingRow) =>
    `${referralHref(row.referral.reference)}?tab=funding`;

/**
 * Funding assistance view: open funding cases across referrals the viewer can
 * see (server-scoped, requires View funding). Cards and table share one
 * MenuItem[] and link to the referral's own funding section for all work.
 */
export function FundingCollection({
    page,
    layout,
    permissions,
    stages,
    onClear,
}: {
    page: Page<WorkspaceFundingRow>;
    layout: 'cards' | 'table';
    permissions: Permissions;
    stages: { id: string; label: string }[];
    onClear: () => void;
}) {
    const menu = useEntityContextMenu<WorkspaceFundingRow>();
    const actions = (row: WorkspaceFundingRow): MenuItem[] => [
        {
            label: 'Open referral',
            icon: ArrowUpRight,
            onClick: () => router.visit(referralHref(row.referral.reference)),
        },
        {
            label: 'Work on funding case',
            icon: Wallet,
            onClick: () => router.visit(fundingHref(row)),
            disabled: permissions['funding.manage']
                ? undefined
                : 'Requires Manage funding assistance',
        },
    ];
    const due = (row: WorkspaceFundingRow) =>
        row.next_due_at ? (
            <EntityStatusChip
                variant={row.is_overdue ? 'warning' : 'neutral'}
                icon={CalendarDays}
            >
                {row.is_overdue ? 'Overdue · ' : ''}
                {formatDate(row.next_due_at)}
            </EntityStatusChip>
        ) : (
            <EntityChip icon={CalendarDays}>No funding action dated</EntityChip>
        );
    if (page.data.length === 0)
        return (
            <EmptyState
                icon={Wallet}
                title="No funding cases in this view"
                description="Change the filters to see other permitted funding work."
                action={
                    <Button variant="outline" onClick={onClear}>
                        Clear filters
                    </Button>
                }
            />
        );
    return (
        <div className="grid gap-5">
            <ListCaption
                title="Funding assistance"
                caption={`${page.data.length} of ${page.total} open funding cases shown`}
            />
            {layout === 'table' ? (
                <EntityTable
                    rows={page.data}
                    rowKey={(row) => row.id}
                    identityLabel="Person / pathway"
                    identityWidth="2fr"
                    identity={(row) => ({
                        mark: <PersonDisc name={row.referral.name} size={30} />,
                        name: row.referral.name,
                        subline: row.pathway_label,
                    })}
                    hrefFor={fundingHref}
                    onOpen={(row) => router.visit(fundingHref(row))}
                    actionsFor={actions}
                    onRowContextMenu={(event, row) => menu.open(event, row)}
                    rowHeight="content"
                    minWidth={900}
                    columns={[
                        {
                            key: 'status',
                            label: 'Funding status',
                            width: '1.1fr',
                            cell: (row) => <StatusBadge status={row.status} />,
                        },
                        {
                            key: 'stage',
                            label: 'Referral stage',
                            width: '1fr',
                            cell: (row) => (
                                <ReferralStageBadge
                                    stage={row.referral.stage}
                                    stages={stages}
                                />
                            ),
                        },
                        {
                            key: 'navigator',
                            label: 'Navigator',
                            width: '1.2fr',
                            cell: (row) => (
                                <PersonCell name={row.navigator_name} />
                            ),
                        },
                        {
                            key: 'next',
                            label: 'Next funding action',
                            width: '1.8fr',
                            cell: (row) => (
                                <div className="grid min-w-0 gap-1">
                                    <span className="break-words">
                                        {row.next_action ?? (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </span>
                                    {due(row)}
                                </div>
                            ),
                        },
                    ]}
                />
            ) : (
                <EntityCardGrid>
                    {page.data.map((row) => (
                        <EntityCard
                            key={row.id}
                            meridian={row.is_overdue ? 'warning' : 'success'}
                            mark={
                                <PersonDisc
                                    name={row.referral.name}
                                    size={40}
                                />
                            }
                            name={row.referral.name}
                            href={fundingHref(row)}
                            linkLabel={`Open funding for ${row.referral.name}`}
                            subline={row.pathway_label}
                            actions={actions(row)}
                            onContextMenu={(event) => menu.open(event, row)}
                            chips={
                                <>
                                    <StatusBadge status={row.status} />
                                    <EntityChip>
                                        {row.referral.site_name ??
                                            'Central intake'}
                                    </EntityChip>
                                </>
                            }
                            alerts={
                                <>
                                    {due(row)}
                                    {row.next_action && (
                                        <span className="w-full text-sm break-words">
                                            {row.next_action}
                                        </span>
                                    )}
                                </>
                            }
                            footer={{
                                personName: row.navigator_name,
                                primary:
                                    row.navigator_name ?? 'No navigator yet',
                                secondary: row.referral.reference,
                            }}
                        />
                    ))}
                </EntityCardGrid>
            )}
            <LaravelPagination links={page.links} lastPage={page.last_page} />
            {menu.ctx && (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    title={menu.ctx.record.referral.name}
                    items={actions(menu.ctx.record)}
                    onClose={menu.close}
                />
            )}
        </div>
    );
}
