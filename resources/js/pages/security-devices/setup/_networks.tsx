/** Setup › Hubs & networks › Networks & scans (pg-sdr-15c). */
import { EntityContextMenu, useEntityContextMenu, type MenuItem } from '@/components/lists';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Note, QuietLine } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { seenTime } from '@/lib/security-devices/device-time';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Eye, Home, Network, Plus, Radar, ScanSearch } from 'lucide-react';
import { useState } from 'react';
import { LineText, matches } from './_parts';
import { SwitchGroup, type SettingsDraft } from './_settings';
import type { NetworkRow, SetupProps } from './_types';

export function SetupNetworks({
    data,
    draft,
    canAdd,
    query,
    now,
    onAdd,
}: {
    data: NonNullable<SetupProps['networks']>;
    draft: SettingsDraft;
    canAdd: boolean;
    query: string;
    now: Date;
    onAdd: () => void;
}) {
    const menu = useEntityContextMenu<NetworkRow>();
    const [scanning, setScanning] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const rows = data.rows.filter((row) => matches(query, row.site.name, row.range, row.checked_by));

    const scanNow = async (row: NetworkRow) => {
        setScanning(row.id);
        setError(null);
        try {
            const response = await axios.post<{ run_id: number }>(`/security-devices/setup/networks/${row.id}/scan`);
            router.visit(`/security-devices/scans/${response.data.run_id}`);
        } catch (failure) {
            const data = axios.isAxiosError(failure) ? (failure.response?.data as { errors?: Record<string, string[]> } | undefined) : undefined;
            const first = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
            setError(first ?? (axios.isAxiosError(failure) && !failure.response ? 'Result not confirmed. Check Found devices before trying again.' : 'The scan didn’t start. Try again.'));
        } finally {
            setScanning(null);
        }
    };

    const open = (row: NetworkRow) => router.visit(row.run_id ? `/security-devices/scans/${row.run_id}` : `/security-devices/sites/${row.site.id}`);
    const actionsFor = (row: NetworkRow): MenuItem[] => [
        ...(row.run_id ? [{ label: 'See what was found', icon: Eye, onClick: () => router.visit(`/security-devices/scans/${row.run_id}`) }] : []),
        ...(row.can_scan
            ? [{ label: scanning === row.id ? 'Starting…' : 'Scan now', icon: Radar, onClick: () => void scanNow(row) }]
            : [{ label: 'Scan now', icon: Radar, disabled: row.scan_blocked ?? 'Not available' }]),
        { label: 'Open the Site', icon: Home, onClick: () => router.visit(`/security-devices/sites/${row.site.id}`) },
    ];

    return (
        <div className="space-y-5">
            <ListCaption
                title="Networks"
                caption="The address ranges at each Site, who checks them and when they are scanned"
                right={
                    canAdd ? (
                        <Button size="sm" onClick={onAdd}>
                            <Plus className="size-4" /> Add a network
                        </Button>
                    ) : undefined
                }
            />
            {error ? (
                <Note tone="critical">
                    <span role="alert">{error}</span>
                </Note>
            ) : null}
            {data.rows.length === 0 ? (
                <QuietLine text="No networks yet. Add one, or add a hub: it suggests its own network to scan." />
            ) : (
                <EntityTable<NetworkRow>
                    rows={rows}
                    rowKey={(row) => row.id}
                    identityLabel="Site"
                    identity={(row) => ({ icon: Network, name: row.site.name, linkLabel: `Open ${row.site.name}` })}
                    columns={[
                        { key: 'range', label: 'Range', width: '1.1fr', cell: (row) => <span className="font-mono text-[13px]">{row.range}</span> },
                        { key: 'by', label: 'Checked by', width: '1.2fr', cell: (row) => row.checked_by },
                        {
                            key: 'last',
                            label: 'Last scan',
                            width: '1.3fr',
                            cell: (row) =>
                                row.last.tone ? (
                                    <StatusBadge variant={row.last.tone === 'neutral' ? 'neutral' : row.last.tone}>
                                        <LineText line={row.last} now={now} />
                                    </StatusBadge>
                                ) : (
                                    <LineText line={row.last} now={now} />
                                ),
                        },
                        { key: 'next', label: 'Next scan', width: '1fr', cell: (row) => (row.next.at ? seenTime(row.next.at, now) : row.next.text) },
                        { key: 'found', label: 'Found', width: '0.9fr', cell: (row) => row.found ?? <span className="text-muted-foreground">—</span> },
                    ]}
                    actionsFor={actionsFor}
                    onOpen={open}
                    onRowContextMenu={(event, row) => menu.open(event, row)}
                    minWidth={980}
                />
            )}
            {rows.length === 0 && data.rows.length > 0 ? <QuietLine text="No network matches your search." /> : null}
            {menu.ctx ? (
                <EntityContextMenu x={menu.ctx.x} y={menu.ctx.y} icon={Network} title={menu.ctx.record.range} items={actionsFor(menu.ctx.record)} onClose={menu.close} />
            ) : null}

            {data.notes.map((note, index) => (
                <Note key={index} tone={note.tone}>
                    <LineText line={{ text: note.text, at: null }} now={now} />
                </Note>
            ))}

            <SwitchGroup
                icon={ScanSearch}
                title="Scans"
                caption="Applies to every network unless a Site changes it"
                keys={['scans.daily', 'scans.server_direct']}
                draft={draft}
            />
        </div>
    );
}
