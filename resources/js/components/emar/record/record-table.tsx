import {
    EntityContextMenu,
    EntityTable,
    useEntityContextMenu,
    type EntityTableProps,
} from '@/components/lists';

/**
 * EA-217 (Lane C9): a record table whose rows open the same actions on
 * right-click as from their kebab (DESIGN.md: row kebab + right-click +
 * row click, one MenuItem[]). A row with no actions keeps the browser's
 * own menu.
 */
export function RecordTable<T>(props: EntityTableProps<T>) {
    const context = useEntityContextMenu<T>();
    const record = context.ctx?.record;
    return (
        <>
            <EntityTable
                {...props}
                onRowContextMenu={
                    props.onRowContextMenu ??
                    ((event, row) => {
                        if (props.actionsFor(row).length > 0)
                            context.open(event, row);
                    })
                }
            />
            {context.ctx && record !== undefined ? (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    icon={props.identity(record).icon}
                    title={props.identity(record).name}
                    items={props.actionsFor(record)}
                    onClose={context.close}
                />
            ) : null}
        </>
    );
}
