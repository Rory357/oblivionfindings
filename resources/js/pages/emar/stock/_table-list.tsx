import { EntityCard } from '@/components/lists/entity-card';
import {
    EntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import {
    EntityTable,
    type EntityTableColumn,
} from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { Package } from 'lucide-react';
import { useState, type MouseEvent } from 'react';
import type { Pager } from './_types';

/** One set of facts and actions for phone cards and the desktop stock table. */
export function TableList<T extends { id: number }>({
    title,
    pager,
    name,
    subline,
    columns,
    actions,
    open,
}: {
    title: string;
    pager: Pager<T>;
    name: (row: T) => string;
    subline: (row: T) => string;
    columns: EntityTableColumn<T>[];
    actions: (row: T) => MenuItem[];
    open: (row: T) => void;
}) {
    const [context, setContext] = useState<{
        x: number;
        y: number;
        row: T;
    } | null>(null);
    const showMenu = (event: MouseEvent, row: T) => {
        event.preventDefault();
        setContext({ x: event.clientX, y: event.clientY, row });
    };
    return (
        <div className="grid min-w-0 gap-5">
            <ListCaption
                title={title}
                caption={pager.data.length + ' of ' + pager.total + ' shown'}
            />
            {pager.data.length ? (
                <>
                    <ul
                        className="grid min-w-0 gap-5 md:hidden"
                        aria-label={title + ' cards'}
                    >
                        {pager.data.map((row) => (
                            <li key={row.id} className="min-w-0">
                                <EntityCard
                                    meridian="neutral"
                                    icon={Package}
                                    name={name(row)}
                                    subline={subline(row)}
                                    className="min-w-0 [&_button]:min-h-11 [&_button]:min-w-11 [&_h3]:break-words [&_h3]:whitespace-normal"
                                    actions={actions(row)}
                                    onOpen={() => open(row)}
                                    onContextMenu={(event) =>
                                        showMenu(event, row)
                                    }
                                    chips={
                                        <dl className="grid w-full min-w-0 gap-2.5">
                                            {columns.map((column) => (
                                                <div
                                                    key={column.key}
                                                    className="min-w-0"
                                                >
                                                    <dt className="text-xs text-muted-foreground">
                                                        {column.label}
                                                    </dt>
                                                    <dd className="text-sm break-words">
                                                        {column.cell(row)}
                                                    </dd>
                                                </div>
                                            ))}
                                        </dl>
                                    }
                                    footer={{ primary: 'Stock record' }}
                                />
                            </li>
                        ))}
                    </ul>
                    <div className="hidden min-w-0 md:block">
                        <EntityTable
                            rows={pager.data}
                            rowKey={(row) => row.id}
                            identity={(row) => ({
                                name: name(row),
                                subline: subline(row),
                                icon: Package,
                            })}
                            columns={columns}
                            actionsFor={actions}
                            onOpen={open}
                            onRowContextMenu={showMenu}
                            rowHeight="content"
                            minWidth={860}
                        />
                    </div>
                </>
            ) : (
                <EmptyState
                    icon={Package}
                    title="Nothing to show"
                    description="No visible records match this view. Change the filters or open another stock view."
                />
            )}
            <LaravelPagination links={pager.links} lastPage={pager.last_page} />
            {context && (
                <EntityContextMenu
                    x={context.x}
                    y={context.y}
                    title={name(context.row)}
                    icon={Package}
                    items={actions(context.row)}
                    onClose={() => setContext(null)}
                />
            )}
        </div>
    );
}
