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
import { LayoutGrid, List, type LucideIcon } from 'lucide-react';
import { useState, type MouseEvent, type ReactNode } from 'react';
import './studio.css';
import './workspace.css';

export type VehicleCollectionView = 'list' | 'cards';

const STORAGE_PREFIX = 'vehicle-workspace.view.';

/** List or Cards, remembered per section in this browser when storage allows. */
export function useVehicleCollectionView(section: string) {
    const [view, setViewState] = useState<VehicleCollectionView>(() => {
        try {
            const saved = window.localStorage.getItem(STORAGE_PREFIX + section);
            return saved === 'cards' ? 'cards' : 'list';
        } catch {
            return 'list';
        }
    });
    const setView = (next: VehicleCollectionView) => {
        setViewState(next);
        try {
            window.localStorage.setItem(STORAGE_PREFIX + section, next);
        } catch {
            // Storage can be unavailable (private windows); the choice still applies now.
        }
    };
    return { view, setView };
}

/** The design's List / Cards segmented control. */
export function VehicleCollectionToggle({
    label,
    view,
    onChange,
}: {
    label: string;
    view: VehicleCollectionView;
    onChange: (view: VehicleCollectionView) => void;
}) {
    return (
        <div
            className="collection-toggle"
            role="group"
            aria-label={`${label} layout`}
        >
            {/* eslint-disable-next-line no-restricted-syntax -- Segment of the design's layout switch. */}
            <button
                type="button"
                aria-pressed={view === 'list'}
                onClick={() => onChange('list')}
            >
                <List className="size-4" />
                List
            </button>
            {/* eslint-disable-next-line no-restricted-syntax -- Segment of the design's layout switch. */}
            <button
                type="button"
                aria-pressed={view === 'cards'}
                onClick={() => onChange('cards')}
            >
                <LayoutGrid className="size-4" />
                Cards
            </button>
        </div>
    );
}

export type VehicleCollectionRecord = {
    id: string | number;
    name: string;
    subline?: ReactNode;
    icon?: EntityCardProps['icon'];
    mark?: ReactNode;
    fields: ReactNode[];
    actions: MenuItem[];
    onOpen?: () => void;
    /** An alert accent on the card; records without one show no accent. */
    tone?: EntityCardProps['meridian'];
    footer?: EntityCardProps['footer'];
};

/** Controls inside a field act on their own; they don't also open the record. */
function keepControlClicks(event: MouseEvent<HTMLElement>) {
    if ((event.target as HTMLElement).closest('button,a,input,select,textarea'))
        event.stopPropagation();
}

/** The design's record collection: one set of records as a list or as cards. */
export function VehicleRecordCollection({
    label,
    view,
    records,
    columns,
    empty,
    total,
    identityWidth = '1.5fr',
    minWidth = 0,
}: {
    label: string;
    view: VehicleCollectionView;
    records: VehicleCollectionRecord[];
    columns: Array<{ label: string; width?: string }>;
    empty: {
        /** Kept for callers; the design's empty state has no icon. */
        icon?: LucideIcon;
        title: string;
        description?: string;
        action?: ReactNode;
    };
    total?: number;
    identityWidth?: string;
    minWidth?: number;
}) {
    const menu = useEntityContextMenu<VehicleCollectionRecord>();
    return (
        <div
            className="record-collection min-w-0"
            data-record-view={view}
            aria-label={label}
        >
            <div className="collection-caption">
                <span>{label}</span>
                <span>
                    {records.length}{' '}
                    {total !== undefined && total !== records.length
                        ? `of ${total} `
                        : ''}
                    {records.length === 1 ? 'record' : 'records'} shown
                </span>
            </div>
            {!records.length ? (
                <div className="collection-empty">
                    <h3>{empty.title}</h3>
                    {empty.description && <p>{empty.description}</p>}
                    {empty.action}
                </div>
            ) : view === 'list' ? (
                <EntityTable
                    rows={records}
                    rowKey={(record) => record.id}
                    identityLabel="Record"
                    identityWidth={identityWidth}
                    minWidth={minWidth}
                    identity={(record) => ({
                        name: record.name,
                        subline: record.subline,
                        icon: record.icon,
                        mark: record.mark,
                    })}
                    columns={columns.map((column, index) => ({
                        key: String(index),
                        label: column.label,
                        width: column.width ?? '1fr',
                        cell: (record: VehicleCollectionRecord) => (
                            <span
                                className="collection-field"
                                onClick={keepControlClicks}
                            >
                                <span className="collection-field-label">
                                    {column.label}
                                </span>
                                <span>{record.fields[index]}</span>
                            </span>
                        ),
                    }))}
                    actionsFor={(record) => record.actions}
                    onOpen={(record) => record.onOpen?.()}
                    onRowContextMenu={menu.open}
                    className="collection-table"
                />
            ) : (
                <EntityCardGrid className="collection-cards">
                    {records.map((record) => (
                        <EntityCard
                            key={record.id}
                            name={record.name}
                            subline={record.subline}
                            icon={record.icon}
                            mark={record.mark}
                            meridian={record.tone ?? 'success'}
                            actions={record.actions}
                            onOpen={record.onOpen}
                            onContextMenu={(event) => menu.open(event, record)}
                            className={
                                record.tone
                                    ? 'collection-card'
                                    : 'collection-card no-alert-meridian'
                            }
                            chips={
                                <dl className="collection-facts">
                                    {columns.map((column, index) => (
                                        <div key={column.label}>
                                            <dt>{column.label}</dt>
                                            <dd onClick={keepControlClicks}>
                                                {record.fields[index]}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            }
                            footer={
                                record.footer ?? {
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
