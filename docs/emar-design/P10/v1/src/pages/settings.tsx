/* Medication › Settings — the P11 v5 frame, showing Alerts & access ›
 * Emergency access. That tab is P11’s (built by P11 B3): its four steps, its
 * two groups and its worked example are drawn here as they are, read-only,
 * and not redesigned. P10 ADDS one group (Main: “P10 designs everything else
 * in P11 AUDIT §6”): whether a second person confirms a grant, when reviews
 * are due, and the fixed reviewer rule. Drawn in P11’s exact group/row
 * pattern with “Default — not yet reviewed”, the sticky save bar, “Review …
 * changes” and the change history. Every other view is P11’s and link-only. */
import { EntityChip } from '@/components/lists/entity-cells';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import {
    PageHeader,
    PageHeaderFilterButton,
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
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { Modal as FleetModal, Notice as FleetNotice, Sections } from '@/pages/fleet-assets/settings/_ui';
import { Activity, AlertOctagon, Archive, ArrowUpRight, Bell, Building2, ClipboardCheck, Clock, Eye, History, HelpCircle, Home, KeyRound, Lock, LockKeyhole, Pencil, Pill, RefreshCw, Repeat, Settings as SettingsIcon, Shield, Stethoscope, Timer, UserCheck } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { PERSONAS, P11_HISTORY, has } from '../data';
import { useOpen } from '../host';
import { EA_DEFAULT, POLICY, canAudit, fmtMin, type EaSettings } from '../model';
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
/** P11 v5’s “Still to decide”, with P05’s, P08b’s and P09’s approved additions (not yet reviewed). */
const P11_OPEN = 45 + 1 + 1 + 2;
const DirtyDot = () => <span role="img" aria-label="Unsaved changes" className="size-2 rounded-full bg-status-warning" />;
const LINKED_SECS: Record<string, string> = { reviews: 'Medication reviews is P05’s approved addition', triage: 'Error triage is P08b’s approved addition', records: 'Records & reporting is P09’s approved addition' };
const SECOND_LABEL: Record<EaSettings['second'], string> = { off: 'Not asked', optional: 'Optional — the person starting it chooses', required: 'Required — it can’t start without one' };
const DAYS_LABEL: Record<EaSettings['reviewDays'], string> = { '1': '1 day', '2': '2 days', '3': '3 days' };

const merged = (ea: EaSettings, d: Partial<EaSettings> | null): EaSettings => ({ ...ea, ...(d ?? {}) });
function changesOf(ea: EaSettings, d: Partial<EaSettings> | null) {
    const m = merged(ea, d);
    const out: { key: string; label: string; from: string; to: string }[] = [];
    if (m.second !== ea.second) out.push({ key: 'second', label: 'A second person confirms a grant', from: SECOND_LABEL[ea.second], to: SECOND_LABEL[m.second] });
    if (m.reviewDays !== ea.reviewDays) out.push({ key: 'reviewDays', label: 'A review is due within', from: DAYS_LABEL[ea.reviewDays], to: DAYS_LABEL[m.reviewDays] });
    return out;
}
const canEditEa = (p: Parameters<typeof has>[0]) => has(p, 'ea.policy');

export function SettingsPage() {
    const s = useStore();
    const r = s.route;
    const p = r.persona;
    const open = useOpen();
    const view = SET_VIEWS[r.q.get('view') ?? ''] ? r.q.get('view')! : 'alerts';
    const secs = SET_VIEWS[view].secs;
    const sec = secs.some(([k]) => k === r.q.get('sec')) ? r.q.get('sec')! : view === 'alerts' ? 'emergency' : secs[0][0];
    const [query, setQuery] = useState('');
    const ea = s.rt.ea;
    const changes = changesOf(ea, s.rt.eaDraft);
    const dirty = changes.length > 0;
    const open11 = P11_OPEN + (ea.secondReviewed ? 0 : 1) + (ea.reviewReviewed ? 0 : 1);
    const ro = !canEditEa(p) || r.scenario === 'offline';
    const auditor = !has(p, 'errors.manage') && canAudit(p);
    const goto = (v: string, sc?: string) => s.set({ view: v, sec: sc, open: undefined });
    const flashRef = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (s.rt.flash) flashRef.current?.focus();
    }, [s.rt.flash]);
    if (!canAudit(p) && !has(p, 'errors.manage'))
        return (
            <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication' }, { title: 'Settings' }]}>
                <Card className="items-center gap-3 p-10 text-center">
                    <LockKeyhole className="size-8 text-muted-foreground" aria-hidden="true" />
                    <h1 className="text-section-title">We can’t show this page</h1>
                    <p className="text-subtle">It may not exist, or it may not be available to you.</p>
                </Card>
            </Shell>
        );

    const accessText = auditor ? 'Read-only for audit' : canEditEa(p) ? 'All-sites authority · every setting' : has(p, 'settings.org') ? 'All-sites authority · emergency access policy read-only' : 'House settings · organisation rules read-only';
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
                    <PageHeaderFilterButton icon={RefreshCw} onClick={() => s.toast('success', 'Up to date · 9:12 am NZDT')} aria-label="Updated 9:12 am NZDT — refresh">
                        Updated 9:12 am
                    </PageHeaderFilterButton>
                    {dirty ? (
                        <PageHeaderFilterButton active icon={Pencil} onClick={() => goto('alerts', 'emergency')}>
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
                    decorations={dirty ? { alerts: <DirtyDot key="d" /> } : undefined}
                />
            }
        />
    );
    const tabs = secs.map(([key, label, icon]) => ({ key, label, icon, ...(key === 'decide' ? { count: open11 } : {}), ...(key === 'emergency' && dirty ? { warningCount: changes.length } : {}) }));

    let body: ReactNode;
    if (view === 'alerts' && sec === 'emergency') body = <EmergencySection ro={ro} q={query} />;
    else if (view === 'history' && sec === 'changes') body = <AllChanges q={query} />;
    else
        body = (
            <Card className="p-2">
                <EmptyState
                    icon={Lock}
                    title={LINKED_SECS[sec] ?? `${secs.find(([k]) => k === sec)![1]} is P11’s approved ${sec === 'overview' ? 'overview' : 'section'}`}
                    description={view === 'history' && sec === 'decide' ? `It lists every setting nobody has deliberately chosen — ${open11}, with P10’s two emergency access settings until they’re reviewed.` : sec === 'oncall' ? 'Each house’s on-call contact (D12) — P11’s. The emergency access start screen shows it when nobody can confirm a grant.' : 'This preview shows P10’s addition to Settings: Alerts & access › Emergency access.'}
                    action={
                        <Button variant="outline" size="sm" onClick={() => goto('alerts', 'emergency')}>
                            Go to Emergency access
                        </Button>
                    }
                />
            </Card>
        );
    const bar =
        view === 'alerts' && sec === 'emergency' ? (
            <SaveBar
                count={changes.length}
                onDiscard={() => s.update((rt) => ({ ...rt, eaDraft: null }))}
                onReview={() => open('p11review')}
                readOnly={auditor ? 'Read-only — auditors can view settings and their history, not change them.' : !canEditEa(p) ? 'Only admins and provider managers can change the emergency access policy (today’s rule). Ask Rangi Parata.' : r.scenario === 'offline' ? 'You’re offline — settings are never saved on the device. Reconnect to change them.' : undefined}
            />
        ) : null;
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '#/emar/emergency-access' }, { title: 'Settings' }]}>
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
                <DesignNote title="Design note — P10’s addition to P11’s emergency access tab (P11 AUDIT §6)">
                    <p>The four steps, “How long a grant lasts”, “What reviewers see” and the worked example are P11 v5’s, drawn read-only here and built by P11 B3. P10 adds the second-person setting, the review due time and the reviewer rule, built with P10. The header numbers are P11 v5’s reference numbers with P05’s, P08b’s and P09’s approved additions; “Still to decide” counts P10’s two until they’re reviewed.</p>
                </DesignNote>
            </div>
        </Shell>
    );
}

/* ───────────── Alerts & access › Emergency access (P11’s tab + P10’s group) ───────────── */
function EmergencySection({ ro, q }: { ro: boolean; q: string }) {
    const s = useStore();
    const p = s.route.persona;
    const ea = s.rt.ea;
    const m = merged(ea, s.rt.eaDraft);
    const changes = changesOf(ea, s.rt.eaDraft);
    const changed = (k: string) => changes.some((c) => c.key === k);
    const match = (...t: string[]) => !q || t.some((x) => x.toLowerCase().includes(q.toLowerCase()));
    const edit = (patch: Partial<EaSettings>) => s.update((rt) => ({ ...rt, eaDraft: { ...(rt.eaDraft ?? {}), ...patch }, flash: null }));
    const steps = [
        { icon: LockKeyhole, t: 'Start', d: 'Someone who isn’t rostered for a person, but must record a dose for them now, chooses Emergency access on that person’s record and says why.' },
        { icon: Pill, t: 'Record', d: `For ${fmtMin(POLICY.def)}, they can record for that one person only — never a whole house or round.` },
        { icon: Clock, t: 'Extend or end', d: `They can add ${fmtMin(POLICY.ext)} at a time, but never past ${fmtMin(POLICY.max)} in all. Then access ends by itself.` },
        { icon: Eye, t: 'Review', d: `Reviewers get a daily report of every grant. Someone using it ${POLICY.repeatN} times within ${POLICY.repeatDays} days is flagged.` },
    ];
    const ro11 = <StatusBadge variant="neutral" size="sm">P11</StatusBadge>;
    return (
        <Section id="sc-ea" title="Emergency access" caption="When someone must record for a person they aren’t rostered for" right={<EntityChip icon={Building2}>Every house</EntityChip>}>
            {!canEditEa(p) && !(canAudit(p) && !has(p, 'errors.manage')) ? (
                <Card className="flex-row items-start gap-3 p-4">
                    <LockKeyhole className="mt-0.5 size-4 text-muted-foreground" aria-hidden="true" />
                    <p className="text-sm">
                        <b>You can read this policy.</b> Only admins and provider managers can change it — ask Rangi Parata.
                    </p>
                </Card>
            ) : null}
            <ol className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="How emergency access works">
                {steps.map((x, i) => (
                    <li key={x.t}>
                        <Card className="h-full gap-2 p-4">
                            <div className="flex items-center gap-2">
                                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[12px] font-semibold text-primary" aria-hidden="true">
                                    {i + 1}
                                </span>
                                <x.icon className="size-4 text-muted-foreground" aria-hidden="true" />
                                <p className="text-sm font-semibold">{x.t}</p>
                            </div>
                            <p className="text-subtle">{x.d}</p>
                        </Card>
                    </li>
                ))}
            </ol>
            <GroupGrid>
                <SettingGroup id="length" icon={Clock} title="How long a grant lasts" caption="Steps 2 and 3 — P11’s settings, shown as saved">
                    <GroupRow id="ea-def" label="A grant lasts" hint="They can choose a shorter time when they start." control={<span className="inline-flex items-center gap-2 text-sm font-semibold">{fmtMin(POLICY.def)} {ro11}</span>} hidden={!match('A grant lasts')} />
                    <GroupRow id="ea-ext" label="Each extension adds" hint={`Up to ${Math.ceil((POLICY.max - POLICY.def) / POLICY.ext)} extensions with these numbers.`} control={<span className="inline-flex items-center gap-2 text-sm font-semibold">{fmtMin(POLICY.ext)} {ro11}</span>} hidden={!match('Each extension adds')} />
                    <GroupRow id="ea-max" label="Longest in all" hint="The grant and its extensions never go past this." control={<span className="inline-flex items-center gap-2 text-sm font-semibold">{fmtMin(POLICY.max)} {ro11}</span>} hidden={!match('Longest in all')} />
                </SettingGroup>
                <SettingGroup id="review" icon={Eye} title="What reviewers see" caption="Steps 1 and 4 — P11’s settings, shown as saved">
                    <GroupRow id="ea-reason" label="A reason is required" hint="They say why when they start. Reviewers and the audit trail see it." control={<span className="inline-flex items-center gap-2 text-sm font-semibold">On {ro11}</span>} hidden={!match('A reason is required')} />
                    <GroupRow id="ea-repeat" label="Flag repeat use" hint="When one person uses emergency access this often, reviewers are told." control={<span className="inline-flex items-center gap-2 text-sm font-semibold">{POLICY.repeatN} grants within {POLICY.repeatDays} days {ro11}</span>} hidden={!match('Flag repeat use')} />
                    <GroupRow id="ea-reviewers" label="Who gets the daily report" hint="People who review emergency access here. Set on the “Emergency access used” alert." control={<Button variant="link" onClick={() => s.toast('info', 'P11’s alert recipients dialog — outside this preview.')}>Change <ArrowUpRight className="size-4" /></Button>} hidden={!match('Who gets the daily report', 'reviewer')} />
                </SettingGroup>
                <SettingGroup id="p10" icon={ClipboardCheck} title="A second person, and reviews" caption="Added by P10 — the rest of this tab is P11’s" wide>
                    <GroupRow id="ea-second" label="A second person confirms a grant" hint="They type their own witness PIN on the screen of the person starting it." state={changed('second') ? 'changed' : ea.secondReviewed ? null : 'default'} hidden={!match('second person', 'confirm')}>
                        <Choice
                            value={m.second}
                            disabled={ro}
                            onChange={(v) => edit({ second: v })}
                            options={[
                                ['off', 'Not asked'],
                                ['optional', 'Optional'],
                                ['required', 'Required'],
                            ]}
                        />
                        <p className="text-caption">{m.second === 'required' ? 'If nobody who can confirm is there, it can’t start — the screen shows the house’s on-call contact. There’s no way around it.' : m.second === 'optional' ? 'The person starting it chooses. Reviewers see when no one confirmed it. Today’s behaviour.' : 'Nobody is asked to confirm.'}</p>
                        {!ea.secondReviewed && !changed('second') && !ro ? (
                            <p>
                                <Button variant="link" className="h-auto p-0 text-[12.5px]" onClick={() => s.set({ open: 'p11review:keep-second' })}>
                                    Keep the default — optional
                                </Button>
                            </p>
                        ) : null}
                    </GroupRow>
                    <GroupRow id="ea-due" label="A review is due within" hint="Counted from when the grant ends — however it ends. Overdue reviews go to Follow-ups and the daily report." state={changed('reviewDays') ? 'changed' : ea.reviewReviewed ? null : 'default'} hidden={!match('review is due', 'overdue')}>
                        <Choice
                            value={m.reviewDays}
                            disabled={ro}
                            onChange={(v) => edit({ reviewDays: v })}
                            options={[
                                ['1', '1 day'],
                                ['2', '2 days'],
                                ['3', '3 days'],
                            ]}
                        />
                        {!ea.reviewReviewed && !changed('reviewDays') && !ro ? (
                            <p>
                                <Button variant="link" className="h-auto p-0 text-[12.5px]" onClick={() => s.set({ open: 'p11review:keep-review' })}>
                                    Keep the default — {DAYS_LABEL[EA_DEFAULT.reviewDays]}
                                </Button>
                            </p>
                        ) : null}
                    </GroupRow>
                    <GroupRow id="ea-reviewer" label="Who reviews a grant" hint="Anyone who reviews emergency access at the house — never the person who used it, or the one who confirmed it. Not a setting." control={<span className="inline-flex items-center gap-2 text-sm text-muted-foreground"><KeyRound className="size-4" aria-hidden="true" /> Always</span>} hidden={!match('Who reviews', 'reviewer')} />
                    <GroupRow id="ea-where" label="Where grants are reviewed" hint="Safety & oversight › Emergency access › To review." control={<Button variant="outline" size="sm" onClick={() => s.go('/emar/emergency-access', { view: 'review', sec: undefined, open: undefined })}>Emergency access <ArrowUpRight className="size-4" /></Button>} hidden={!match('Where', 'reviewed')} />
                </SettingGroup>
                <ReviewCard icon={Timer} title="Example: one grant with these settings" span>
                    <p className="text-subtle mb-2">P11’s worked example, with P10’s additions. Synthetic — nothing is recorded.</p>
                    <ReviewRow label="9:00 am" value={<span className="text-right"><b>Mere Kahu starts emergency access for Aroha N.</b><span className="text-caption block">{`Reason: “Aroha’s rostered support worker went home unwell.”${m.second === 'off' ? '' : m.second === 'required' ? ' Hana Kereama confirms it with her PIN — it can’t start without her.' : ' Mere may ask someone to confirm it.'}`}</span></span>} />
                    <ReviewRow label="10:00 am" value={<span className="text-right"><b>Access ends</b><span className="text-caption block">Unless Mere extends it before then.</span></span>} />
                    <ReviewRow label="1:00 pm" value={<span className="text-right"><b>Latest possible end</b><span className="text-caption block">After 6 extensions of 30 minutes. No more extensions — access ends by itself.</span></span>} />
                    <ReviewRow label="Next day" value={<span className="text-right"><b>Reviewers get the daily report</b><span className="text-caption block">People who review emergency access here</span></span>} />
                    <ReviewRow label="If it happens again" value={<span className="text-right"><b>Repeat use is flagged</b><span className="text-caption block">When Mere has used it {POLICY.repeatN} times within {POLICY.repeatDays} days.</span></span>} />
                    <ReviewRow label={`Within ${DAYS_LABEL[m.reviewDays]}`} value={<span className="text-right"><b>Someone other than Mere reviews it</b><span className="text-caption block">If it isn’t reviewed by then, it’s overdue — in Follow-ups and the daily report.</span></span>} />
                </ReviewCard>
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
            { label: 'Go to the setting', icon: ArrowUpRight, onClick: () => s.set({ view: h.area === 'Rounds & timing' ? 'rounds' : h.area === 'Alerts & access' ? 'alerts' : 'rules', sec: h.area === 'Alerts & access' ? 'emergency' : undefined, open: undefined }) },
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
    const keep = spec === 'p11review:keep-second' ? 'second' : spec === 'p11review:keep-review' ? 'reviewDays' : null;
    const ea = s.rt.ea;
    const changes = keep ? [] : changesOf(ea, s.rt.eaDraft);
    const [err, setErr] = useState('');
    const save = () => {
        if (s.route.scenario === 'offline') return;
        if (s.route.scenario === 'logdown') return setErr('Couldn’t save — try again. Nothing was changed, and your changes are still here.');
        const at = '28 Sep 2026 9:12 am';
        s.update((rt) => {
            const m = merged(rt.ea, rt.eaDraft);
            const rows = keep
                ? [{ id: `p10-${rt.history.length + 1}`, what: keep === 'second' ? 'A second person confirms a grant' : 'A review is due within', from: `${keep === 'second' ? SECOND_LABEL[rt.ea.second] : DAYS_LABEL[rt.ea.reviewDays]} (default — not yet reviewed)`, to: `${keep === 'second' ? SECOND_LABEL[rt.ea.second] : DAYS_LABEL[rt.ea.reviewDays]} — kept`, who: me.name, when: at, scope: 'All houses', area: 'Alerts & access', fresh: true }]
                : changes.map((c, i) => ({ id: `p10-${rt.history.length + i + 1}`, what: c.label, from: c.from, to: c.to, who: me.name, when: at, scope: 'All houses', area: 'Alerts & access', fresh: true }));
            const ea2: EaSettings = keep ? { ...rt.ea, ...(keep === 'second' ? { secondReviewed: true } : { reviewReviewed: true }) } : { ...m, secondReviewed: rt.ea.secondReviewed || changes.some((c) => c.key === 'second'), reviewReviewed: rt.ea.reviewReviewed || changes.some((c) => c.key === 'reviewDays') };
            return { ...rt, ea: ea2, eaDraft: keep ? rt.eaDraft : null, history: [...rows, ...rt.history], flash: `${rows.length} ${rows.length === 1 ? 'change' : 'changes'} saved. From the next grant — grants already running keep what they started with.` };
        });
        onClose();
    };
    return (
        <FleetModal
            title={keep ? 'Keep the default?' : 'Review emergency access changes'}
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
            <ReviewCard icon={KeyRound} title="Emergency access">
                {keep ? (
                    <ReviewRow label={keep === 'second' ? 'A second person confirms a grant' : 'A review is due within'} value={<b>{keep === 'second' ? SECOND_LABEL[ea.second] : DAYS_LABEL[ea.reviewDays]} — kept</b>} />
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
            {err ? (
                <p className="text-sm font-semibold text-status-critical" role="alert">
                    {err}
                </p>
            ) : null}
            <p className="text-caption">From the next grant — grants already running keep what they started with. Recorded in the change history and the audit trail with your name and the time.</p>
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
