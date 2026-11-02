/**
 * Setup › Computers & Obli › Fixes Obli can make (pg-sdr-15j, S&D Phase I). An honest fix list:
 * the Windows service actions and restart the Oblivion service already runs for IT, who turned
 * each on for Obli and where, and how it has gone. Pop-up 93 turns one on, for one computer first,
 * through today's policy update. Every row has ⋮, right-click and a row click.
 */
import { EntityContextMenu, useEntityContextMenu, type MenuItem } from '@/components/lists';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { TurnOnFixPopup } from '@/components/security-devices/obli-fix-popups';
import { KnowList, Note, QuietLine } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDate } from '@/lib/datetime';
import { obliError, type ObliFixesSetupData, type ObliFixRow } from '@/lib/security-devices/obli-fixes';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Cog, Info, Laptop, Power, PowerOff, ToggleRight } from 'lucide-react';
import { useState } from 'react';
import { matches } from './_parts';

export function SetupFixes({ data, query }: { data: ObliFixesSetupData; query: string }) {
    const menu = useEntityContextMenu<ObliFixRow>();
    const [turningOn, setTurningOn] = useState<ObliFixRow | null>(null);
    const [error, setError] = useState<string | null>(null);
    const rows = data.rows.filter((row) => matches(query, row.label, row.sub, row.state.label, ...row.where.map((place) => place.name)));

    const turnOff = async (row: ObliFixRow, deviceId: number) => {
        setError(null);
        try {
            await axios.post('/security-devices/setup/obli/fixes/off', { fix_key: row.key, device_id: deviceId });
            router.reload({ only: ['fixes'] });
        } catch (failure) {
            setError(obliError(axios.isAxiosError(failure) ? failure.response?.status : undefined));
        }
    };

    const actionsFor = (row: ObliFixRow): MenuItem[] => [
        row.can_turn_on
            ? { label: row.where.length === 0 ? 'Turn on for 1 computer' : 'Turn on for another computer', icon: ToggleRight, onClick: () => setTurningOn(row) }
            : { label: 'Turn on for 1 computer', icon: ToggleRight, disabled: data.can_manage ? (row.state.note ?? 'Not available on this server') : 'You need permission to manage Obli’s fixes' },
        ...row.where.flatMap((place): MenuItem[] => [
            { separator: true },
            { label: `Open ${place.name}`, icon: Laptop, onClick: () => router.visit(`/security-devices/devices/${place.device_id}?tab=obli`) },
            ...(data.can_manage ? [{ label: `Turn off for ${place.name}`, icon: PowerOff, onClick: () => void turnOff(row, place.device_id) }] : []),
        ]),
    ];

    return (
        <div className="space-y-5" data-testid="sd-setup-fixes">
            <Note icon={Info}>
                <b className="font-semibold">Most real fixes need an administrator.</b> Obli runs as the person, so on screen it can only do what they could do. Fixes on this list are
                the Windows service actions and restarts the Oblivion service already runs for IT: Obli can use one only after IT turns it on for that computer, and IT then runs it
                once from the computer’s fix form. Windows doesn’t let any app answer its “Allow changes” or sign-in screens, so Obli never does.
            </Note>
            <ListCaption title="Fixes Obli can make" caption="What each one needs, who turned it on and how it has gone · last 30 days" />
            {data.rows.length === 0 ? (
                <QuietLine text="No fixes yet. They come from the service actions IT has approved for the Oblivion service." />
            ) : (
                <EntityTable<ObliFixRow>
                    rows={rows}
                    rowKey={(row) => row.key}
                    identityLabel="Fix"
                    wrapIdentity
                    identity={(row) => ({ icon: row.icon === 'power' ? Power : Cog, name: row.label, subline: row.sub })}
                    columns={[
                        { key: 'runs', label: 'Runs as', width: '1.1fr', cell: (row) => row.runs_as },
                        {
                            key: 'on',
                            label: 'Turned on',
                            width: '1.3fr',
                            cell: (row) =>
                                row.turned_on ? (
                                    <span className="text-sm">
                                        {row.turned_on.by} · {formatDate(row.turned_on.at)}
                                        <span className="text-caption block">{row.where.length === 1 ? row.where[0].name : `${row.where.length} computers`}</span>
                                    </span>
                                ) : (
                                    '—'
                                ),
                        },
                        { key: 'used', label: 'Used', width: '0.8fr', cell: (row) => (row.used ? `${row.used.asked} · ran ${row.used.ran}` : '—') },
                        {
                            key: 'state',
                            label: 'State',
                            width: '1.2fr',
                            cell: (row) => (
                                <span className="grid justify-items-start gap-0.5">
                                    <StatusBadge variant={row.state.tone}>{row.state.label}</StatusBadge>
                                    {row.state.note ? <span className="text-caption">{row.state.note}</span> : null}
                                </span>
                            ),
                        },
                    ]}
                    actionsFor={actionsFor}
                    onOpen={(row) => (row.can_turn_on ? setTurningOn(row) : row.where[0] ? router.visit(`/security-devices/devices/${row.where[0].device_id}?tab=obli`) : undefined)}
                    onRowContextMenu={(event, row) => menu.open(event, row)}
                    rowHeight="content"
                />
            )}
            {rows.length === 0 && data.rows.length > 0 ? <QuietLine text="No fix matches your search." /> : null}
            {error ? (
                <Note tone="critical">
                    <span role="alert">{error}</span>
                </Note>
            ) : null}
            {menu.ctx ? <EntityContextMenu x={menu.ctx.x} y={menu.ctx.y} icon={Cog} title={menu.ctx.record.label} items={actionsFor(menu.ctx.record)} onClose={menu.close} /> : null}
            <KnowList
                title="Obli never does these"
                sub="It explains why and offers an IT ticket instead"
                items={[
                    { tone: 'critical', text: 'Turn off antivirus, the firewall or BitLocker' },
                    { tone: 'critical', text: 'Make someone an administrator, or change who can sign in' },
                    { tone: 'critical', text: 'Anything on Windows’ “Allow changes” or sign-in screens (Windows doesn’t allow it)' },
                    { tone: 'critical', text: 'Type a password, or read one out' },
                    { tone: 'warning', text: 'A one-off admin change that isn’t on this list: Obli asks IT to approve it once' },
                ]}
            />
            <TurnOnFixPopup open={turningOn !== null} fix={turningOn} computers={data.computers} onClose={() => setTurningOn(null)} />
        </div>
    );
}
