import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import type { LucideIcon } from 'lucide-react';
import type { MouseEvent, ReactNode } from 'react';

/**
 * EA-217 (Lane C9): every row with a kebab also opens the same actions on
 * right-click (DESIGN.md: row kebab + right-click + row click, one
 * MenuItem[]). Rows with no actions keep the browser's own menu.
 */
export function useRowMenu<T>(
    actionsFor: (row: T) => MenuItem[],
    title: (row: T) => string,
    icon?: LucideIcon,
): {
    onRowContextMenu: (event: MouseEvent, row: T) => void;
    menu: ReactNode;
} {
    const context = useEntityContextMenu<T>();
    return {
        onRowContextMenu: (event, row) => {
            if (actionsFor(row).length > 0) context.open(event, row);
        },
        menu: context.ctx ? (
            <EntityContextMenu
                x={context.ctx.x}
                y={context.ctx.y}
                icon={icon}
                title={title(context.ctx.record)}
                items={actionsFor(context.ctx.record)}
                onClose={context.close}
            />
        ) : null,
    };
}
