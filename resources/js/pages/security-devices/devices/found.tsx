/**
 * Found devices (S&D rebuild drawing pg-sdr-14): one scan's results as New · Already added ·
 * Ignored — what each one probably is, how sure we are and why — with Same device? (pop-up 5)
 * and Ignore (pop-up 6). Adding uses today's discovery candidate routes.
 */
import {
    EntityContextMenu,
    EntityTable,
    ProgressValue,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { PopupConfirm, PopupSelectionBar } from '@/components/popup';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import {
    IgnoreFound,
    SameDevice,
    ScanningViewer,
    type FoundCandidate,
} from '@/components/security-devices/scan-popups';
import { Note, QuietLine } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { durationWords, seenTime } from '@/lib/security-devices/device-time';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { CirclePlus, EyeOff, GitMerge, Info, Plus, Radar, SquareArrowOutUpRight } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { BlockHead } from './_parts';

type FoundTab = 'new' | 'added' | 'ignored';

export type FoundRow = FoundCandidate & {
    tab: FoundTab;
    kind: string;
    why: string;
    sure: { pct: number | null; band: 'high' | 'medium' | 'low'; label: string };
    suggested_name: string;
    matched: { id: number; name: string; href: string } | null;
    actionable: boolean;
    decision: string;
};

export type FoundDevicesProps = {
    scan: {
        id: number;
        scope_id: number | null;
        site_id: number | null;
        site: string | null;
        range: string | null;
        runs_from: string;
        status: string;
        running: boolean;
        planned: number;
        started_at: string | null;
        completed_at: string | null;
        cancelled: boolean;
    };
    tab: FoundTab;
    filters: { q: string; sure: 'all' | 'low' };
    counts: { new: number; added: number; ignored: number; unsure: number; addable: number };
    rows: FoundRow[];
    can: { add: boolean; same_as: boolean; ignore: boolean; scan: boolean };
    matchDevices?: { value: string; label: string; description: string }[];
};

const TAB_LABEL: Record<FoundTab, string> = { new: 'New', added: 'Already added', ignored: 'Ignored' };
const TAB_TITLE: Record<FoundTab, string> = { new: 'New devices', added: 'Already added', ignored: 'Ignored' };
const SURE_TONE = { high: 'success', medium: 'success', low: 'warning' } as const;

type Popup =
    | { kind: 'same'; row: FoundRow }
    | { kind: 'ignore'; row: FoundRow }
    | { kind: 'ignore-many'; rows: FoundRow[] }
    | { kind: 'scanning'; runId: number }
    | null;

export default function FoundDevices(props: FoundDevicesProps) {
    const { scan, tab, filters, counts, rows, can } = props;
    const base = `/security-devices/scans/${scan.id}`;
    const [search, setSearch] = useState(filters.q);
    const [popup, setPopup] = useState<Popup>(null);
    const [selecting, setSelecting] = useState(false);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [working, setWorking] = useState(false);
    const searchTimer = useRef<number | null>(null);
    const menu = useEntityContextMenu<FoundRow>();
    const matchDevices = usePage<{ matchDevices?: FoundDevicesProps['matchDevices'] }>().props.matchDevices;

    useEffect(() => {
        setSelected(new Set());
        setSelecting(false);
    }, [tab, filters.q, filters.sure]);

    const go = (changes: Record<string, string | undefined>) => {
        menu.close();
        const next = { tab: tab === 'new' ? undefined : tab, q: filters.q || undefined, sure: filters.sure === 'low' ? 'low' : undefined, ...changes };
        router.get(base, Object.fromEntries(Object.entries(next).filter(([, value]) => value)), { preserveState: true, preserveScroll: true });
    };

    const add = (row: FoundRow) =>
        router.post(`/security-devices/discovery/candidates/${row.id}/adopt`, { name: row.suggested_name || undefined }, { preserveScroll: true });

    const openSame = (row: FoundRow) => {
        if (!matchDevices) router.reload({ only: ['matchDevices'] });
        setPopup({ kind: 'same', row });
    };

    const actionsFor = (row: FoundRow): MenuItem[] => {
        if (row.tab === 'added' && row.matched) {
            return [{ label: `Open ${row.matched.name}`, icon: SquareArrowOutUpRight, onClick: () => router.visit(row.matched!.href) }];
        }
        if (!row.actionable) return [];
        const items: MenuItem[] = [];
        if (can.add) items.push({ label: 'Add device', icon: CirclePlus, onClick: () => add(row) });
        if (can.same_as) items.push({ label: 'Same device?', icon: GitMerge, onClick: () => openSame(row) });
        if (can.ignore) items.push({ separator: true }, { label: 'Ignore', icon: EyeOff, onClick: () => setPopup({ kind: 'ignore', row }) });
        return items;
    };

    const scanAgain = () => {
        if (!scan.scope_id) return;
        axios
            .post<{ run: { id: number } }>(`/security-devices/discovery/scopes/${scan.scope_id}/apply`, {}, { headers: { Accept: 'application/json' } })
            .then(({ data }) => setPopup({ kind: 'scanning', runId: data.run.id }))
            .catch(() => toast.error('The scan couldn’t start. Check the network and its hub, then try again.'));
    };

    const addSelected = async () => {
        setWorking(true);
        let added = 0;
        for (const row of rows.filter((item) => selected.has(item.id))) {
            try {
                await axios.post(`/security-devices/discovery/candidates/${row.id}/adopt`, { name: row.suggested_name || undefined }, { headers: { Accept: 'application/json' } });
                added += 1;
            } catch {
                // Counted below as not added.
            }
        }
        setWorking(false);
        const missed = selected.size - added;
        if (added > 0) toast.success(`Added ${added} ${added === 1 ? 'device' : 'devices'} to Devices.`);
        if (missed > 0) toast.warning(`${missed} couldn’t be added. Try Same device? for those.`);
        setSelected(new Set());
        router.reload();
    };

    const ignoreMany = async (items: FoundRow[]) => {
        setWorking(true);
        for (const row of items) {
            try {
                await axios.post(`/security-devices/discovery/candidates/${row.id}/ignore`, {}, { headers: { Accept: 'application/json' } });
            } catch {
                // The list reload shows what's left.
            }
        }
        setWorking(false);
        setPopup(null);
        setSelected(new Set());
        router.reload();
    };

    const finishedLine = scan.running
        ? `running · started ${seenTime(scan.started_at)}`
        : scan.cancelled
          ? `stopped ${seenTime(scan.completed_at ?? scan.started_at)}`
          : [
                scan.completed_at ? `finished ${seenTime(scan.completed_at)}` : null,
                scan.started_at && scan.completed_at ? `took ${durationWords(scan.started_at, new Date(scan.completed_at))}` : null,
            ]
                .filter(Boolean)
                .join(' · ');
    const title = `Scan of ${scan.site ?? 'a Site'}`;
    const selectable = can.add || can.ignore;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & devices', href: '/security-devices' },
                { title: 'Devices', href: '/security-devices/devices' },
                { title, href: base },
            ]}
        >
            <Head title={title} />
            <PageLayout
                hero={
                    <PageHeader
                        icon={Radar}
                        title={title}
                        subline={[scan.range, `by ${scan.runs_from}`, finishedLine].filter(Boolean).join(' · ')}
                        actions={
                            <>
                                <PageHeaderSearch
                                    value={search}
                                    placeholder="Search found devices"
                                    onChange={(value) => {
                                        setSearch(value);
                                        if (searchTimer.current) window.clearTimeout(searchTimer.current);
                                        searchTimer.current = window.setTimeout(() => go({ q: value.trim() || undefined }), 350);
                                    }}
                                />
                                {can.scan && scan.scope_id ? (
                                    <PageHeaderGlassButton icon={Radar} onClick={scanAgain}>
                                        Scan again
                                    </PageHeaderGlassButton>
                                ) : null}
                                {can.add && counts.addable > 0 && scan.scope_id ? (
                                    <PageHeaderPrimaryButton
                                        icon={Plus}
                                        onClick={() =>
                                            router.post('/security-devices/discovery/candidates/adopt-all', { scope_id: scan.scope_id }, { preserveScroll: true })
                                        }
                                    >
                                        Add all {counts.addable}
                                    </PageHeaderPrimaryButton>
                                ) : null}
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock label="Not sure what it is" onClick={() => go({ tab: undefined, sure: 'low' })} ariaLabel="Show the ones we’re not sure about">
                                    <PageHeaderMeterBig>{counts.unsure}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>{counts.unsure === 0 ? 'We know what each one is' : 'We’ll ask you'}</PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock label="Addresses in range" onClick={() => setPopup({ kind: 'scanning', runId: scan.id })} ariaLabel="Show the scan’s progress">
                                    <PageHeaderMeterBig>{scan.planned}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {scan.running ? 'Still scanning' : scan.cancelled ? 'Stopped before the end' : 'All checked'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={
                            <PageHeaderFilterSelect
                                label="How sure"
                                value={filters.sure}
                                options={[
                                    { value: 'all', label: 'Any' },
                                    { value: 'low', label: 'Not sure' },
                                ]}
                                onChange={(value) => go({ sure: value === 'low' ? 'low' : undefined })}
                            />
                        }
                        rail={
                            <PageHeaderRail<FoundTab>
                                ariaLabel="Found devices"
                                items={(['new', 'added', 'ignored'] as const).map((key) => ({ key, label: TAB_LABEL[key], count: counts[key] }))}
                                value={tab}
                                onSelect={(key) => go({ tab: key === 'new' ? undefined : key })}
                            />
                        }
                    />
                }
            >
                <div className="flex flex-col gap-5">
                    {scan.running ? (
                        <QuietLine
                            text={`Still scanning ${scan.planned} addresses. What it finds shows here.`}
                            action={{ label: 'Watch progress', onClick: () => setPopup({ kind: 'scanning', runId: scan.id }) }}
                        />
                    ) : null}
                    {selecting ? (
                        <PopupSelectionBar count={selected.size} noun="device" onClear={() => setSelected(new Set())}>
                            {can.add && tab === 'new' ? (
                                <Button type="button" variant="outline" disabled={working} onClick={addSelected}>
                                    Add selected
                                </Button>
                            ) : null}
                            {can.ignore && tab === 'new' ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={working}
                                    onClick={() => setPopup({ kind: 'ignore-many', rows: rows.filter((row) => selected.has(row.id)) })}
                                >
                                    Ignore selected
                                </Button>
                            ) : null}
                        </PopupSelectionBar>
                    ) : null}
                    <section className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
                        <div className="border-b border-border px-4 py-3.5">
                            <BlockHead
                                title={TAB_TITLE[tab]}
                                sub="What we think each one is, and why"
                                right={
                                    selectable && tab === 'new' && rows.some((row) => row.actionable) ? (
                                        <Button type="button" variant="outline" size="sm" aria-pressed={selecting} onClick={() => setSelecting((current) => !current)}>
                                            {selecting ? 'Done' : 'Select'}
                                        </Button>
                                    ) : null
                                }
                            />
                        </div>
                        {rows.length === 0 ? (
                            <p className="text-subtle px-4 py-6">
                                {filters.q || filters.sure === 'low'
                                    ? 'Nothing matches. Try another search.'
                                    : tab === 'new'
                                      ? scan.running
                                          ? 'Nothing new yet.'
                                          : 'Nothing new on this network.'
                                      : tab === 'added'
                                        ? 'Nothing from this scan matched your devices.'
                                        : 'Nothing ignored.'}
                            </p>
                        ) : (
                            <EntityTable<FoundRow>
                                className="rounded-none border-0 shadow-none"
                                rows={rows}
                                rowKey={(row) => row.id}
                                identityLabel="What we think it is"
                                identity={(row) => ({ icon: iconForKind(row.kind), name: row.guess, subline: row.sub ?? undefined })}
                                actionsFor={actionsFor}
                                onOpen={(row) => (row.matched ? router.visit(row.matched.href) : undefined)}
                                onRowContextMenu={(event, row) => menu.open(event, row)}
                                rowHeight="content"
                                minWidth={900}
                                selection={
                                    selecting
                                        ? {
                                              keys: selected,
                                              canSelect: (row) => row.actionable,
                                              onToggle: (row, on) =>
                                                  setSelected((current) => {
                                                      const next = new Set(current);
                                                      if (on) next.add(row.id);
                                                      else next.delete(row.id);
                                                      return next;
                                                  }),
                                              labelFor: (row) => `Select ${row.guess}${row.address ? ` at ${row.address}` : ''}`,
                                          }
                                        : undefined
                                }
                                columns={[
                                    { key: 'address', label: 'Address', width: '120px', cell: (row) => row.address ?? '—' },
                                    { key: 'maker', label: 'Maker', width: '0.8fr', cell: (row) => row.maker ?? '—' },
                                    {
                                        key: 'why',
                                        label: tab === 'added' ? 'Matched to' : 'Why we think so',
                                        width: '1.6fr',
                                        cell: (row) =>
                                            tab === 'added' && row.matched ? row.matched.name : <span className="line-clamp-2">{row.why}</span>,
                                    },
                                    {
                                        key: 'sure',
                                        label: 'Sure?',
                                        width: '130px',
                                        cell: (row) => (
                                            <span className="w-full">
                                                <ProgressValue percent={row.sure.pct} tone={SURE_TONE[row.sure.band]}>
                                                    {row.sure.label}
                                                </ProgressValue>
                                            </span>
                                        ),
                                    },
                                ]}
                            />
                        )}
                    </section>
                    {tab === 'new' ? (
                        <Note icon={Info}>
                            Phones and tablets that come and go are found but not watched. Add them only if they belong to the house.
                        </Note>
                    ) : null}
                </div>
            </PageLayout>

            {menu.ctx ? (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    icon={iconForKind(menu.ctx.record.kind)}
                    title={menu.ctx.record.guess}
                    items={actionsFor(menu.ctx.record)}
                    onClose={menu.close}
                />
            ) : null}

            <SameDevice
                open={popup?.kind === 'same'}
                candidate={popup?.kind === 'same' ? popup.row : null}
                site={scan.site}
                devices={matchDevices}
                onClose={() => setPopup(null)}
            />
            <IgnoreFound open={popup?.kind === 'ignore'} candidate={popup?.kind === 'ignore' ? popup.row : null} onClose={() => setPopup(null)} />
            <PopupConfirm
                open={popup?.kind === 'ignore-many'}
                onClose={() => setPopup(null)}
                onConfirm={() => popup?.kind === 'ignore-many' && ignoreMany(popup.rows)}
                icon={EyeOff}
                title={`Ignore ${popup?.kind === 'ignore-many' ? popup.rows.length : 0} devices?`}
                description="They move to the Ignored tab and aren’t added."
                confirmLabel="Ignore devices"
                processing={working}
            />
            <ScanningViewer
                open={popup?.kind === 'scanning'}
                runId={popup?.kind === 'scanning' ? popup.runId : null}
                canStop={can.scan}
                onClose={() => setPopup(null)}
            />
        </AppLayout>
    );
}
