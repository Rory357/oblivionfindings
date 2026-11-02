/** Setup › Change history (pg-sdr-15l): who changed what and where, then how long things are kept. */
import { EntityContextMenu, useEntityContextMenu, type MenuItem } from '@/components/lists';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { FactRows, QuietLine } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { Pagination } from '@/components/ui/pagination';
import { seenTime } from '@/lib/security-devices/device-time';
import { router } from '@inertiajs/react';
import { Download, History, Home } from 'lucide-react';
import { matches } from './_parts';
import type { HistoryRow, SetupProps } from './_types';

const EXPORT_URL = '/security-devices/setup/history/export';

export function ChangeHistory({
    data,
    canExport,
    query,
    now,
    onPage,
}: {
    data: NonNullable<SetupProps['history']>;
    canExport: boolean;
    query: string;
    now: Date;
    onPage: (page: number) => void;
}) {
    const menu = useEntityContextMenu<HistoryRow>();
    const rows = data.rows.filter((row) => matches(query, row.who, row.what, row.where));
    const exportAll = () => window.location.assign(EXPORT_URL);
    const actionsFor = (row: HistoryRow): MenuItem[] => [
        ...(row.site_id ? [{ label: `Open ${row.where}`, icon: Home, onClick: () => router.visit(`/security-devices/sites/${row.site_id}`) }] : []),
        canExport ? { label: 'Export the history', icon: Download, onClick: exportAll } : { label: 'Export the history', icon: Download, disabled: 'You can’t export the history' },
    ];

    return (
        <div className="space-y-5">
            <ListCaption
                title="Change history"
                caption="Every change to Security & devices settings, newest first"
                right={
                    canExport && data.total > 0 ? (
                        <Button variant="outline" size="sm" asChild>
                            <a href={EXPORT_URL}>
                                <Download className="size-4" /> Export
                            </a>
                        </Button>
                    ) : undefined
                }
            />
            {data.total === 0 ? (
                <QuietLine text="No changes yet. Approving a hub, adding a network or turning a setting on or off shows here." />
            ) : (
                <>
                    <EntityTable<HistoryRow>
                        rows={rows}
                        rowKey={(row) => row.id}
                        identityLabel="When"
                        identityWidth="1fr"
                        identity={(row) => ({ icon: History, name: row.at ? seenTime(row.at, now) : '—' })}
                        columns={[
                            { key: 'who', label: 'Who', width: '1fr', cell: (row) => row.who },
                            { key: 'what', label: 'What changed', width: '2.6fr', cell: (row) => <span className="text-sm">{row.what}</span> },
                            { key: 'where', label: 'Where', width: '1fr', cell: (row) => row.where },
                        ]}
                        actionsFor={actionsFor}
                        onOpen={(row) => (row.site_id ? router.visit(`/security-devices/sites/${row.site_id}`) : undefined)}
                        onRowContextMenu={(event, row) => menu.open(event, row)}
                        rowHeight="content"
                    />
                    {rows.length === 0 ? <QuietLine text="No change on this page matches your search." /> : null}
                    {data.pages > 1 ? <Pagination currentPage={data.page} lastPage={data.pages} onPageChange={onPage} /> : null}
                </>
            )}
            {menu.ctx ? (
                <EntityContextMenu x={menu.ctx.x} y={menu.ctx.y} icon={History} title={menu.ctx.record.what} items={actionsFor(menu.ctx.record)} onClose={menu.close} />
            ) : null}
            <FactRows title="How long things are kept" rows={data.kept} />
        </div>
    );
}
