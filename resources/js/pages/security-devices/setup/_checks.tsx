/** Setup › Alerts & checks › Checks (pg-sdr-15h): what each kind of device gets, and what happens to new devices. */
import { EntityContextMenu, useEntityContextMenu, type MenuItem } from '@/components/lists';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { QuietLine } from '@/components/signature/blocks';
import { router } from '@inertiajs/react';
import { ListChecks, ScanSearch, Search } from 'lucide-react';
import { matches } from './_parts';
import { SwitchGroup, type SettingsDraft } from './_settings';
import type { CheckRow, SetupProps } from './_types';

export function SetupChecks({ data, draft, query, canOpenDevices }: { data: NonNullable<SetupProps['checks']>; draft: SettingsDraft; query: string; canOpenDevices: boolean }) {
    const menu = useEntityContextMenu<CheckRow>();
    const rows = data.rows.filter((row) => matches(query, row.kind, row.check));
    const show = (row: CheckRow) => router.visit(`/security-devices/devices?q=${encodeURIComponent(row.kind)}`);
    const actionsFor = (row: CheckRow): MenuItem[] => [
        canOpenDevices ? { label: `Show ${row.kind.toLowerCase()} devices`, icon: Search, onClick: () => show(row) } : { label: `Show ${row.kind.toLowerCase()} devices`, icon: Search, disabled: 'You can’t open the Devices list' },
    ];

    return (
        <div className="space-y-5">
            <ListCaption title="What every kind of device gets" caption="Applied when a device is added; change one device on its own page" />
            {data.rows.length === 0 ? (
                <QuietLine text="No devices yet. Add or scan for devices and they’re listed here by kind." />
            ) : (
                <EntityTable<CheckRow>
                    rows={rows}
                    rowKey={(row) => row.key}
                    identityLabel="Kind"
                    identity={(row) => ({ icon: ListChecks, name: row.kind })}
                    columns={[
                        { key: 'check', label: 'What we check', width: '1.6fr', cell: (row) => row.check },
                        { key: 'often', label: 'How often', width: '1fr', cell: (row) => row.often ?? <span className="text-muted-foreground">—</span> },
                        { key: 'after', label: 'Alert after', width: '1.2fr', cell: (row) => row.after ?? <span className="text-muted-foreground">—</span> },
                        { key: 'devices', label: 'Devices', width: '0.9fr', cell: (row) => (row.watched === row.devices ? String(row.devices) : `${row.devices} · ${row.watched} watched`) },
                    ]}
                    actionsFor={actionsFor}
                    onOpen={canOpenDevices ? show : undefined}
                    onRowContextMenu={(event, row) => menu.open(event, row)}
                />
            )}
            {rows.length === 0 && data.rows.length > 0 ? <QuietLine text="No kind matches your search." /> : null}
            {menu.ctx ? (
                <EntityContextMenu x={menu.ctx.x} y={menu.ctx.y} icon={ListChecks} title={menu.ctx.record.kind} items={actionsFor(menu.ctx.record)} onClose={menu.close} />
            ) : null}
            <SwitchGroup icon={ScanSearch} title="New devices" caption="What happens when someone adds a device a scan found" keys={['devices.watch_on_add', 'devices.watch_phones']} draft={draft} />
        </div>
    );
}
