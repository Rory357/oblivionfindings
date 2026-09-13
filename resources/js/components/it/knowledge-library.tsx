import { EntityCard, EntityCardGrid } from '@/components/lists/entity-card';
import {
    EntityChip,
    EntityStatusChip,
    PersonCell,
} from '@/components/lists/entity-cells';
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
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { formatDateOnly } from '@/lib/datetime';
import { BookOpen } from 'lucide-react';
import type { ReactNode } from 'react';
import { KNOWLEDGE_DOCUMENT_TYPES } from './knowledge-document';

export interface KnowledgePage {
    total: number;
    from: number | null;
    to: number | null;
    current_page: number;
    last_page: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface KnowledgeListRow {
    id: number;
    title: string;
    category: string;
    document_type?: string;
    status?: string;
    owner?: string | null;
    related_service?: string | null;
    review_due_at?: string | null;
    review_overdue?: boolean;
    updated?: string | null;
    working_copy?: { status: string } | null;
    tags?: string[];
}

const titleCase = (value: string) =>
    value.replaceAll('_', ' ').replace(/^\w/, (letter) => letter.toUpperCase());
const typeLabel = (row: KnowledgeListRow) =>
    KNOWLEDGE_DOCUMENT_TYPES.find((type) => type.value === row.document_type)
        ?.label ?? 'Guide';
const status = (row: KnowledgeListRow) => (
    <EntityStatusChip
        variant={
            row.status === 'published' || !row.status
                ? 'success'
                : row.status === 'in_review'
                  ? 'warning'
                  : 'neutral'
        }
    >
        {titleCase(row.status ?? 'published')}
    </EntityStatusChip>
);

/** One current, authorised row set and action list drive Cards and Table. */
export function KnowledgeLibrary<T extends KnowledgeListRow>({
    rows,
    page,
    view,
    governance,
    actionsFor,
    hrefFor,
    onOpen,
    emptyState,
    paginationDisabled = false,
}: {
    rows: T[];
    page?: KnowledgePage | null;
    view: 'cards' | 'table';
    governance: boolean;
    actionsFor: (row: T) => MenuItem[];
    hrefFor: (row: T) => string;
    onOpen: (row: T) => void;
    emptyState: ReactNode;
    paginationDisabled?: boolean;
}) {
    const menu = useEntityContextMenu<number>();
    const selected = menu.ctx
        ? rows.find((row) => row.id === menu.ctx?.record)
        : undefined;
    const columns: EntityTableColumn<T>[] = [
        { key: 'type', label: 'Document type', width: '1fr', cell: typeLabel },
        {
            key: 'publication',
            label: 'Publication',
            width: '1fr',
            cell: (row) => (
                <div className="space-y-1">
                    {status(row)}
                    {row.working_copy && (
                        <div className="text-caption">
                            Proposal: {titleCase(row.working_copy.status)}
                        </div>
                    )}
                </div>
            ),
        },
        ...(governance
            ? [
                  {
                      key: 'owner',
                      label: 'Review owner',
                      width: '1.2fr',
                      cell: (row: T) => <PersonCell name={row.owner} />,
                  },
              ]
            : []),
        {
            key: 'service',
            label: 'Service',
            width: '1.1fr',
            cell: (row) => row.related_service ?? 'Not linked',
        },
        {
            key: 'review',
            label: 'Review due',
            width: '1fr',
            cell: (row) => (
                <span
                    className={
                        row.review_overdue
                            ? 'font-medium text-status-warning'
                            : 'text-muted-foreground'
                    }
                >
                    {formatDateOnly(row.review_due_at, 'Not scheduled')}
                    {row.review_overdue && (
                        <span className="text-caption block">
                            Review overdue
                        </span>
                    )}
                </span>
            ),
        },
    ];

    return (
        <div className="space-y-4">
            <ListCaption
                title={
                    governance ? 'Documentation library' : 'Published guides'
                }
                caption={
                    page
                        ? `${page.from ?? 0}–${page.to ?? 0} of ${page.total} shown`
                        : `${rows.length} shown`
                }
            />
            {rows.length === 0 ? (
                emptyState
            ) : view === 'cards' ? (
                <EntityCardGrid>
                    {rows.map((row) => (
                        <EntityCard
                            key={row.id}
                            icon={BookOpen}
                            name={row.title}
                            subline={`${typeLabel(row)} · ${titleCase(row.category)}`}
                            meridian={
                                row.review_overdue ? 'warning' : 'success'
                            }
                            href={hrefFor(row)}
                            onOpen={() => onOpen(row)}
                            linkLabel={`Read ${row.title}`}
                            actions={actionsFor(row)}
                            onContextMenu={(event) => menu.open(event, row.id)}
                            muted={row.status === 'retired'}
                            chips={
                                <>
                                    {status(row)}
                                    {row.tags?.map((tag) => (
                                        <EntityChip key={tag}>{tag}</EntityChip>
                                    ))}
                                    {row.related_service && (
                                        <EntityChip>
                                            {row.related_service}
                                        </EntityChip>
                                    )}
                                    {row.working_copy && (
                                        <EntityChip>
                                            Proposal:{' '}
                                            {titleCase(row.working_copy.status)}
                                        </EntityChip>
                                    )}
                                </>
                            }
                            alerts={
                                row.review_overdue ? (
                                    <EntityStatusChip variant="warning">
                                        Review overdue
                                    </EntityStatusChip>
                                ) : undefined
                            }
                            footer={{
                                personName: governance ? row.owner : null,
                                primary: governance
                                    ? (row.owner ?? 'Review owner not set')
                                    : (row.related_service ??
                                      'Support guidance'),
                                secondary: row.review_due_at
                                    ? `Review due ${formatDateOnly(row.review_due_at)}`
                                    : 'Review not scheduled',
                            }}
                        />
                    ))}
                </EntityCardGrid>
            ) : (
                <EntityTable
                    rows={rows}
                    rowKey={(row) => row.id}
                    identityLabel="Document"
                    identity={(row) => ({
                        icon: BookOpen,
                        name: row.title,
                        subline: titleCase(row.category),
                    })}
                    columns={columns}
                    actionsFor={actionsFor}
                    hrefFor={hrefFor}
                    onOpen={onOpen}
                    onRowContextMenu={(event, row) => menu.open(event, row.id)}
                    mutedFor={(row) => row.status === 'retired'}
                />
            )}
            {page && (
                <fieldset
                    disabled={paginationDisabled}
                    aria-busy={paginationDisabled}
                    aria-label="Library pages"
                    className="m-0 min-w-0 border-0 p-0"
                >
                    <LaravelPagination
                        links={page.links}
                        lastPage={page.last_page}
                        preserveScroll
                    />
                </fieldset>
            )}
            {selected && menu.ctx && (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    title={selected.title}
                    icon={BookOpen}
                    items={actionsFor(selected)}
                    onClose={menu.close}
                />
            )}
        </div>
    );
}
