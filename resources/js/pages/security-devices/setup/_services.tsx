/** Setup › Services & logins: Connected services (15d), Saved logins (15e), Supported devices (15f). */
import { EntityContextMenu, useEntityContextMenu, type MenuItem } from '@/components/lists';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { FactRows, Note, PlaceCards, QuietLine } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { seenTime } from '@/lib/security-devices/device-time';
import { router } from '@inertiajs/react';
import { Cloud, ExternalLink, Home, KeyRound, ListChecks, Plug, Search } from 'lucide-react';
import { LineText, matches } from './_parts';
import type { LoginRow, SetupProps, SupportedRow } from './_types';

const RING = { success: 'success', warning: 'warning', critical: 'critical', info: 'info', neutral: 'none' } as const;

export function ConnectedServices({ data, query, now, onConnect }: { data: NonNullable<SetupProps['connected']>; query: string; now: Date; onConnect?: () => void }) {
    const cards = data.cards.filter((card) => matches(query, card.name, card.sub, card.flag.text));
    // Pop-up 15, for someone who can manage services (securityDevices.integrations.manage).
    const connect = onConnect ? { label: 'Connect a service', onClick: onConnect } : null;

    return (
        <div className="space-y-5">
            {cards.length === 0 ? (
                <QuietLine title="Connected services" text={query ? 'No service matches your search.' : 'No services yet.'} action={connect} />
            ) : (
                <PlaceCards
                    title="Connected services"
                    sub="Accounts and receivers that bring devices in without scanning"
                    action={connect}
                    cols={3}
                    items={cards.map((card) => ({
                        id: card.key,
                        name: card.name,
                        sub: card.sub,
                        flag: <StatusBadge variant={card.flag.tone}>{card.flag.text}</StatusBadge>,
                        facts: card.facts.map((fact) => ({ tone: RING[fact.tone], text: <LineText line={fact} now={now} /> })),
                        href: card.href ?? undefined,
                    }))}
                />
            )}
        </div>
    );
}

export function SavedLogins({ data, query, now }: { data: NonNullable<SetupProps['logins']>; query: string; now: Date }) {
    const menu = useEntityContextMenu<LoginRow>();
    const rows = data.rows.filter((row) => matches(query, row.used_for, row.sub, row.site?.name, row.used_by, row.state.text));
    const actionsFor = (row: LoginRow): MenuItem[] =>
        row.site
            ? [{ label: 'Open the Site', icon: Home, onClick: () => router.visit(`/security-devices/sites/${row.site!.id}`) }]
            : [{ label: 'Open Connected services', icon: Plug, onClick: () => router.visit('/security-devices/setup/services?tab=connected') }];

    return (
        <div className="space-y-5">
            <Note icon={KeyRound}>
                <b>Saved logins are stored encrypted.</b> A hub gets only the logins for the Sites it covers, and only after an admin approves it. Removing a hub
                wipes the logins it held. Nobody can read a password back.
            </Note>
            <ListCaption title="Saved logins" caption="By Site, with the hub or service that may use each one" />
            {data.rows.length === 0 ? (
                <QuietLine text="No saved logins yet. They’re added when a device or service needs one." />
            ) : (
                <EntityTable<LoginRow>
                    rows={rows}
                    rowKey={(row) => row.key}
                    identityLabel="Used for"
                    identity={(row) => ({ icon: row.icon === 'cloud' ? Cloud : KeyRound, name: row.used_for, subline: row.sub })}
                    columns={[
                        { key: 'site', label: 'Site', width: '1.1fr', cell: (row) => row.site?.name ?? 'Every Site' },
                        { key: 'by', label: 'Used by', width: '1.1fr', cell: (row) => row.used_by },
                        { key: 'last', label: 'Last used', width: '1fr', cell: (row) => (row.last_used ? seenTime(row.last_used, now) : 'Not used yet') },
                        { key: 'state', label: 'State', width: '1fr', cell: (row) => <StatusBadge variant={row.state.tone}>{row.state.text}</StatusBadge> },
                    ]}
                    actionsFor={actionsFor}
                    onOpen={(row) => router.visit(row.site ? `/security-devices/sites/${row.site.id}` : '/security-devices/setup/services?tab=connected')}
                    onRowContextMenu={(event, row) => menu.open(event, row)}
                    rowHeight="content"
                />
            )}
            {rows.length === 0 && data.rows.length > 0 ? <QuietLine text="No saved login matches your search." /> : null}
            {menu.ctx ? (
                <EntityContextMenu x={menu.ctx.x} y={menu.ctx.y} icon={KeyRound} title={menu.ctx.record.used_for} items={actionsFor(menu.ctx.record)} onClose={menu.close} />
            ) : null}
            <FactRows title="How saved logins are kept" rows={data.kept} />
        </div>
    );
}

export function SupportedDevices({ data, query, canOpenDevices }: { data: NonNullable<SetupProps['supported']>; query: string; canOpenDevices: boolean }) {
    const menu = useEntityContextMenu<SupportedRow>();
    const brandOnly = (row: SupportedRow) => row.brand.split(' ')[0];
    const showDevices = (row: SupportedRow) => router.visit(`/security-devices/devices?q=${encodeURIComponent(brandOnly(row))}`);
    const actionsFor = (row: SupportedRow): MenuItem[] => [
        canOpenDevices
            ? { label: `Show my ${brandOnly(row)} devices`, icon: Search, onClick: () => showDevices(row) }
            : { label: `Show my ${brandOnly(row)} devices`, icon: Search, disabled: 'You can’t open the Devices list' },
        ...row.docs.map((doc) => ({ label: `Open “${doc.label}”`, icon: ExternalLink, onClick: () => window.open(doc.url, '_blank', 'noopener,noreferrer') })),
    ];

    return (
        <div className="space-y-5">
            {data.tables.map((table) => {
                const rows = table.rows.filter((row) => matches(query, row.brand, row.models, row.kind, row.works, row.level.text));
                return (
                    <section key={table.key} className="space-y-3">
                        <ListCaption title={table.title} caption={table.sub} />
                        {rows.length === 0 ? (
                            <QuietLine text={query ? 'No brand here matches your search.' : 'None yet.'} />
                        ) : (
                            <EntityTable<SupportedRow>
                                rows={rows}
                                rowKey={(row) => row.key}
                                identityLabel="Brand and models"
                                wrapIdentity
                                identity={(row) => ({ icon: ListChecks, name: row.brand, subline: [row.models, row.view_only ? 'view only' : null].filter(Boolean).join(' · ') || undefined })}
                                columns={[
                                    { key: 'kind', label: 'Kind', width: '0.8fr', cell: (row) => row.kind },
                                    { key: 'works', label: 'What works', width: '2fr', cell: (row) => <span className="text-sm">{row.works || '—'}</span> },
                                    { key: 'level', label: 'How we know', width: '1.1fr', cell: (row) => <StatusBadge variant={row.level.tone}>{row.level.text}</StatusBadge> },
                                ]}
                                actionsFor={actionsFor}
                                onOpen={canOpenDevices ? showDevices : undefined}
                                onRowContextMenu={(event, row) => menu.open(event, row)}
                                rowHeight="content"
                            />
                        )}
                    </section>
                );
            })}
            {menu.ctx ? (
                <EntityContextMenu x={menu.ctx.x} y={menu.ctx.y} icon={ListChecks} title={menu.ctx.record.brand} items={actionsFor(menu.ctx.record)} onClose={menu.close} />
            ) : null}
            <Note icon={ListChecks}>
                “Tested on real hardware” means we have run it against that brand’s actual device. “Built from vendor docs” works the way the maker describes but hasn’t been tried on
                a real one yet. Hikvision and Dahua cameras have had serious security holes, so keep them on their own camera network with no internet.
            </Note>
        </div>
    );
}
