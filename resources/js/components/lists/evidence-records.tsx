import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import {
    ReviewCard,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { router } from '@inertiajs/react';
import { Eye, type LucideIcon } from 'lucide-react';
import { useRef, useState, type ComponentType, type ReactNode } from 'react';
import {
    EntityCard,
    EntityCardGrid,
    type EntityCardProps,
} from './entity-card';
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from './entity-menu';
import {
    EntityTable,
    type EntityTableColumn,
    type EntityTableIdentity,
} from './entity-table';
import { ListCaption } from './list-caption';
export type EvidenceRecordsView = 'cards' | 'table';
export type EvidenceRecordListProps<T> = {
    title: string;
    description: string;
    rows: T[];
    view: EvidenceRecordsView;
    rowKey: (row: T) => string | number;
    identity: (row: T) => EntityTableIdentity;
    articleLabel?: (row: T) => string;
    hrefFor?: (row: T) => string | null;
    actionsFor: (row: T) => MenuItem[];
    cardFor: (
        row: T,
    ) => Omit<EntityCardProps, 'actions' | 'onContextMenu' | 'href' | 'onOpen'>;
    cardComponent?: ComponentType<{ row: T; actions: MenuItem[] }>;
    columns: EntityTableColumn<T>[];
    compactColumns: EntityTableColumn<T>[];
    initialDetailSection?: string;
    empty: string;
    icon: LucideIcon;
    gap?: ReactNode;
    total?: number;
    extraFor?: (row: T) => ReactNode;
    minWidth?: number;
};

export function EvidenceRecords<T>(props: EvidenceRecordListProps<T>) {
    return (
        <EvidenceRecordsInner
            key={JSON.stringify([props.view, props.rows])}
            {...props}
        />
    );
}

function EvidenceRecordsInner<T>({
    title,
    description,
    rows,
    view,
    rowKey,
    identity,
    articleLabel,
    hrefFor,
    actionsFor,
    cardFor,
    cardComponent: CardComponent,
    columns,
    compactColumns,
    initialDetailSection,
    empty,
    icon: Icon,
    gap,
    total,
    extraFor,
    minWidth = 1100,
}: EvidenceRecordListProps<T>) {
    const context = useEntityContextMenu<T>();
    const [selected, setSelected] = useState<T | null>(null);
    const triggers = useRef(new Map<string | number, HTMLButtonElement>());
    const cardRoots = useRef(new Map<string | number, HTMLElement>());
    const returnFocus = useRef<HTMLElement | null>(null);
    const showEvidence = (row: T) => {
        returnFocus.current =
            triggers.current.get(rowKey(row)) ??
            cardRoots.current
                .get(rowKey(row))
                ?.querySelector<HTMLButtonElement>('button[aria-label]') ??
            null;
        context.close();
        setSelected(row);
    };
    const destinationFor = (row: T) => hrefFor?.(row) || undefined;
    const openRecord = (row: T) => {
        const destination = destinationFor(row);
        if (destination) router.visit(destination);
        else showEvidence(row);
    };
    const recordActions = (row: T): MenuItem[] => [
        { label: 'View evidence', icon: Eye, onClick: () => showEvidence(row) },
        ...actionsFor(row),
    ];
    return (
        <section className="space-y-5">
            <ListCaption
                title={title}
                caption={
                    total === undefined
                        ? rows.length +
                          ' loaded ' +
                          (rows.length === 1 ? 'record' : 'records') +
                          ' shown'
                        : rows.length + ' of ' + total + ' shown'
                }
            />
            <p className="text-sm text-muted-foreground">{description}</p>
            {gap}
            {rows.length === 0 ? (
                <EmptyState icon={Icon} title={empty} />
            ) : view === 'cards' ? (
                <EntityCardGrid>
                    {rows.map((row) => (
                        <article
                            key={rowKey(row)}
                            ref={(node) => {
                                if (node)
                                    cardRoots.current.set(rowKey(row), node);
                                else cardRoots.current.delete(rowKey(row));
                            }}
                            aria-label={
                                articleLabel?.(row) ?? identity(row).name
                            }
                            className="min-w-0 space-y-5"
                        >
                            {CardComponent ? (
                                <CardComponent
                                    row={row}
                                    actions={recordActions(row)}
                                />
                            ) : (
                                <EntityCard
                                    {...cardFor(row)}
                                    href={destinationFor(row)}
                                    onOpen={() => openRecord(row)}
                                    actions={recordActions(row)}
                                    onContextMenu={(event) =>
                                        context.open(event, row)
                                    }
                                />
                            )}
                            {extraFor?.(row)}
                        </article>
                    ))}
                </EntityCardGrid>
            ) : (
                <EntityTable
                    rows={rows}
                    rowKey={rowKey}
                    identity={identity}
                    identityLabel="Record"
                    identityWidth="1.6fr"
                    columns={[
                        ...compactColumns,
                        {
                            key: 'details',
                            label: 'Evidence',
                            width: '105px',
                            cell: (row) => (
                                <Button
                                    ref={(node) => {
                                        if (node)
                                            triggers.current.set(
                                                rowKey(row),
                                                node,
                                            );
                                        else
                                            triggers.current.delete(
                                                rowKey(row),
                                            );
                                    }}
                                    variant="link"
                                    size="sm"
                                    aria-label={
                                        'View evidence for ' +
                                        identity(row).name
                                    }
                                    onClick={(event) => {
                                        event.stopPropagation();
                                        showEvidence(row);
                                    }}
                                >
                                    View evidence
                                </Button>
                            ),
                        },
                    ]}
                    actionsFor={recordActions}
                    hrefFor={destinationFor}
                    onOpen={(row) => openRecord(row)}
                    onRowContextMenu={context.open}
                    minWidth={minWidth}
                />
            )}
            {context.ctx ? (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    title={identity(context.ctx.record).name}
                    icon={Icon}
                    items={recordActions(context.ctx.record)}
                    onClose={context.close}
                />
            ) : null}
            {selected ? (
                <RecordEvidenceViewer
                    row={selected}
                    name={identity(selected).name}
                    icon={Icon}
                    columns={columns}
                    initialSection={initialDetailSection}
                    onClose={() => setSelected(null)}
                    onReturnFocus={() => {
                        const node = returnFocus.current;
                        if (node?.isConnected) node.focus();
                    }}
                />
            ) : null}
        </section>
    );
}

function RecordEvidenceViewer<T>({
    row,
    name,
    icon: Icon,
    columns,
    initialSection,
    onClose,
    onReturnFocus,
}: {
    row: T;
    name: string;
    icon: LucideIcon;
    columns: EntityTableColumn<T>[];
    initialSection?: string;
    onClose: () => void;
    onReturnFocus: () => void;
}) {
    const [section, setSection] = useState(
        Math.max(
            0,
            columns.findIndex((column) => column.key === initialSection),
        ),
    );
    const current = columns[section];
    if (columns.length === 1)
        return (
            <Dialog
                open
                onOpenChange={(open) => {
                    if (!open) onClose();
                }}
            >
                <DialogContent
                    className="max-h-[90vh] overflow-x-hidden overflow-y-auto"
                    style={{
                        maxWidth: 'min(92vw, 480px)',
                        width: 'min(92vw, 480px)',
                    }}
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        onReturnFocus();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>{'Evidence for ' + name}</DialogTitle>
                        <DialogDescription>
                            Read-only retained information
                        </DialogDescription>
                    </DialogHeader>
                    <ReviewCard icon={Icon} title={current.label}>
                        {current.cell(row)}
                    </ReviewCard>
                    <DialogFooter>
                        <Button variant="outline" onClick={onClose}>
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        );
    return (
        <WizardShell
            open
            onClose={onClose}
            onCloseAutoFocus={(event) => {
                event.preventDefault();
                onReturnFocus();
            }}
            title={'Evidence for ' + name}
            description={'Read-only retained evidence for ' + name}
            railIcon={Icon}
            railTitle={name}
            railSub="Retained evidence"
            steps={columns.map((column) => ({
                key: column.key,
                label: column.label,
                blurb: 'Retained evidence',
                icon: Icon,
            }))}
            stepIndex={section}
            onStepClick={setSection}
            sequential={false}
            headerLabel={current.label}
            maxWidth="min(94vw, 1100px)"
            footerStart={
                <Button variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
        >
            <WizardStepPane key={current.key}>
                {current.key === 'snapshots' ? (
                    current.cell(row)
                ) : (
                    <ReviewCard icon={Icon} title={current.label}>
                        {current.cell(row)}
                    </ReviewCard>
                )}
            </WizardStepPane>
        </WizardShell>
    );
}
