/**
 * Site › Network map: how each link was found (S&D drawing pg-sdr-12j). Beside the map, where the
 * links come from; below it every link the hub and connected services found, how sure Oblivion is,
 * when it was last seen and whether it's on the map; then what changed between checks and the
 * devices nothing reports. Found links never join the map by themselves: Add to the map and Take
 * off the map are on each row's menu, for people who may edit devices.
 */
import { EntityContextMenu, EntityTable, useEntityContextMenu, type EntityTableColumn, type MenuItem } from '@/components/lists';
import { PopupConfirm, PopupField } from '@/components/popup';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import { KnowList, Log, QuietLine } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuLabel, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { formatDateOnly } from '@/lib/datetime';
import { seenTime } from '@/lib/security-devices/device-time';
import { router } from '@inertiajs/react';
import { ChevronDown, Cpu, Link2, Unlink } from 'lucide-react';
import { useMemo, useState } from 'react';
import { BlockHead, ColumnsMenu, useColumns, type ColumnChoice } from '../devices/_parts';
import type { FoundLink, LinksFound } from './_types';

const COLUMNS: ColumnChoice[] = [
    { key: 'by', label: 'Found by' },
    { key: 'ports', label: 'Ports' },
    { key: 'sure', label: 'How sure' },
    { key: 'seen', label: 'Last seen' },
    { key: 'map', label: 'On the map' },
];

/** The note under a link's map state: "{time}" is a time, "{date}" a day. */
function mapNote(link: FoundLink, now: Date): string {
    const at = link.map.at;
    if (!at) return link.map.note.replace(/\{time\}|\{date\}/g, '—');
    return link.map.note.replace('{time}', seenTime(at, now)).replace('{date}', formatDateOnly(at.slice(0, 10)));
}

/** Beside the map: the hub and services that report links, and what the map shows. */
export function WhereLinksComeFrom({ links, now }: { links: LinksFound; now: Date }) {
    return (
        <KnowList
            title="Where the links come from"
            items={[
                ...links.sources.map((source) => ({
                    tone: 'info' as const,
                    text: (
                        <>
                            <b>{source.who}</b> {source.text}
                            {source.at ? (
                                <>
                                    {' '}
                                    · last at <b>{seenTime(source.at, now)}</b>
                                </>
                            ) : null}
                        </>
                    ),
                })),
                {
                    tone: 'success' as const,
                    text: (
                        <>
                            <b>The map shows links a person added or confirmed.</b> A found link waits below until someone adds it
                        </>
                    ),
                },
                {
                    tone: 'none' as const,
                    text: `Links that Oblivion is at least ${links.threshold}% sure of also decide which devices show Can’t check when the one before them goes offline.`,
                },
            ]}
        />
    );
}

export function LinksFoundTable({ links, siteName, now }: { links: LinksFound; siteName: string; now: Date }) {
    const menu = useEntityContextMenu<FoundLink>();
    const columns = useColumns('sd.site.links.columns', COLUMNS, COLUMNS.map((column) => column.key));
    const [source, setSource] = useState('all');
    const [removing, setRemoving] = useState<FoundLink | null>(null);
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const sources = useMemo(() => [...new Set(links.rows.flatMap((row) => row.by.split(/, | and /)))].sort(), [links.rows]);
    const rows = source === 'all' ? links.rows : links.rows.filter((row) => row.by.split(/, | and /).includes(source));

    const add = (link: FoundLink) => {
        menu.close();
        router.post(
            `/security-devices/devices/${link.parent_id}/relationships`,
            { other_device_id: link.child_id, relationship_type: 'connected_to', direction: 'downstream', port: link.ports ? link.ports.slice(0, 64) : null },
            { preserveScroll: true },
        );
    };
    const takeOff = () => {
        if (!removing?.relationship || reason.trim() === '') {
            setError('Say why it comes off the map.');
            return;
        }
        setProcessing(true);
        router.delete(`/security-devices/devices/${removing.relationship.device_id}/relationships/${removing.relationship.id}`, {
            data: { reason: reason.trim() },
            preserveScroll: true,
            onSuccess: () => setRemoving(null),
            onError: (errors) => setError(String(Object.values(errors)[0] ?? 'It couldn’t be taken off the map. Try again.')),
            onFinish: () => setProcessing(false),
        });
    };
    const actionsFor = (link: FoundLink): MenuItem[] => [
        ...(links.can.change && !link.relationship ? [{ label: 'Add to the map', icon: Link2, onClick: () => add(link) }] : []),
        ...(links.can.change && link.relationship
            ? [
                  {
                      label: 'Take off the map',
                      icon: Unlink,
                      onClick: () => {
                          menu.close();
                          setReason('');
                          setError(null);
                          setRemoving(link);
                      },
                  },
              ]
            : []),
        { label: 'Open the device', icon: Cpu, onClick: () => router.visit(link.child_href) },
    ];
    const all: Record<string, EntityTableColumn<FoundLink>> = {
        by: { key: 'by', label: 'Found by', width: '1fr', cell: (row) => <span className="py-1 whitespace-normal">{row.by}</span> },
        ports: { key: 'ports', label: 'Ports', width: '1.1fr', cell: (row) => <span className="py-1 whitespace-normal">{row.ports ?? '—'}</span> },
        sure: { key: 'sure', label: 'How sure', width: '90px', cell: (row) => <span className="whitespace-nowrap tabular-nums">{row.sure}%</span> },
        seen: { key: 'seen', label: 'Last seen', width: '150px', cell: (row) => <span className="whitespace-nowrap">{row.seen_at ? seenTime(row.seen_at, now) : '—'}</span> },
        map: {
            key: 'map',
            label: 'On the map',
            width: '1.3fr',
            cell: (row) => (
                <span className="flex min-w-0 flex-col items-start gap-1 py-1">
                    <StatusBadge variant={row.map.tone} size="sm">
                        {row.map.label}
                    </StatusBadge>
                    <span className="max-w-full text-xs whitespace-normal text-muted-foreground">{mapNote(row, now)}</span>
                </span>
            ),
        },
    };

    return (
        <section className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
            <div className="border-b border-border px-4 py-3.5">
                <BlockHead
                    title="Links found"
                    sub={`What the hub and connected services found at ${siteName}, problems first · ⋮ or right-click a row${links.can.change ? ': Add to the map, Take off the map, Open the device' : ' to open the device'}`}
                    right={
                        <>
                            {sources.length > 1 ? (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button type="button" variant="outline" size="sm">
                                            {source === 'all' ? 'Every source' : source}
                                            <ChevronDown className="size-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" className="w-52">
                                        <DropdownMenuLabel>Found by</DropdownMenuLabel>
                                        <DropdownMenuRadioGroup value={source} onValueChange={setSource}>
                                            <DropdownMenuRadioItem value="all">Every source</DropdownMenuRadioItem>
                                            {sources.map((name) => (
                                                <DropdownMenuRadioItem key={name} value={name}>
                                                    {name}
                                                </DropdownMenuRadioItem>
                                            ))}
                                        </DropdownMenuRadioGroup>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            ) : null}
                            <ColumnsMenu all={COLUMNS} shown={columns.shown} onToggle={columns.toggle} />
                        </>
                    }
                />
            </div>
            {rows.length === 0 ? (
                <p className="text-subtle px-4 py-6">No links found yet. The hub and connected services report them after their next check.</p>
            ) : (
                <EntityTable<FoundLink>
                    className="rounded-none border-0 shadow-none"
                    rows={rows}
                    rowKey={(row) => row.key}
                    identityLabel="Link"
                    identity={(row) => ({ icon: iconForKind(row.child_kind ?? 'other'), name: row.name, subline: row.sub, linkLabel: `Open ${row.name.split(' → ').pop()}` })}
                    hrefFor={(row) => row.child_href}
                    columns={columns.shown.map((key) => all[key]).filter(Boolean)}
                    actionsFor={actionsFor}
                    onRowContextMenu={(event, row) => menu.open(event, row)}
                    rowHeight="content"
                    minWidth={880}
                />
            )}
            <div className="border-t border-border px-4 py-3">
                <span className="text-subtle">
                    {rows.length === 0 ? 'No links' : `Showing ${rows.length} of ${links.rows.length}`}
                </span>
            </div>

            {menu.ctx ? (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    icon={iconForKind(menu.ctx.record.child_kind ?? 'other')}
                    title={menu.ctx.record.name}
                    items={actionsFor(menu.ctx.record)}
                    onClose={menu.close}
                />
            ) : null}
            <PopupConfirm
                open={removing !== null}
                onClose={() => setRemoving(null)}
                onConfirm={takeOff}
                icon={Unlink}
                title={`Take ${removing?.name ?? 'this link'} off the map?`}
                description="The map stops drawing it. What the hub found stays in Links found, and you can add it again."
                confirmLabel="Take off the map"
                processing={processing}
                processingLabel="Taking it off…"
                confirmDisabled={reason.trim() === ''}
                error={error ?? undefined}
                testId="sd-take-link-off"
            >
                <PopupField label="Why it comes off" htmlFor="link-off-reason" required>
                    <Textarea
                        id="link-off-reason"
                        rows={3}
                        maxLength={1000}
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        placeholder="e.g. The TV was moved to the office"
                    />
                </PopupField>
            </PopupConfirm>
        </section>
    );
}

export function LinkChanges({ links, now }: { links: LinksFound; now: Date }) {
    if (links.changes.length === 0) {
        return <QuietLine title="Changes since the last checks" text="No link was added, lost or moved in the checks Oblivion keeps." />;
    }

    return (
        <Log
            title="Changes since the last checks"
            sub="Newest first"
            limit={12}
            items={links.changes.map((change) => ({
                key: change.key,
                time: change.at ? seenTime(change.at, now) : '',
                tone: change.tone,
                title: change.title,
                sub: change.sub,
            }))}
        />
    );
}

export function NotPlaced({ links }: { links: LinksFound }) {
    const { count, names, more } = links.unplaced;
    if (count === 0) {
        return <QuietLine title="Not placed yet" text="Every device here is on a link the hub or a person has reported." />;
    }
    const list = [...names, ...(more > 0 ? [`${more} more`] : [])];
    const words = list.length <= 1 ? list[0] : `${list.slice(0, -1).join(', ')} and ${list[list.length - 1]}`;

    return (
        <QuietLine
            title="Not placed yet"
            text={`Nothing reports a link for ${words}, so ${count === 1 ? 'it isn’t' : 'they aren’t'} on the map. Devices that talk by radio to a sensor gateway are often like this.`}
        />
    );
}
