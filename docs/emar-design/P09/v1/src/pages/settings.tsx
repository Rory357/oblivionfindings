/* Medication › Settings — the P11 v5 frame, showing P09’s two ADDITIONS TO
 * P11 (Main, Q7 and Q11, built with P09, not in B1): how long medication
 * records are kept, and SAC ratings for adverse-event reporting. The page top
 * is P11 v5’s approved Settings header, rail and section strip with its
 * reference numbers (as Hana Kereama sees them, as at 28 Sep); Medication
 * rules gains a “Records & reporting” section drawn in P11’s exact group/row
 * pattern, with “Default — not yet reviewed”, the sticky save bar, “Review …
 * changes” and the change history. Every other view and section is P11’s (or
 * an approved package’s addition) and link-only. */
import { EntityChip } from '@/components/lists/entity-cells';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { Modal as FleetModal, Notice as FleetNotice, Sections } from '@/pages/fleet-assets/settings/_ui';
import { Activity, AlertOctagon, Archive, ArrowUpRight, Bell, Building2, Clock, Eye, History, HelpCircle, Home, Lock, Pencil, Pill, RefreshCw, Repeat, Scale, Settings as SettingsIcon, Shield, Stethoscope, UserCheck } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { PERSONAS, P11_HISTORY, RETENTION_LABEL, SAC_DEFAULT, has, type Sac } from '../data';
import { useOpen } from '../host';
import { canSetOrg, housesOf, type OrgRecords, type Retention } from '../model';
import { Shell } from '../shell';
import { useStore } from '../store';
import { DesignNote } from '../ui';
import { Choice, GroupGrid, GroupRow, SaveBar, Section, SettingGroup } from '../p11-ui';
import { useCtx } from './hub';

const SET_VIEWS: Record<string, { label: string; icon: typeof Pill; secs: [string, string, typeof Pill][] }> = {
    rules: { label: 'Medication rules', icon: Pill, secs: [['overview', 'Overview', Activity], ['medicines', 'Medicine rules', Pill], ['safety', 'Safety checks', Shield], ['controlled', 'Controlled drugs', Lock], ['photos', 'Medicine photos', Pill], ['records', 'Records & reporting', Archive]] },
    rounds: { label: 'Rounds & timing', icon: Repeat, secs: [['overview', 'Overview', Activity], ['templates', 'Round templates', Repeat], ['timing', 'Dose timing', Clock], ['reviews', 'Medication reviews', Stethoscope]] },
    staff: { label: 'Staff & PINs', icon: UserCheck, secs: [['overview', 'Overview', Activity], ['competency', 'Competency', UserCheck], ['exemptions', 'Exemption limit', Shield], ['pins', 'Witness PINs', Lock], ['status', 'PIN status', UserCheck]] },
    alerts: { label: 'Alerts & access', icon: Bell, secs: [['overview', 'Overview', Activity], ['alerts', 'Alerts', Bell], ['delivery', 'Delivery', Bell], ['triage', 'Error triage', AlertOctagon], ['oncall', 'On-call contacts', Bell], ['emergency', 'Emergency access', Lock], ['log', 'Alert log', History]] },
    history: { label: 'Change history', icon: History, secs: [['decide', 'Still to decide', HelpCircle], ['changes', 'All changes', History]] },
};
/** P11 v5’s “Still to decide” as at its approved version, plus P05’s and P08b’s approved additions (not yet reviewed). */
const P11_OPEN = 45 + 1 + 1;
const DirtyDot = () => <span role="img" aria-label="Unsaved changes" className="size-2 rounded-full bg-status-warning" />;
const RET_OPTS = (Object.keys(RETENTION_LABEL) as Retention[]).map((k) => [k, RETENTION_LABEL[k].replace(' after the person’s last service', '')] as [Retention, string]);
const SAC_OPTS: [string, string][] = [['1', 'SAC 1'], ['2', 'SAC 2'], ['3', 'SAC 3'], ['4', 'SAC 4']];
const LINKED_SECS: Record<string, string> = { reviews: 'Medication reviews is P05’s approved addition', triage: 'Error triage is P08b’s approved addition' };

/** The saved values with the unsaved draft on top. */
const merged = (org: OrgRecords, d: Partial<OrgRecords> | null): OrgRecords => ({ ...org, ...(d ?? {}), map: { ...org.map, ...(d?.map ?? {}) } });
function changesOf(org: OrgRecords, d: Partial<OrgRecords> | null) {
    const m = merged(org, d);
    const out: { key: string; label: string; from: string; to: string }[] = [];
    if (m.retention !== org.retention) out.push({ key: 'retention', label: 'Keep medication records for', from: RETENTION_LABEL[org.retention], to: RETENTION_LABEL[m.retention] });
    if (m.sac !== org.sac) out.push({ key: 'sac', label: 'Add SAC ratings when an error is closed', from: org.sac ? 'On' : 'Off', to: m.sac ? 'On' : 'Off' });
    (['death', 'moderate', 'minor'] as const).forEach((k) => {
        if (m.map[k] !== org.map[k]) out.push({ key: `map-${k}`, label: `SAC for ${k === 'death' ? 'death' : k === 'moderate' ? 'moderate harm' : 'minor or no harm'}`, from: `SAC ${org.map[k]}`, to: `SAC ${m.map[k]}` });
    });
    return out;
}

export function SettingsPage() {
    const s = useStore();
    const r = s.route;
    const p = r.persona;
    const me = PERSONAS[p];
    const open = useOpen();
    const view = SET_VIEWS[r.q.get('view') ?? ''] ? r.q.get('view')! : 'rules';
    const secs = SET_VIEWS[view].secs;
    const sec = secs.some(([k]) => k === r.q.get('sec')) ? r.q.get('sec')! : view === 'rules' ? 'records' : secs[0][0];
    const [query, setQuery] = useState('');
    const org = s.rt.org;
    const changes = changesOf(org, s.rt.orgDraft);
    const dirty = changes.length > 0;
    const open11 = P11_OPEN + (org.retentionReviewed ? 0 : 1) + (org.sacReviewed ? 0 : 1);
    const ro = !canSetOrg(p) || r.scenario === 'offline';
    const auditor = !has(p, 'errors.manage') && has(p, 'audit.view');
    const goto = (v: string, sc?: string) => s.set({ view: v, sec: sc, open: undefined });
    const flashRef = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (s.rt.flash) flashRef.current?.focus();
    }, [s.rt.flash]);

    const accessText = auditor ? 'Read-only for audit' : canSetOrg(p) ? 'All-sites authority · emergency access policy read-only' : `House settings for ${housesOf(p).map((h) => (h === 'kowhai' ? 'Kōwhai' : 'Rimu')).join(' and ')} · organisation rules read-only`;
    const header = (
        <PageHeader
            className="overflow-clip!"
            icon={SettingsIcon}
            title="Settings"
            titleChip={<PageHeaderStatusChip variant="neutral">Organisation</PageHeaderStatusChip>}
            subline="Medication rules and house settings · times in NZDT (Pacific/Auckland)"
            actions={
                <>
                    <PageHeaderSearch value={query} onChange={setQuery} placeholder={`Search ${secs.find(([k]) => k === sec)![1].replace(/^[A-Z](?![A-Z])/, (c) => c.toLowerCase())}`} />
                    <PageHeaderGlassButton icon={Home} onClick={() => s.toast('info', 'At a house is P11’s — outside this preview.')}>
                        At a house
                    </PageHeaderGlassButton>
                    <PageHeaderGlassButton icon={History} onClick={() => goto('history', 'changes')}>
                        Changes
                    </PageHeaderGlassButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock label="Still to decide" tone="warning" ariaLabel={`View ${open11} settings still to decide`} onClick={() => goto('history', 'decide')}>
                        <PageHeaderMeterBig>{open11}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>5 not configured · the rest are defaults</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Medicine rules" ariaLabel="View medicine rules, 2 active" onClick={() => goto('rules', 'medicines')}>
                        <PageHeaderMeterBig>2 active</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>1 paused · 2 overlap</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Round templates" ariaLabel="View round templates, 6 active" onClick={() => goto('rounds', 'templates')}>
                        <PageHeaderMeterBig>6 active</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>1 paused · 2 houses</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Witness PINs" tone="warning" ariaLabel="View witness PIN status, 6 of 10 set" onClick={() => goto('staff', 'status')}>
                        <PageHeaderMeterDonut
                            percent={60}
                            caption={
                                <>
                                    6 of 10 set
                                    <br />1 locked · 3 to set
                                </>
                            }
                        />
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="On-call contacts" tone="warning" ariaLabel="View on-call contacts, 0 of 2 set" onClick={() => goto('alerts', 'oncall')}>
                        <PageHeaderMeterBig>0 of 2</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>2 not configured</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <span className="mr-2 inline-flex items-center gap-1.5 text-[11px] text-primary-foreground/80">
                        {auditor ? <Eye className="size-3" /> : <Shield className="size-3" />}
                        {accessText}
                    </span>
                    {sec === 'records' ? <PageHeaderFilterSelect label="All settings" value={r.q.get('show') ?? 'all'} allValue="all" onChange={(v) => s.set({ show: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All settings' }, { value: 'open', label: 'Not yet reviewed' }, { value: 'changed', label: 'Unsaved changes' }]} /> : null}
                    <PageHeaderFilterButton icon={RefreshCw} onClick={() => s.toast('success', 'Up to date · 9:12 am NZDT')} aria-label="Updated 9:12 am NZDT — refresh">
                        Updated 9:12 am
                    </PageHeaderFilterButton>
                    {dirty ? (
                        <PageHeaderFilterButton active icon={Pencil} onClick={() => goto('rules', 'records')}>
                            {changes.length} unsaved {changes.length === 1 ? 'change' : 'changes'}
                        </PageHeaderFilterButton>
                    ) : null}
                </>
            }
            rail={
                <PageHeaderRail
                    items={Object.entries(SET_VIEWS).map(([key, v]) => ({ key, label: v.label, icon: v.icon, ...(key === 'history' ? { count: open11 } : {}) }))}
                    value={view}
                    onSelect={(k) => goto(k)}
                    ariaLabel="Settings views"
                    decorations={dirty ? { rules: <DirtyDot key="d" /> } : undefined}
                />
            }
        />
    );
    const tabs = secs.map(([key, label, icon]) => ({ key, label, icon, ...(key === 'decide' ? { count: open11 } : {}), ...(key === 'records' && dirty ? { warningCount: changes.length } : {}) }));

    let body: ReactNode;
    if (view === 'rules' && sec === 'records') body = <RecordsSection ro={ro} show={r.q.get('show') ?? 'all'} q={query} />;
    else if (view === 'history' && sec === 'changes') body = <AllChanges q={query} />;
    else
        body = (
            <Card className="p-2">
                <EmptyState
                    icon={Lock}
                    title={LINKED_SECS[sec] ?? `${secs.find(([k]) => k === sec)![1]} is P11’s approved ${sec === 'overview' ? 'overview' : 'section'}`}
                    description={view === 'history' && sec === 'decide' ? `It lists every setting nobody has deliberately chosen — ${open11}, with P09’s “Keep medication records for” and “SAC ratings” until they’re reviewed.` : sec === 'log' ? 'The alert log stays here, in Settings; Reports & audit links to it.' : 'This preview shows P09’s additions to Settings: Medication rules › Records & reporting, and the change history.'}
                    action={
                        <Button variant="outline" size="sm" onClick={() => goto('rules', 'records')}>
                            Go to Records & reporting
                        </Button>
                    }
                />
            </Card>
        );
    const bar =
        view === 'rules' && sec === 'records' ? (
            <SaveBar
                count={changes.length}
                onDiscard={() => s.update((rt) => ({ ...rt, orgDraft: null }))}
                onReview={() => open('p11review')}
                readOnly={auditor ? 'Read-only — auditors can view settings and their history, not change them.' : !canSetOrg(p) ? 'Only someone who manages medication settings for all houses can change these — for example Hana Kereama, clinical lead.' : r.scenario === 'offline' ? 'You’re offline — settings are never saved on the device. Reconnect to change them.' : undefined}
            />
        ) : null;
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/emar/reports' }, { title: 'Settings' }]}>
            <div className="space-y-5">
                {header}
                <Sections tabs={tabs} value={sec} onChange={(k) => goto(view, k)} />
                {s.rt.flash ? (
                    <div role="status" tabIndex={-1} ref={flashRef} className="outline-none">
                        <FleetNotice role="note">
                            <span>{s.rt.flash}</span>
                        </FleetNotice>
                    </div>
                ) : null}
                {body}
                {bar}
                <DesignNote title="Design note — two additions to P11 (Main, Q7 and Q11), built with P09, not B1">
                    <p>
                        Today medication audit rows are deleted after 2 years with everything else, and nothing rates errors for adverse-event reporting (AUDIT 3, 6). These two rows are drawn in P11’s pattern and built with P09, not in B1. The header numbers are P11 v5’s reference numbers with P05’s and P08b’s approved additions, as Hana Kereama sees them; “Still to decide” counts P09’s two until they’re reviewed.
                    </p>
                </DesignNote>
            </div>
        </Shell>
    );
}

/* ───────────── Medication rules › Records & reporting (P09’s additions) ───────────── */
function RecordsSection({ ro, show, q }: { ro: boolean; show: string; q: string }) {
    const s = useStore();
    const org = s.rt.org;
    const d = s.rt.orgDraft;
    const m = merged(org, d);
    const changes = changesOf(org, d);
    const changed = (k: string) => changes.some((c) => c.key === k || c.key.startsWith(k));
    const match = (...t: string[]) => !q || t.some((x) => x.toLowerCase().includes(q.toLowerCase()));
    const vis = (openRow: boolean, changedRow: boolean, ...t: string[]) => (show === 'open' ? openRow : show === 'changed' ? changedRow : true) && match(...t);
    const edit = (patch: Partial<OrgRecords>) => s.update((rt) => ({ ...rt, orgDraft: { ...(rt.orgDraft ?? {}), ...patch, map: { ...rt.org.map, ...(rt.orgDraft?.map ?? {}), ...(patch.map ?? {}) } }, flash: null }));
    const setMap = (k: 'death' | 'moderate' | 'minor', v: string) => edit({ map: { ...m.map, [k]: Number(v) as Sac, ...(k === 'minor' ? { none: Number(v) as Sac } : {}) } });
    return (
        <Section id="sc-records" title="Records & reporting" caption="Every house · how long medication records are kept, and ratings for adverse-event reporting">
            <GroupGrid>
                <SettingGroup id="retention" icon={Archive} title="Keeping medication records" caption="The event log, MARs, registers and the records behind reports">
                    <GroupRow id="rr-keep" label="Keep medication records for" hint="Counted from the person’s last service. The weekly clean-up of other records never removes them." state={changed('retention') ? 'changed' : org.retentionReviewed ? null : 'default'} hidden={!vis(!org.retentionReviewed, changed('retention'), 'Keep medication records', 'years')}>
                        <Choice value={m.retention} options={RET_OPTS} disabled={ro} onChange={(v) => edit({ retention: v })} />
                        <p className="text-caption">After the person’s last service.</p>
                        {!org.retentionReviewed && !changed('retention') && !ro ? (
                            <p>
                                <Button variant="link" className="h-auto p-0 text-[12.5px]" onClick={() => s.set({ open: 'p11review:keep-retention' })}>
                                    Keep the default — {RETENTION_LABEL.y10}
                                </Button>
                            </p>
                        ) : null}
                    </GroupRow>
                </SettingGroup>
                <SettingGroup id="sac" icon={Scale} title="Adverse-event reporting" caption="For organisations that report medication errors as adverse events">
                    <GroupRow
                        id="rr-sac"
                        label="Add SAC ratings when an error is closed"
                        hint="The person closing each error confirms its rating, chosen for them from the mapping below."
                        state={changed('sac') ? 'changed' : org.sacReviewed ? null : 'default'}
                        hidden={!vis(!org.sacReviewed, changed('sac') || changed('map'), 'SAC', 'adverse')}
                        control={
                            <span className="inline-flex items-center gap-3">
                                <Switch id="rr-sac" checked={m.sac} onCheckedChange={(v) => edit({ sac: v })} disabled={ro} aria-label="Add SAC ratings when an error is closed" />
                                <span className="text-subtle w-7" aria-hidden="true">
                                    {m.sac ? 'On' : 'Off'}
                                </span>
                            </span>
                        }
                    >
                        {m.sac ? (
                            <ul className="divide-y divide-border rounded-lg border" aria-label="The mapping">
                                {(
                                    [
                                        ['death', 'Death'],
                                        ['moderate', 'Moderate harm'],
                                        ['minor', 'Minor or no harm'],
                                    ] as const
                                ).map(([k, label]) => (
                                    <li key={k} className="flex flex-wrap items-center justify-between gap-3 p-2.5 text-[13px]">
                                        <span>
                                            {label}
                                            {m.map[k] !== SAC_DEFAULT[k] ? <span className="block text-caption">Default: SAC {SAC_DEFAULT[k]}</span> : null}
                                        </span>
                                        <Choice value={String(m.map[k])} options={SAC_OPTS} disabled={ro} onChange={(v) => setMap(k, v)} />
                                    </li>
                                ))}
                                <li className="flex flex-wrap items-center justify-between gap-3 p-2.5 text-[13px]">
                                    <span>Severe or permanent harm</span>
                                    <span className="text-caption">The person closing it chooses SAC 1 or SAC 2 — nothing is chosen for them</span>
                                </li>
                                <li className="flex flex-wrap items-center justify-between gap-3 p-2.5 text-[13px]">
                                    <span>Near miss</span>
                                    <span className="text-caption">No SAC — it didn’t reach the person</span>
                                </li>
                            </ul>
                        ) : null}
                        {!org.sacReviewed && !changed('sac') && !ro ? (
                            <p>
                                <Button variant="link" className="h-auto p-0 text-[12.5px]" onClick={() => s.set({ open: 'p11review:keep-sac' })}>
                                    Keep the default — off
                                </Button>
                            </p>
                        ) : null}
                        <p className="text-caption">Shown in the medication errors report and its file. The harm questions themselves don’t change.</p>
                    </GroupRow>
                    <GroupRow id="rr-close" label="Where it’s used" hint="Closing an error, in Safety & oversight › Medication errors." control={<Button variant="outline" size="sm" onClick={() => s.go('/emar/errors', { view: undefined, sec: undefined, show: undefined, open: undefined })}>Medication errors <ArrowUpRight className="size-4" /></Button>} hidden={!vis(false, false, 'Where it’s used', 'closing')} />
                </SettingGroup>
            </GroupGrid>
        </Section>
    );
}

/* ───────────── Change history › All changes (P11 pattern) ───────────── */
type HistRow = (typeof P11_HISTORY)[number] & { fresh?: boolean };
function AllChanges({ q }: { q: string }) {
    const s = useStore();
    const open = useOpen();
    const ctx = useCtx(History);
    const all: HistRow[] = [...s.rt.history, ...P11_HISTORY];
    const rows = all.filter((h) => !q || `${h.what} ${h.to} ${h.who}`.toLowerCase().includes(q.toLowerCase()));
    const menu = (h: HistRow): MenuItem[] =>
        compactMenu([
            { label: 'View before and after', icon: Eye, onClick: () => open(`p11hist:${h.id}`) },
            { label: 'Go to the setting', icon: ArrowUpRight, onClick: () => s.set({ view: h.area === 'Rounds & timing' ? 'rounds' : 'rules', sec: h.id.startsWith('p09') ? 'records' : undefined, open: undefined }) },
        ]);
    return (
        <Section id="sc-changes" title="All changes" caption={`${rows.length} of ${all.length} shown · newest first · also in the audit trail`}>
            {rows.length ? (
                <EntityTable<HistRow>
                    rows={rows}
                    rowKey={(h) => h.id}
                    identityLabel="What changed"
                    identityWidth="2fr"
                    minWidth={960}
                    rowHeight="content"
                    identity={(h) => ({ icon: History, name: h.what, subline: `${h.from && h.from !== '—' ? `${h.from} → ` : ''}${h.to}`, extra: h.fresh ? <StatusBadge variant="info" size="sm">Just now</StatusBadge> : undefined })}
                    columns={[
                        { key: 'when', label: 'When (NZDT)', width: '1fr', cell: (h) => <span className="text-[13px]">{h.when}</span> },
                        { key: 'who', label: 'Who', width: '1fr', cell: (h) => <span className="text-[13px]">{h.who}</span> },
                        { key: 'where', label: 'Where', width: '0.9fr', cell: (h) => <EntityChip icon={h.scope === 'All houses' ? Building2 : Home}>{h.scope}</EntityChip> },
                        { key: 'area', label: 'Area', width: '0.9fr', cell: (h) => <span className="text-[13px]">{h.area}</span> },
                    ]}
                    actionsFor={menu}
                    onOpen={(h) => open(`p11hist:${h.id}`)}
                    onRowContextMenu={(e, h) => ctx.openAt(e, h.what, menu(h))}
                />
            ) : (
                <EmptyState icon={History} title="No changes match this search" description="Clear the search to see every change." />
            )}
            {ctx.node}
        </Section>
    );
}

/* ───────────── dialogs (hosted by host.tsx) ───────────── */
/** P11’s “Review … changes” (Fleet’s pattern): before → after, then save. Keeping a default marks it reviewed. */
export function ReviewSettings({ onClose }: { onClose: () => void }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const spec = s.route.q.get('open') ?? '';
    const keep = spec === 'p11review:keep-retention' ? 'retention' : spec === 'p11review:keep-sac' ? 'sac' : null;
    const org = s.rt.org;
    const changes = keep ? [] : changesOf(org, s.rt.orgDraft);
    const [err, setErr] = useState('');
    const save = () => {
        if (s.route.scenario === 'offline') return;
        if (s.route.scenario === 'logdown') return setErr('Couldn’t save — try again. Nothing was changed, and your changes are still here.');
        const at = '28 Sep 2026 9:12 am';
        s.update((rt) => {
            const m = merged(rt.org, rt.orgDraft);
            const rows = keep
                ? [{ id: `p09-${rt.history.length + 1}`, what: keep === 'retention' ? 'Keep medication records for' : 'SAC ratings when an error is closed', from: keep === 'retention' ? `${RETENTION_LABEL[rt.org.retention]} (default — not yet reviewed)` : 'Off (default — not yet reviewed)', to: keep === 'retention' ? `${RETENTION_LABEL[rt.org.retention]} — kept` : 'Off — kept', who: me.name, when: at, scope: 'All houses', area: 'Medication rules', fresh: true }]
                : changes.map((c, i) => ({ id: `p09-${rt.history.length + i + 1}`, what: c.label, from: c.from, to: c.to, who: me.name, when: at, scope: 'All houses', area: 'Medication rules', fresh: true }));
            const org2: OrgRecords = keep
                ? { ...rt.org, ...(keep === 'retention' ? { retentionReviewed: true } : { sacReviewed: true }), by: me.name, at }
                : { ...m, retentionReviewed: rt.org.retentionReviewed || changes.some((c) => c.key === 'retention'), sacReviewed: rt.org.sacReviewed || changes.some((c) => c.key !== 'retention'), by: me.name, at };
            return { ...rt, org: org2, orgDraft: keep ? rt.orgDraft : null, history: [...rows, ...rt.history], flash: `${rows.length} ${rows.length === 1 ? 'change' : 'changes'} saved.${changes.some((c) => c.key === 'sac' && c.to === 'On') ? ' Errors closed from now on are rated; closed ones stay as they were.' : ''}` };
        });
        onClose();
    };
    return (
        <FleetModal
            title={keep ? 'Keep the default?' : 'Review records & reporting changes'}
            description={keep ? 'It stops showing “Default — not yet reviewed”.' : `${changes.length} ${changes.length === 1 ? 'change' : 'changes'} · nothing applies until you save.`}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Keep editing
                    </Button>
                    <Button onClick={save} disabled={s.route.scenario === 'offline'}>
                        {keep ? 'Keep it' : 'Save changes'}
                    </Button>
                </>
            }
        >
            <ReviewCard icon={Archive} title="Records & reporting">
                {keep ? (
                    <ReviewRow label={keep === 'retention' ? 'Keep medication records for' : 'SAC ratings'} value={<b>{keep === 'retention' ? `${RETENTION_LABEL[org.retention]} — kept` : 'Off — kept'}</b>} />
                ) : (
                    changes.map((c) => (
                        <ReviewRow
                            key={c.key}
                            label={c.label}
                            value={
                                <>
                                    <span className="text-muted-foreground line-through decoration-1">{c.from}</span> → <b>{c.to}</b>
                                </>
                            }
                        />
                    ))
                )}
            </ReviewCard>
            {err ? <p className="text-sm font-semibold text-status-critical" role="alert">{err}</p> : null}
            <p className="text-caption">Recorded in the change history and the audit trail with your name and the time.</p>
        </FleetModal>
    );
}
export function HistDetail({ id, onClose }: { id: string; onClose: () => void }) {
    const s = useStore();
    const h = [...s.rt.history, ...P11_HISTORY].find((x) => x.id === id);
    if (!h)
        return (
            <FleetModal title="We can’t show this record" description="It may not exist, or it may not be available to you." onClose={onClose}>
                <p className="text-subtle">Check the link, or choose the change from the list.</p>
            </FleetModal>
        );
    return (
        <FleetModal title={h.what} description={`${h.when} NZDT · ${h.who}`} onClose={onClose}>
            <ReviewCard icon={History} title="The change">
                <ReviewRow label="Before" value={h.from || '—'} />
                <ReviewRow label="After" value={<b>{h.to}</b>} />
            </ReviewCard>
            <ReviewCard icon={Building2} title="Where and when">
                <ReviewRow label="Area" value={h.area} />
                <ReviewRow label="Where" value={h.scope} />
            </ReviewCard>
        </FleetModal>
    );
}
