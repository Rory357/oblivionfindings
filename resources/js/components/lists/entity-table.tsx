/* eslint-disable no-restricted-syntax -- The table is THE canonical list
 * table shell (LIST_STYLE_GUIDE.md §3): a grid-row equivalent of
 * ui/table.tsx with table semantics, bound to semantic tokens. Widths in
 * fr need CSS grid, which the <table> element can't express. */
/**
 * The Event Horizon entity table (design_styles/LIST_STYLE_GUIDE.md §3).
 * One reusable shell for EVERY table listable in the project, configured
 * per entity by a column spec — never restyled per page:
 *
 *   - identity cell always FIRST, kebab cell always LAST (32px)
 *   - 32px muted header row, 50px rows, hairlines, brand-tint hover
 *   - every other column picks from the cell library (entity-cells.tsx)
 *   - row click opens the record; kebab + right-click carry the actions
 *     (one MenuItem[] — entity-menu.tsx)
 *
 * Wide tables scroll inside their own overflow-x-auto — the page body
 * never scrolls horizontally.
 */
import { Check } from 'lucide-react';
import type { ComponentType, MouseEvent, ReactNode } from 'react';

import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

import { EntityKebab, type MenuItem } from './entity-menu';

type IconType = ComponentType<{ className?: string }>;

export interface EntityTableColumn<T> {
    key: string;
    label: string;
    /** CSS grid track — an fr value ('1.2fr') or fixed ('96px'). */
    width: string;
    align?: 'left' | 'right' | 'center';
    cell: (row: T) => ReactNode;
}

export interface EntityTableIdentity {
    icon?: IconType;
    /** Custom 30px mark (person avatar) — wins over `icon`. */
    mark?: ReactNode;
    name: string;
    subline?: ReactNode;
    /** Small extra rendered after the name (e.g. a signal dot). */
    extra?: ReactNode;
}

export interface EntityTableProps<T> {
    rows: T[];
    rowKey: (row: T) => string | number;
    /** Identity column config — always the first cell. */
    identity: (row: T) => EntityTableIdentity;
    identityLabel?: string;
    identityWidth?: string;
    columns: EntityTableColumn<T>[];
    /** The one MenuItem[] feeding kebab AND context menu. */
    actionsFor: (row: T) => MenuItem[];
    onOpen?: (row: T) => void;
    onRowContextMenu?: (e: MouseEvent, row: T) => void;
    /** Dim archived/inactive rows. */
    mutedFor?: (row: T) => boolean;
    /** Horizontal scroll threshold for the inner grid. */
    minWidth?: number;
    /* Multi-select support (page-owned state). */
    selectMode?: boolean;
    selectedKeys?: ReadonlySet<string | number>;
    onToggleSelect?: (row: T) => void;
    className?: string;
}

const ALIGN: Record<
    NonNullable<EntityTableColumn<unknown>['align']>,
    string
> = {
    left: 'justify-start text-left',
    right: 'justify-end text-right',
    center: 'justify-center text-center',
};

export function EntityTable<T>({
    rows,
    rowKey,
    identity,
    identityLabel = 'Name',
    identityWidth = '1.9fr',
    columns,
    actionsFor,
    onOpen,
    onRowContextMenu,
    mutedFor,
    minWidth = 900,
    selectMode = false,
    selectedKeys,
    onToggleSelect,
    className,
}: EntityTableProps<T>) {
    const template = `${identityWidth} ${columns
        .map((c) => c.width)
        .join(' ')} 32px`;

    return (
        <Card
            className={cn(
                'gap-0 overflow-hidden rounded-[14px] py-0',
                className,
            )}
        >
            <div className="scrollbar-pretty overflow-x-auto">
                <div role="table" style={{ minWidth }}>
                    {/* header row */}
                    <div
                        role="row"
                        className="grid h-8 items-center border-b border-border bg-muted/60"
                        style={{ gridTemplateColumns: template }}
                    >
                        <span
                            role="columnheader"
                            className="px-3 text-[10px] font-semibold tracking-[0.06em] text-muted-foreground uppercase"
                        >
                            {identityLabel}
                        </span>
                        {columns.map((c) => (
                            <span
                                key={c.key}
                                role="columnheader"
                                className={cn(
                                    'flex px-3 text-[10px] font-semibold tracking-[0.06em] text-muted-foreground uppercase',
                                    ALIGN[c.align ?? 'left'],
                                )}
                            >
                                {c.label}
                            </span>
                        ))}
                        <span role="columnheader" aria-label="Actions" />
                    </div>

                    {/* data rows */}
                    {rows.map((row) => {
                        const key = rowKey(row);
                        const id = identity(row);
                        const selected = selectedKeys?.has(key) ?? false;
                        const muted = mutedFor?.(row) ?? false;
                        const Icon = id.icon;
                        return (
                            <div
                                key={key}
                                role="row"
                                tabIndex={0}
                                onClick={() =>
                                    selectMode
                                        ? onToggleSelect?.(row)
                                        : onOpen?.(row)
                                }
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' || e.key === ' ') {
                                        e.preventDefault();
                                        if (selectMode) onToggleSelect?.(row);
                                        else onOpen?.(row);
                                    }
                                }}
                                onContextMenu={(e) =>
                                    onRowContextMenu?.(e, row)
                                }
                                className={cn(
                                    'grid h-[50px] cursor-pointer items-center border-b border-border transition-colors outline-none last:border-b-0 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                                    selected
                                        ? 'bg-primary/[0.11] hover:bg-primary/[0.11]'
                                        : 'hover:bg-primary/5',
                                    muted && 'opacity-75',
                                )}
                                style={{ gridTemplateColumns: template }}
                            >
                                {/* identity cell */}
                                <span
                                    role="cell"
                                    className="flex min-w-0 items-center gap-2.5 px-3"
                                >
                                    {selectMode ? (
                                        <span
                                            aria-hidden="true"
                                            className={cn(
                                                'flex h-5 w-5 shrink-0 items-center justify-center rounded-[6px] border transition-colors',
                                                selected
                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                    : 'border-input bg-card text-transparent',
                                            )}
                                        >
                                            <Check className="size-3" />
                                        </span>
                                    ) : (
                                        (id.mark ??
                                        (Icon ? (
                                            <span className="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[8px] bg-primary/15 text-primary">
                                                <Icon className="size-4" />
                                            </span>
                                        ) : null))
                                    )}
                                    <span className="min-w-0">
                                        <span className="flex items-center gap-1.5">
                                            <span className="truncate text-[13px] font-semibold text-foreground">
                                                {id.name}
                                            </span>
                                            {id.extra}
                                        </span>
                                        {id.subline ? (
                                            <span className="block truncate text-[11.5px] text-muted-foreground">
                                                {id.subline}
                                            </span>
                                        ) : null}
                                    </span>
                                </span>

                                {columns.map((c) => (
                                    <span
                                        key={c.key}
                                        role="cell"
                                        className={cn(
                                            'flex min-w-0 items-center px-3 text-[12.5px]',
                                            ALIGN[c.align ?? 'left'],
                                        )}
                                    >
                                        {c.cell(row)}
                                    </span>
                                ))}

                                {/* kebab cell — always last */}
                                <span
                                    role="cell"
                                    onClick={(e) => e.stopPropagation()}
                                    className="flex items-center justify-end pr-1.5"
                                >
                                    {!selectMode ? (
                                        <EntityKebab
                                            actions={actionsFor(row)}
                                        />
                                    ) : null}
                                </span>
                            </div>
                        );
                    })}
                </div>
            </div>
        </Card>
    );
}
