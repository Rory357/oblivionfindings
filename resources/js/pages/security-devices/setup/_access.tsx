/**
 * Setup › Doors & access › UniFi Access (pg-sdr-15m, S&D Phase H): one door console per Site, each
 * reached through that Site's hub; what Oblivion keeps in step with UniFi; and the privacy and
 * safety rules that apply to every door. "Connect a Site" opens pop-up 91.
 *
 * Not built, so not shown: "New staff get a Give a card task", door schedules and holidays, and an
 * alert when a door is held open.
 */
import { EntityContextMenu, useEntityContextMenu, type MenuItem } from '@/components/lists';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { AlwaysOn, SettingGroup, SettingRow } from '@/components/referrals/settings-ui';
import { Note, QuietLine } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { seenTime } from '@/lib/security-devices/device-time';
import { House, Plus, RefreshCw, ShieldCheck, TriangleAlert } from 'lucide-react';
import { matches } from './_parts';
import { SwitchGroup, type SettingsDraft } from './_settings';
import type { AccessPlace, AccessSetupData } from './_types';

/** How a Site's console is doing, in words. */
export function accessFlag(place: AccessPlace): { label: string; tone: StatusVariant } {
    const console = place.console;
    if (!console) return { label: 'Not set up', tone: 'neutral' };
    if (!console.token) return { label: 'Needs its token', tone: 'warning' };
    if (!console.trusted) return { label: console.certificate?.offered?.changed ? 'Certificate changed' : 'Certificate to check', tone: 'warning' };
    if (console.state === 'failed') return { label: 'Can’t read it', tone: 'critical' };
    if (console.state === 'ok') return { label: 'Connected', tone: 'success' };
    return { label: 'Waiting for the hub', tone: 'info' };
}

function placeSub(place: AccessPlace): string {
    const console = place.console;
    if (!console) return place.hub ? `No door console connected · ${place.hub.name}` : 'No door console · no hub yet';
    const doors = console.doors === 1 ? '1 door' : `${console.doors} doors`;
    return `UniFi Access at ${console.address}:${console.port} · ${doors}`;
}

function placeFacts(place: AccessPlace, now: Date): string {
    const console = place.console;
    if (!console) return place.hub?.needs_update ? `${place.hub.name} needs its software update first` : '—';
    const parts: string[] = [];
    if (place.hub) parts.push(place.hub.needs_update ? `${place.hub.name} needs its software update` : `Through ${place.hub.name}`);
    if (console.last_synced_at) parts.push(`read ${seenTime(console.last_synced_at, now)}`);
    if (console.state === 'failed' && console.last_error) parts.push(console.last_error);
    if (console.to_match > 0) parts.push(`${console.to_match} to match`);
    if (console.state === 'ok' && !console.doors_confirmed_at) parts.push('exit buttons not confirmed yet');
    return parts.join(' · ');
}

export function SetupAccess({
    data,
    draft,
    query,
    now,
    onConnect,
}: {
    data: AccessSetupData;
    draft: SettingsDraft;
    query: string;
    now: Date;
    onConnect: (siteId: number | null) => void;
}) {
    const rows = data.places.filter((place) => matches(query, place.site, placeSub(place), placeFacts(place, now)));
    const menu = useEntityContextMenu<AccessPlace>();
    const actionsFor = (place: AccessPlace): MenuItem[] =>
        data.can.connect
            ? [
                  place.hub || place.console
                      ? { label: place.console ? 'Check or reconnect' : 'Connect', icon: place.console ? RefreshCw : Plus, onClick: () => onConnect(place.site_id) }
                      : { label: 'Connect', icon: Plus, disabled: 'This Site has no hub yet' },
              ]
            : [{ label: 'Connect', icon: Plus, disabled: 'Only someone who manages connected services can' }];

    return (
        <div className="space-y-6" data-testid="sd-setup-access">
            <ListCaption
                title="UniFi Access"
                caption="One door console per Site; each Site’s hub talks to it, because UniFi Access only answers on the Site’s own network"
                right={
                    data.can.connect ? (
                        <Button size="sm" onClick={() => onConnect(null)}>
                            <Plus className="size-4" /> Connect a Site
                        </Button>
                    ) : undefined
                }
            />
            {rows.length === 0 ? (
                <QuietLine text="No Sites to show." />
            ) : (
                <EntityTable<AccessPlace>
                    rows={rows}
                    rowKey={(place) => place.site_id}
                    identityLabel="Site"
                    identity={(place) => ({ icon: House, name: place.site, subline: placeSub(place) })}
                    columns={[
                        {
                            key: 'state',
                            label: 'State',
                            width: '1fr',
                            cell: (place) => {
                                const flag = accessFlag(place);
                                return <StatusBadge variant={flag.tone}>{flag.label}</StatusBadge>;
                            },
                        },
                        { key: 'facts', label: 'Details', width: '2fr', cell: (place) => placeFacts(place, now) },
                    ]}
                    actionsFor={actionsFor}
                    onOpen={data.can.connect ? (place) => onConnect(place.site_id) : undefined}
                    onRowContextMenu={(event, place) => menu.open(event, place)}
                    rowHeight="content"
                />
            )}

            {menu.ctx ? <EntityContextMenu x={menu.ctx.x} y={menu.ctx.y} icon={House} title={menu.ctx.record.site} items={actionsFor(menu.ctx.record)} onClose={menu.close} /> : null}

            <SwitchGroup
                icon={RefreshCw}
                title="What Oblivion keeps in step with UniFi"
                caption="Through the UniFi Access API; people are matched to staff by employee number"
                keys={['access.changes_from_oblivion', 'access.leavers_off']}
                draft={draft}
            />
            <SettingGroup icon={ShieldCheck} title="Privacy and safety" caption="Applies to every door Oblivion manages">
                <SettingRow
                    label="Residents’ fobs show “Resident fob”, not their name"
                    hint="Their comings and goings are their own (decision D19)"
                    control={<AlwaysOn />}
                />
                <SettingRow label="Names on door events" hint="Kept 90 days, then only the time and how (decision D14)" control={<StatusBadge variant="neutral">90 days</StatusBadge>} />
                <SettingRow
                    label="Doors open from the inside"
                    hint="By the exit button and handle: clients fit both on every door before it joins. Lockdown stops cards from outside and isn’t offered until someone confirms this; evacuation unlocks every door"
                    control={<StatusBadge variant="neutral">Installer confirms</StatusBadge>}
                />
                <SettingRow label="Touch Pass (phones)" hint="Set up in UniFi. Touch Passes cost money, so Oblivion never buys them" control={<StatusBadge variant="neutral">In UniFi</StatusBadge>} />
            </SettingGroup>
            <Note tone="warning" icon={TriangleAlert}>
                <b className="font-semibold">Upgrading a console to UniFi Identity Enterprise switches its API off.</b> Oblivion would stop seeing that Site’s doors until it’s reconnected another way. Check with IT before upgrading.
            </Note>
        </div>
    );
}
