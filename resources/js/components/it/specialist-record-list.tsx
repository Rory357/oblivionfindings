import { EntityCard, EntityCardGrid } from '@/components/lists/entity-card';
import { EntityChip, EntityStatusChip } from '@/components/lists/entity-cells';
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import {
    EntityTable,
    type EntityTableProps,
} from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Card, CardContent } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import type { StatusVariant } from '@/components/ui/status-badge';
import { router, usePage } from '@inertiajs/react';
import { ArrowRight, Copy, type LucideIcon } from 'lucide-react';
import { toast } from 'sonner';

export interface SpecialistListItem {
    id: number;
    reference: string;
    title: string;
    href: string;
    state: { label: string; tone: StatusVariant };
    priority: string;
    impact: string;
    facts: { label: string; value: string }[];
    owner?: string | null;
    alerts?: { label: string; tone: 'critical' | 'warning' }[];
}

export function SpecialistRecordList({
    title,
    icon,
    rows,
    total,
    links,
    selection,
    extraActions,
}: {
    title: string;
    icon: LucideIcon;
    rows: SpecialistListItem[];
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
    selection?: EntityTableProps<SpecialistListItem>['selection'];
    /** Record-specific actions appended after the shared open/copy entries (kebab and context menu alike). */
    extraActions?: (row: SpecialistListItem) => MenuItem[];
}) {
    const { url } = usePage();
    const view =
        new URL(url, 'http://local.invalid').searchParams.get('list_view') ===
        'cards'
            ? 'cards'
            : 'table';
    const menu = useEntityContextMenu<number>();
    const current = menu.ctx
        ? rows.find((row) => row.id === menu.ctx?.record)
        : null;
    const open = (row: SpecialistListItem) => router.visit(row.href);
    const actions = (row: SpecialistListItem): MenuItem[] => [
        { label: 'Open record', icon: ArrowRight, onClick: () => open(row) },
        {
            label: 'Copy record link',
            icon: Copy,
            onClick: () => {
                void navigator.clipboard
                    .writeText(new URL(row.href, window.location.origin).href)
                    .then(() => toast.success('Record link copied.'))
                    .catch(() =>
                        toast.error(
                            'The link could not be copied. Open the record and copy its address.',
                        ),
                    );
            },
        },
        ...(extraActions?.(row) ?? []),
    ];
    const status = (row: SpecialistListItem) => (
        <EntityStatusChip variant={row.state.tone}>
            {row.state.label}
        </EntityStatusChip>
    );
    const priority = (row: SpecialistListItem) => (
        <EntityStatusChip
            variant={
                ['urgent', 'high'].includes(row.priority.toLowerCase())
                    ? 'critical'
                    : 'neutral'
            }
        >
            {row.priority}
        </EntityStatusChip>
    );
    return (
        <section aria-label={title} className="space-y-4">
            <ListCaption
                title={title}
                caption={`${rows.length} of ${total} shown`}
            />
            {rows.length === 0 ? (
                <Card>
                    <CardContent className="px-6 py-12 text-center">
                        <p className="font-medium">No matching records</p>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Try another search or filter.
                        </p>
                    </CardContent>
                </Card>
            ) : view === 'table' ? (
                <EntityTable
                    rows={rows}
                    selection={selection}
                    rowKey={(row) => row.id}
                    identityLabel="Record"
                    identity={(row) => ({
                        icon,
                        name: row.title,
                        subline: `${row.reference} · ${row.impact}`,
                    })}
                    hrefFor={(row) => row.href}
                    onOpen={open}
                    actionsFor={actions}
                    onRowContextMenu={(event, row) => menu.open(event, row.id)}
                    columns={[
                        {
                            key: 'state',
                            label: 'Workflow',
                            width: '1fr',
                            cell: status,
                        },
                        {
                            key: 'priority',
                            label: 'Priority',
                            width: '88px',
                            cell: priority,
                        },
                        {
                            key: 'details',
                            label: 'Details',
                            width: '1.8fr',
                            cell: (row) => (
                                <div className="space-y-1 py-2">
                                    {row.facts.map((fact) => (
                                        <p
                                            key={fact.label}
                                            className="text-caption"
                                        >
                                            <span className="font-medium text-foreground">
                                                {fact.label}:{' '}
                                            </span>
                                            {fact.value}
                                        </p>
                                    ))}
                                </div>
                            ),
                        },
                        {
                            key: 'attention',
                            label: 'Needs attention',
                            width: '1fr',
                            cell: (row) =>
                                row.alerts?.length ? (
                                    <div className="space-y-1">
                                        {row.alerts.map((alert) => (
                                            <EntityStatusChip
                                                key={alert.label}
                                                variant={alert.tone}
                                            >
                                                {alert.label}
                                            </EntityStatusChip>
                                        ))}
                                    </div>
                                ) : (
                                    <span className="text-caption">—</span>
                                ),
                        },
                    ]}
                />
            ) : (
                <EntityCardGrid>
                    {rows.map((row) => (
                        <EntityCard
                            key={row.id}
                            icon={icon}
                            name={row.title}
                            subline={row.reference}
                            href={row.href}
                            selection={
                                selection
                                    ? {
                                          checked: selection.keys.has(row.id),
                                          label: selection.labelFor(row),
                                          onToggle: (checked) =>
                                              selection.onToggle(row, checked),
                                          disabled:
                                              selection.disabled ||
                                              (selection.canSelect
                                                  ? !selection.canSelect(row)
                                                  : false),
                                      }
                                    : undefined
                            }
                            onOpen={() => open(row)}
                            actions={actions(row)}
                            onContextMenu={(event) => menu.open(event, row.id)}
                            meridian={
                                row.alerts?.some(
                                    (alert) => alert.tone === 'critical',
                                )
                                    ? 'critical'
                                    : row.alerts?.length
                                      ? 'warning'
                                      : 'success'
                            }
                            chips={
                                <>
                                    {status(row)}
                                    {priority(row)}
                                    {row.facts.map((fact) => (
                                        <EntityChip key={fact.label}>
                                            {fact.label}: {fact.value}
                                        </EntityChip>
                                    ))}
                                </>
                            }
                            alerts={row.alerts?.map((alert) => (
                                <EntityStatusChip
                                    key={alert.label}
                                    variant={alert.tone}
                                >
                                    {alert.label}
                                </EntityStatusChip>
                            ))}
                            footer={{
                                personName: row.owner,
                                primary: row.owner ?? row.reference,
                                secondary: row.impact,
                            }}
                        />
                    ))}
                </EntityCardGrid>
            )}
            <LaravelPagination links={links} preserveScroll />
            {current && menu.ctx && (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    title={current.reference}
                    icon={icon}
                    items={actions(current)}
                    onClose={menu.close}
                />
            )}
        </section>
    );
}
