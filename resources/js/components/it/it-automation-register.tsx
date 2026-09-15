import {
    EntityCard,
    EntityCardGrid,
    type EntityMeridian,
} from '@/components/lists/entity-card';
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import {
    EntityTable,
    type EntityTableColumn,
} from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import type { LucideIcon } from 'lucide-react';
import type { MouseEvent as ReactMouseEvent, ReactNode } from 'react';

/**
 * One register shell for the Setup → Automation sub-tabs, on the shared list
 * contract: count caption, Cards/Table honouring the header toggle, kebab +
 * right-click actions on every row, honest empty states.
 */
export function ItAutomationRegister<T extends { id: number; name: string }>({
    title,
    rows,
    total,
    layout,
    icon,
    subline,
    chips,
    alerts,
    meridian,
    muted,
    footer,
    columns,
    actionsFor,
    onOpen,
    emptyCopy,
}: {
    title: string;
    rows: T[];
    total: number;
    layout: 'cards' | 'table';
    icon: LucideIcon;
    subline: (row: T) => ReactNode;
    chips: (row: T) => ReactNode;
    alerts?: (row: T) => ReactNode;
    meridian: (row: T) => EntityMeridian;
    muted: (row: T) => boolean;
    footer: (row: T) => {
        primary: ReactNode;
        secondary?: ReactNode;
        personName?: string | null;
    };
    columns: EntityTableColumn<T>[];
    actionsFor: (row: T) => MenuItem[];
    onOpen: (row: T) => void;
    emptyCopy: string;
}) {
    const context = useEntityContextMenu<number>();
    const current = rows.find((row) => row.id === context.ctx?.record);
    const openContext = (event: ReactMouseEvent, row: T) => {
        if (actionsFor(row).length === 0) return;
        context.open(event, row.id);
    };

    return (
        <section className="space-y-4" aria-label={title}>
            <ListCaption
                title={title}
                caption={`${rows.length} of ${total} shown`}
            />
            {rows.length === 0 ? (
                <div className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                    {total === 0
                        ? emptyCopy
                        : 'No matching records. Clear or change the search.'}
                </div>
            ) : layout === 'table' ? (
                <EntityTable
                    rows={rows}
                    rowKey={(row) => row.id}
                    identity={(row) => ({
                        name: row.name,
                        icon,
                        subline: subline(row),
                    })}
                    columns={columns}
                    actionsFor={actionsFor}
                    onOpen={onOpen}
                    onRowContextMenu={openContext}
                    mutedFor={muted}
                />
            ) : (
                <EntityCardGrid>
                    {rows.map((row) => (
                        <EntityCard
                            className="[&_h3]:overflow-visible [&_h3]:break-words [&_h3]:text-clip [&_h3]:whitespace-normal"
                            key={row.id}
                            name={row.name}
                            icon={icon}
                            subline={subline(row)}
                            meridian={meridian(row)}
                            muted={muted(row)}
                            actions={actionsFor(row)}
                            onOpen={() => onOpen(row)}
                            openLabel="Edit"
                            onContextMenu={(event) => openContext(event, row)}
                            chips={chips(row)}
                            alerts={alerts?.(row)}
                            footer={footer(row)}
                        />
                    ))}
                </EntityCardGrid>
            )}
            {current && context.ctx && (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    title={current.name}
                    icon={icon}
                    items={actionsFor(current)}
                    onClose={context.close}
                />
            )}
        </section>
    );
}
