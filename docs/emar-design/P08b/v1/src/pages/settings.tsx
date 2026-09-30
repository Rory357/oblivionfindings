/* Medication › Settings — the P11 v5 frame, showing P08b’s ADDITION TO P11
 * (Main, Q5 addition, built with P08b, not in B1): how soon a reported
 * medication error must be triaged. The page top is P11 v5’s approved Settings
 * header, rail and section strip with its reference numbers (as Hana Kereama
 * sees them, as at 28 Sep); Alerts & access gains an “Error triage” section
 * drawn in P11’s exact group/row pattern, with “Default — not yet reviewed”,
 * the sticky save bar, “Review … changes” and the change history. Every other
 * view and section is P11’s (or P05’s approved addition) and link-only. */
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
import { InfoCard } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { Modal as FleetModal, Notice as FleetNotice, Sections } from '@/pages/fleet-assets/settings/_ui';
import { Activity, AlertOctagon, AlertTriangle, ArrowUpRight, Bell, Building2, Clock, Eye, History, HelpCircle, Home, Lock, Pencil, Pill, RefreshCw, Repeat, Settings as SettingsIcon, Shield, Stethoscope, UserCheck, Users } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { PERSONAS, P11_HISTORY, TRIAGE_LABEL } from '../data';
import { useOpen } from '../host';
import { canManage, canSetOrg, housesOf, TRIAGE_ORDER, type TriageValue } from '../model';
import { Shell } from '../shell';
import { useStore } from '../store';
import { DesignNote } from '../ui';
import { Choice, GroupGrid, GroupRow, SaveBar, Section, SettingGroup } from '../p11-ui';
import { useCtx } from './errors';

const SET_VIEWS: Record<string, { label: string; icon: typeof Pill; secs: [string, string, typeof Pill][] }> = {
    rules: { label: 'Medication rules', icon: Pill, secs: [['overview', 'Overview', Activity], ['medicines', 'Medicine rules', Pill], ['safety', 'Safety checks', Shield], ['controlled', 'Controlled drugs', Lock], ['photos', 'Medicine photos', Pill]] },
    rounds: { label: 'Rounds & timing', icon: Repeat, secs: [['overview', 'Overview', Activity], ['templates', 'Round templates', Repeat], ['timing', 'Dose timing', Clock], ['reviews', 'Medication reviews', Stethoscope]] },
    staff: { label: 'Staff & PINs', icon: UserCheck, secs: [['overview', 'Overview', Activity], ['competency', 'Competency', UserCheck], ['exemptions', 'Exemption limit', Shield], ['pins', 'Witness PINs', Lock], ['status', 'PIN status', UserCheck]] },
    alerts: { label: 'Alerts & access', icon: Bell, secs: [['overview', 'Overview', Activity], ['alerts', 'Alerts', Bell], ['delivery', 'Delivery', Bell], ['triage', 'Error triage', AlertOctagon], ['oncall', 'On-call contacts', Bell], ['emergency', 'Emergency access', Lock], ['log', 'Alert log', History]] },
    history: { label: 'Change history', icon: History, secs: [['decide', 'Still to decide', HelpCircle], ['changes', 'All changes', History]] },
};
/** P11 v5’s “Still to decide” as at its approved version, plus P05’s approved addition (not yet reviewed). */
const P11_OPEN = 45 + 1;
const DirtyDot = () => <span role="img" aria-label="Unsaved changes" className="size-2 rounded-full bg-status-warning" />;
const OPTIONS = TRIAGE_ORDER.map((v) => [v, TRIAGE_LABEL[v]] as [TriageValue, string]);

export function SettingsPage() {
    const s = useStore();
    const r = s.route;
    const p = r.persona;
    const me = PERSONAS[p];
    const open = useOpen();
    const view = SET_VIEWS[r.q.get('view') ?? ''] ? r.q.get('view')! : 'alerts';
    const secs = SET_VIEWS[view].secs;
    const sec = secs.some(([k]) => k === r.q.get('sec')) ? r.q.get('sec')! : view === 'alerts' ? 'triage' : secs[0][0];
    const [query, setQuery] = useState('');
    const org = s.rt.org;
    const draft = s.rt.orgDraft;
    const dirty = draft !== null && draft !== org.value;
    const open11 = P11_OPEN + (org.reviewed ? 0 : 1);
    const ro = !canSetOrg(p) || r.scenario === 'offline';
    const auditor = !canManage(p) && me.perms.includes('audit.view');
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
                    {sec === 'triage' ? <PageHeaderFilterSelect label="All settings" value={r.q.get('show') ?? 'all'} allValue="all" onChange={(v) => s.set({ show: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All settings' }, { value: 'open', label: 'Not yet reviewed' }, { value: 'changed', label: 'Unsaved changes' }]} /> : null}
                    <PageHeaderFilterButton icon={RefreshCw} onClick={() => s.toast('success', 'Up to date · 9:12 am NZDT')} aria-label="Updated 9:12 am NZDT — refresh">
                        Updated 9:12 am
                    </PageHeaderFilterButton>
                    {dirty ? (
                        <PageHeaderFilterButton active icon={Pencil} onClick={() => goto('alerts', 'triage')}>
                            1 unsaved change
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
                    decorations={dirty ? { alerts: <DirtyDot key="d" /> } : undefined}
                />
            }
        />
    );
    const tabs = secs.map(([key, label, icon]) => ({ key, label, icon, ...(key === 'decide' ? { count: open11 } : {}), ...(key === 'triage' && dirty ? { warningCount: 1 } : {}) }));

    let body: ReactNode;
    if (view === 'alerts' && sec === 'triage') body = <TriageSection ro={ro} show={r.q.get('show') ?? 'all'} q={query} />;
    else if (view === 'history' && sec === 'changes') body = <AllChanges q={query} />;
    else
        body = (
            <Card className="p-2">
                <EmptyState
                    icon={Lock}
                    title={view === 'rounds' && sec === 'reviews' ? 'Medication reviews is P05’s approved addition' : `${secs.find(([k]) => k === sec)![1]} is P11’s approved ${sec === 'overview' ? 'overview' : 'section'}`}
                    description={view === 'history' && sec === 'decide' ? `It lists every setting nobody has deliberately chosen — ${open11} with P05’s “Regular review every” and P08b’s “Triage a reported error”.` : 'This preview shows P08b’s addition to Settings: Alerts & access › Error triage, and the change history.'}
                    action={
                        <Button variant="outline" size="sm" onClick={() => goto('alerts', 'triage')}>
                            Go to Error triage
                        </Button>
                    }
                />
            </Card>
        );
    const bar =
        view === 'alerts' && sec === 'triage' ? (
            <SaveBar
                count={dirty ? 1 : 0}
                onDiscard={() => s.update((rt) => ({ ...rt, orgDraft: null }))}
                onReview={() => open('p11review')}
                readOnly={auditor ? 'Read-only — auditors can view settings and their history, not change them.' : !canSetOrg(p) ? 'Only someone who manages medication settings for all houses can change these — for example Hana Kereama, clinical lead.' : r.scenario === 'offline' ? 'You’re offline — settings are never saved on the device. Reconnect to change them.' : undefined}
            />
        ) : null;
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/emar/errors' }, { title: 'Settings' }]}>
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
                <DesignNote title="Design note — an addition to P11 (Main, Q5), built with P08b, not B1">
                    <p>
                        P11 v5 routes the “Medication errors reported” alert to the house lead and the clinical lead until an error is triaged, but no time for triage exists anywhere today (AUDIT 3). This is it, drawn in P11’s pattern and built with P08b, not in B1. The header numbers are P11 v5’s reference numbers with P05’s approved addition, as Hana Kereama sees them; “Still to decide” counts one more until this default is reviewed.
                    </p>
                </DesignNote>
            </div>
        </Shell>
    );
}

/* ───────────── Alerts & access › Error triage (P08b’s addition) ───────────── */
function TriageSection({ ro, show, q }: { ro: boolean; show: string; q: string }) {
    const s = useStore();
    const org = s.rt.org;
    const draft = s.rt.orgDraft ?? org.value;
    const dirty = s.rt.orgDraft !== null && s.rt.orgDraft !== org.value;
    const match = (...t: string[]) => !q || t.some((x) => x.toLowerCase().includes(q.toLowerCase()));
    const vis = (openRow: boolean, changedRow: boolean, ...t: string[]) => (show === 'open' ? openRow : show === 'changed' ? changedRow : true) && match(...t);
    const edit = (v: TriageValue) => s.update((rt) => ({ ...rt, orgDraft: v === rt.org.value ? null : v, flash: null }));
    return (
        <Section id="sc-triage" title="Error triage" caption="Every house · how soon a reported medication error is looked at">
            <GroupGrid>
                <SettingGroup id="triage" icon={AlertOctagon} title="Medication errors" caption="From the time an error is reported" wide>
                    <GroupRow id="et-by" label="Triage a reported error" hint="The owner and the due date are set at triage. Until then the house lead and the clinical lead are alerted." state={dirty ? 'changed' : org.reviewed ? null : 'default'} hidden={!vis(!org.reviewed, dirty, 'Triage a reported error', ...Object.values(TRIAGE_LABEL))}>
                        <Choice value={draft} options={OPTIONS} disabled={ro} onChange={edit} />
                        {!org.reviewed && !dirty && !ro ? (
                            <p>
                                <Button variant="link" className="h-auto p-0 text-[12.5px]" onClick={() => s.set({ open: 'p11review:keep' })}>
                                    Keep the default — {TRIAGE_LABEL[org.value].toLowerCase()}
                                </Button>
                            </p>
                        ) : null}
                        {org.reviewed && org.by ? <p className="text-caption">Set by {org.by}, {org.at}</p> : null}
                    </GroupRow>
                    <GroupRow id="et-alert" label="Who’s alerted until then" hint="“Medication errors reported” — the house lead and the clinical lead for the house." hidden={!vis(false, false, 'alerted', 'Medication errors reported')} control={<Button variant="outline" size="sm" onClick={() => s.toast('info', 'Opens Alerts & access › Delivery (P11) — “Medication errors reported”.')}>Delivery <ArrowUpRight className="size-4" /></Button>} />
                    <GroupRow id="et-who" label="Who triages" hint="House leads, clinical leads, coordinators and managers. Set by role, not here." hidden={!vis(false, false, 'Who triages')} control={<Button variant="outline" size="sm" onClick={() => s.toast('info', 'Opens Settings › Roles — outside this preview.')}><Users className="size-4" /> Roles</Button>} />
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
            { label: 'Go to the setting', icon: ArrowUpRight, onClick: () => s.set({ view: h.area === 'Alerts & access' ? 'alerts' : h.area === 'Rounds & timing' ? 'rounds' : 'rules', sec: h.id.startsWith('p08b') ? 'triage' : undefined, open: undefined }) },
        ]);
    return (
        <Section id="sc-changes" title="All changes" caption={`${rows.length} of ${all.length} shown · newest first · also in the audit log`}>
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
/** P11’s “Review … changes” (Fleet’s pattern): before → after, a warning when a change loosens a check, then save. */
export function ReviewSettings({ onClose }: { onClose: () => void }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const keep = s.route.q.get('open') === 'p11review:keep';
    const from = s.rt.org.value;
    const to = keep ? from : s.rt.orgDraft ?? from;
    const looser = TRIAGE_ORDER.indexOf(to) > TRIAGE_ORDER.indexOf(from);
    const save = () => {
        if (s.route.scenario === 'offline') return;
        const at = '28 Sep 2026 9:12 am';
        s.update((rt) => ({
            ...rt,
            org: { value: to, reviewed: true, by: me.name, at },
            orgDraft: null,
            history: [{ id: `p08b-${rt.history.length + 1}`, what: 'Error triage — triage a reported error', from: keep ? `${TRIAGE_LABEL[from]} (default — not yet reviewed)` : TRIAGE_LABEL[from], to: keep ? `${TRIAGE_LABEL[to]} — kept` : TRIAGE_LABEL[to], who: me.name, when: at, scope: 'All houses', area: 'Alerts & access', fresh: true }, ...rt.history],
            flash: '1 change saved. Applies to errors reported from now; errors already reported keep their due time.',
        }));
        onClose();
    };
    return (
        <FleetModal
            title={keep ? 'Keep the default?' : 'Review alerts & access changes'}
            description={keep ? 'It stops showing “Default — not yet reviewed”.' : '1 change · nothing applies until you save.'}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Keep editing
                    </Button>
                    <Button variant={looser ? 'destructive' : 'default'} onClick={save} disabled={s.route.scenario === 'offline'}>
                        {keep ? 'Keep it' : 'Save changes'}
                    </Button>
                </>
            }
        >
            <ReviewCard icon={AlertOctagon} title="Applies to errors reported from now">
                <ReviewRow
                    label="Triage a reported error"
                    value={
                        <>
                            {keep ? (
                                <b>{TRIAGE_LABEL[to]} — kept</b>
                            ) : (
                                <>
                                    <span className="text-muted-foreground line-through decoration-1">{TRIAGE_LABEL[from]}</span> → <b>{TRIAGE_LABEL[to]}</b>
                                </>
                            )}
                            {looser ? <span className="text-caption block text-status-critical">Loosens this check</span> : null}
                        </>
                    }
                />
            </ReviewCard>
            {looser ? (
                <InfoCard icon={AlertTriangle} tone="warn">
                    <b>This change loosens a check.</b> Reported errors can wait longer before anyone looks at them. Save only if that’s intended.
                </InfoCard>
            ) : null}
            <p className="text-caption">Recorded in the change history and the audit log with your name and the time.</p>
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
