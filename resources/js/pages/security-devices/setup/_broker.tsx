/** Setup › Hubs & networks › Sensor broker (pg-sdr-15o): gateways with their own login, how readings travel, and the public-port check. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { EntityContextMenu, useEntityContextMenu, type MenuItem } from '@/components/lists';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { AlwaysOn, SettingGroup, SettingRow } from '@/components/referrals/settings-ui';
import { Note, QuietLine } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { seenTime } from '@/lib/security-devices/device-time';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Home, KeyRound, Plus, RadioTower, ShieldCheck, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { matches } from './_parts';
import { SwitchGroup, type SettingsDraft } from './_settings';
import type { BrokerRow, SetupProps } from './_types';

export function SetupBroker({ data, draft, query, now, onAdd }: { data: NonNullable<SetupProps['broker']>; draft: SettingsDraft; query: string; now: Date; onAdd: () => void }) {
    const menu = useEntityContextMenu<BrokerRow>();
    const [removing, setRemoving] = useState<BrokerRow | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const rows = data.rows.filter((row) => matches(query, row.label, row.sub, row.site.name, row.reaches, row.connection.text));

    const remove = async () => {
        if (!removing) return;
        setBusy(true);
        setError(null);
        try {
            await axios.delete(`/security-devices/setup/broker/gateways/${removing.id}`);
            setRemoving(null);
            router.reload({ except: ['deviceForm'] });
        } catch (failure) {
            const status = axios.isAxiosError(failure) ? failure.response?.status : undefined;
            setError(status === 403 ? 'You can’t remove gateway logins.' : status ? 'It wasn’t removed. Try again.' : 'Result not confirmed. Reload to check whether it was removed.');
        } finally {
            setBusy(false);
        }
    };

    const actionsFor = (row: BrokerRow): MenuItem[] => [
        { label: 'Open the Site', icon: Home, onClick: () => router.visit(`/security-devices/sites/${row.site.id}`) },
        data.can_add
            ? { label: 'Remove its login', icon: Trash2, danger: true, onClick: () => setRemoving(row) }
            : { label: 'Remove its login', icon: Trash2, disabled: 'Only someone who manages saved logins can' },
    ];

    return (
        <div className="space-y-5">
            <SwitchGroup
                icon={RadioTower}
                title="Sensor broker"
                caption="Where sensor gateways and plugs send their readings (MQTT): each Site’s hub, on the house network only"
                keys={['mqtt.allow_unencrypted']}
                draft={draft}
            />
            <SettingGroup icon={ShieldCheck} title="Safety" caption="Always on; nothing here can be switched off">
                <SettingRow label="A login can only send its own readings" hint="Its own topic only; another device’s topic ends the connection" control={<AlwaysOn />} />
                <SettingRow label="Alert after 5 wrong logins in 10 minutes" hint="The hub ignores that address for 10 minutes and IT is told, as for a device that stops answering" control={<AlwaysOn />} />
            </SettingGroup>

            <ListCaption
                title="Gateways and devices with a login"
                caption="Each has its own login and may send only its own readings"
                right={
                    data.can_add ? (
                        <Button size="sm" onClick={onAdd}>
                            <Plus className="size-4" /> Add a sensor gateway
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
                <QuietLine text="No gateway logins yet. Add a sensor gateway and Oblivion makes its own login on the Site’s hub." />
            ) : (
                <EntityTable<BrokerRow>
                    rows={rows}
                    rowKey={(row) => row.id}
                    identityLabel="Device"
                    identity={(row) => ({ icon: KeyRound, name: row.label, subline: row.sub })}
                    columns={[
                        { key: 'site', label: 'Site', width: '1fr', cell: (row) => row.site.name },
                        { key: 'reaches', label: 'Reaches Oblivion', width: '1.3fr', cell: (row) => row.reaches },
                        { key: 'connection', label: 'Connection', width: '1.4fr', cell: (row) => <StatusBadge variant={row.connection.tone}>{row.connection.text}</StatusBadge> },
                        { key: 'last', label: 'Last message', width: '1fr', cell: (row) => (row.last ? seenTime(row.last, now) : 'Not heard from yet') },
                    ]}
                    actionsFor={actionsFor}
                    onOpen={(row) => router.visit(`/security-devices/sites/${row.site.id}`)}
                    onRowContextMenu={(event, row) => menu.open(event, row)}
                    rowHeight="content"
                />
            )}
            {rows.length === 0 && data.rows.length > 0 ? <QuietLine text="No gateway matches your search." /> : null}
            {menu.ctx ? (
                <EntityContextMenu x={menu.ctx.x} y={menu.ctx.y} icon={KeyRound} title={menu.ctx.record.label} items={actionsFor(menu.ctx.record)} onClose={menu.close} />
            ) : null}

            <Note tone={data.check === null ? undefined : data.check.passed ? 'success' : 'critical'} icon={ShieldCheck}>
                <b>Never on the public internet.</b>{' '}
                {data.check === null
                    ? 'Every day a hub tries Oblivion’s public address on ports 1883 and 8883; both must refuse. No hub has checked yet.'
                    : data.check.passed
                      ? `Every day a hub tries Oblivion’s public address on ports 1883 and 8883; both must refuse. The last check passed at ${seenTime(data.check.at, now)} (${data.check.by}).`
                      : `Port ${data.check.answered.join(' and ')} answered when ${data.check.by} checked at ${seenTime(data.check.at, now)}. Close it on the server’s firewall.`}
            </Note>
            {data.clashes.length > 0 ? (
                <Note tone="warning">
                    <b>Unencrypted MQTT is refused at {data.clashes.join(', ')}</b> because another Site uses the same address range. Logins, not addresses, decide which Site a reading belongs to.
                </Note>
            ) : null}

            <ConfirmDialog
                open={removing !== null}
                onClose={() => setRemoving(null)}
                onConfirm={remove}
                processing={busy}
                title={removing ? `Remove the login for ${removing.label}?` : 'Remove the login?'}
                description="The hub stops accepting its readings at its next update (within a few minutes). Readings already received are kept."
                confirmText="Remove login"
                cancelText="Cancel"
            />
        </div>
    );
}
