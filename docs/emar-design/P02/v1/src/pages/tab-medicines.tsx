/* Medicines (current · stopped · photos) and Support plan (by medicine ·
 * assessment). Orders are changed in Orders & reviews (P04); support is changed
 * through a reassessment (P03); pack photos are taken at stock receipt (P06).
 * This record shows them — one place to edit each fact. */
import { FilePreviewDialog, type PreviewFile } from '@/components/files/file-preview-dialog';
import { EntityContextMenu, compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import { AlertTriangle, ClipboardList, Download, Eye, History, ImageOff, Info, LockKeyhole, Pill, Users, ArrowUpRight } from 'lucide-react';
import { useState, type MouseEvent } from 'react';
import { FACTS, SUPPORT_ASSESSMENT, medByKey, medsOf, type Medicine, type PersonId } from '../data';
import { PEOPLE } from '../p01/data';
import { DesignNote, Notice, SUPPORT, SupportChip } from '../p01/ui';
import { useDlg, useP02 } from '../store';
import { CONCEALED, ConcealedCaption, FactStrip, SectionCard } from '../ui';
import { useMedMenu } from './tab-chart';

function useCtx() {
    const [ctx, setCtx] = useState<{ x: number; y: number; title: string; items: MenuItem[] } | null>(null);
    const openAt = (e: MouseEvent, title: string, items: MenuItem[]) => {
        e.preventDefault();
        const b = (e.currentTarget as HTMLElement).getBoundingClientRect();
        const kb = e.clientX === 0 && e.clientY === 0;
        setCtx({ x: kb ? b.left + 12 : e.clientX, y: kb ? b.top + 12 : e.clientY, title, items });
    };
    const node = ctx ? <EntityContextMenu x={ctx.x} y={ctx.y} icon={Pill} title={ctx.title} items={ctx.items} onClose={() => setCtx(null)} /> : null;
    return { openAt, node };
}
const concealedIdentity = { icon: LockKeyhole, name: CONCEALED.name, subline: CONCEALED.subline };
const whyHidden = (toast: (k: 'info', m: string) => void): MenuItem[] => [{ label: 'Why is this hidden?', icon: Info, onClick: () => toast('info', `${CONCEALED.subline}. ${CONCEALED.ask}`) }];

function PhotoState({ m }: { m: Medicine }) {
    const p = m.photos.find((x) => x.state !== 'replaced');
    if (!p)
        return (
            <span className="inline-flex items-center gap-1 text-[12px] text-muted-foreground">
                <ImageOff className="size-3.5" aria-hidden="true" /> No photo — check the label
            </span>
        );
    return p.state === 'changed' ? (
        <StatusBadge variant="warning" className="rounded-[8px]">
            <AlertTriangle className="size-3" aria-hidden="true" /> Pack or brand changed
        </StatusBadge>
    ) : (
        <span className="text-[12px]">Photo {p.taken}</span>
    );
}

/* ───────────── Medicines ───────────── */
export function MedicinesTab({ pid, view }: { pid: PersonId; view: string }) {
    const s = useP02();
    const dlg = useDlg();
    const medMenu = useMedMenu();
    const ctx = useCtx();
    const state = s.route.state;
    if (state === 'loading')
        return (
            <Card className="p-4">
                <SkeletonTable rows={6} columns={5} />
            </Card>
        );
    const type = s.route.q.get('type');
    const attn = s.route.q.get('attn') === '1';
    const all = state === 'empty' ? [] : medsOf(pid);
    if (view === 'photos') return <Photos pid={pid} />;
    const stopped = view === 'stopped';
    const base = all.filter((m) => (stopped ? m.status === 'stopped' : m.status !== 'stopped'));
    const rows = base.filter((m) => (!type || m.kind === type) && (!attn || m.status === 'awaiting' || m.photos.some((p) => p.state === 'changed')));
    const hidden = s.cdView ? 0 : rows.filter((m) => m.cd).length;
    return (
        <section aria-label={stopped ? 'Stopped medicines' : 'Current medicines'} className="flex flex-col gap-2.5">
            <ListCaption
                title={stopped ? 'Stopped medicines' : 'Current medicines'}
                caption={
                    <>
                        {rows.length} of {base.length} shown{hidden ? ' · ' : ''}
                        <ConcealedCaption n={hidden} />
                    </>
                }
                right={s.can('orders.manage') ? <Button size="sm" variant="link" onClick={() => s.go('/emar/prescriptions', { client_id: String(FACTS[pid].clientId) })}>Add or change an order <ArrowUpRight className="size-4" aria-hidden="true" /></Button> : null}
            />
            {rows.length ? (
                <EntityTable<Medicine>
                    rows={rows}
                    rowKey={(m) => m.key}
                    identityLabel="Medicine"
                    identityWidth="2fr"
                    rowHeight="content"
                    minWidth={1000}
                    identity={(m) => (m.cd && !s.cdView ? concealedIdentity : { icon: Pill, name: `${m.name} ${m.strength}`, subline: `${m.amount} · ${m.route}` })}
                    columns={
                        stopped
                            ? [
                                  { key: 'st', label: 'Stopped', width: '1.2fr', cell: (m) => <span className="text-[12.5px]">{m.stopped!.on} by {m.stopped!.by}</span> },
                                  { key: 'why', label: 'Reason recorded', width: '1.4fr', cell: (m) => <span className="text-[12.5px]">{m.stopped!.reason}</span> },
                                  { key: 'ran', label: 'Ran from', width: '1fr', cell: (m) => <span className="text-[12.5px]">{m.started}</span> },
                              ]
                            : [
                                  { key: 'when', label: 'When', width: '1.2fr', cell: (m) => (m.cd && !s.cdView ? <span className="text-muted-foreground">—</span> : <span className="text-[12.5px]">{m.kind === 'prn' ? 'As needed' : m.when}</span>) },
                                  { key: 'sup', label: 'Support', width: '1fr', cell: (m) => (m.cd && !s.cdView ? <span className="text-muted-foreground">—</span> : <SupportChip support={m.support} />) },
                                  { key: 'ord', label: 'Order', width: '1.4fr', cell: (m) => (m.cd && !s.cdView ? <span className="text-muted-foreground">—</span> : m.status === 'awaiting' ? <StatusBadge variant="warning" className="rounded-[8px]">Waiting to be checked</StatusBadge> : <span className="text-[12px]">{m.order}</span>) },
                                  { key: 'ph', label: 'Pack photo', width: '1.1fr', cell: (m) => (m.cd && !s.cdView ? <span className="text-muted-foreground">—</span> : <PhotoState m={m} />) },
                              ]
                    }
                    actionsFor={(m) => (m.cd && !s.cdView ? whyHidden(s.toast) : medMenu(m))}
                    onOpen={(m) => (m.cd && !s.cdView ? s.toast('info', `${CONCEALED.subline}. ${CONCEALED.ask}`) : dlg.open(`med:${m.key}`))}
                    onRowContextMenu={(e, m) => ctx.openAt(e, m.cd && !s.cdView ? CONCEALED.name : `${m.name} ${m.strength}`, m.cd && !s.cdView ? whyHidden(s.toast) : medMenu(m))}
                    mutedFor={(m) => m.status === 'stopped'}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState
                        variant="compact"
                        icon={Pill}
                        title={state === 'empty' ? `No medicines on ${PEOPLE[pid].pref}’s chart` : stopped ? 'No stopped medicines' : 'No medicines match these filters'}
                        description={state === 'empty' ? 'New orders are added and checked in Orders & reviews.' : stopped ? 'Stopped orders stay here with who stopped them and why.' : 'Clear the filters in the header to see every medicine.'}
                    />
                </Card>
            )}
            {ctx.node}
            {!stopped ? <p className="text-caption">Click a medicine for its order, how it’s given, its pack photo and recent doses. Orders are added, changed and stopped in Orders &amp; reviews — never here.</p> : null}
        </section>
    );
}

/* Photo history: staff photos of the supplied pack (Stephan, 29 Sep). Display only here; taken at stock receipt (P06). */
function Photos({ pid }: { pid: PersonId }) {
    const s = useP02();
    const ctx = useCtx();
    const [file, setFile] = useState<PreviewFile | null>(null);
    const only = s.route.q.get('med');
    const meds = medsOf(pid).filter((m) => (s.cdView || !m.cd) && (!only || m.key === only));
    const rows = meds.flatMap((m) => m.photos.map((p, i) => ({ id: `${m.key}-${i}`, m, p })));
    const hidden = s.cdView ? 0 : medsOf(pid).filter((m) => m.cd && m.photos.length).length;
    const view = (r: (typeof rows)[number]) =>
        setFile({ id: r.id, name: `${r.m.name} ${r.m.strength} — photo of the supplied pack`, filename: r.p.file, mime: 'image/png', source: `Taken ${r.p.taken} by ${r.p.by} · ${r.p.pack} · synthetic placeholder`, previewUrl: `/photos/${r.p.file}`, downloadUrl: `/photos/${r.p.file}` });
    const menu = (r: (typeof rows)[number]): MenuItem[] => compactMenu([{ label: 'View photo', icon: Eye, onClick: () => view(r) }, { label: 'Download the original', icon: Download, onClick: () => s.toast('info', 'Downloads the original file (FilePreviewDialog’s Download) — outside this preview.') }, { separator: true }, { label: 'View medicine details', icon: Info, onClick: () => s.set({ dlg: `med:${r.m.key}` }) }]);
    return (
        <section aria-label="Pack photos" className="flex flex-col gap-2.5">
            <ListCaption
                title={only ? `Pack photos · ${medByKey(only).name}` : 'Pack photos'}
                caption={
                    <>
                        {rows.length} {rows.length === 1 ? 'photo' : 'photos'} · newest first{hidden ? ' · ' : ''}
                        <ConcealedCaption n={hidden} listed={false} />
                    </>
                }
                right={only ? <Button size="sm" variant="ghost" onClick={() => s.set({ med: undefined })}>Show every medicine</Button> : null}
            />
            <Notice tone="neutral" icon={Info} title="Check the label — the picture is a guide only">
                Staff take these photos when stock is received (Stock &amp; pharmacy). They’re optional and never block recording. When the pack or brand changes, the older photo is kept here and marked replaced.
            </Notice>
            {rows.length ? (
                <EntityTable
                    rows={rows}
                    rowKey={(r) => r.id}
                    identityLabel="Photo"
                    identityWidth="2.2fr"
                    rowHeight="content"
                    minWidth={900}
                    identity={(r) => ({
                        mark: <img src={`./photos/${r.p.file}`} alt="" className="size-[42px] rounded-[8px] border object-cover" />,
                        name: `${r.m.name} ${r.m.strength}`,
                        subline: r.p.pack,
                    })}
                    columns={[
                        { key: 'taken', label: 'Taken', width: '1.2fr', cell: (r) => <span className="text-[12.5px]">{r.p.taken} by {r.p.by}</span> },
                        {
                            key: 'st',
                            label: 'Status',
                            width: '1.3fr',
                            cell: (r) =>
                                r.p.state === 'current' ? (
                                    <StatusBadge variant="success" className="rounded-[8px]">Current pack</StatusBadge>
                                ) : r.p.state === 'changed' ? (
                                    <StatusBadge variant="warning" className="rounded-[8px]">Pack or brand changed — check the label</StatusBadge>
                                ) : (
                                    <StatusBadge variant="neutral" className="rounded-[8px]">Replaced</StatusBadge>
                                ),
                        },
                    ]}
                    actionsFor={menu}
                    onOpen={view}
                    onRowContextMenu={(e, r) => ctx.openAt(e, `${r.m.name} photo`, menu(r))}
                    mutedFor={(r) => r.p.state === 'replaced'}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState variant="compact" icon={ImageOff} title="No pack photos yet" description="A photo can be added when stock is received. It’s optional." />
                </Card>
            )}
            {ctx.node}
            <FilePreviewDialog file={file} onClose={() => setFile(null)} />
        </section>
    );
}

/* ───────────── Support plan ───────────── */
export function SupportTab({ pid, view }: { pid: PersonId; view: string }) {
    const s = useP02();
    const dlg = useDlg();
    const medMenu = useMedMenu();
    const ctx = useCtx();
    const a = SUPPORT_ASSESSMENT[pid];
    const sup = s.route.q.get('sup');
    if (s.route.state === 'loading')
        return (
            <Card className="p-4">
                <SkeletonTable rows={5} columns={4} />
            </Card>
        );
    const toP03 = () => s.toast('info', 'Support & self-administration (assessment, agreement, reassessment) is designed in P03 — outside this preview.');
    if (view === 'assessment')
        return a ? (
            <SectionCard eyebrow="Self-administration assessment" title={a.outcome} icon={ClipboardList} right={<Button variant="link" onClick={toP03}>Open in Support &amp; self-administration <ArrowUpRight className="size-4" aria-hidden="true" /></Button>}>
                <FactStrip
                    items={[
                        { label: 'Assessed', value: `${a.assessed} · ${a.by}` },
                        { label: 'Reassess by', value: a.reassess },
                        { label: 'Agreement', value: a.agreement },
                    ]}
                />
                <p className="text-sm">
                    <span className="text-muted-foreground">Storage · </span>
                    {a.storage}
                </p>
                <p className="text-caption">Support for each medicine comes from this assessment. It changes only through a reassessment — never by editing a medicine.</p>
            </SectionCard>
        ) : (
            <Card className="p-2">
                <EmptyState icon={ClipboardList} title={`No self-administration assessment for ${PEOPLE[pid].pref}`} description="Until one is done, staff give every medicine (Administer). Leads start an assessment in Support & self-administration." action={s.can('orders.manage') ? <Button variant="link" onClick={toP03}>Start an assessment <ArrowUpRight className="size-4" aria-hidden="true" /></Button> : undefined} />
            </Card>
        );
    const meds = medsOf(pid).filter((m) => m.status !== 'stopped' && (!sup || m.support === sup));
    const hidden = s.cdView ? 0 : meds.filter((m) => m.cd).length;
    const menu = (m: Medicine): MenuItem[] => compactMenu([...medMenu(m), { separator: true }, { label: 'Change support (reassessment)', icon: Users, onClick: toP03 }]);
    return (
        <section aria-label="Support by medicine" className="flex flex-col gap-2.5">
            <ListCaption
                title="Support by medicine"
                caption={
                    <>
                        {meds.length} of {meds.length} shown{hidden ? ' · ' : ''}
                        <ConcealedCaption n={hidden} />
                        {a ? ` · from the assessment of ${a.assessed}` : ' · no assessment yet'}
                    </>
                }
            />
            {meds.length ? (
                <EntityTable<Medicine>
                    rows={meds}
                    rowKey={(m) => m.key}
                    identityLabel="Medicine"
                    identityWidth="1.8fr"
                    rowHeight="content"
                    minWidth={960}
                    identity={(m) => (m.cd && !s.cdView ? concealedIdentity : { icon: Pill, name: `${m.name} ${m.strength}`, subline: m.kind === 'prn' ? 'As needed' : m.when })}
                    columns={[
                        { key: 'sup', label: 'Support', width: '1fr', cell: (m) => (m.cd && !s.cdView ? <span className="text-muted-foreground">—</span> : <SupportChip support={m.support} />) },
                        { key: 'means', label: 'What staff do', width: '1.4fr', cell: (m) => (m.cd && !s.cdView ? <span className="text-muted-foreground">—</span> : <span className="text-[12.5px]">{SUPPORT[m.support].desc}</span>) },
                        { key: 'from', label: 'Decided', width: '1.3fr', cell: () => <span className="text-[12.5px]">{a ? `${a.assessed} · ${a.by}` : 'No assessment — staff give it'}</span> },
                        { key: 'rev', label: 'Reassess by', width: '0.9fr', cell: () => <span className="text-[12.5px]">{a?.reassess ?? '—'}</span> },
                    ]}
                    actionsFor={(m) => (m.cd && !s.cdView ? whyHidden(s.toast) : menu(m))}
                    onOpen={(m) => (m.cd && !s.cdView ? s.toast('info', `${CONCEALED.subline}. ${CONCEALED.ask}`) : dlg.open(`med:${m.key}:how`))}
                    onRowContextMenu={(e, m) => ctx.openAt(e, m.cd && !s.cdView ? CONCEALED.name : m.name, m.cd && !s.cdView ? whyHidden(s.toast) : menu(m))}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState variant="compact" icon={Users} title="No medicines with this support" description="Clear the Support filter in the header." />
                </Card>
            )}
            {ctx.node}
            <DesignNote title="Design note — support words">
                <p>Shown with the P00 support words (Administer · Assist · Prompt · Independent). Today’s assessment stores three values per medicine (staff given · prompted · self-managed) — mapping them is decision D6, owned by P03.</p>
            </DesignNote>
        </section>
    );
}
