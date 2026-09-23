import {
    EntityCard,
    EntityCardGrid,
    type EntityCardProps,
} from '@/components/lists/entity-card';
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { LayoutGrid, List } from 'lucide-react';
import { useState, type ReactNode } from 'react';

type View = 'list' | 'cards';
const read = (scope: string): View => {
    try {
        return localStorage.getItem(`pkg02b-v12-layout-${scope}`) === 'cards'
            ? 'cards'
            : 'list';
    } catch {
        return 'list';
    }
};
export function useCollectionView(scope: string) {
    const [choice, setChoice] = useState(() => ({ scope, view: read(scope) }));
    const view = choice.scope === scope ? choice.view : read(scope);
    const setView = (view: View) => {
        setChoice({ scope, view });
        try {
            localStorage.setItem(`pkg02b-v12-layout-${scope}`, view);
        } catch {
            /* In-memory choice still works. */
        }
    };
    return { view, setView };
}
export function CollectionToggle({
    label,
    view,
    setView,
}: {
    label: string;
    view: View;
    setView: (view: View) => void;
}) {
    return (
        <div
            className="collection-toggle"
            role="group"
            aria-label={`${label} layout`}
        >
            <button
                type="button"
                aria-pressed={view === 'list'}
                onClick={() => setView('list')}
            >
                <List size={16} />
                List
            </button>
            <button
                type="button"
                aria-pressed={view === 'cards'}
                onClick={() => setView('cards')}
            >
                <LayoutGrid size={16} />
                Cards
            </button>
        </div>
    );
}
export type CollectionRecord = {
    id: string;
    name: string;
    subline?: ReactNode;
    icon?: EntityCardProps['icon'];
    mark?: ReactNode;
    alert?: EntityCardProps['meridian'];
    fields: ReactNode[];
    actions: MenuItem[];
    open: () => void;
    footer?: EntityCardProps['footer'];
};
export function RecordCollection({
    label,
    view,
    records,
    columns,
    empty,
    identityWidth = '1.5fr',
}: {
    label: string;
    view: View;
    records: CollectionRecord[];
    columns: { label: string; width?: string }[];
    empty?: ReactNode;
    identityWidth?: string;
}) {
    const menu = useEntityContextMenu<CollectionRecord>();
    return (
        <div
            className="record-collection"
            data-record-view={view}
            aria-label={label}
        >
            <div className="collection-caption">
                <span>{label}</span>
                <span>
                    {records.length}{' '}
                    {records.length === 1 ? 'record' : 'records'} shown
                </span>
            </div>
            {!records.length ? (
                <div className="collection-empty">
                    {empty || 'No records to show.'}
                </div>
            ) : view === 'list' ? (
                <EntityTable
                    rows={records}
                    rowKey={(r) => r.id}
                    identityLabel="Record"
                    identityWidth={identityWidth}
                    identity={(r) => ({
                        name: r.name,
                        subline: r.subline,
                        icon: r.icon,
                        mark: r.mark,
                    })}
                    columns={columns.map((c, i) => ({
                        key: String(i),
                        label: c.label,
                        width: c.width || '1fr',
                        cell: (r: CollectionRecord) => (
                            <span
                                className="collection-field"
                                onClick={(e) => {
                                    if (
                                        (e.target as HTMLElement).closest(
                                            'button,a,input',
                                        )
                                    )
                                        e.stopPropagation();
                                }}
                            >
                                <span className="collection-field-label">
                                    {c.label}
                                </span>
                                <span>{r.fields[i]}</span>
                            </span>
                        ),
                    }))}
                    actionsFor={(r) => r.actions}
                    onOpen={(r) => r.open()}
                    onRowContextMenu={menu.open}
                    minWidth={0}
                    className="collection-table"
                />
            ) : (
                <EntityCardGrid className="collection-cards">
                    {records.map((r) => (
                        <EntityCard
                            key={r.id}
                            name={r.name}
                            subline={r.subline}
                            icon={r.icon}
                            mark={r.mark}
                            meridian={r.alert || 'success'}
                            actions={r.actions}
                            onOpen={r.open}
                            onContextMenu={(e) => menu.open(e, r)}
                            className={
                                !r.alert
                                    ? 'collection-card no-alert-meridian'
                                    : 'collection-card'
                            }
                            chips={
                                <dl className="collection-facts">
                                    {columns.map((c, i) => (
                                        <div key={c.label}>
                                            <dt>{c.label}</dt>
                                            <dd
                                                onClick={(e) => {
                                                    if (
                                                        (
                                                            e.target as HTMLElement
                                                        ).closest(
                                                            'button,a,input',
                                                        )
                                                    )
                                                        e.stopPropagation();
                                                }}
                                            >
                                                {r.fields[i]}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            }
                            footer={
                                r.footer || {
                                    primary: 'Record details',
                                    secondary: 'Open to view and follow up',
                                }
                            }
                        />
                    ))}
                </EntityCardGrid>
            )}
            {menu.ctx && (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    icon={menu.ctx.record.icon}
                    title={menu.ctx.record.name}
                    items={menu.ctx.record.actions}
                    onClose={menu.close}
                />
            )}
        </div>
    );
}
