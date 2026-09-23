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
import { Button } from '@/components/ui/button';
import { LayoutGrid, List } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import './workspace.css';

export type VehicleCollectionView = 'list' | 'cards';
export function useVehicleCollectionView() {
    const [view, setView] = useState<VehicleCollectionView>('list');
    return { view, setView };
}
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
        // eslint-disable-next-line no-restricted-syntax -- Segmented layout control, not a content card.
        <div
            role="group"
            aria-label={`${label} layout`}
            className="inline-flex shrink-0 rounded-lg border bg-card p-1"
        >
            <Button
                size="sm"
                variant={view === 'list' ? 'secondary' : 'ghost'}
                aria-pressed={view === 'list'}
                onClick={() => onChange('list')}
            >
                <List className="size-4" />
                List
            </Button>
            <Button
                size="sm"
                variant={view === 'cards' ? 'secondary' : 'ghost'}
                aria-pressed={view === 'cards'}
                onClick={() => onChange('cards')}
            >
                <LayoutGrid className="size-4" />
                Cards
            </Button>
        </div>
    );
}

export type VehicleCollectionRecord = {
    id: string | number;
    name: string;
    subline?: ReactNode;
    icon?: EntityCardProps['icon'];
    fields: ReactNode[];
    actions: MenuItem[];
    onOpen: () => void;
    tone?: EntityCardProps['meridian'];
    footer?: EntityCardProps['footer'];
};

export function VehicleRecordCollection({
    label,
    view,
    records,
    columns,
    empty,
    total,
}: {
    label: string;
    view: VehicleCollectionView;
    records: VehicleCollectionRecord[];
    columns: Array<{ label: string; width?: string }>;
    empty: ReactNode;
    total?: number;
}) {
    const menu = useEntityContextMenu<VehicleCollectionRecord>();
    return (
        <div className="vehicle-record-collection min-w-0" aria-label={label}>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                <span>{label}</span>
                <span>
                    {records.length}{' '}
                    {total !== undefined && total !== records.length
                        ? `of ${total} `
                        : ''}
                    {records.length === 1 && total !== 0 ? 'record' : 'records'}{' '}
                    shown
                </span>
            </div>
            {!records.length ? (
                <div className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                    {empty}
                </div>
            ) : view === 'list' ? (
                <EntityTable
                    rows={records}
                    rowKey={(record) => record.id}
                    identityLabel="Record"
                    identityWidth="1.3fr"
                    minWidth={680}
                    identity={(record) => ({
                        name: record.name,
                        subline: record.subline,
                        icon: record.icon,
                    })}
                    columns={columns.map((column, index) => ({
                        key: column.label,
                        label: column.label,
                        width: column.width ?? '1fr',
                        cell: (record: VehicleCollectionRecord) =>
                            record.fields[index],
                    }))}
                    actionsFor={(record) => record.actions}
                    onOpen={(record) => record.onOpen()}
                    onRowContextMenu={menu.open}
                    className="vehicle-record-table"
                />
            ) : (
                <EntityCardGrid>
                    {records.map((record) => (
                        <EntityCard
                            key={record.id}
                            meridian={record.tone ?? 'success'}
                            icon={record.icon}
                            name={record.name}
                            subline={record.subline}
                            actions={record.actions}
                            onOpen={record.onOpen}
                            onContextMenu={(event) => menu.open(event, record)}
                            chips={
                                <dl className="grid w-full gap-3">
                                    {columns.map((column, index) => (
                                        <div key={column.label}>
                                            <dt className="text-xs text-muted-foreground">
                                                {column.label}
                                            </dt>
                                            <dd className="mt-1 text-sm">
                                                {record.fields[index]}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            }
                            footer={record.footer}
                            className="vehicle-record-card"
                        />
                    ))}
                </EntityCardGrid>
            )}
            {menu.ctx && (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    title={menu.ctx.record.name}
                    items={menu.ctx.record.actions}
                    onClose={menu.close}
                />
            )}
        </div>
    );
}
