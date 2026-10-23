/**
 * The drawings' table block (pg-hsn-1, 1b, 1c, 2b, 2e, 3): a plain card with a
 * head (title, one sub-line, actions on the right), the table, and an optional
 * footer (count + pages). Identity cell first, kebab last; the row opens its
 * record and right-click opens the same actions as the kebab (one MenuItem[],
 * LIST_STYLE_GUIDE.md §1). Built from components/ui/table.tsx and the shared
 * list menus — page-group local until the shared kit carries a table surface.
 */
import {
    EntityContextMenu,
    EntityKebab,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { ComponentType, ReactNode } from 'react';

export interface RecordTableColumn<T> {
    key: string;
    label: string;
    cell: (row: T) => ReactNode;
    /** Keep the cell on one line (dates, people, badges). */
    nowrap?: boolean;
    className?: string;
}

export function RecordTable<T>({
    title,
    sub,
    actions,
    ariaLabel,
    columns,
    rows,
    rowKey,
    onOpen,
    canOpen,
    actionsFor,
    menuTitle,
    menuIcon,
    empty,
    footer,
    children,
    className,
    id,
}: {
    title?: string;
    sub?: ReactNode;
    /** Outline buttons on the right of the head. */
    actions?: ReactNode;
    ariaLabel?: string;
    columns: RecordTableColumn<T>[];
    rows: T[];
    rowKey: (row: T) => string | number;
    onOpen?: (row: T) => void;
    /** Rows that may not open (a restricted, need-to-know row) stay plain: no pointer, no focus, no click. */
    canOpen?: (row: T) => boolean;
    /** The one action list behind both the kebab and the right-click menu. */
    actionsFor?: (row: T) => MenuItem[];
    /** Names the record in the right-click menu and the kebab label. */
    menuTitle?: (row: T) => string;
    menuIcon?: ComponentType<{ className?: string }>;
    /** Shown in place of the table when there are no rows. */
    empty?: ReactNode;
    /** Under the table — the count and pages. */
    footer?: ReactNode;
    /** Between the head and the table (an inline form). */
    children?: ReactNode;
    className?: string;
    id?: string;
}) {
    const menu = useEntityContextMenu<T>();
    const hasActions = Boolean(actionsFor);
    return (
        <section
            id={id}
            aria-label={ariaLabel ?? title}
            className={cn(
                'min-w-0 overflow-hidden rounded-[14px] border border-border bg-card text-card-foreground shadow-xs',
                className,
            )}
        >
            {title ? (
                <div className="flex flex-wrap items-start gap-x-3 gap-y-2 border-b border-border px-4 py-3.5">
                    <div className="grid min-w-0 flex-[1_1_180px] gap-0.5">
                        <h2 className="text-section-title">{title}</h2>
                        {sub ? <p className="text-subtle">{sub}</p> : null}
                    </div>
                    {actions ? (
                        <div className="flex shrink-0 flex-wrap items-center gap-2">
                            {actions}
                        </div>
                    ) : null}
                </div>
            ) : null}
            {children}
            {rows.length === 0 ? (
                (empty ?? null)
            ) : (
                <Table className="text-sm">
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            {columns.map((column) => (
                                <TableHead
                                    key={column.key}
                                    className="text-caption h-auto bg-muted px-3.5 py-2.5 font-semibold tracking-wide uppercase"
                                >
                                    {column.label}
                                </TableHead>
                            ))}
                            {hasActions ? (
                                <TableHead className="h-auto w-12 bg-muted px-2 py-2.5">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            ) : null}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => {
                            const items = actionsFor?.(row) ?? [];
                            const name = menuTitle?.(row) ?? '';
                            const open = onOpen && (canOpen?.(row) ?? true) ? onOpen : undefined;
                            return (
                                <TableRow
                                    key={rowKey(row)}
                                    tabIndex={open ? 0 : undefined}
                                    onClick={open ? () => open(row) : undefined}
                                    onKeyDown={
                                        open
                                            ? (event) => {
                                                  if (event.target !== event.currentTarget) return;
                                                  if (event.key === 'Enter' || event.key === ' ') {
                                                      event.preventDefault();
                                                      open(row);
                                                  }
                                              }
                                            : undefined
                                    }
                                    onContextMenu={
                                        items.length ? (event) => menu.open(event, row) : undefined
                                    }
                                    className={cn(
                                        'outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                                        open ? 'cursor-pointer hover:bg-primary/5' : 'hover:bg-transparent',
                                    )}
                                >
                                    {columns.map((column) => (
                                        <TableCell
                                            key={column.key}
                                            className={cn(
                                                'px-3.5 py-2.5 align-middle',
                                                column.nowrap ? 'whitespace-nowrap' : 'whitespace-normal',
                                                column.className,
                                            )}
                                        >
                                            {column.cell(row)}
                                        </TableCell>
                                    ))}
                                    {hasActions ? (
                                        <TableCell className="w-12 px-2 py-2.5 text-right">
                                            <EntityKebab
                                                actions={items}
                                                label={`Actions for ${name}`}
                                            />
                                        </TableCell>
                                    ) : null}
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            )}
            {footer ? (
                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-3.5 py-2.5">
                    {footer}
                </div>
            ) : null}
            {menu.ctx && actionsFor ? (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    icon={menuIcon}
                    title={menuTitle?.(menu.ctx.record) ?? 'Actions'}
                    items={actionsFor(menu.ctx.record)}
                    onClose={menu.close}
                />
            ) : null}
        </section>
    );
}

/** Identity cell: a bold title and one quieter line. */
export function RecordIdentity({
    title,
    sub,
    leading,
}: {
    title: ReactNode;
    sub?: ReactNode;
    leading?: ReactNode;
}) {
    return (
        <div className="flex min-w-0 items-center gap-2.5">
            {leading ? (
                <span className="flex size-[30px] shrink-0 items-center justify-center rounded-[8px] bg-primary/10 text-primary">
                    {leading}
                </span>
            ) : null}
            <div className="min-w-0">
                <b className="block font-semibold text-foreground">{title}</b>
                {sub ? <span className="text-subtle block">{sub}</span> : null}
            </div>
        </div>
    );
}

/** A person in a table cell: initials in the tint, then the name. */
export function RecordPerson({ name }: { name: string }) {
    const initials =
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0])
            .join('')
            .toUpperCase() || '?';
    return (
        <span className="inline-flex items-center gap-2 font-medium text-foreground">
            <span
                aria-hidden="true"
                className="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary"
            >
                {initials}
            </span>
            {name}
        </span>
    );
}
